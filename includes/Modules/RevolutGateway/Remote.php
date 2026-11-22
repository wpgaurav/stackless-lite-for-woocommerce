<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Reads Revolut orders and decides what an earlier attempt came to.
 *
 * A failed payment puts a Revolut order back to pending, so it could still be paid. A cancelled
 * one cannot take another payment. So before a second attempt the earlier order is read, and
 * cancelled at Revolut unless it is paid or processing: only then is it known unpaid.
 */
final class Remote
{
    public const PAID = 'paid';
    /** The order is processing or authorized. */
    public const PROCESSING = 'processing';
    /** Pending, with a payment under way. */
    public const ACTIVE = 'active';
    /** Pending, with a payment waiting for the customer to authenticate. */
    public const CHALLENGE = 'challenge';
    /** Pending, and every payment made so far failed. */
    public const DECLINED = 'declined';
    /** Pending, no payment made. */
    public const OPEN = 'open';
    /** Cancelled or failed: no payment can follow. */
    public const DEAD = 'dead';

    /** A charge Revolut has no payment for is given this long to appear before it is cancelled. */
    public const GRACE = 600;

    private const FAILED_PAYMENTS = ['declined', 'soft_declined', 'cancelled', 'failed'];
    private const GOOD_PAYMENTS = ['authorisation_passed', 'authorised', 'capture_started', 'captured', 'completing', 'completed'];

    /** @param array<string,mixed> $remote A Revolut order. */
    public static function classify(array $remote): string
    {
        $state = (string) ($remote['state'] ?? '');
        if ($state === 'completed') {
            return self::PAID;
        }
        if ($state === 'authorised') {
            // A card check for nothing has nothing to capture.
            return isset($remote['amount']) && $remote['amount'] === 0 ? self::PAID : self::PROCESSING;
        }
        if ($state === 'cancelled' || $state === 'failed') {
            return self::DEAD;
        }
        if ($state !== 'pending') {
            // processing, and any state added later: not known unpaid.
            return self::PROCESSING;
        }
        $payments = self::payments($remote);
        if (!$payments) {
            return self::OPEN;
        }
        $active = [];
        foreach ($payments as $payment) {
            $paymentState = (string) ($payment['state'] ?? '');
            if (!in_array($paymentState, self::FAILED_PAYMENTS, true)) {
                $active[$paymentState] = true;
            }
        }
        if (!$active) {
            return self::DECLINED;
        }
        return array_keys($active) === ['authentication_challenge'] ? self::CHALLENGE : self::ACTIVE;
    }

    /**
     * @param array<string,mixed> $remote
     * @return array<int,array<string,mixed>>
     */
    public static function payments(array $remote): array
    {
        $payments = isset($remote['payments']) && is_array($remote['payments']) ? $remote['payments'] : [];
        return array_values(array_filter($payments, 'is_array'));
    }

    /**
     * The saved payment method id of the payment that went through ('' when none was saved).
     *
     * @param array<string,mixed> $remote
     */
    public static function savedMethodId(array $remote): string
    {
        foreach (array_reverse(self::payments($remote)) as $payment) {
            $method = isset($payment['payment_method']) && is_array($payment['payment_method']) ? $payment['payment_method'] : [];
            if (in_array((string) ($payment['state'] ?? ''), self::GOOD_PAYMENTS, true) && isset($method['id']) && is_string($method['id']) && Api::isId($method['id'])) {
                return $method['id'];
            }
        }
        return '';
    }

    /** @param array<string,mixed> $remote */
    public static function customerId(array $remote): string
    {
        return isset($remote['customer']) && is_array($remote['customer']) ? (string) ($remote['customer']['id'] ?? '') : '';
    }

    /** @param array<string,mixed> $remote */
    public static function reference(array $remote): string
    {
        $data = isset($remote['merchant_order_data']) && is_array($remote['merchant_order_data']) ? $remote['merchant_order_data'] : [];
        return isset($data['reference']) && is_scalar($data['reference']) ? (string) $data['reference'] : '';
    }

    /**
     * @param array<string,mixed> $remote
     * @return array<string,mixed>
     */
    public static function metadata(array $remote): array
    {
        return isset($remote['metadata']) && is_array($remote['metadata']) ? $remote['metadata'] : [];
    }

