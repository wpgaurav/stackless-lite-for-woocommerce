<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_billing_health_sources) and
 * payment-failure alerts (stackless_payment_alerts_gateways). Both answer neutrally when the
 * reading module is off, so nothing here depends on it.
 */
final class Health
{
    /** A charge without a known result is reported after this long. */
    public const STUCK_AFTER = HOUR_IN_SECONDS;

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

    /**
     * Sandbox and production have separate hosts, so the host alone marks a live call.
     *
     * @param array $routes @return array
     */
    public static function alerts($routes)
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['braintree'] = [
            'label' => 'Braintree',
            'ids' => [Gateway::ID],
            'hosts' => [Api::HOSTS['live'], Api::GATEWAY_HOSTS['live']],
            'class' => 'Stackless\\Modules\\BraintreeGateway\\',
            'mode_meta' => Meta::MODE,
            'live_auth' => [],
        ];
        return $routes;
    }

    /**
     * @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array>}
     */
    public static function report(): array
    {
        $settings = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID);
        $mode = Config::mode();
        $issues = [];
        if (Gateway::setting('enabled', 'no') === 'yes') {
            if (!Config::hasKeys($mode)) {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Braintree is missing its Merchant ID or keys', 'stackless-lite'),
                    'detail' => __('Checkout hides Braintree and renewals cannot be charged until the Merchant ID, public key and private key are set for the selected mode.', 'stackless-lite'),
                    'url' => $settings,
                ];
            } else {
                $connection = Connection::cached($mode);
                $store = strtoupper(stackless_base_currency());
                if (!$connection) {
                    $issues[] = [
                        'severity' => 'error',
                        'title' => __('Braintree has not confirmed these keys', 'stackless-lite'),
                        'detail' => __('Save the Braintree settings to check the connection and read your merchant accounts.', 'stackless-lite'),
                        'url' => $settings,
                    ];
                } else {
                    $row = Connection::merchantAccount($mode, $store, $connection);
                    if (!$row) {
                        $issues[] = [
                            'severity' => 'warning',
                            /* translators: %s: currency code */
                            'title' => sprintf(__('Braintree has no merchant account for %s', 'stackless-lite'), $store),
                            'detail' => __('A merchant account takes one currency, so Braintree hides for carts in currencies without one. Ask Braintree for an account in this currency, or choose one in the settings when there are several.', 'stackless-lite'),
                            'url' => $settings,
                        ];
                    } elseif (!$row['secure'] && Config::threeDSecure() !== ThreeDSecure::OFF) {
                        $issues[] = [
                            'severity' => 'warning',
                            'title' => __('3-D Secure is required but not switched on at Braintree', 'stackless-lite'),
                            'detail' => __('Card payments fail until Braintree enables 3-D Secure for this merchant account.', 'stackless-lite'),
                            'url' => $settings,
                        ];
                    }
                    if (stackless_base_price_decimals() > Money::decimals($store)) {
                        $issues[] = [
                            'severity' => 'warning',
                            /* translators: %s: currency code */
                            'title' => sprintf(__('Braintree takes %s without decimals', 'stackless-lite'), $store),
                            'detail' => __('The store uses decimals, and a total with a fraction cannot be charged exactly. Set the number of decimals to 0.', 'stackless-lite'),
                            'url' => admin_url('admin.php?page=wc-settings&tab=general'),
                        ];
                    }
                }
                if (!get_option(Webhook::LAST_OPTION . $mode)) {
                    $issues[] = [
                        'severity' => 'info',
                        'title' => __('No Braintree webhook has arrived yet', 'stackless-lite'),
                        'detail' => __('Create the webhook in the Control Panel and use Check URL there. Without it, disputes reach WooCommerce within six hours instead of at once.', 'stackless-lite'),
                        'url' => $settings,
                    ];
                }
            }
            if ($mode === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Braintree is in sandbox mode', 'stackless-lite'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
        }

        $waiting = [];
        foreach (Orders::withMeta(Meta::ATTEMPT, 90) as $id) {
            $attempt = Attempt::current($id);
            if ($attempt && time() - (int) $attempt['started'] >= self::STUCK_AFTER) {
                $waiting[] = '#' . $id;
            }
        }
        if ($waiting) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Braintree charge needs a look', '%d Braintree charges need a look', count($waiting), 'stackless-lite'), count($waiting)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('These orders wait on a charge with no known result or a payment that does not match, so they are not charged again: %s', 'stackless-lite'), implode(', ', $waiting)),
                'url' => admin_url('admin.php?page=wc-orders'),
            ];
        }
        $flagged = array_map(static function ($id) {
            return '#' . $id;
        }, Orders::withMeta(Meta::FLAGGED, 30));
        if ($flagged) {
            $issues[] = [
                'severity' => 'error',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d order has an extra Braintree payment', '%d orders have extra Braintree payments', count($flagged), 'stackless-lite'), count($flagged)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('The order notes name the transaction to refund in the Control Panel: %s', 'stackless-lite'), implode(', ', $flagged)),
                'url' => admin_url('admin.php?page=wc-orders'),
            ];
        }

        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Braintree webhook failed recently', '%d Braintree webhooks failed recently', count($events), 'stackless-lite'), count($events)),
                'detail' => __('Braintree retries every hour for 24 hours. Look in WooCommerce → Status → Logs (stackless-braintree_gateway) for the reason.', 'stackless-lite'),
                'url' => admin_url('admin.php?page=wc-status&tab=logs'),
            ];
        }
        return ['label' => 'Braintree', 'issues' => $issues, 'events' => $events];
    }
}
