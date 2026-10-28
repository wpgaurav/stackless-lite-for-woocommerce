<?php

namespace Stackless\Modules\DodoGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Dodo Payments webhook events and objects to Stackless behavior.
 */
final class EventMap
{
    /** Events to subscribe to in Dodo → Developer → Webhooks. */
    public const EVENTS = [
        'payment.succeeded',
        'refund.succeeded',
        'refund.failed',
        'subscription.active',
        'subscription.updated',
        'subscription.renewed',
        'subscription.on_hold',
        'subscription.paused',
        'subscription.cancelled',
        'subscription.failed',
        'subscription.expired',
        'dispute.opened',
        'dispute.lost',
    ];

    /** Handler name in Sync for an event type, or null to acknowledge and ignore. */
    public static function handler(string $type): ?string
    {
        switch ($type) {
            case 'payment.succeeded':
                return 'payment';
            case 'refund.succeeded':
            case 'refund.failed':
                return 'refund';
            case 'subscription.renewed':
                return 'renewed';
            case 'dispute.opened':
            case 'dispute.lost':
                return 'dispute';
            default:
                return strpos($type, 'subscription.') === 0 ? 'subscription' : null;
        }
    }

    /** The id of the object an event carries, for the webhook log. */
    public static function resourceId(array $data): string
    {
        foreach (['payment_id', 'refund_id', 'subscription_id', 'dispute_id'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }
        return '';
    }

    /** Dodo reports a trialing subscription as active; it is in trial until created_at + trial days. */
    public static function isTrialing(array $remote, ?int $now = null): bool
    {
        $days = (int) ($remote['trial_period_days'] ?? 0);
        $created = strtotime((string) ($remote['created_at'] ?? ''));
        return ($remote['status'] ?? '') === 'active' && $days > 0 && $created && $created + $days * 86400 > ($now ?? time());
    }

    /**
     * Stackless subscription status for a Dodo subscription object.
     *
     * @return string|null SubscriptionData::STATUS_* slug, or null to leave the status alone.
     */
    public static function status(array $remote, ?int $now = null): ?string
    {
        $cancelAtEnd = !empty($remote['cancel_at_next_billing_date']);
        switch ((string) ($remote['status'] ?? '')) {
            case 'active':
                if ($cancelAtEnd) {
                    return SubscriptionData::STATUS_PENDING_CANCEL;
                }
                return self::isTrialing($remote, $now) ? SubscriptionData::STATUS_TRIAL : SubscriptionData::STATUS_ACTIVE;
            case 'on_hold':
            case 'past_due':
            case 'paused':
                return SubscriptionData::STATUS_ON_HOLD;
            case 'cancelled':
                return SubscriptionData::STATUS_CANCELLED;
            case 'failed':
            case 'expired':
                return SubscriptionData::STATUS_EXPIRED;
            default:
                return null; // pending, or a status this version doesn't know.
        }
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [SubscriptionData::STATUS_CANCELLED, SubscriptionData::STATUS_EXPIRED], true);
    }

    /** first|renewal|other for a succeeded Dodo payment. */
    public static function paymentKind(array $payment): string
    {
        $type = (string) ($payment['charge_type'] ?? 'new');
        if ($type === 'renewal') {
            return 'renewal';
        }
        // Plan changes and on-demand charges are started in Dodo, not here.
        return in_array($type, ['new', ''], true) ? 'first' : 'other';
    }

    /** WooCommerce billing period/interval → Dodo payment frequency, or null when Dodo can't bill it. @return array{0:string,1:int}|null */
    public static function interval(string $period, int $interval): ?array
    {
        $map = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];
        $period = strtolower($period);
        if (!isset($map[$period]) || $interval < 1) {
            return null;
        }
        return [$map[$period], $interval];
    }
}
