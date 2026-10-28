<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Settings-tab extras: key validation on save (a real API call per mode), connection and
 * webhook status, and the recent webhook log.
 */
final class Admin
{
    private const NOTICE_TRANSIENT = 'stackless_stripe_gateway_notices_';

    public static function registerHooks(): void
    {
        add_action('stackless_settings_saved_' . Settings::MODULE_ID, [self::class, 'validateKeys']);
        add_action('stackless_settings_after_' . Settings::MODULE_ID, [self::class, 'renderStatus']);
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('woocommerce_admin_settings_sanitize_option_' . Settings::PREFIX . 'statement_descriptor_suffix', [Settings::class, 'sanitizeDescriptorSuffix']);
        foreach (['test_secret_key', 'live_secret_key', 'test_publishable_key', 'live_publishable_key', 'test_webhook_secret', 'live_webhook_secret'] as $key) {
            add_filter('woocommerce_admin_settings_sanitize_option_' . Settings::PREFIX . $key, [self::class, 'sanitizeKey']);
        }
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function sanitizeKey($value)
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', (string) $value);
    }

    public static function validateKeys(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $notices = [];
        foreach (['test', 'live'] as $mode) {
            $secret = Settings::secretKey($mode);
            $publishable = Settings::publishableKey($mode);
            $webhook = Settings::webhookSecret($mode);
            $label = $mode === 'live' ? __('Live', 'stackless-lite') : __('Test', 'stackless-lite');

            if ($secret === '' && $publishable === '') {
                delete_option(Settings::PREFIX . 'account_' . $mode);
                if (Settings::mode() === $mode) {
                    /* translators: %s: Test or Live */
                    $notices[] = ['error', sprintf(__('%s mode is selected but no API keys are entered. Stripe stays hidden at checkout until you add them.', 'stackless-lite'), $label)];
                }
                continue;
            }
            if ($secret === '' || $publishable === '') {
                /* translators: %s: Test or Live */
                $notices[] = ['error', sprintf(__('%s mode needs both the publishable key and the secret key.', 'stackless-lite'), $label)];
                continue;
            }
            if (strpos($secret, 'sk_' . $mode . '_') !== 0 && strpos($secret, 'rk_' . $mode . '_') !== 0) {
                /* translators: 1: Test or Live, 2: expected prefix */
                $notices[] = ['error', sprintf(__('The %1$s secret key should start with %2$s.', 'stackless-lite'), $label, 'sk_' . $mode . '_')];
                continue;
            }
            if (strpos($publishable, 'pk_' . $mode . '_') !== 0) {
                /* translators: 1: Test or Live, 2: expected prefix */
                $notices[] = ['error', sprintf(__('The %1$s publishable key should start with %2$s.', 'stackless-lite'), $label, 'pk_' . $mode . '_')];
                continue;
            }
            if ($webhook !== '' && strpos($webhook, 'whsec_') !== 0) {
                /* translators: %s: Test or Live */
                $notices[] = ['error', sprintf(__('The %s webhook signing secret should start with whsec_.', 'stackless-lite'), $label)];
            }

            $client = new StripeClient($secret, $mode);
            $pendingWebhook = $webhook === ''; // created once the key check below succeeds
            try {
                $account = $client->get('account');
                update_option(Settings::PREFIX . 'account_' . $mode, [
                    'id' => (string) ($account['id'] ?? ''),
                    'name' => (string) ($account['settings']['dashboard']['display_name'] ?? $account['business_profile']['name'] ?? ''),
                    'country' => (string) ($account['country'] ?? ''),
                    'currency' => strtoupper((string) ($account['default_currency'] ?? '')),
                    'charges_enabled' => !empty($account['charges_enabled']),
                    'checked' => time(),
                ], false);
                if (empty($account['charges_enabled']) && $mode === 'live') {
                    $notices[] = ['warning', __('Stripe accepted the live keys, but the account cannot take charges yet. Finish activation in the Stripe Dashboard.', 'stackless-lite')];
                } else {
                    /* translators: 1: Test or Live, 2: Stripe account id */
                    $notices[] = ['success', sprintf(__('%1$s keys verified with Stripe (account %2$s).', 'stackless-lite'), $label, (string) ($account['id'] ?? ''))];
                }
                if ($pendingWebhook) {
                    $notices[] = self::createWebhook($client, $mode, $label);
                }
            } catch (StripeApiException $e) {
                if ($e->getHttpStatus() === 403 || $e->getStripeCode() === 'secret_key_required' || ($e->getStripeType() === 'invalid_request_error' && strpos($secret, 'rk_') === 0)) {
                    // Restricted keys may not read the account object; a balance read proves the key works.
                    try {
                        $client->get('balance');
                        /* translators: %s: Test or Live */
                        $notices[] = ['success', sprintf(__('%s restricted key verified with Stripe.', 'stackless-lite'), $label)];
                        if ($pendingWebhook) {
                            $notices[] = self::createWebhook($client, $mode, $label);
                        }
                        continue;
                    } catch (StripeApiException $inner) {
                        $e = $inner;
                    }
                }
                delete_option(Settings::PREFIX . 'account_' . $mode);
                /* translators: 1: Test or Live, 2: Stripe error message */
                $notices[] = ['error', sprintf(__('Stripe rejected the %1$s secret key: %2$s', 'stackless-lite'), $label, $e->getMessage())];
            }
        }
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), $notices, 120);
    }

    /**
     * Create this site's webhook endpoint in Stripe and save its signing secret. Stripe shows the
     * secret only when the endpoint is created, so an endpoint that already exists for this URL is
     * left alone and the admin is pointed at it instead.
     *
     * @return array{0:string,1:string} Notice type and message.
     */
    public static function createWebhook(StripeClient $client, string $mode, string $label): array
    {
        $url = Settings::webhookUrl();
        $dashboard = 'https://dashboard.stripe.com/' . ($mode === 'test' ? 'test/' : '') . 'webhooks';
        $missing = sprintf(
            /* translators: %s: Test or Live */
            __('Add the %s webhook signing secret. Without webhooks, bank debits, Dashboard refunds, disputes and removed cards will not sync.', 'stackless-lite'),
            $label
        );
        if (!\Stackless\Core\WebhookSetup::reachable($url)) {
            return ['warning', $missing . ' ' . \Stackless\Core\WebhookSetup::unreachableNote()];
        }
        try {
            $existing = $client->get('webhook_endpoints', ['limit' => 100]);
            foreach ((array) ($existing['data'] ?? []) as $endpoint) {
                if (is_array($endpoint) && (string) ($endpoint['url'] ?? '') === $url) {
                    return ['warning', sprintf(
                        /* translators: 1: Test or Live, 2: link to the endpoint in Stripe */
                        __('Stripe already has a %1$s webhook for this site. Open it in %2$s, reveal its signing secret and paste it here.', 'stackless-lite'),
                        strtolower($label),
                        '<a href="' . esc_url($dashboard . '/' . rawurlencode((string) ($endpoint['id'] ?? ''))) . '" target="_blank" rel="noopener">' . esc_html__('Stripe', 'stackless-lite') . '</a>'
                    )];
                }
            }
            $endpoint = $client->post('webhook_endpoints', [
                'url' => $url,
                'enabled_events' => Settings::webhookEvents(),
                'description' => 'Stackless for WooCommerce',
                'metadata' => ['stackless_site' => Settings::siteMarker()],
            ]);
        } catch (StripeApiException $e) {
            /* translators: %s: Stripe error message */
            return ['warning', $missing . ' ' . sprintf(__('Creating it automatically failed: %s', 'stackless-lite'), $e->getMessage())];
        }
        $secret = (string) ($endpoint['secret'] ?? '');
        if (strpos($secret, 'whsec_') !== 0) {
            return ['warning', $missing];
        }
        update_option(Settings::PREFIX . $mode . '_webhook_secret', $secret);
        /* translators: %s: Test or Live */
        return ['success', sprintf(__('%s webhook created in Stripe and its signing secret saved.', 'stackless-lite'), $label)];
    }

    public static function notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'stackless') === false || !current_user_can('manage_woocommerce')) {
            return;
        }
        $key = self::NOTICE_TRANSIENT . get_current_user_id();
        $notices = get_transient($key);
        if (!is_array($notices)) {
            return;
        }
        delete_transient($key);
        foreach ($notices as $notice) {
            // Messages may carry a link to the Stripe Dashboard; nothing else survives.
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr((string) $notice[0]), wp_kses((string) $notice[1], ['a' => ['href' => [], 'target' => [], 'rel' => []], 'code' => []]));
        }
    }

    public static function renderStatus(): void
    {
        $gateway_settings = (array) get_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', []);
        $enabled = ($gateway_settings['enabled'] ?? 'no') === 'yes';
        $payments_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Settings::GATEWAY_ID);
        $last = get_option(Webhooks::OPTION_LAST);

        echo '<h2>' . esc_html__('Status', 'stackless-lite') . '</h2>';
        echo '<table class="form-table stackless-stripe-status"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Checkout', 'stackless-lite') . '</th><td>';
        echo $enabled
            ? esc_html__('Enabled at checkout.', 'stackless-lite') . ' '
            : '<strong>' . esc_html__('Disabled.', 'stackless-lite') . '</strong> ';
        printf('<a href="%s">%s</a>', esc_url($payments_url), esc_html__('Turn on or off in WooCommerce → Settings → Payments', 'stackless-lite'));
        echo '</td></tr>';

        foreach (['test', 'live'] as $mode) {
            $account = get_option(Settings::PREFIX . 'account_' . $mode);
            echo '<tr><th scope="row">' . esc_html($mode === 'live' ? __('Live account', 'stackless-lite') : __('Test account', 'stackless-lite')) . '</th><td>';
            if (is_array($account) && !empty($account['id'])) {
                printf(
                    '%s <code>%s</code> %s',
                    esc_html((string) ($account['name'] ?: __('Connected', 'stackless-lite'))),
                    esc_html((string) $account['id']),
                    esc_html(trim(($account['country'] ?? '') . ' ' . ($account['currency'] ?? '')))
                );
            } else {
                esc_html_e('Not verified. Save valid keys to check the connection.', 'stackless-lite');
            }
            echo '</td></tr>';
        }

        echo '<tr><th scope="row">' . esc_html__('Webhook endpoint', 'stackless-lite') . '</th><td>';
        printf('<input type="text" class="large-text code" readonly value="%s" onclick="this.select()">', esc_attr(Settings::webhookUrl()));
        echo '<p class="description">' . esc_html__('In Stripe → Developers → Webhooks, add an endpoint with this URL for test mode and again for live mode, select these events, then paste each signing secret above:', 'stackless-lite') . '</p>';
        echo '<p><code>' . implode('</code> <code>', array_map('esc_html', Settings::webhookEvents())) . '</code></p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Last webhook', 'stackless-lite') . '</th><td>';
        if (is_array($last) && !empty($last['time'])) {
            /* translators: 1: event type, 2: mode, 3: time ago */
            printf(esc_html__('%1$s (%2$s) %3$s ago', 'stackless-lite'), esc_html((string) $last['type']), esc_html((string) $last['mode']), esc_html(human_time_diff((int) $last['time'])));
        } else {
            esc_html_e('No signed webhook received yet.', 'stackless-lite');
        }
        $recent = EventStore::recent(8);
        if ($recent) {
            echo '<table class="widefat striped" style="margin-top:8px;max-width:900px"><thead><tr><th>' . esc_html__('Event', 'stackless-lite') . '</th><th>' . esc_html__('Type', 'stackless-lite') . '</th><th>' . esc_html__('Result', 'stackless-lite') . '</th><th>' . esc_html__('Received (UTC)', 'stackless-lite') . '</th></tr></thead><tbody>';
            foreach ($recent as $row) {
                printf(
                    '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html((string) $row['event_id']),
                    esc_html((string) $row['event_type']),
                    esc_html(trim((string) $row['status'] . ' ' . (string) $row['note'])),
                    esc_html((string) $row['created_at'])
                );
            }
            echo '</tbody></table>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';
    }
}
