<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * Flutterwave v3 takes and reports amounts in major units (7500 NGN is "7500"). Amounts are
 * compared in hundredths so a float never decides whether an order is paid.
 */
final class Money
{
    /** Currencies Flutterwave's hosted checkout charges in (which ones work depends on the account's country). */
    public const CURRENCIES = ['NGN', 'USD', 'EUR', 'GBP', 'GHS', 'KES', 'UGX', 'TZS', 'RWF', 'ZAR', 'XAF', 'XOF', 'MWK', 'ZMW', 'EGP'];

    /** Currencies without a minor unit: sent as whole numbers. */
    public const ZERO_DECIMAL = ['UGX', 'RWF', 'XAF', 'XOF'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** The amount Flutterwave is asked to charge, as a decimal string. @param float|int|string $amount */
    public static function format($amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        return number_format(round((float) $amount, $decimals), $decimals, '.', '');
    }

    /** @param float|int|string $amount Decimal → hundredths, for comparisons. */
    public static function toMinor($amount, string $currency = ''): int
    {
        $decimals = $currency !== '' ? self::decimals($currency) : 2;
        return (int) round(round((float) $amount, $decimals) * 100);
    }

    /** @param float|int|string $a @param float|int|string $b */
    public static function equals($a, $b, string $currency): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_flutterwave_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /** Whether a verified transaction is for exactly the order's amount, in its currency. @param float|int|string $expected */
    public static function paidMatches(array $transaction, $expected, string $currency): bool
    {
        if (strtoupper((string) ($transaction['currency'] ?? '')) !== strtoupper($currency) || !isset($transaction['amount'])) {
            return false;
        }
        return self::equals($transaction['amount'], $expected, $currency);
    }
}
