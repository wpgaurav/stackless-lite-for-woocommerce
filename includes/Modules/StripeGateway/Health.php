<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Admin\SettingsPage;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Billing Health source and payment-alert route for Stripe and Stripe Managed Payments
 * (2.0.0). Reports configuration problems and recent failed or stuck webhook events: event
 * ids, types and short notes only, never secrets or customer data.
 */
final class Health
{
    /**
     * stackless_billing_health_sources
     *
     * @param mixed $sources
     * @return mixed
     */
    public static function register($sources)
    {
        if (!is_array($sources)) {
            return $sources;
        }
        $sources[Settings::MODULE_ID] = [self::class, 'source'];
        return $sources;
    }

    /**
     * stackless_payment_alerts_gateways: Managed Payments orders and API calls alert like Stripe's.
     *
     * @param mixed $routes
     * @return mixed
     */
    public static function alertRoutes($routes)
    {
        if (!is_array($routes)) {
            return $routes;
        }
        $routes[Managed\Gateway::ID] = [
            'label' => 'Stripe Managed Payments',
            'ids' => [Managed\Gateway::ID],
            'hosts' => ['api.stripe.com'],
            'class' => 'Stackless\\Modules\\StripeGateway\\Managed\\',
            'mode_meta' => Payments::META_MODE,
        ];
        return $routes;
    }

    /** @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array{id:string,type:string,status:string,note:string,at:string}>} */
    public static function source(): array
    {
        return [
            'label' => __('Stripe', 'stackless-lite'),
            'issues' => self::issues(),
            'events' => self::events(),
        ];
    }

    /** @return array<int,array{severity:string,title:string,detail:string,url:string}> */
    public static function issues(): array
    {
        $url = SettingsPage::url(Settings::MODULE_ID);
        $card = self::gatewayEnabled(Settings::GATEWAY_ID);
        $managed = self::gatewayEnabled(Managed\Gateway::ID);
        $mode = Settings::mode();
        $issues = [];
        if (!$card && !$managed) {
            return $issues;
        }
        $label = $mode === 'live' ? __('live', 'stackless-lite') : __('test', 'stackless-lite');

        if (!Settings::isConfigured($mode)) {
            $issues[] = self::issue('error', sprintf(
                /* translators: %s: live or test */
                __('Stripe is enabled but the %s API keys are missing', 'stackless-lite'),
                $label
            ), __('Stripe stays hidden at checkout until both keys for the selected mode are saved.', 'stackless-lite'), $url);
        } else {
            $problem = self::keyModeProblem(Settings::secretKey($mode), Settings::publishableKey($mode), $mode);
            if ($problem !== '') {
                $issues[] = self::issue('error', __('Stripe keys do not match the selected mode', 'stackless-lite'), $problem, $url);
            }
        }
        if (Settings::webhookSecret($mode) === '') {
            $issues[] = self::issue('error', sprintf(
                /* translators: %s: live or test */
                __('The Stripe %s webhook signing secret is missing', 'stackless-lite'),
                $label
            ), $managed
                ? __('Managed Payments renewals, refunds and cancellations only reach WooCommerce through the webhook. Save the Stripe settings to create it, or paste its signing secret.', 'stackless-lite')
                : __('Bank debits, Dashboard refunds, disputes and removed cards do not sync without it. Save the Stripe settings to create it, or paste its signing secret.', 'stackless-lite'), $url);
        } elseif ($managed && get_option(Admin::eventsOption($mode)) !== Admin::eventsHash()) {
            $issues[] = self::issue('warning', __('The Stripe webhook may be missing the Managed Payments events', 'stackless-lite'), __('Save the Stripe settings once so the webhook also sends Checkout, invoice and subscription events.', 'stackless-lite'), $url);
        }
        if ($mode === 'test' && class_exists(LiveStore::class) && LiveStore::isLive()) {
            $issues[] = self::issue('warning', __('Stripe is in test mode on the live store', 'stackless-lite'), __('Customers cannot pay with real cards until test mode is turned off.', 'stackless-lite'), $url);
        }
        $last = get_option(Webhooks::OPTION_LAST);
        if (is_array($last) && !empty($last['mode']) && (string) $last['mode'] !== $mode && (int) ($last['time'] ?? 0) > time() - 7 * DAY_IN_SECONDS) {
            $issues[] = self::issue('info', __('Stripe webhooks arrive in the other mode', 'stackless-lite'), sprintf(
                /* translators: 1: mode of the last webhook, 2: selected mode */
                __('The last signed webhook was a %1$s event while the store runs in %2$s mode. Check that the endpoint for the selected mode exists in Stripe.', 'stackless-lite'),
                (string) $last['mode'],
                $mode
            ), $url);
        }
        return $issues;
    }

    /** Why the keys don't belong to the mode, or ''. */
    public static function keyModeProblem(string $secret, string $publishable, string $mode): string
    {
        $mode = $mode === 'live' ? 'live' : 'test';
        if ($secret !== '' && strpos($secret, 'sk_' . $mode . '_') !== 0 && strpos($secret, 'rk_' . $mode . '_') !== 0) {
            /* translators: %s: expected key prefix */
            return sprintf(__('The secret key for this mode should start with %s.', 'stackless-lite'), 'sk_' . $mode . '_');
        }
        if ($publishable !== '' && strpos($publishable, 'pk_' . $mode . '_') !== 0) {
            /* translators: %s: expected key prefix */
            return sprintf(__('The publishable key for this mode should start with %s.', 'stackless-lite'), 'pk_' . $mode . '_');
        }
        return '';
    }

    /** @return array<int,array{id:string,type:string,status:string,note:string,at:string}> */
    public static function events(): array
    {
        $events = [];
        foreach (WebhookFailures::all() as $row) {
            if (strtotime($row['at'] . ' UTC') < time() - 7 * DAY_IN_SECONDS) {
                continue;
            }
            $events[] = ['id' => $row['id'], 'type' => $row['type'], 'status' => 'failed', 'note' => self::note($row['note']), 'at' => $row['at']];
        }
        foreach (EventStore::stuck(10) as $row) {
            $events[] = [
                'id' => (string) ($row['event_id'] ?? ''),
                'type' => (string) ($row['event_type'] ?? ''),
                'status' => 'stuck',
                'note' => __('Claimed but never finished; Stripe retries it.', 'stackless-lite'),
                'at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        usort($events, static function (array $a, array $b): int {
            return strcmp($b['at'], $a['at']);
        });
        return array_slice($events, 0, 20);
    }

    /** Keeps an order number, drops anything that looks like an email address or a key. */
    private static function note(string $note): string
    {
        $note = (string) preg_replace('/[^\s@]+@[^\s@]+/', '[email]', $note);
        return (string) preg_replace('/\b(sk|rk|pk|whsec)_[A-Za-z0-9_]+/', '[key]', $note);
    }

    private static function gatewayEnabled(string $id): bool
    {
        $settings = get_option('woocommerce_' . $id . '_settings', []);
        return is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes';
    }

    /** @return array{severity:string,title:string,detail:string,url:string} */
    private static function issue(string $severity, string $title, string $detail, string $url): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'url' => $url];
    }
}
