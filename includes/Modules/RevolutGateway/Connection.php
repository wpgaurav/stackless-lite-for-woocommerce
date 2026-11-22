<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * The check that runs when settings are saved: the key works, can read what the gateway needs,
 * and this site's webhook exists with its signing secret saved.
 */
final class Connection
{
    /** @return string[] problems to show after a save */
    public static function check(): array
    {
        $problems = [];
        foreach (['live', 'test'] as $mode) {
            if (Settings::secretKey($mode) === '') {
                continue;
            }
            $label = $mode === 'test' ? __('Sandbox', 'stackless-lite') : __('Live', 'stackless-lite');
            foreach (self::checkMode($mode) as $problem) {
                $problems[] = $label . ': ' . $problem;
            }
        }
        if (Platform::gatewayEnabled() && Settings::secretKey() === '') {
            $problems[] = __('Revolut is enabled but has no secret key for the selected mode.', 'stackless-lite');
        }
        return $problems;
    }

    /** @return string[] */
    private static function checkMode(string $mode): array
    {
        $api = Settings::api($mode);
        foreach (['orders', 'customers'] as $resource) {
            $read = $api->request('GET', $resource, ['limit' => 1]);
            if (is_wp_error($read)) {
                return [$read->get_error_message()];
            }
        }
        $url = Settings::webhookUrl($mode);
        $list = $api->request('GET', 'webhooks');
        if (is_wp_error($list)) {
            return [$list->get_error_message()];
        }

        $hook = null;
        foreach (isset($list['webhooks']) && is_array($list['webhooks']) ? $list['webhooks'] : [] as $candidate) {
            if (is_array($candidate) && self::sameUrl((string) ($candidate['url'] ?? ''), $url)) {
                $hook = $candidate;
                break;
            }
        }
        $missing = $hook ? array_diff(Webhook::EVENTS, (array) ($hook['events'] ?? [])) : Webhook::EVENTS;
        $problems = [];
        if ($missing) {
            if (!Settings::liveStore() || !Platform::reachable($url)) {
                // A copy never changes the provider; a private site cannot receive events.
                $problems[] = sprintf(
                    /* translators: %s: webhook URL */
                    __('The key works. The webhook was not set up automatically, because this site is not reachable over public HTTPS or is not confirmed as the live store. Add %s in Revolut and save its signing secret here.', 'stackless-lite'),
                    $url
                );
            } else {
                $saved = $hook && Api::isId((string) ($hook['id'] ?? ''))
                    ? $api->request('PATCH', 'webhooks/' . rawurlencode((string) $hook['id']), ['events' => array_values(array_unique(array_merge((array) ($hook['events'] ?? []), Webhook::EVENTS)))])
                    : $api->request('POST', 'webhooks', ['url' => $url, 'events' => Webhook::EVENTS]);
                if (is_wp_error($saved)) {
                    /* translators: %s: error */
                    $problems[] = sprintf(__('The key works, but the webhook could not be set up: %s', 'stackless-lite'), $saved->get_error_message());
                } else {
                    $hook = $saved;
                }
            }
        }
        $secret = $hook && isset($hook['signing_secret']) && is_string($hook['signing_secret']) ? trim($hook['signing_secret']) : '';
        if ($secret !== '') {
            if (!Settings::isDefined($mode, 'webhook_secret')) {
                if ($secret !== Settings::webhookSecret($mode)) {
                    Settings::saveWebhookSecret($mode, $secret);
                }
            } elseif (!hash_equals($secret, Settings::webhookSecret($mode))) {
                $problems[] = __('The webhook signing secret in wp-config.php is not the one Revolut uses for this site.', 'stackless-lite');
            }
        } elseif (!$problems && Settings::webhookSecret($mode) === '') {
            $problems[] = __('Save the webhook signing secret from Revolut.', 'stackless-lite');
        }
        return $problems;
    }

    private static function sameUrl(string $a, string $b): bool
    {
        return $a !== '' && rtrim(rawurldecode($a), '/') === rtrim(rawurldecode($b), '/');
    }
}
