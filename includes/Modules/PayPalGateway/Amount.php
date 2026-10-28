<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Money helpers for PayPal's REST APIs.
 *
 * PayPal takes amounts as decimal strings with a currency-specific number of
 * decimals (HUF, JPY and TWD take none). Every comparison between a WooCommerce
 * total and a PayPal amount goes through format() so rounding never decides
 * whether an order is marked paid.
 */
final class Amount
{
    /** Currencies PayPal REST accepts for checkout (developer.paypal.com/reference/currency-codes). */
    public const CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR',
        'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /** Currencies PayPal only accepts as whole numbers. */
    public const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * Decimal string PayPal accepts, e.g. "10.00", "1500" for JPY.
     *
     * @param float|int|string $amount
     */
    public static function format($amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        $value = round((float) $amount, $decimals);
        if (abs($value) < 1e-9) {
            $value = 0.0; // never send "-0.00"
        }
        return number_format($value, $decimals, '.', '');
    }

    /** PayPal money object. @param float|int|string $amount */
    public static function money($amount, string $currency): array
    {
        return [
            'currency_code' => strtoupper($currency),
            'value' => self::format($amount, $currency),
        ];
    }

    /**
     * Whether two amounts are the same once formatted for the currency.
     *
     * @param float|int|string $a
     * @param float|int|string $b
     */
    public static function equals($a, $b, string $currency): bool
    {
        return self::format($a, $currency) === self::format($b, $currency);
    }

    /** Whether a PayPal money object carries exactly this amount and currency. @param float|int|string $expected */
    public static function matches(array $money, $expected, string $currency): bool
    {
        if (strtoupper((string) ($money['currency_code'] ?? '')) !== strtoupper($currency)) {
            return false;
        }
        return isset($money['value']) && self::equals((string) $money['value'], $expected, $currency);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_paypal_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /**
     * An amount breakdown that PayPal will accept, or null when the parts don't
     * add up to the total exactly (PayPal rejects mismatched breakdowns, so the
     * order is sent with the bare total instead).
     *
     * @param array{item_total?:float|int|string,shipping?:float|int|string,tax_total?:float|int|string,handling?:float|int|string,discount?:float|int|string} $parts
     * @param float|int|string $total
     */
    public static function breakdown(array $parts, $total, string $currency): ?array
    {
        $decimals = self::decimals($currency);
        $factor = 10 ** $decimals;
        $keys = ['item_total', 'shipping', 'tax_total', 'handling', 'discount'];
        $minor = [];
        foreach ($keys as $key) {
            $value = isset($parts[$key]) ? (float) $parts[$key] : 0.0;
            if ($value < 0) {
                return null;
            }
            $minor[$key] = (int) round($value * $factor);
        }

        $sum = $minor['item_total'] + $minor['shipping'] + $minor['tax_total'] + $minor['handling'] - $minor['discount'];
        if ($sum !== (int) round((float) $total * $factor) || $minor['item_total'] <= 0) {
            return null;
        }

        $breakdown = [];
        foreach ($keys as $key) {
            if ($key === 'item_total' || $minor[$key] > 0) {
                $breakdown[$key] = self::money($minor[$key] / $factor, $currency);
            }
        }
        return $breakdown;
    }

    /** Trim a string to PayPal's character limits without breaking multibyte text. */
    public static function truncate(string $text, int $length): string
    {
        $text = trim(wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
        if (function_exists('mb_substr')) {
            return mb_strlen($text) > $length ? mb_substr($text, 0, $length) : $text;
        }
        return strlen($text) > $length ? substr($text, 0, $length) : $text;
    }
}
