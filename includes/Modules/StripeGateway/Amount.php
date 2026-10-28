<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Converts WooCommerce decimal amounts to and from Stripe's integer minor units.
 *
 * Stripe's rules (https://docs.stripe.com/currencies):
 * - Zero-decimal currencies (JPY, KRW, ...) are sent as whole units: ¥500 => 500.
 * - Three-decimal currencies (KWD, BHD, ...) are sent in thousandths, and the last digit
 *   must be 0: 12.345 KWD => 12350.
 * - ISK is zero-decimal in practice but still sent as a two-decimal value whose fraction
 *   is always 00: 5 ISK => 500.
 * - Everything else is two-decimal: $19.99 => 1999.
 *
 * Pure PHP so it can be unit tested without WordPress.
 */
final class Amount
{
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Sent as two-decimal values but only whole units can be charged. */
    public const WHOLE_UNITS_TWO_DECIMAL = ['ISK'];

    /** Minimum charge amounts in major units, from Stripe's published table. */
    public const MINIMUMS = [
        'USD' => '0.50', 'AED' => '2.00', 'AUD' => '0.50', 'BGN' => '1.00', 'BRL' => '0.50',
        'CAD' => '0.50', 'CHF' => '0.50', 'CZK' => '15.00', 'DKK' => '2.50', 'EUR' => '0.50',
        'GBP' => '0.30', 'HKD' => '4.00', 'HUF' => '175.00', 'INR' => '0.50', 'JPY' => '50',
        'MXN' => '10.00', 'MYR' => '2.00', 'NOK' => '3.00', 'NZD' => '0.50', 'PLN' => '2.00',
        'RON' => '2.00', 'SEK' => '3.00', 'SGD' => '0.50', 'THB' => '10.00',
    ];

    /** Presentment currencies Stripe accepts for card payments. */
    public const SUPPORTED = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BHD',
        'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP',
        'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP',
        'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JOD', 'JPY',
        'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL',
        'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK',
        'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF',
        'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TND', 'TOP',
        'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF',
        'XPF', 'YER', 'ZAR', 'ZMW',
    ];

    /** Number of decimal places Stripe uses for this currency's integer amount. */
    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($currency, self::THREE_DECIMAL, true)) {
            return 3;
        }
        return 2;
    }

    /**
     * Decimal amount (string, int or float) to Stripe minor units.
     *
     * @param string|int|float $amount
     */
    public static function toMinor($amount, string $currency): int
    {
        $currency = strtoupper($currency);
        $value = self::normalize($amount);
        if ($value < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative.');
        }

        if (in_array($currency, self::WHOLE_UNITS_TWO_DECIMAL, true)) {
            return (int) round($value, 0, PHP_ROUND_HALF_UP) * 100;
        }

        $decimals = self::decimals($currency);
        $minor = (int) round(round($value, $decimals, PHP_ROUND_HALF_UP) * (10 ** $decimals), 0, PHP_ROUND_HALF_UP);

        if ($decimals === 3) {
            // Stripe rejects three-decimal amounts whose last digit is not zero.
            $minor = (int) (round($minor / 10, 0, PHP_ROUND_HALF_UP) * 10);
        }
        return $minor;
    }

    /** Stripe minor units back to a decimal string in major units. */
    public static function fromMinor(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);
        if ($decimals === 0) {
            return (string) $minor;
        }
        $negative = $minor < 0;
        $digits = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$decimals);
        $fraction = substr($digits, -$decimals);
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    /** Minimum chargeable amount in major units, or null when Stripe publishes none for the currency. */
    public static function minimum(string $currency): ?string
    {
        $currency = strtoupper($currency);
        return self::MINIMUMS[$currency] ?? null;
    }

    /** @param string|int|float $amount */
    public static function meetsMinimum($amount, string $currency): bool
    {
        $minimum = self::minimum($currency);
        if ($minimum === null) {
            return self::toMinor($amount, $currency) > 0;
        }
        return self::toMinor($amount, $currency) >= self::toMinor($minimum, $currency);
    }

    public static function isSupported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED, true);
    }

    /**
     * Converts a Store API / blocks integer (WooCommerce minor units, $wc_decimals places)
     * into Stripe minor units. Mirrors the conversion done in the blocks script.
     */
    public static function fromWooMinor(int $value, int $wc_decimals, string $currency): int
    {
        $decimal = $wc_decimals > 0 ? $value / (10 ** $wc_decimals) : $value;
        return self::toMinor(number_format($decimal, max(0, $wc_decimals), '.', ''), $currency);
    }

    /** @param string|int|float $amount */
    private static function normalize($amount): float
    {
        if (is_string($amount)) {
            $amount = trim(str_replace(',', '', $amount));
            if ($amount === '') {
                return 0.0;
            }
            if (!is_numeric($amount)) {
                throw new \InvalidArgumentException('Amount is not numeric.');
            }
        }
        return (float) $amount;
    }
}
