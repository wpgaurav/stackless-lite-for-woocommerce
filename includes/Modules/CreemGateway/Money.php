<?php

namespace Stackless\Modules\CreemGateway;

defined('ABSPATH') || exit;

/**
 * Creem prices products in US dollars and euros, as integers in cents. WooCommerce amounts
 * are decimal strings, so every conversion goes through here.
 */
final class Money
{
    public const CURRENCIES = ['USD', 'EUR'];

    /** Creem's smallest price: 1.00 (a product price or a one-time custom price below 100 cents is refused). */
    public const MINIMUM = 100;

    /** @param float|int|string $amount Decimal amount → cents. */
    public static function toMinor($amount, string $currency = ''): int
    {
        return (int) round((float) $amount * 100);
    }

    /** Cents → decimal float. */
    public static function fromMinor(int $amount, string $currency = ''): float
    {
        return round($amount / 100, 2);
    }

    /** @param float|int|string $a @param float|int|string $b */
    public static function equals($a, $b, string $currency = ''): bool
    {
        return self::toMinor($a) === self::toMinor($b);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_creem_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_intersect(array_map('strtoupper', (array) $list), self::CURRENCIES));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /**
     * Whether a Creem order or transaction paid the price we set. Creem's own figures before
     * a discount ("amount", "sub_total") say nothing about what was paid, so a discount is a
     * mismatch, and the match is on "amount_paid": the price itself when tax is included, or
     * the price plus "tax_amount" when tax is added on top. Only an object without
     * "amount_paid" is matched on "amount".
     */
    public static function paidMatches(array $paid, int $expectedMinor): bool
    {
        if (isset($paid['discount_amount']) && (int) $paid['discount_amount'] > 0) {
            return false;
        }
        if (!isset($paid['amount_paid']) || !is_numeric($paid['amount_paid'])) {
            return isset($paid['amount']) && is_numeric($paid['amount']) && (int) $paid['amount'] === $expectedMinor;
        }
        $total = (int) $paid['amount_paid'];
        $tax = isset($paid['tax_amount']) && is_numeric($paid['tax_amount']) ? (int) $paid['tax_amount'] : 0;
        return in_array($expectedMinor, [$total, $total - $tax], true);
    }

    /** What the buyer paid in total, tax included (0 when Creem did not say). */
    public static function paidTotal(array $paid): int
    {
        foreach (['amount_paid', 'amount'] as $key) {
            if (isset($paid[$key]) && is_numeric($paid[$key])) {
                return (int) $paid[$key];
            }
        }
        return 0;
    }

    /**
     * A Creem refund (what the buyer got back, tax included) in the order's own terms:
     * scaled down when Creem added tax on top of the price.
     */
    public static function orderRefund(int $refunded, int $orderMinor, int $paidMinor): int
    {
        if ($paidMinor <= 0 || $paidMinor === $orderMinor) {
            return $refunded;
        }
        return (int) round($refunded * $orderMinor / $paidMinor);
    }
}
