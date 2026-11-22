<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * Registrations with other modules: Billing Health (stackless_billing_health_sources) and
 * payment-failure alerts (stackless_payment_alerts_gateways). Both answer neutrally when the
 * reading module is off, so nothing here depends on it.
 */
final class Health
{
    /** Charges still without a result after this long are reported. */
    public const STUCK_AFTER = 2 * DAY_IN_SECONDS;

    public static function registerHooks(): void
    {
        add_filter('stackless_billing_health_sources', [self::class, 'source']);
        add_filter('stackless_payment_alerts_gateways', [self::class, 'alerts']);
    }

    /** @param array $sources @return array */
    public static function source($sources)
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Settings::MODULE_ID] = [self::class, 'report'];
        return $sources;
    }

    /** @param array $routes @return array */
    public static function alerts($routes)
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['airwallex'] = [
            'label' => 'Airwallex',
            'ids' => [Settings::GATEWAY_ID],
            // Sandbox calls go to another host.
            'hosts' => ['api.airwallex.com'],
            'class' => 'Stackless\\Modules\\AirwallexGateway\\',
            'mode_meta' => Meta::MODE,
        ];
        return $routes;
    }

    /**
     * @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array<string,string>>}
     */
    public static function report(): array
    {
        $settings = Settings::settingsUrl();
        $issues = [];
        if (Settings::enabled()) {
            if (!Settings::configured()) {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Airwallex is not connected for the selected mode', 'stackless-lite'),
                    'detail' => __('Checkout hides Airwallex and renewals wait until the client ID, API key and account ID are added.', 'stackless-lite'),
                    'url' => $settings,
                ];
            } elseif (Settings::secret(Settings::mode()) === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Airwallex has no webhook secret', 'stackless-lite'),
                    'detail' => __('Webhooks are refused, so delayed payments, refunds and disputes are not recorded on time.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if (Settings::mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Airwallex is in sandbox mode', 'stackless-lite'),
                    'detail' => __('Payments and renewals use test money only.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
        }
        $review = OrderLists::needingReview();
        if ($review) {
            $issues[] = [
                'severity' => 'error',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d order has an Airwallex payment to review', '%d orders have an Airwallex payment to review', count($review), 'stackless-lite'), count($review)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('A second, mismatched or late payment arrived. Refund it in Airwallex or complete the order by hand: %s', 'stackless-lite'), implode(', ', $review)),
                'url' => OrderLists::url(),
            ];
        }
        $stuck = OrderLists::stuck(time() - self::STUCK_AFTER);
        if ($stuck) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Airwallex charge has had no result for over two days', '%d Airwallex charges have had no result for over two days', count($stuck), 'stackless-lite'), count($stuck)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('Check these in Airwallex before charging again: %s', 'stackless-lite'), implode(', ', $stuck)),
                'url' => OrderLists::url(),
            ];
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Airwallex webhook failed recently', '%d Airwallex webhooks failed recently', count($events), 'stackless-lite'), count($events)),
                'detail' => __('Airwallex retries for about three days. The Stackless Airwallex log has the reason.', 'stackless-lite'),
                'url' => OrderLists::logsUrl(),
            ];
        }
        return ['label' => 'Airwallex', 'issues' => $issues, 'events' => $events];
    }
}
