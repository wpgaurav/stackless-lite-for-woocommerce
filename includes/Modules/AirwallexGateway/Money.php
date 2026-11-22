<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * Airwallex takes major units with at most the currency's own decimals. Its table differs from
 * ISO 4217 and from store settings (SLE has none, IDR has two), so conversions go through here.
 */
final class Money
{
    /** Currencies Airwallex collects (docs: payments/reference/supported-currencies). */
    private const CURRENCIES = 'AED ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BZD CAD CHF CLP CNY COP CRC CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HUF IDR ILS INR ISK JMD JOD JPY KES KGS KHR KMF KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MNT MOP MUR MVR MWK MXN MYR NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RWF SAR SBD SCR SEK SGD SHP SLE SRD STD SVC SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VND VUV WST XAF XCD XOF XPF ZAR ZMW ZWL';

    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'SLE', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        return in_array($currency, self::THREE_DECIMAL, true) ? 3 : 2;
    }

    /** @return string[] */
    public static function currencies(): array
    {
        return explode(' ', self::CURRENCIES);
    }

    public static function supported(string $currency): bool
    {
        return in_array(strtoupper($currency), self::currencies(), true);
    }

    /** @param float|int|string $amount */
    public static function toMinor($amount, string $currency): int
    {
        // The inner round drops the float's noise (10.075 * 100 is 1007.4999999999999) on every PHP version.
        return (int) round(round((float) $amount * (10 ** self::decimals($currency)), 6));
    }

    /** @param float|int|string $amount A decimal string rounded to the currency's minor unit. */
    public static function value($amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        return number_format(self::toMinor($amount, $currency) / (10 ** $decimals), $decimals, '.', '');
    }

    /**
     * Whether an amount read from Airwallex equals a store amount in the same currency.
     *
     * @param mixed $amount
     * @param mixed $currency
     * @param float|int|string $expected
     */
    public static function matches($amount, $currency, $expected, string $expectedCurrency): bool
    {
        if (!is_numeric($amount) || !is_string($currency) || strtoupper($currency) !== strtoupper($expectedCurrency)) {
            return false;
        }
        return self::toMinor($amount, $expectedCurrency) === self::toMinor($expected, $expectedCurrency);
    }
}
