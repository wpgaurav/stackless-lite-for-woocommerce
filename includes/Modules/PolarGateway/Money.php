<?php

namespace Stackless\Modules\PolarGateway;

defined('ABSPATH') || exit;

/**
 * Polar amounts are integers in the currency's smallest unit (cents; whole yen).
 * WooCommerce amounts are decimal strings, so every conversion goes through here.
 */
final class Money
{
    /** Presentment currencies Polar accepts (polar.sh/docs/features/products). */
    public const CURRENCIES = ['AED', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SOS', 'SRD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'];

    /** Currencies whose smallest unit is the whole unit. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount Decimal amount → Polar integer. */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::decimals($currency)));
    }

    /** Polar integer → decimal float. */
    public static function fromMinor(int $amount, string $currency): float
    {
        $decimals = self::decimals($currency);
        return round($amount / (10 ** $decimals), $decimals);
    }

    /** @param float|int|string $a @param float|int|string $b */
    public static function equals($a, $b, string $currency): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_polar_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /**
     * What Polar actually charged for the checkout price we set. Discount codes
     * are off, so the buyer pays our price; depending on the tax behavior Polar
     * reports it as the net amount (tax added on top) or the total (tax included).
     *
     * @return bool True when the Polar order carries the expected price.
     */
    public static function paidMatches(array $polarOrder, int $expectedMinor): bool
    {
        $candidates = [
            (int) ($polarOrder['net_amount'] ?? -1),
            (int) ($polarOrder['total_amount'] ?? -1),
            (int) ($polarOrder['subtotal_amount'] ?? -1) - (int) ($polarOrder['discount_amount'] ?? 0),
        ];
        return in_array($expectedMinor, $candidates, true);
    }
}
