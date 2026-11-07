<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * Mercado Pago charges in its account's local currency: each seller account belongs to one
 * country site (MLA Argentina, MLB Brazil, …) and takes that site's currency. Amounts are
 * decimal numbers; Chilean and Colombian pesos are whole numbers.
 */
final class Money
{
    /** Country site id → currency. */
    public const SITES = [
        'MLA' => 'ARS',
        'MLB' => 'BRL',
        'MLC' => 'CLP',
        'MCO' => 'COP',
        'MLM' => 'MXN',
        'MPE' => 'PEN',
        'MLU' => 'UYU',
    ];

    public const ZERO_DECIMAL = ['CLP', 'COP'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount Decimal amount → the number Mercado Pago is sent. */
    public static function amount($amount, string $currency): float
    {
        return round((float) $amount, self::decimals($currency));
    }

    /** @param float|int|string $amount Decimal → hundredths (after currency rounding), for comparisons. */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round(self::amount($amount, $currency) * 100);
    }

    /** @param float|int|string $a @param float|int|string $b */
    public static function equals($a, $b, string $currency): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }

    /**
     * Currencies the connected account takes: its site's currency once the account is known,
     * every Mercado Pago currency before that.
     *
     * @return string[]
     */
    public static function currencies(string $siteId = ''): array
    {
        $siteId = strtoupper($siteId);
        $list = isset(self::SITES[$siteId]) ? [self::SITES[$siteId]] : array_values(self::SITES);
        $list = function_exists('apply_filters') ? apply_filters('stackless_mercadopago_gateway_currencies', $list, $siteId) : $list;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency, string $siteId = ''): bool
    {
        return in_array(strtoupper($currency), self::currencies($siteId), true);
    }

    /** Whether a payment is for exactly the order's amount, in its currency. @param float|int|string $expected */
    public static function paidMatches(array $payment, $expected, string $currency): bool
    {
        if (strtoupper((string) ($payment['currency_id'] ?? '')) !== strtoupper($currency) || !isset($payment['transaction_amount'])) {
            return false;
        }
        return self::equals($payment['transaction_amount'], $expected, $currency);
    }
}
