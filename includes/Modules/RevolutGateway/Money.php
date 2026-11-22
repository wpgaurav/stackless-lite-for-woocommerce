<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Revolut amounts are integers in Revolut's minor units, which are not the store's decimals:
 * HUF has two here (stores often show none), JPY has none.
 *
 * Card currencies come from Revolut's own WooCommerce plugin, less BGN (Bulgaria moved to the
 * euro). ISK is left out on purpose: Revolut's API spec and that plugin disagree on its minor
 * unit, and the wrong reading charges a hundred times too much.
 */
final class Money
{
    public const CURRENCIES = ['AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'NOK', 'NZD', 'PLN', 'RON', 'SEK', 'SGD', 'TRY', 'USD', 'ZAR'];

    public const ZERO_DECIMAL = ['JPY'];

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::decimals($currency)));
    }

    public static function fromMinor(int $minor, string $currency): float
    {
        return round($minor / (10 ** self::decimals($currency)), self::decimals($currency));
    }

    /** "12.00 EUR", for notes and admin messages. */
    public static function display(int $minor, string $currency): string
    {
        return number_format(self::fromMinor($minor, $currency), self::decimals($currency), '.', '') . ' ' . strtoupper($currency);
    }

    /**
     * Whether a Revolut order object is for exactly this amount and currency.
     *
     * @param array<string,mixed> $remote
     */
    public static function matches(array $remote, int $minor, string $currency): bool
    {
        if (!isset($remote['amount'], $remote['currency']) || !is_int($remote['amount']) || !is_string($remote['currency'])) {
            return false;
        }
        return $remote['amount'] === $minor && strtoupper($remote['currency']) === strtoupper($currency);
    }
}
