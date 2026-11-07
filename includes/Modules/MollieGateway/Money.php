<?php

namespace Stackless\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * Mollie amounts are {"currency": "EUR", "value": "10.00"}: a string with exactly the
 * currency's decimals. WooCommerce amounts are decimal strings, so conversions go through here.
 */
final class Money
{
    /** Currencies Mollie accepts for payments (docs: multicurrency). Test mode takes EUR only. */
    public const CURRENCIES = ['AED', 'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'ISK', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TWD', 'UAH', 'USD', 'ZAR'];

    /** Currencies with no minor unit at Mollie. */
    public const ZERO_DECIMAL = ['ISK', 'JPY'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount */
    public static function value($amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        return number_format(round((float) $amount, $decimals), $decimals, '.', '');
    }

    /** @param float|int|string $amount @return array{currency:string, value:string} */
    public static function amount($amount, string $currency): array
    {
        return ['currency' => strtoupper($currency), 'value' => self::value($amount, $currency)];
    }

    /** @param float|int|string $amount */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::decimals($currency)));
    }

    /** A Mollie amount object (or null) as a float in its currency. */
    public static function fromAmount($amount): float
    {
        return is_array($amount) ? (float) ($amount['value'] ?? 0) : 0.0;
    }

    /** Smallest chargeable amount: one minor unit (0.01, or 1 for JPY and ISK). */
    public static function smallest(string $currency): string
    {
        return self::value(1 / (10 ** self::decimals($currency)), $currency);
    }

    /** Whether a Mollie amount object equals a WooCommerce amount in a currency. */
    public static function matches($mollieAmount, $amount, string $currency): bool
    {
        if (!is_array($mollieAmount) || strtoupper((string) ($mollieAmount['currency'] ?? '')) !== strtoupper($currency)) {
            return false;
        }
        return self::toMinor((float) ($mollieAmount['value'] ?? 0), $currency) === self::toMinor($amount, $currency);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_mollie_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }
}
