<?php

namespace Stackless\Modules\CreemGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Creem webhook events and objects to Stackless behavior.
 */
final class EventMap
{
    /** Events the webhook endpoint subscribes to. */
    public const EVENTS = [
        'checkout.completed',
        'subscription.active',
        'subscription.trialing',
        'subscription.paid',
        'subscription.update',
        'subscription.scheduled_cancel',
        'subscription.canceled',
        'subscription.past_due',
        'subscription.unpaid',
        'subscription.paused',
        'subscription.expired',
        'refund.created',
        'dispute.created',
    ];

    /** Handler name in Sync for an event type, or null to acknowledge and ignore. */
    public static function handler(string $type): ?string
    {
        switch ($type) {
            case 'checkout.completed':
                return 'checkout';
            case 'subscription.paid':
                return 'paid';
            case 'subscription.past_due':
            case 'subscription.unpaid':
                return 'failed';
            case 'refund.created':
                return 'refund';
            case 'dispute.created':
                return 'dispute';
            default:
                return strpos($type, 'subscription.') === 0 ? 'subscription' : null;
        }
    }

    /** The id of an object Creem sends, whether it is expanded or just an id. @param mixed $value */
    public static function id($value): string
    {
        if (is_array($value)) {
            return isset($value['id']) && is_scalar($value['id']) ? (string) $value['id'] : '';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Stackless subscription status for a Creem subscription object.
     *
     * @return string|null SubscriptionData::STATUS_* slug, or null to leave the status alone.
     */
    public static function status(array $remote, string $event = ''): ?string
    {
        if ($event === 'subscription.expired') {
            return SubscriptionData::STATUS_EXPIRED;
        }
        switch ((string) ($remote['status'] ?? '')) {
            case 'active':
                return SubscriptionData::STATUS_ACTIVE;
            case 'trialing':
                return SubscriptionData::STATUS_TRIAL;
            case 'scheduled_cancel':
                return SubscriptionData::STATUS_PENDING_CANCEL;
            case 'past_due':
            case 'unpaid':
            case 'paused':
                return SubscriptionData::STATUS_ON_HOLD;
            case 'canceled':
                return SubscriptionData::STATUS_CANCELLED;
            default:
                return null;
        }
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [SubscriptionData::STATUS_CANCELLED, SubscriptionData::STATUS_EXPIRED], true);
    }

    /**
     * Decline category for a failed renewal. Creem gives no decline reason: past_due means it
     * will try the card again, unpaid that its own retries ran out.
     *
     * @return array{0:string,1:string} [SubscriptionData::DECLINE_*, provider code]
     */
    public static function decline(string $event): array
    {
        return $event === 'subscription.unpaid'
            ? [SubscriptionData::DECLINE_HARD, 'unpaid']
            : [SubscriptionData::DECLINE_SOFT, 'past_due'];
    }

    /**
     * WooCommerce billing period/interval → Creem product billing fields, or null when Creem
     * cannot bill it. Creem's presets are used where they exist; anything else is a custom
     * interval (days up to 365, weeks up to 52, months up to 24, years up to 3).
     *
     * @return array<string,string|int>|null
     */
    public static function interval(string $period, int $interval): ?array
    {
        $period = strtolower($period);
        $presets = ['day:1' => 'every-day', 'month:1' => 'every-month', 'month:3' => 'every-three-months', 'month:6' => 'every-six-months', 'year:1' => 'every-year'];
        if (isset($presets[$period . ':' . $interval])) {
            return ['billing_period' => $presets[$period . ':' . $interval]];
        }
        $limits = ['day' => 365, 'week' => 52, 'month' => 24, 'year' => 3];
        if (!isset($limits[$period]) || $interval < 1 || $interval > $limits[$period]) {
            return null;
        }
        return ['billing_period' => 'custom', 'recurring_interval' => $period, 'recurring_interval_count' => $interval];
    }

    /** Short, stable key for a billing schedule, used in catalog keys. @param array<string,string|int> $billing */
    public static function intervalKey(array $billing): string
    {
        return (string) ($billing['billing_period'] ?? '') . (isset($billing['recurring_interval']) ? ':' . $billing['recurring_interval'] . ':' . (int) $billing['recurring_interval_count'] : '');
    }

    /** test|live for a Creem API key, '' when the prefix is unknown. */
    public static function keyMode(string $key): string
    {
        $key = trim($key);
        if (strpos($key, 'creem_test_') === 0) {
            return 'test';
        }
        return strpos($key, 'creem_') === 0 ? 'live' : '';
    }
}
