<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the gateway's WooCommerce settings, for code that runs without a gateway
 * object (webhooks, retries, refunds).
 *
 * Each value can be pinned in wp-config.php with STACKLESS_AIRWALLEX_{LIVE|TEST}_{CLIENT_ID|
 * API_KEY|ACCOUNT_ID|WEBHOOK_SECRET}; pinned values win and their fields are hidden.
 */
final class Settings
{
    public const GATEWAY_ID = 'stackless_airwallex';
    public const MODULE_ID = 'airwallex_gateway';
    public const OPTION = 'woocommerce_stackless_airwallex_settings';
    /** merchant_order_id prefix of this plugin's payments. */
    public const REFERENCE_PREFIX = 'slwc';

    public const CREDENTIALS = ['client_id', 'api_key', 'account_id', 'webhook_secret'];

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = '')
    {
        $settings = get_option(self::OPTION, []);
        return is_array($settings) && isset($settings[$key]) ? $settings[$key] : $default;
    }

    /** test|live */
    public static function mode(): string
    {
        return self::get('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    public static function normalizeMode(string $mode): string
    {
        return $mode === 'live' || $mode === 'test' ? $mode : self::mode();
    }

    public static function constantName(string $mode, string $key): string
    {
        return 'STACKLESS_AIRWALLEX_' . ($mode === 'test' ? 'TEST' : 'LIVE') . '_' . strtoupper($key);
    }

    /** Whether a value is pinned in wp-config.php. */
    public static function isDefined(string $mode, string $key): bool
    {
        $constant = self::constantName($mode, $key);
        return defined($constant) && constant($constant);
    }

    public static function credential(string $key, string $mode = ''): string
    {
        $mode = self::normalizeMode($mode);
        if (self::isDefined($mode, $key)) {
            return trim((string) constant(self::constantName($mode, $key)));
        }
        return trim((string) self::get($mode . '_' . $key, ''));
    }

    public static function account(string $mode = ''): string
    {
        return self::credential('account_id', $mode);
    }

    public static function secret(string $mode): string
    {
        return self::credential('webhook_secret', $mode);
    }

    public static function configured(string $mode = ''): bool
    {
        return self::api($mode)->configured();
    }

    public static function api(string $mode = ''): Api
    {
        $mode = self::normalizeMode($mode);
        return new Api(
            $mode,
            self::credential('client_id', $mode),
            self::credential('api_key', $mode),
            self::credential('account_id', $mode),
            self::get($mode . '_login_as', 'no') === 'yes'
        );
    }

    public static function enabled(): bool
    {
        return self::get('enabled', 'no') === 'yes';
    }

    public static function webhookUrl(): string
    {
        return rest_url(Webhook::REST_NAMESPACE . Webhook::ROUTE);
    }

    public static function settingsUrl(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID);
    }

    public static function userAgent(): string
    {
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev');
    }

    /** Warnings and errors always log; info and debug only with the debug log on. Never pass secrets. */
    public static function log(string $message, string $level = 'info'): void
    {
        if (($level === 'info' || $level === 'debug') && self::get('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log(self::MODULE_ID, $message, $level);
    }
}
