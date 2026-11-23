<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * What an order's buyer-present charges left behind. Every charge with one idempotency key is at
 * most one payment, so a saved card gets the next key only once the last key's payment is known
 * to have failed.
 *
 * A request Square has not answered is the order's hold (see Hold): written before the request is
 * sent and kept until Square shows its result. It names the key, amount, location and card, never
 * the buyer's email or address; a saved card's request is matched by its hash to be sent again.
 *
 * Renewals and other off-session charges keep their own keys and counter (Payments::META_ATTEMPT).
 */
final class CheckoutAttempt
{
    public const META = '_stackless_square_checkout_attempt';

    public const KIND_SAVED = 'saved';
    public const KIND_NEW = 'new';

    /** @var int Saved-card declines at checkout: the key's suffix. */
    public $declines = 0;

    /**
     * The request Square has not answered, or empty: key, kind, minor, currency, location, mode,
     * source (the saved card's Square id, else empty), hash (of the whole request, saved cards
     * only), note (hash of the payment note), sent, empty_since, checks, renewal_waiting.
     *
     * @var array<string,mixed>
     */
    public $hold = [];

    public static function of(\WC_Order $order): self
    {
        $attempt = new self();
        $saved = json_decode((string) $order->get_meta(self::META), true);
        if (!is_array($saved)) {
            return $attempt;
        }
        $attempt->declines = max(0, (int) ($saved['declines'] ?? 0));
        if (is_array($saved['hold'] ?? null) && $saved['hold']) {
            $attempt->hold = $saved['hold'];
        } elseif (is_array($saved['open'] ?? null) && $saved['open']) {
            // Stackless 2.1.0 kept the whole unanswered request, email and address included.
            $attempt->hold = self::holdFor($saved['open'], self::KIND_SAVED, (string) $order->get_meta(Gateway::META_MODE));
        } elseif (!empty($saved['new_unknown'])) {
            // 2.1.0: a new card or wallet had no answer; its key and request were not kept.
            $attempt->hold = self::holdFor([
                'amount_money' => Money::money($order->get_total(), $order->get_currency()),
                'location_id' => '',
            ], self::KIND_NEW, (string) $order->get_meta(Gateway::META_MODE));
        }
        return $attempt;
    }

    /**
     * The hold for a request that is about to be sent.
     *
     * @param array<string,mixed> $body The CreatePayment request.
     * @return array<string,mixed>
     */
    public static function holdFor(array $body, string $kind, string $mode): array
    {
        $saved = $kind === self::KIND_SAVED;
        return [
            'key' => (string) ($body['idempotency_key'] ?? ''),
            'kind' => $kind,
            'minor' => (int) ($body['amount_money']['amount'] ?? 0),
            'currency' => (string) ($body['amount_money']['currency'] ?? ''),
            'location' => (string) ($body['location_id'] ?? ''),
            'mode' => $mode === 'live' ? 'live' : 'test',
            'source' => $saved ? (string) ($body['source_id'] ?? '') : '',
            'hash' => $saved && isset($body['idempotency_key']) ? self::hash($body) : '',
            'note' => isset($body['note']) ? hash('sha256', (string) $body['note']) : '',
            'sent' => time(),
            'empty_since' => 0,
            'checks' => 0,
            'renewal_waiting' => false,
        ];
    }

    /** @param array<string,mixed> $body */
    public static function hash(array $body): string
    {
        return hash('sha256', (string) wp_json_encode($body));
    }

    /** Sets the order meta; the caller saves the order. */
    public function write(\WC_Order $order): void
    {
        $value = array_filter(['declines' => $this->declines, 'hold' => $this->hold]);
        if ($value) {
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
