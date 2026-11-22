<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * What an order's checkout charges left behind. Every charge with one idempotency key is at most
 * one payment, so a saved card gets the next key only once the last key's payment is known to
 * have failed. Until then its request is kept, to be sent again unchanged and never replaced.
 *
 * Renewals and other off-session charges keep their own keys and counter (Payments::META_ATTEMPT).
 */
final class CheckoutAttempt
{
    public const META = '_stackless_square_checkout_attempt';

    /** @var int Saved-card declines at checkout: the key's suffix. */
    public $declines = 0;

    /** @var array<string,mixed> The saved-card request Square has not answered, or empty. */
    public $open = [];

    /** @var bool A typed card or wallet was sent and its outcome is not known. */
    public $newUnknown = false;

    public static function of(\WC_Order $order): self
    {
        $attempt = new self();
        $saved = json_decode((string) $order->get_meta(self::META), true);
        if (is_array($saved)) {
            $attempt->declines = max(0, (int) ($saved['declines'] ?? 0));
            $attempt->open = is_array($saved['open'] ?? null) ? $saved['open'] : [];
            $attempt->newUnknown = !empty($saved['new_unknown']);
        }
        return $attempt;
    }

    /** Sets the order meta; the caller saves the order. */
    public function write(\WC_Order $order): void
    {
        $value = array_filter(['declines' => $this->declines, 'open' => $this->open, 'new_unknown' => $this->newUnknown]);
        if ($value) {
            // JSON: WordPress strips backslashes from strings inside array meta, and a request is resent byte for byte.
            $order->update_meta_data(self::META, (string) wp_json_encode($value));
        } else {
            $order->delete_meta_data(self::META);
        }
    }

    public function key(\WC_Order $order): string
    {
        $suffix = (string) (int) $order->get_meta(Payments::META_ATTEMPT);
        return Api::key('c' . $order->get_id(), $this->declines > 0 ? $suffix . '-' . $this->declines : $suffix);
    }

    /**
     * Whether Square's answer proves that the request's key made no payment and never will.
     *
     * @param array<string,mixed>|\WP_Error $answer The payment, or Square's error.
     * @param bool $resent The request had been sent before, with an unknown outcome.
     */
    public static function declined($answer, bool $resent): bool
    {
        if (!is_wp_error($answer)) {
            return in_array($answer['status'] ?? '', ['FAILED', 'CANCELED'], true);
        }
        if (Api::isTransient($answer)) {
            return false;
        }
        $data = (array) $answer->get_error_data();
        if (is_array($data['payment'] ?? null)) {
            return in_array($data['payment']['status'] ?? '', ['FAILED', 'CANCELED'], true);
        }
        // Without the payment, only the first answer counts: a resend's error says nothing about the first send.
        return !$resent && self::refused($answer) && ($data['category'] ?? '') === 'PAYMENT_METHOD_ERROR';
    }

    /**
     * Whether the outcome of this request is known.
     *
     * @param array<string,mixed>|\WP_Error $answer The payment, or Square's error.
     */
    public static function answered($answer, bool $resent): bool
    {
        if (!is_wp_error($answer) || self::declined($answer, $resent)) {
            return true;
        }
        if ($resent) {
            return false;
        }
        if ($answer->get_error_code() === 'stackless_square_token') {
            return true; // never sent: no access token
        }
        $data = (array) $answer->get_error_data();
        return empty($data['payment']) && !Api::isTransient($answer) && self::refused($answer);
    }

    /** A 4xx that means "not done", unlike a timeout (408) or a key still in progress (409). */
    private static function refused(\WP_Error $error): bool
    {
        $status = Api::status($error);
        return $status >= 400 && $status < 500 && !in_array($status, [408, 409, 429], true);
    }
}
