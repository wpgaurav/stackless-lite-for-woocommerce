<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_billing_health_sources) and
 * payment-failure alerts (stackless_payment_alerts_gateways).
 */
final class Health
{
    /** Debits still pending after this long are reported (they normally settle within three days). */
    public const STUCK_AFTER = 5 * DAY_IN_SECONDS;

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
        $routes['razorpay'] = [
            'label' => 'Razorpay',
            'ids' => [Gateway::ID],
            'hosts' => ['api.razorpay.com'],
            'class' => 'Stackless\\Modules\\RazorpayGateway\\',
            'mode_meta' => Gateway::META_MODE,
            // Test and live calls share this host: only live credentials alert.
            'live_auth' => ['rzp_live_'],
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
            if (!$gateway->api()->hasKeys()) {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Razorpay has no API keys for the selected mode', 'stackless-lite'),
                    'detail' => __('Checkout hides Razorpay and renewals cannot be debited until the key id and secret are added.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if ($gateway->webhookSecret() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Razorpay webhook secret is missing', 'stackless-lite'),
                    'detail' => __('Renewal debits settle only through the webhook, so renewals stay pending without it.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if ($gateway->mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Razorpay is in test mode', 'stackless-lite'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
        }
        $stuck = self::stuckDebits();
        if ($stuck) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Razorpay debit has been pending for over five days', '%d Razorpay debits have been pending for over five days', count($stuck), 'stackless-lite'), count($stuck)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('Check these orders in the Razorpay dashboard and make sure the webhook is active: %s', 'stackless-lite'), implode(', ', $stuck)),
                'url' => admin_url('admin.php?page=wc-orders&status=wc-pending'),
            ];
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Razorpay webhook failed recently', '%d Razorpay webhooks failed recently', count($events), 'stackless-lite'), count($events)),
                'detail' => __('Razorpay retries for 24 hours and then disables the webhook. Look in WooCommerce → Status → Logs (stackless-razorpay_gateway) for the reason.', 'stackless-lite'),
                'url' => admin_url('admin.php?page=wc-status&tab=logs'),
            ];
        }
        return ['label' => 'Razorpay', 'issues' => $issues, 'events' => $events];
    }

    /** @return string[] order numbers of debits still waiting on Razorpay */
    private static function stuckDebits(): array
    {
        $orders = wc_get_orders(\Stackless\Core\OrderQuery::args([
            'limit' => 20,
            'status' => ['pending', 'on-hold'],
            'payment_method' => Gateway::ID,
            'date_modified' => '<' . (time() - self::STUCK_AFTER),
            'meta_query' => [['key' => Gateway::META_FLOW, 'value' => Renewals::FLOWS, 'compare' => 'IN']], // phpcs:ignore WordPress.DB.SlowDBQuery
        ]));
        $numbers = [];
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order && in_array((string) $order->get_meta(Gateway::META_FLOW), Renewals::FLOWS, true) && (string) $order->get_meta(Gateway::META_PAYMENT_ID) !== '') {
                $numbers[] = '#' . $order->get_order_number();
            }
        }
        return $numbers;
    }
}
