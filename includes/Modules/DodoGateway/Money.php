<?php

namespace Stackless\Modules\DodoGateway;

defined('ABSPATH') || exit;

/**
 * Dodo amounts are integers in the currency's smallest unit (cents; whole yen).
 * WooCommerce amounts are decimal strings, so every conversion goes through here.
 */
final class Money
{
    /**
     * Currencies Dodo prices products in (its Currency enum). Three-decimal currencies
     * (BHD, IQD, JOD, KWD, LYD, OMR, TND) are left out: WooCommerce stores them with
     * whatever decimals the store sets, which would not line up with Dodo's units.
     */
    public const CURRENCIES = ['AED', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SLL', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SZL', 'THB', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW'];

    /** Currencies whose smallest unit is the whole unit. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** @param float|int|string $amount Decimal amount → Dodo integer. */
    public static function toMinor($amount, string $currency): int
    {
        return (int) round((float) $amount * (10 ** self::decimals($currency)));
    }

    /** Dodo integer → decimal float. */
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
        $list = function_exists('apply_filters') ? apply_filters('stackless_dodo_gateway_currencies', self::CURRENCIES) : self::CURRENCIES;
        return array_values(array_unique(array_map('strtoupper', (array) $list)));
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /**
     * Whether a Dodo payment carries the price we set. Discount codes are off, so the
     * buyer pays our price; Dodo's total includes any tax, either added on top of the
     * price (tax exclusive) or contained in it (tax inclusive).
     */
    public static function paidMatches(array $payment, int $expectedMinor): bool
    {
        $total = (int) ($payment['total_amount'] ?? -1);
        $tax = (int) ($payment['tax'] ?? 0);
        return in_array($expectedMinor, [$total, $total - $tax], true);
    }

    /**
     * A Dodo refund amount (what the buyer got back, tax included) in the order's own
     * terms: scaled down when Dodo added tax on top of the price.
     *
     * @param int $orderMinor The order total in minor units.
     * @param int $paidMinor  What Dodo charged in total (0 when unknown).
     */
    public static function orderRefund(int $dodoAmount, int $orderMinor, int $paidMinor): int
    {
        if ($paidMinor <= 0 || $paidMinor === $orderMinor) {
            return $dodoAmount;
        }
        return (int) round($dodoAmount * $orderMinor / $paidMinor);
    }
}
