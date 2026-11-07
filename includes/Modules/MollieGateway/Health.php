<?php

namespace Stackless\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_billing_health_sources) and
 * payment-failure alerts (stackless_payment_alerts_gateways). Both answer neutrally when the
 * reading module is off, so nothing here depends on it.
 */
final class Health
{
    /** Renewals still pending after this long are reported (SEPA settles within about 9 business days). */
    public const STUCK_AFTER = 14 * DAY_IN_SECONDS;

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
        $routes['mollie'] = [
            'label' => 'Mollie',
            'ids' => [Gateway::ID],
            'hosts' => ['api.mollie.com'],
            'class' => 'Stackless\\Modules\\MollieGateway\\',
            'mode_meta' => Gateway::META_MODE,
            // Test and live calls share this host: only live credentials alert.
            'live_auth' => ['live_', 'access_'],
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
            if ($gateway->apiKey() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Mollie has no API key for the selected mode', 'stackless-lite'),
                    'detail' => __('Checkout hides Mollie and renewals cannot be charged until a key is added.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if ($gateway->mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Mollie is in test mode', 'stackless-lite'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
        }
        $stuck = self::stuckRenewals();
        if ($stuck) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Mollie charge has been pending for over two weeks', '%d Mollie charges have been pending for over two weeks', count($stuck), 'stackless-lite'), count($stuck)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('Check these orders in Mollie: %s', 'stackless-lite'), implode(', ', $stuck)),
                'url' => admin_url('admin.php?page=wc-orders&status=wc-pending'),
            ];
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Mollie webhook failed recently', '%d Mollie webhooks failed recently', count($events), 'stackless-lite'), count($events)),
                'detail' => __('Mollie retries for about a day. Look in WooCommerce → Status → Logs (stackless-mollie_gateway) for the reason.', 'stackless-lite'),
                'url' => admin_url('admin.php?page=wc-status&tab=logs'),
            ];
        }
        return ['label' => 'Mollie', 'issues' => $issues, 'events' => $events];
    }

    /** @return string[] order numbers of charges still waiting on Mollie */
    private static function stuckRenewals(): array
    {
        $orders = wc_get_orders([
            'limit' => 20,
            'status' => ['pending'],
            'payment_method' => Gateway::ID,
            'date_created' => '<' . (time() - self::STUCK_AFTER),
            'meta_key' => Gateway::META_SEQUENCE, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => 'recurring', // phpcs:ignore WordPress.DB.SlowDBQuery
        ]);
        $numbers = [];
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order && (string) $order->get_meta(Gateway::META_PAYMENT_ID) !== '') {
                $numbers[] = '#' . $order->get_order_number();
            }
        }
        return $numbers;
    }
}
