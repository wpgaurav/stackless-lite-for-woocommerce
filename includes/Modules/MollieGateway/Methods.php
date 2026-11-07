<?php

namespace Stackless\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * Which Mollie methods can do what, and which ones the account has switched on.
 *
 * A first payment (sequenceType=first) creates a mandate for later recurring payments:
 * cards, Apple Pay and Google Pay give a card mandate, PayPal a PayPal mandate, and the
 * bank methods (iDEAL, Bancontact, EPS, ...) a SEPA Direct Debit mandate. Only cards and
 * PayPal accept a 0.00 first payment; the others need a one-cent verification payment,
 * which this gateway refunds once the mandate exists.
 */
final class Methods
{
    /** Methods that can create a mandate, and the mandate method they produce. */
    public const MANDATE_OF = [
        'creditcard' => 'creditcard',
        'applepay' => 'creditcard',
        'googlepay' => 'creditcard',
        'paypal' => 'paypal',
        'ideal' => 'directdebit',
        'bancontact' => 'directdebit',
        'belfius' => 'directdebit',
        'eps' => 'directdebit',
        'kbc' => 'directdebit',
        'mybank' => 'directdebit',
        'paybybank' => 'directdebit',
        'directdebit' => 'directdebit',
    ];

    /** First payments that may be 0.00 (Mollie docs: credit card and PayPal). */
    public const ZERO_AMOUNT_FIRST = ['creditcard', 'paypal'];

    /** Methods that only take euros. */
    public const EUR_ONLY = ['ideal', 'bancontact', 'belfius', 'eps', 'kbc', 'mybank', 'directdebit', 'paybybank'];

    /** Mandates that settle days after the charge (renewals stay pending until the webhook). */
    public const SLOW_MANDATES = ['directdebit'];

    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** Labels for the method picker and saved methods. */
    public static function labels(): array
    {
        return [
            'creditcard' => __('Card', 'stackless-lite'),
            'applepay' => __('Apple Pay', 'stackless-lite'),
            'googlepay' => __('Google Pay', 'stackless-lite'),
            'paypal' => __('PayPal', 'stackless-lite'),
            'ideal' => __('iDEAL', 'stackless-lite'),
            'bancontact' => __('Bancontact', 'stackless-lite'),
            'belfius' => __('Belfius', 'stackless-lite'),
            'eps' => __('EPS', 'stackless-lite'),
            'kbc' => __('KBC/CBC', 'stackless-lite'),
            'mybank' => __('MyBank', 'stackless-lite'),
            'paybybank' => __('Pay by Bank', 'stackless-lite'),
            'directdebit' => __('SEPA Direct Debit', 'stackless-lite'),
            'banktransfer' => __('Bank transfer', 'stackless-lite'),
            'klarna' => __('Klarna', 'stackless-lite'),
            'przelewy24' => __('Przelewy24', 'stackless-lite'),
            'blik' => __('BLIK', 'stackless-lite'),
            'twint' => __('TWINT', 'stackless-lite'),
            'trustly' => __('Trustly', 'stackless-lite'),
            'swish' => __('Swish', 'stackless-lite'),
            'vipps' => __('Vipps', 'stackless-lite'),
            'mobilepay' => __('MobilePay', 'stackless-lite'),
            'satispay' => __('Satispay', 'stackless-lite'),
            'riverty' => __('Riverty', 'stackless-lite'),
            'in3' => __('iDEAL in3', 'stackless-lite'),
            'billie' => __('Billie', 'stackless-lite'),
            'paysafecard' => __('paysafecard', 'stackless-lite'),
        ];
    }

    public static function label(string $method): string
    {
        $labels = self::labels();
        return $labels[$method] ?? ucwords(str_replace('_', ' ', $method));
    }

    public static function canCreateMandate(string $method): bool
    {
        return isset(self::MANDATE_OF[$method]);
    }

    /** Amount of a first payment that only saves the method: 0.00 or one minor unit. */
    public static function verificationAmount(string $method, string $currency): string
    {
        return in_array($method, self::ZERO_AMOUNT_FIRST, true) ? Money::value(0, $currency) : Money::smallest($currency);
    }

    /** Whether a mandate method can be charged in a currency. */
    public static function mandateTakes(string $mandateMethod, string $currency): bool
    {
        return !in_array($mandateMethod, self::EUR_ONLY, true) || strtoupper($currency) === 'EUR';
    }

    /**
     * Methods the account offers for an amount, cached per mode, currency and amount.
     * Order of the list is Mollie's.
     *
     * @return array<string,string> id => description, empty when Mollie could not be asked.
     */
    public static function enabled(Api $api, string $currency, string $amount, bool $first): array
    {
        $currency = strtoupper($currency);
        $value = Money::value(max((float) $amount, (float) Money::smallest($currency)), $currency);
        $key = 'stackless_mollie_gateway_methods_' . md5($api->mode() . '|' . $currency . '|' . $value . '|' . ($first ? 'first' : 'oneoff'));
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $query = ['amount' => ['value' => $value, 'currency' => $currency], 'includeWallets' => 'applepay'];
        if ($first) {
            $query['sequenceType'] = 'first';
        }
        $response = $api->request('GET', 'methods', $query);
        if (is_wp_error($response)) {
            return [];
        }
        $methods = [];
        foreach ((array) ($response['_embedded']['methods'] ?? []) as $method) {
            if (is_array($method) && !empty($method['id']) && ($method['status'] ?? 'activated') === 'activated') {
                $methods[(string) $method['id']] = (string) ($method['description'] ?? self::label((string) $method['id']));
            }
        }
        set_transient($key, $methods, self::CACHE_TTL);
        return $methods;
    }

    /**
     * The method list to send with a payment: the store's chosen methods (all when none are
     * chosen), narrowed to what Mollie offers for this payment when Mollie answered.
     *
     * @param string[] $configured
     * @param array<string,string> $available
     * @return string[]
     */
    public static function pick(array $configured, array $available, bool $first): array
    {
        $ids = $available ? array_keys($available) : $configured;
        if ($configured) {
            $ids = $available ? array_values(array_intersect($ids, $configured)) : $configured;
        }
        if ($first) {
            $ids = array_values(array_filter($ids, [self::class, 'canCreateMandate']));
        }
        return array_values(array_unique(array_map('strval', $ids)));
    }
}
