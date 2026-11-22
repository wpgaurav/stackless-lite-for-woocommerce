<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Core\WebhookSetup;

defined('ABSPATH') || exit;

/** The WooCommerce screens, links and notices the payment flows use. */
final class Platform
{
    /** @param array<string,string|int> $args */
    public static function pageUrl(array $args): string
    {
        return add_query_arg($args, WC()->api_request_url(Pages::ACTION));
    }

    public static function redirect(string $url): void
    {
        wp_safe_redirect($url);
        exit;
    }

    public static function checkoutUrl(): string
    {
        return wc_get_checkout_url();
    }

    public static function methodsUrl(int $subscriptionId = 0): string
    {
        // Subscriptions takes the customer back to the subscription itself.
        return wc_get_account_endpoint_url('payment-methods');
    }

    /** @param array<string,mixed> $args */
    public static function render(array $args): void
    {
        status_header(200);
        stackless_get_template('revolut_gateway/pay-page.php', $args + [
            'script' => \Stackless\Core\Assets::url('revolut_gateway/checkout.js') . '?ver=' . rawurlencode(defined('STACKLESS_VERSION') ? STACKLESS_VERSION : '1'),
        ]);
        exit;
    }

    public static function emptyCart(): void
    {
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
    }

    public static function checkoutError(string $message): void
    {
        self::notice($message, 'error');
    }

    /** @param string $type success|error|info */
    public static function notice(string $message, string $type): void
    {
        if (function_exists('wc_add_notice') && function_exists('WC') && WC()->session) {
            wc_add_notice($message, $type === 'info' ? 'notice' : $type);
        }
    }

    public static function currency(): string
    {
        return strtoupper(stackless_current_currency());
    }

    public static function gatewayEnabled(): bool
    {
        return Settings::get('enabled', 'no') === 'yes';
    }

    public static function reachable(string $url): bool
    {
        return WebhookSetup::reachable($url);
    }

    public static function ordersUrl(): string
    {
        return admin_url('admin.php?page=wc-orders');
    }

    public static function logsUrl(): string
    {
        return admin_url('admin.php?page=wc-status&tab=logs');
    }
}
