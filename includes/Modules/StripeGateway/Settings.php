<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Read access to the module's options (stored by the Stackless settings tab as
 * stackless_stripe_gateway_{key}) plus derived values used across the module.
 */
final class Settings
{
    public const MODULE_ID = 'stripe_gateway';
    public const GATEWAY_ID = 'stripe_gateway';
    public const PREFIX = 'stackless_stripe_gateway_';

    /** @var array<string,StripeClient> */
    private static $clients = [];

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = '')
    {
        return get_option(self::PREFIX . $key, $default);
    }

    public static function isTestMode(): bool
    {
        return self::get('testmode', 'yes') === 'yes';
    }

    public static function mode(): string
    {
        return self::isTestMode() ? 'test' : 'live';
    }

    public static function secretKey(?string $mode = null): string
    {
        $mode = $mode ?? self::mode();
        return trim((string) self::get($mode . '_secret_key', ''));
    }

    public static function publishableKey(?string $mode = null): string
    {
        $mode = $mode ?? self::mode();
        return trim((string) self::get($mode . '_publishable_key', ''));
    }

    public static function webhookSecret(string $mode): string
    {
        return trim((string) self::get($mode . '_webhook_secret', ''));
    }

    public static function isConfigured(?string $mode = null): bool
    {
        return self::secretKey($mode) !== '' && self::publishableKey($mode) !== '';
    }

    public static function captureNow(): bool
    {
        return self::get('capture', 'now') !== 'authorize';
    }

    public static function savedCardsEnabled(): bool
    {
        return self::get('saved_cards', 'yes') === 'yes';
    }

    public static function debug(): bool
    {
        return self::get('debug', 'no') === 'yes';
    }

    public static function title(): string
    {
        $title = (string) self::get('title', '');
        return $title !== '' ? $title : __('Card and more', 'stackless-lite');
    }

    public static function description(): string
    {
        return (string) self::get('description', __('Pay securely with your card, Apple Pay, Google Pay, Link or a local payment method.', 'stackless-lite'));
    }

    /** Appearance preset: site (follow the theme), stripe, night, flat. */
    public static function appearance(): string
    {
        $value = (string) self::get('appearance', 'site');
        return in_array($value, ['site', 'stripe', 'night', 'flat'], true) ? $value : 'site';
    }

    /** @return string[] subset of product, cart, checkout */
    public static function expressLocations(): array
    {
        $value = self::get('express_locations', ['product', 'cart', 'checkout']);
        if (!is_array($value)) {
            $value = $value === '' ? [] : [(string) $value];
        }
        return array_values(array_intersect(['product', 'cart', 'checkout'], array_map('strval', $value)));
    }

    public static function expressEnabledOn(string $location): bool
    {
        return in_array($location, self::expressLocations(), true);
    }

    public static function expressButtonType(): string
    {
        $value = (string) self::get('express_button_type', 'buy');
        return in_array($value, ['plain', 'buy', 'checkout', 'order', 'pay'], true) ? $value : 'buy';
    }

    /**
     * Stripe allows 22 characters for the full descriptor; the suffix is appended to the
     * account's shortened descriptor, so keep it short and free of <>\'" characters.
     */
    public static function statementDescriptorSuffix(): string
    {
        return self::sanitizeDescriptorSuffix((string) self::get('statement_descriptor_suffix', ''));
    }

    public static function sanitizeDescriptorSuffix(string $value): string
    {
        $value = preg_replace('/[<>\\\\\'"*]/', '', remove_accents(wp_strip_all_tags($value)));
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));
        if ($value === '' || !preg_match('/[A-Za-z]/', $value)) {
            return '';
        }
        return substr($value, 0, 22);
    }

    public static function client(?string $mode = null): StripeClient
    {
        $mode = ($mode === 'live' || $mode === 'test') ? $mode : self::mode();
        $secret = self::secretKey($mode);
        $cache_key = $mode . ':' . md5($secret);
        if (!isset(self::$clients[$cache_key])) {
            self::$clients[$cache_key] = new StripeClient($secret, $mode);
        }
        return self::$clients[$cache_key];
    }

    /** Client for an order: always the mode the order was paid in, even after the store switches modes. */
    public static function clientForOrder(\WC_Order $order): StripeClient
    {
        $mode = (string) $order->get_meta(Payments::META_MODE);
        return self::client($mode !== '' ? $mode : null);
    }

    public static function webhookUrl(): string
    {
        return rest_url('stackless/v1/stripe/webhook');
    }

    /** Events the webhook endpoint must subscribe to. */
    public static function webhookEvents(): array
    {
        return [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.processing',
            'payment_intent.canceled',
            'charge.refunded',
            'charge.dispute.created',
            'setup_intent.succeeded',
            'setup_intent.setup_failed',
            'payment_method.detached',
            'payment_method.updated',
            'payment_method.automatically_updated',
        ];
    }

    /** Stable per-site marker written to Stripe metadata, so events for other sites on the same account are ignored. */
    public static function siteMarker(): string
    {
        return substr(hash('sha256', (string) home_url('/')), 0, 16);
    }

    /**
     * Logs to WooCommerce → Status → Logs (source stackless-stripe_gateway). Errors and warnings
     * always log; info/debug only with debug logging on. Callers never pass secrets or card data.
     */
    public static function log(string $message, string $level = 'info', array $context = []): void
    {
        if (!in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning'], true) && !self::debug()) {
            return;
        }
        Logger::log(self::MODULE_ID, $message, $level, $context);
    }
}
