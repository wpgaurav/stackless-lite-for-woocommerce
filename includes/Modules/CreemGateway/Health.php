<?php

namespace Stackless\Modules\CreemGateway;

defined('ABSPATH') || exit;

/**
 * What Creem tells the store's health screens: payment alert routing and the Billing Health
 * source (setup problems of the original and every copy, and recent failed or stuck webhook
 * events). No keys and no customer data leave this class.
 */
final class Health
{
    /** Live and test are separate hosts, so only api.creem.io is watched. @param mixed $routes */
    public static function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['creem'] = [
            'label' => 'Creem',
            'ids' => [Gateway::ID],
            'hosts' => ['api.creem.io'],
            'class' => 'Stackless\\Modules\\CreemGateway\\',
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
        $issues = [];
        $seen = get_option(Webhook::LAST_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        foreach (Gateway::all() as $gateway) {
            if ($gateway->enabled !== 'yes') {
                continue;
            }
            $name = $gateway->get_method_title();
            $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $gateway->id);
            $mode = $gateway->mode();
            $key = $gateway->apiKey($mode);
            if ($key === '') {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'error', 'title' => sprintf(__('%s has no API key', 'stackless-lite'), $name), 'detail' => __('Creem is enabled but cannot take payments until an API key is added for the selected mode.', 'stackless-lite'), 'url' => $url];
            } elseif (EventMap::keyMode($key) !== '' && EventMap::keyMode($key) !== $mode) {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'error', 'title' => sprintf(__('%s key is for the other mode', 'stackless-lite'), $name), 'detail' => __('The API key does not match the selected mode (test or live).', 'stackless-lite'), 'url' => $url];
            }
            if ($gateway->webhookSecret($mode) === '') {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'error', 'title' => sprintf(__('%s webhooks are refused', 'stackless-lite'), $name), 'detail' => __('Without the webhook secret, renewals, failed renewals, refunds and cancellations made in Creem never reach WooCommerce.', 'stackless-lite'), 'url' => $url];
            } elseif ($key !== '' && empty($seen[$gateway->id . ':' . $mode])) {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'warning', 'title' => sprintf(__('No webhook received yet for %s', 'stackless-lite'), $name), 'detail' => __('Check that the webhook in Creem points at this site and listens to the listed events.', 'stackless-lite'), 'url' => $url];
            }
            if ($mode === 'test') {
                /* translators: %s: gateway name */
                $issues[] = ['severity' => 'info', 'title' => sprintf(__('%s is in test mode', 'stackless-lite'), $name), 'detail' => __('Checkout takes test payments only. Turn test mode off to take real payments.', 'stackless-lite'), 'url' => $url];
            }
        }
        return [
            'label' => 'Creem',
            'issues' => $issues,
            'events' => WebhookLog::problems(10),
        ];
    }
}
