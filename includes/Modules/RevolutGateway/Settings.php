<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Core\LiveStore;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the gateway's WooCommerce settings, without building the gateway object.
 *
 * Secrets can be pinned in wp-config.php with STACKLESS_REVOLUT_{LIVE|TEST}_SECRET_KEY and
 * STACKLESS_REVOLUT_{LIVE|TEST}_WEBHOOK_SECRET; pinned values win and their fields are hidden.
 */
final class Settings
{
    public const OPTION = 'woocommerce_' . Keys::GATEWAY_ID . '_settings';
    public const SECRETS = ['secret_key', 'webhook_secret'];

    public static function get(string $key, string $default = ''): string
    {
        $settings = get_option(self::OPTION, []);
        return is_array($settings) && isset($settings[$key]) && is_scalar($settings[$key]) ? (string) $settings[$key] : $default;
    }

    /** test|live */
    public static function mode(): string
    {
        return self::get('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    public static function constantName(string $mode, string $field): string
    {
        return 'STACKLESS_REVOLUT_' . ($mode === 'live' ? 'LIVE' : 'TEST') . '_' . strtoupper($field);
    }

    /** Whether a secret is pinned in wp-config.php. */
    public static function isDefined(string $mode, string $field): bool
    {
        $constant = self::constantName($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function secret(string $mode, string $field): string
    {
        if (self::isDefined($mode, $field)) {
            return trim((string) constant(self::constantName($mode, $field)));
        }
        return trim(self::get($mode . '_' . $field));
    }

    public static function secretKey(string $mode = ''): string
    {
        return self::secret($mode === 'live' || $mode === 'test' ? $mode : self::mode(), 'secret_key');
    }

    public static function webhookSecret(string $mode): string
    {
        return self::secret($mode === 'live' ? 'live' : 'test', 'webhook_secret');
    }

    public static function saveWebhookSecret(string $mode, string $secret): void
    {
        $settings = get_option(self::OPTION, []);
        $settings = is_array($settings) ? $settings : [];
        $settings[($mode === 'live' ? 'live' : 'test') . '_webhook_secret'] = $secret;
        update_option(self::OPTION, $settings);
    }

    public static function api(string $mode): Api
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        return new Api($mode, self::secretKey($mode));
    }

    /** False on a copy of the live store. */
    public static function liveStore(): bool
    {
        return LiveStore::isLive();
    }

    /**
     * Saving a card only serves modules that charge it later, so it is offered only while one
     * answers stackless_order_requires_saved_payment_method.
     */
    public static function savesMethods(): bool
    {
        return (bool) has_filter('stackless_order_requires_saved_payment_method');
    }

    public static function webhookUrl(string $mode): string
    {
        return rest_url('stackless/v1' . Webhook::ROUTE . ($mode === 'live' ? 'live' : 'test'));
    }

    public static function settingsUrl(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Keys::GATEWAY_ID);
    }

    /** Warnings and errors always log; the rest only with the debug log on. Callers never pass secrets. */
    public static function log(string $message, string $level = 'info'): void
    {
        if (($level === 'info' || $level === 'debug') && self::get('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log(Keys::MODULE_ID, $message, $level);
    }
}
