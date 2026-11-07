<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Billing Health source for PayPal (2.0.0): configuration problems and recent failed or stuck
 * webhook events. Event ids, types and short notes only, never secrets or customer data.
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
        $sources['paypal_gateway'] = [self::class, 'source'];
        return $sources;
    }

    /** @return array{label:string, issues:array<int,array{severity:string,title:string,detail:string,url:string}>, events:array<int,array{id:string,type:string,status:string,note:string,at:string}>} */
    public static function source(): array
    {
        return ['label' => __('PayPal', 'stackless-lite'), 'issues' => self::issues(Gateway::instance()), 'events' => self::events()];
    }

    /** @return array<int,array{severity:string,title:string,detail:string,url:string}> */
    public static function issues(Gateway $gateway): array
    {
        if ($gateway->enabled !== 'yes') {
            return [];
        }
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID);
        $environment = $gateway->environment();
        $issues = [];
        if (!$gateway->api()->hasCredentials()) {
            $issues[] = self::issue('error', sprintf(
                /* translators: %s: sandbox or live */
                __('PayPal is enabled but the %s client ID or secret is missing', 'stackless-lite'),
                $environment
            ), __('PayPal stays hidden at checkout until both are saved for the selected environment.', 'stackless-lite'), $url);
        }
        if ($gateway->webhookId($environment) === '') {
            $issues[] = self::issue('error', sprintf(
                /* translators: %s: sandbox or live */
                __('The PayPal %s webhook ID is missing', 'stackless-lite'),
                $environment
            ), __('Refunds made in PayPal, delayed payments and saved-account changes do not reach WooCommerce without it. Save the PayPal settings to create the webhook, or paste its ID.', 'stackless-lite'), $url);
        }
        if ($environment === 'sandbox' && class_exists(LiveStore::class) && LiveStore::isLive()) {
            $issues[] = self::issue('warning', __('PayPal is in sandbox mode on the live store', 'stackless-lite'), __('Customers cannot pay with real PayPal accounts until sandbox mode is turned off.', 'stackless-lite'), $url);
        }
        if (($gateway->get_option('fastlane', 'no') === 'yes') && !$gateway->cardFieldsEnabled()) {
            $issues[] = self::issue('info', __('Fastlane needs the PayPal card fields', 'stackless-lite'), __('Turn on card fields too, or Fastlane stays hidden.', 'stackless-lite'), $url);
        }
        return $issues;
    }

    /** @return array<int,array{id:string,type:string,status:string,note:string,at:string}> */
    public static function events(): array
    {
        $events = [];
        foreach (WebhookLog::problems(20) as $row) {
            $failed = (string) ($row['status'] ?? '') === 'failed';
            $note = $failed ? (string) ($row['message'] ?? '') : __('Claimed but never finished; PayPal retries it.', 'stackless-lite');
            $events[] = [
                'id' => (string) ($row['event_id'] ?? ''),
                'type' => (string) ($row['event_type'] ?? ''),
                'status' => $failed ? 'failed' : 'stuck',
                'note' => (string) preg_replace('/[^\s@]+@[^\s@]+/', '[email]', $note),
                'at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        return $events;
    }

    /** @return array{severity:string,title:string,detail:string,url:string} */
    private static function issue(string $severity, string $title, string $detail, string $url): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'url' => $url];
    }
}
