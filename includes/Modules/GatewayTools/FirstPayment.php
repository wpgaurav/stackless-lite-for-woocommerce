<?php

namespace Stackless\Modules\GatewayTools;

defined('ABSPATH') || exit;

/**
 * First payments that differ from the renewal price, for hosted gateways that bill one
 * price per cycle (Polar, Dodo Payments).
 *
 * A coupon that only discounts a subscription's first payment is sent to the provider as a
 * one-cycle discount for the difference, so the buyer pays the order total and renewals
 * return to the full price. A first payment above the renewal price (a signup fee) cannot
 * be expressed that way.
 */
final class FirstPayment
{
    /**
     * What to take off the first charge, in minor units.
     *
     * @return int|null 0 when the first payment equals the renewal; null when a discount
     *                  cannot produce it (a higher first payment, or nothing due).
     */
    public static function discount(int $firstMinor, int $recurringMinor): ?int
    {
        if ($firstMinor === $recurringMinor) {
            return 0;
        }
        if ($firstMinor > 0 && $firstMinor < $recurringMinor) {
            return $recurringMinor - $firstMinor;
        }
        return null;
    }

    /** The same share of another price (the discount in another currency): at least 1, at most that price. */
    public static function scale(int $discountMinor, int $recurringMinor, int $otherMinor): int
    {
        if ($recurringMinor <= 0 || $otherMinor <= 0) {
            return 1;
        }
        return max(1, min($otherMinor, (int) round($otherMinor * $discountMinor / $recurringMinor)));
    }

    /** A short provider-side name that ties the discount to its WooCommerce order. */
    public static function label(int $orderId): string
    {
        /* translators: %d: WooCommerce order number */
        return sprintf(__('First payment, order #%d', 'stackless-lite'), $orderId);
    }
}
