<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Idempotency keys sent to Stripe. Stripe caches the first response per key for 24 hours,
 * so a double-fired renewal action or a double-clicked Place order never charges twice.
 */
final class IdempotencyKey
{
    private const MAX_LENGTH = 255;

    /**
     * Renewal charge key. Attempt 0 is the contract key "stackless-renewal-{order_id}"; a dunning
     * retry of the same renewal order after a definitive failure gets its own suffix, because
     * Stripe would otherwise replay the cached decline for 24 hours.
     */
    public static function renewal(int $order_id, int $attempt = 0): string
    {
        $key = 'stackless-renewal-' . $order_id;
        if ($attempt > 0) {
            $key .= '-retry-' . $attempt;
        }
        return $key;
    }

    /** One key per order + payment source, so a resubmitted form with the same source is collapsed. */
    public static function order(int $order_id, string $source): string
    {
        return self::limit('stackless-order-' . $order_id . '-' . self::digest($source));
    }

    public static function setup(string $scope, string $source): string
    {
        return self::limit('stackless-setup-' . preg_replace('/[^A-Za-z0-9_-]/', '', $scope) . '-' . self::digest($source));
    }

    public static function refund(int $order_id, int $refund_id, int $amount_minor): string
    {
        return 'stackless-refund-' . $order_id . '-' . $refund_id . '-' . $amount_minor;
    }

    public static function customer(string $scope): string
    {
        return self::limit('stackless-customer-' . self::digest($scope));
    }

    private static function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 24);
    }

    private static function limit(string $key): string
    {
        return strlen($key) > self::MAX_LENGTH ? substr($key, 0, self::MAX_LENGTH) : $key;
    }
}
