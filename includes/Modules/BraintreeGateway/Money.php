<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Braintree amounts are decimal strings in the merchant account's currency: whole numbers for
 * zero-decimal currencies, two decimals for the rest. The store's decimals can differ, so an
 * amount that cannot be written exactly in Braintree's units is refused, never rounded.
 */
final class Money
{
    /** Braintree's currency reference. An account takes only those it has merchant accounts for. */
    public const CURRENCIES = [
        'AED', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP',
        'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD',
        'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW',
        'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LTL', 'MAD', 'MDL', 'MKD', 'MNT', 'MOP', 'MUR', 'MVR',
        'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
        'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS',
        'SRD', 'STD', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD',
        'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMK', 'ZWD',
    ];

    /** Marked zero-decimal by Braintree. HUF, IDR and TWD have two decimals there. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'LAK', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * The amount in Braintree's minor units, or null when it cannot be written exactly in them.
     *
     * @param float|int|string|null $amount
     */
    public static function minor($amount, string $currency): ?int
    {
        if (!is_numeric($amount)) {
            return null;
        }
        $scaled = (float) $amount * (10 ** self::decimals($currency));
        $whole = round($scaled);
        return abs($scaled - $whole) < 0.001 ? (int) $whole : null;
    }

    /** An Amount for the API: "12.50", or "1250" for a zero-decimal currency. */
    public static function value(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);
        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    public static function toFloat(int $minor, string $currency): float
    {
        return $minor / (10 ** self::decimals($currency));
    }

    /**
     * Minor units of a MonetaryAmount, or null when it is missing or in another currency.
     *
     * @param mixed $amount {value, currencyCode}
     */
    public static function read($amount, string $currency): ?int
    {
        if (!is_array($amount) || strtoupper((string) ($amount['currencyCode'] ?? '')) !== strtoupper($currency)) {
            return null;
        }
        return self::minor($amount['value'] ?? null, $currency);
    }

    /** A MonetaryAmount as text for notes, e.g. "12.50 EUR". @param mixed $amount */
    public static function describe($amount): string
    {
        if (!is_array($amount)) {
            return '';
        }
        return trim(preg_replace('/[^0-9.]/', '', (string) ($amount['value'] ?? '')) . ' ' . preg_replace('/[^A-Za-z]/', '', (string) ($amount['currencyCode'] ?? '')));
    }
}
