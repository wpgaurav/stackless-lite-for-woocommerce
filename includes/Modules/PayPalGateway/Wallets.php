<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Apple Pay through PayPal needs PayPal's domain association file at
 * /.well-known/apple-developer-merchantid-domain-association on the store's domain (2.0.0).
 * The live and sandbox files PayPal publishes ship with the plugin and are served from
 * here, with no extension, as application/octet-stream and without redirects.
 */
final class Wallets
{
    public const PATH = '/.well-known/apple-developer-merchantid-domain-association';

    public static function serveDomainAssociation(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with a fixed path.
        if (($method !== 'GET' && $method !== 'HEAD') || !self::matches($uri, (string) wp_parse_url(home_url('/'), PHP_URL_PATH))) {
            return;
        }
        $gateway = Gateway::instance();
        if ($gateway->enabled !== 'yes' || !$gateway->applePayEnabled()) {
            return;
        }
        $file = STACKLESS_PLUGIN_PATH . 'assets/paypal_gateway/apple-pay/domain-association-' . ($gateway->isSandbox() ? 'sandbox' : 'live') . '.txt';
        if (!is_readable($file)) {
            return;
        }
        status_header(200);
        header('Content-Type: application/octet-stream');
        header('Cache-Control: public, max-age=3600');
        header('X-Robots-Tag: noindex');
        if ($method === 'GET') {
            readfile($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- a static file shipped with the plugin.
        }
        exit;
    }

    /** True when the request path is the association file at the site root. */
    public static function matches(string $uri, string $homePath): bool
    {
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $prefix = rtrim($homePath, '/');
        return $path === $prefix . self::PATH || $path === self::PATH;
    }
}
