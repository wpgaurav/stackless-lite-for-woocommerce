<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * What Billing Health shows for Revolut, and the route for payment-failure alerts. The module
 * registers both; both answer neutrally when the reading module is off.
 */
final class Health
{
    /** A saved-card charge still pending after this long is reported. */
    public const STUCK_AFTER = 2 * 86400;
    /** Payments to review are reported for this long. */
    public const REVIEW_FOR = 30 * 86400;

    /**
     * @return array<string,mixed> A payment alerts route.
     */
    public static function route(): array
    {
        return [
            'label' => 'Revolut',
            'ids' => [Keys::GATEWAY_ID],
            // Live only: the sandbox is another host.
            'hosts' => [(string) wp_parse_url(Api::HOSTS['live'], PHP_URL_HOST)],
            'class' => __NAMESPACE__ . '\\',
            'mode_meta' => Keys::MODE,
        ];
    }

    /**
     * @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array<string,string>>}
     */
    public static function report(): array
    {
        $settings = Settings::settingsUrl();
        $issues = [];
        if (Platform::gatewayEnabled()) {
            if (Settings::secretKey() === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Revolut has no secret key for the selected mode', 'stackless-lite'),
                    'detail' => __('Checkout hides Revolut and renewals cannot be charged until a key is added.', 'stackless-lite'),
                    'url' => $settings,
                ];
            } elseif (Settings::webhookSecret(Settings::mode()) === '') {
                $issues[] = [
                    'severity' => 'error',
                    'title' => __('Revolut has no webhook signing secret', 'stackless-lite'),
                    'detail' => __('Payments that finish after the customer leaves, refunds made in Revolut and disputes do not reach the store. Save the settings again to set the webhook up.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
            if (Settings::mode() === 'test') {
                $issues[] = [
                    'severity' => 'warning',
                    'title' => __('Revolut is in test mode', 'stackless-lite'),
                    'detail' => __('Payments and renewals use the Revolut sandbox.', 'stackless-lite'),
                    'url' => $settings,
                ];
            }
        }
        $stuck = Orders::stuckCharges(time() - self::STUCK_AFTER);
        if ($stuck) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Revolut charge has been unresolved for over two days', '%d Revolut charges have been unresolved for over two days', count($stuck), 'stackless-lite'), count($stuck)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('Check these orders in Revolut Business before charging them another way: %s', 'stackless-lite'), implode(', ', $stuck)),
                'url' => Platform::ordersUrl(),
            ];
        }
        $review = Orders::toReview(time() - self::REVIEW_FOR);
        if ($review) {
            $issues[] = [
                'severity' => 'error',
                /* translators: %d: number of orders */
                'title' => sprintf(_n('%d Revolut payment needs a look', '%d Revolut payments need a look', count($review), 'stackless-lite'), count($review)),
                /* translators: %s: order numbers */
                'detail' => sprintf(__('A second payment, a wrong amount or a failed refund. Read the notes on: %s', 'stackless-lite'), implode(', ', $review)),
                'url' => Platform::ordersUrl(),
            ];
        }
        $events = WebhookLog::problems();
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: %d: number of events */
                'title' => sprintf(_n('%d Revolut webhook failed recently', '%d Revolut webhooks failed recently', count($events), 'stackless-lite'), count($events)),
                'detail' => __('Revolut retries three times, ten minutes apart. The Revolut log has the reason.', 'stackless-lite'),
                'url' => Platform::logsUrl(),
            ];
        }
        return ['label' => 'Revolut', 'issues' => $issues, 'events' => $events];
    }
}
