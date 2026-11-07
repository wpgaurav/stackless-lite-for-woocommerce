<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * Paystack amounts are integers in the currency's subunit (kobo, pesewas, cents): every
 * currency Paystack takes counts in hundredths. WooCommerce amounts are decimal strings,
 * so every conversion goes through here.
 */
final class Money
{
    /**
     * Currencies Paystack documents. Which ones an account can use depends on where the
     * business is registered (Nigeria: NGN and USD; Ghana: GHS; South Africa: ZAR; Kenya:
     * KES and USD; Côte d'Ivoire: XOF); Paystack refuses the rest when the transaction
     * starts. Egypt (EGP) and Rwanda (RWF) accounts can add theirs with the
     * stackless_paystack_gateway_currencies filter.
     */
    public const CURRENCIES = ['NGN', 'GHS', 'ZAR', 'KES', 'USD', 'XOF'];

    /** Smallest charge Paystack accepts per currency, in decimal units. */
    public const MINIMUMS = ['NGN' => 50.0, 'USD' => 2.0, 'GHS' => 0.10, 'ZAR' => 1.0, 'KES' => 3.0, 'XOF' => 1.0];

    /** Paystack counts every currency in hundredths, XOF (which has no subunit) included. */
    public const FACTOR = 100;

    /** The smallest charge for a currency, 0 when Paystack documents none. */
    public static function minimum(string $currency): float
    {
        return (float) (self::MINIMUMS[strtoupper($currency)] ?? 0);
    }

    /** @param float|int|string $amount Decimal amount → Paystack subunits. */
    public static function toMinor($amount, string $currency = ''): int
    {
        return (int) round((float) $amount * self::FACTOR);
    }

    /** Paystack subunits → decimal float. */
    public static function fromMinor(int $amount, string $currency = ''): float
    {
        return round($amount / self::FACTOR, 2);
    }

    /** @param float|int|string $a @param float|int|string $b */
    public static function equals($a, $b, string $currency = ''): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }

    /** @return string[] */
    public static function currencies(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('stackless_paystack_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /**
     * Whether a verified Paystack transaction paid exactly what the order asked for: same
     * currency, and the amount requested (before any fee the customer was asked to bear).
     */
    public static function paidMatches(array $transaction, int $expectedMinor, string $currency): bool
    {
        if (strtoupper((string) ($transaction['currency'] ?? '')) !== strtoupper($currency)) {
            return false;
        }
        $requested = isset($transaction['requested_amount']) ? (int) $transaction['requested_amount'] : (int) ($transaction['amount'] ?? -1);
        $amount = (int) ($transaction['amount'] ?? -1);
        return $requested === $expectedMinor && $amount >= $expectedMinor;
    }
}
