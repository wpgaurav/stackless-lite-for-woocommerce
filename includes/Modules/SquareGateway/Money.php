<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Square amounts are integers in the currency's smallest unit (cents; whole yen). A payment's
 * currency must be the currency of the Square location (the seller's country), so a store
 * takes only that one currency through Square.
 */
final class Money
{
    /** Currencies of the countries Square processes online payments in. */
    public const CURRENCIES = ['USD', 'CAD', 'GBP', 'AUD', 'JPY', 'EUR'];

    public const ZERO_DECIMAL = ['JPY'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::decimals($currency)));
    }

    public static function fromMinor(int $amount, string $currency): float
    {
        $decimals = self::decimals($currency);
        return round($amount / (10 ** $decimals), $decimals);
    }

    /** @param float|int|string $amount @return array{amount:int, currency:string} */
    public static function money($amount, string $currency): array
    {
        return ['amount' => self::toMinor($amount, $currency), 'currency' => strtoupper($currency)];
    }

    /** Decimal string for the Web Payments SDK (verificationDetails.amount, payment requests). */
    public static function display($amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        return number_format(round((float) $amount, $decimals), $decimals, '.', '');
    }

    /** Whether a Square money object equals a WooCommerce amount in a currency. */
    public static function matches($money, $amount, string $currency): bool
    {
        return is_array($money)
            && strtoupper((string) ($money['currency'] ?? '')) === strtoupper($currency)
            && (int) ($money['amount'] ?? -1) === self::toMinor($amount, $currency);
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::CURRENCIES, true);
    }
}
