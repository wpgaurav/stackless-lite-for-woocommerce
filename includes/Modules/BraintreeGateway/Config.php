<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * What the payment engine needs from the gateway settings. Secrets can be pinned in wp-config.php:
 * STACKLESS_BRAINTREE_{LIVE|SANDBOX}_{MERCHANT_ID|PUBLIC_KEY|PRIVATE_KEY|MERCHANT_ACCOUNTS}.
 */
final class Config
{
    public const MODES = ['live', 'test'];
    public const FIELDS = ['merchant_id', 'public_key', 'private_key', 'merchant_accounts'];

    public static function mode(): string
    {
        return Gateway::setting('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    public static function normalMode(string $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : self::mode();
    }

    public static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_BRAINTREE_' . ($mode === 'live' ? 'LIVE' : 'SANDBOX') . '_' . strtoupper($field);
    }

    public static function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return defined($constant) && (string) constant($constant) !== '';
    }

    public static function value(string $mode, string $field): string
    {
        $mode = self::normalMode($mode);
        if (self::isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
        }
        return trim((string) Gateway::setting($mode . '_' . $field, ''));
    }

    public static function merchantId(string $mode): string
    {
        return self::value($mode, 'merchant_id');
    }

    public static function publicKey(string $mode): string
    {
        return self::value($mode, 'public_key');
    }

    public static function privateKey(string $mode): string
    {
        return self::value($mode, 'private_key');
    }

    public static function hasKeys(string $mode): bool
    {
        return self::merchantId($mode) !== '' && self::publicKey($mode) !== '' && self::privateKey($mode) !== '';
    }

    public static function api(string $mode): Api
    {
        $mode = self::normalMode($mode);
        return new Api($mode, self::merchantId($mode), self::publicKey($mode), self::privateKey($mode));
    }

    /**
     * Which merchant account takes which currency, when the owner chose: "EUR:store_eur, GBP:store_gbp".
     *
     * @return array<string,string> currency => merchant account id
     */
    public static function merchantAccounts(string $mode): array
    {
        $map = [];
        foreach (preg_split('/[\s,;]+/', self::value($mode, 'merchant_accounts')) ?: [] as $pair) {
            if (preg_match('/^([A-Za-z]{3})[:=]([A-Za-z0-9_-]{1,64})$/', $pair, $match)) {
                $map[strtoupper($match[1])] = $match[2];
            }
        }
        return $map;
    }

    /** required|strict|off. Anything unknown requires it. */
    public static function threeDSecure(): string
    {
        $policy = (string) Gateway::setting('three_d_secure', ThreeDSecure::REQUIRED);
        return in_array($policy, [ThreeDSecure::STRICT, ThreeDSecure::OFF], true) ? $policy : ThreeDSecure::REQUIRED;
    }

    public static function savedCards(): bool
    {
        return Gateway::setting('saved_cards', 'yes') === 'yes';
    }

    /** Send device data for Braintree's fraud tools. */
    public static function deviceData(): bool
    {
        return Gateway::setting('device_data', 'no') === 'yes';
    }

    public static function webhookUrl(): string
    {
        $route = Webhook::REST_NAMESPACE . Webhook::ROUTE;
        // Not before init: rest_url() needs $wp_rewrite, and settings are also built while plugins load.
        return did_action('init') ? rest_url($route) : $route;
    }

    /** Never pass nonces, tokens, keys or request bodies. Warnings and errors always log. */
    public static function log(string $message, string $level = 'info'): void
    {
        if (($level === 'info' || $level === 'debug') && Gateway::setting('debug', 'no') !== 'yes') {
            return;
        }
        Gateway::writeLog($message, $level);
    }
}
