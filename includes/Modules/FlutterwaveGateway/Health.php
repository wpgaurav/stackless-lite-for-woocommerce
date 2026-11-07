<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * What Flutterwave tells the store's health screens: payment alert routing and the Billing
 * Health source. No keys and no customer data leave this class.
 */
final class Health
{
    /** @param mixed $routes */
    public static function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['flutterwave'] = [
            'label' => 'Flutterwave',
            'ids' => [Gateway::ID],
            'hosts' => ['api.flutterwave.com'],
            // Test and live share one host: only calls sent through LiveTransport are live.
            'class' => LiveTransport::class,
            'mode_meta' => Gateway::META_MODE,
        ];
        return $routes;
    }

    /** @param mixed $sources */
    public static function register($sources): array
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Gateway::ID] = [self::class, 'report'];
        return $sources;
    }

    /** @return array{label:string,issues:array,events:array} */
    public static function report(): array
    {
        $gateway = Gateway::instance();
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID);
        $issues = [];
        if ($gateway->enabled === 'yes') {
            $mode = $gateway->mode();
            $key = $gateway->secretKey($mode);
            if ($key === '') {
                $issues[] = ['severity' => 'error', 'title' => __('Flutterwave has no secret key', 'stackless-lite'), 'detail' => __('Flutterwave is enabled but cannot take payments until a secret key is added for the selected mode.', 'stackless-lite'), 'url' => $url];
            } elseif (EventMap::keyMode($key) !== '' && EventMap::keyMode($key) !== $mode) {
                $issues[] = ['severity' => 'error', 'title' => __('Flutterwave key is for the other mode', 'stackless-lite'), 'detail' => __('The secret key does not match the selected mode (test or live).', 'stackless-lite'), 'url' => $url];
            }
            if ($gateway->secretHash($mode) === '') {
                $issues[] = ['severity' => 'error', 'title' => __('Flutterwave webhooks are refused', 'stackless-lite'), 'detail' => __('Without a webhook secret hash, Flutterwave webhooks cannot be checked, so payments are confirmed only when buyers return to your store.', 'stackless-lite'), 'url' => $url];
            }
            if ($mode === 'test') {
                $issues[] = ['severity' => 'info', 'title' => __('Flutterwave is in test mode', 'stackless-lite'), 'detail' => __('Checkout takes test payments only. Turn test mode off to take real payments.', 'stackless-lite'), 'url' => $url];
            }
            $seen = get_option(Webhook::LAST_SEEN, []);
            if ($key !== '' && $gateway->secretHash($mode) !== '' && empty(is_array($seen) ? ($seen[$mode] ?? 0) : 0)) {
                $issues[] = ['severity' => 'warning', 'title' => __('No Flutterwave webhook received yet', 'stackless-lite'), 'detail' => __('Add the webhook URL and secret hash in Flutterwave’s webhook settings so payments are confirmed even when a buyer does not return to your store.', 'stackless-lite'), 'url' => $url];
            }
        }
        return [
            'label' => 'Flutterwave',
            'issues' => $issues,
            'events' => WebhookLog::problems(10),
        ];
    }
}