    /**
     * Read one Revolut order.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function read(Api $api, string $id)
    {
        if (!Api::isId($id)) {
            return new \WP_Error('stackless_revolut_id', __('That is not a Revolut order id.', 'stackless-lite'), ['status' => 404, 'code' => 'bad_id']);
        }
        $remote = $api->request('GET', 'orders/' . rawurlencode($id));
        if (is_wp_error($remote)) {
            return $remote;
        }
        if ((string) ($remote['id'] ?? '') !== $id) {
            return new \WP_Error('stackless_revolut_id', __('Revolut answered with a different order.', 'stackless-lite'), ['status' => 0, 'code' => 'id_mismatch']);
        }
        return $remote;
    }

    /**
     * Find an order by the reference saved before it was sent.
     *
     * @return array<string,mixed>|null|\WP_Error null when Revolut has none.
     */
    public static function findByReference(Api $api, string $ref)
    {
        $list = $api->request('GET', 'orders', ['merchant_order_data_reference' => $ref, 'limit' => 10]);
        if (is_wp_error($list)) {
            return $list;
        }
        if (!isset($list['orders']) || !is_array($list['orders'])) {
            return new \WP_Error('stackless_revolut_body', __('Revolut sent an answer that could not be read.', 'stackless-lite'), ['status' => 0, 'code' => 'unreadable']);
        }
        foreach ($list['orders'] as $row) {
            if (is_array($row) && self::reference($row) === $ref) {
                return self::read($api, (string) ($row['id'] ?? ''));
            }
        }
        return null;
    }

    /**
     * What an unfinished attempt came to, making sure it can take no money when it is not paid.
     *
     * state: paid | waiting (may still be paid, start nothing else) | unknown (Revolut could not
     * say) | closed (known unpaid or absent). decline is set on a closed charge that was declined.
     *
     * @param array<string,mixed> $attempt
     * @param bool                $refused Revolut refused the payment request outright, so an order
     *                                     without a payment needs no grace period.
     * @return array{state:string, remote:array<string,mixed>, decline:array{category:string,code:string}|null}
     */
    public static function resolve(int $orderId, array $attempt, bool $refused = false): array
    {
        $result = ['state' => 'closed', 'remote' => [], 'decline' => null];
        $mode = (string) $attempt['mode'];
        if ($mode === 'test' && Settings::mode() === 'live') {
            // No money is at stake, and a sandbox payment never completes an order on a live store.
            Attempts::close($orderId, $attempt);
            return $result;
        }
        $api = Settings::api($mode);
        $charge = $attempt['flow'] === Attempts::FLOW_CHARGE;

        if ((string) $attempt['id'] === '') {
            $remote = self::findByReference($api, (string) $attempt['ref']);
            if ($remote === null) {
                Attempts::close($orderId, $attempt);
                return $result;
            }
            if (is_wp_error($remote)) {
                return ['state' => 'unknown'] + $result;
            }
            $attempt = Attempts::update($orderId, $attempt, ['id' => (string) $remote['id']]);
        } else {
            $remote = self::read($api, (string) $attempt['id']);
            if (is_wp_error($remote)) {
                if (Api::status($remote) === 404 && !($charge && (int) $attempt['sent'] > 0)) {
                    Attempts::close($orderId, $attempt);
                    return $result;
                }
                // A charge that was sent and cannot be found (another account's key) is not "unpaid".
                return ['state' => 'unknown'] + $result;
            }
        }
        $result['remote'] = $remote;

        $class = self::classify($remote);
        if ($class === self::PAID) {
            return ['state' => 'paid'] + $result;
        }
        if ($class === self::DEAD) {
            Attempts::close($orderId, $attempt);
            if ($charge) {
                $result['decline'] = Declines::fromOrder($remote);
            }
            return $result;
        }
        if ($class === self::PROCESSING || ($charge && $class === self::ACTIVE)) {
            return ['state' => 'waiting'] + $result;
        }
        if ($charge && $class === self::OPEN && !$refused && (int) $attempt['sent'] > time() - self::GRACE) {
            // The payment request may still land.
            return ['state' => 'waiting'] + $result;
        }

        // Left open: cancel it, so it is known unpaid.
        $cancelled = self::cancel($api, (string) $attempt['id']);
        if ($cancelled === null) {
            return ['state' => 'waiting'] + $result;
        }
        $class2 = self::classify($cancelled);
        if ($class2 === self::PAID) {
            return ['state' => 'paid', 'remote' => $cancelled] + $result;
        }
        if ($class2 !== self::DEAD) {
            return ['state' => 'waiting', 'remote' => $cancelled] + $result;
        }
        Attempts::close($orderId, $attempt);
        if ($charge && $class !== self::OPEN) {
            // The payment list before cancelling holds the reason.
            $result['decline'] = Declines::fromOrder($remote);
        }
        return $result;
    }

    /**
     * Cancel an order and return it as Revolut now has it.
     *
     * @return array<string,mixed>|null null when the outcome is unknown.
     */
    private static function cancel(Api $api, string $id): ?array
    {
        if ($api->mode() === 'live' && !Settings::liveStore()) {
            // A copy of the live store leaves the live store's payments alone.
            return null;
        }
        $answer = $api->request('POST', 'orders/' . rawurlencode($id) . '/cancel');
        if (is_array($answer) && (string) ($answer['id'] ?? '') === $id && isset($answer['state'])) {
            return $answer;
        }
        $remote = self::read($api, $id);
        return is_wp_error($remote) ? null : $remote;
    }
}
