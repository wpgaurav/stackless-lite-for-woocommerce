<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_billing_health_sources) and
 * payment-failure alerts (stackless_payment_alerts_gateways). Both answer neutrally when the
 * reading module is off, so nothing here depends on it.
 */
final class Health
{

    public static function registerHooks(): void
    {
        add_filter('stackless_billing_health_sources', [self::class, 'source']);
        add_filter('stackless_payment_alerts_gateways', [self::class, 'alerts']);
    }

    /** @param array $sources @return array */
    public static function source($sources)
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Gateway::MODULE_ID] = [self::class, 'report'];
        return $sources;
    }

    /** @param array $routes @return array */
    public static function alerts($routes)
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['square'] = [
            'label' => 'Square',
            'ids' => [Gateway::ID],
            'hosts' => ['connect.squareup.com'],
            'class' => 'Stackless\\Modules\\SquareGateway\\',
            'mode_meta' => Gateway::META_MODE,
        ];
        return $routes;
    }

    /**
     * @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array>}
     */
    public static function report(): array
    {
        $gateway = Gateway::instance();
        $settings = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID);
        $issues = [];
        if ($gateway->enabled === 'yes') {
            if ($gateway->appId() === '' || $gateway->accessToken() === '' || $gateway->locationId() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Square is missing its application ID, access token or location', 'stackless-lite'),
                    'detail' => __('Checkout hides Square and renewals cannot be charged until all three are set for the selected mode.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if ($gateway->webhookKey() === '') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Square webhook signature key is missing', 'stackless-lite'),
                    'detail' => __('Refunds made in Square and card changes do not reach WooCommerce without the webhook. Save the settings on a public HTTPS site to create it.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            $currency = $gateway->currency();
            if ($currency !== '' && strtoupper(get_woocommerce_currency()) !== $currency) {
                $issues[] = [
                    'severity' => 'info',
                    /* translators: %s: currency code */
                    'title' => sprintf(__('Square only takes %s', 'stackless-lite'), $currency),
                    'detail' => __('The store’s base currency differs from the Square location’s, so Square shows only for carts in the location’s currency.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if ($gateway->mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Square is in sandbox mode', 'stackless-lite'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Square webhook failed recently', '%d Square webhooks failed recently', count($events), 'stackless-lite'), count($events)),
                'detail' => __('Square retries for 24 hours. Look in WooCommerce → Status → Logs (stackless-square_gateway) for the reason.', 'stackless-lite'),
                'url' => admin_url('admin.php?page=wc-status&tab=logs'),
            ];
        }
        return ['label' => 'Square', 'issues' => $issues, 'events' => $events];
    }
}
