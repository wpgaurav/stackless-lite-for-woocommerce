<?php

namespace Stackless\Modules\PolarGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Polar webhook events and objects to STACKLESS behavior.
 */
final class EventMap
{
    /** Events to subscribe to in Polar → Settings → Webhooks. */
    public const EVENTS = [
        'order.paid',
        'order.updated',
        'order.refunded',
        'refund.created',
        'refund.updated',
        'subscription.created',
        'subscription.updated',
        'subscription.active',
        'subscription.canceled',
        'subscription.uncanceled',
        'subscription.revoked',
        'subscription.past_due',
    ];

    /** Handler name in Sync for an event type, or null to acknowledge and ignore. */
    public static function handler(string $type): ?string
    {
        switch ($type) {
            case 'order.paid':
            case 'order.updated':
                return 'order';
            case 'order.refunded':
                return 'orderRefunded';
            case 'refund.created':
            case 'refund.updated':
                return 'refund';
            default:
                return strpos($type, 'subscription.') === 0 ? 'subscription' : null;
        }
    }

    /**
     * STACKLESS subscription status for a Polar subscription object.
     *
     * Polar keeps a subscription "active" until the paid period ends when it is set to
     * cancel at period end; STACKLESS shows that as pending-cancel.
     *
     * @return string|null SubscriptionData::STATUS_* slug, or null to leave the status alone.
     */
    public static function status(array $remote, string $event = ''): ?string
    {
        if ($event === 'subscription.revoked') {
            return SubscriptionData::STATUS_CANCELLED;
        }
        $cancelAtEnd = !empty($remote['cancel_at_period_end']);
        switch ((string) ($remote['status'] ?? '')) {
            case 'trialing':
                return $cancelAtEnd ? SubscriptionData::STATUS_PENDING_CANCEL : SubscriptionData::STATUS_TRIAL;
            case 'active':
                return $cancelAtEnd ? SubscriptionData::STATUS_PENDING_CANCEL : SubscriptionData::STATUS_ACTIVE;
            case 'past_due':
                return SubscriptionData::STATUS_ON_HOLD;
            case 'canceled':
                return SubscriptionData::STATUS_CANCELLED;
            case 'unpaid':
            case 'incomplete_expired':
                return SubscriptionData::STATUS_EXPIRED;
            default:
                return null; // incomplete, or a status this version doesn't know.
        }
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [SubscriptionData::STATUS_CANCELLED, SubscriptionData::STATUS_EXPIRED], true);
    }

    /** Whether a Polar order counts as paid. */
    public static function isPaid(array $polarOrder): bool
    {
        return !empty($polarOrder['paid']) || in_array($polarOrder['status'] ?? '', ['paid', 'refunded', 'partially_refunded'], true);
    }

    /** first|renewal|other for a paid Polar order. */
    public static function orderKind(array $polarOrder): string
    {
        $reason = (string) ($polarOrder['billing_reason'] ?? 'purchase');
        if ($reason === 'subscription_cycle') {
            return 'renewal';
        }
        return in_array($reason, ['purchase', 'subscription_create'], true) ? 'first' : 'other';
    }

    /** Polar refund reason for a free-text WooCommerce refund reason. */
    public static function refundReason(string $reason): string
    {
        $allowed = ['duplicate', 'fraudulent', 'customer_request', 'service_disruption', 'satisfaction_guarantee', 'other'];
        if (in_array($reason, $allowed, true)) {
            return $reason;
        }
        return trim($reason) === '' ? 'customer_request' : 'other';
    }

    /** WooCommerce billing period/interval → Polar recurring interval, or null when Polar can't bill it. @return array{0:string,1:int}|null */
    public static function interval(string $period, int $interval): ?array
    {
        $period = strtolower($period);
        if (!in_array($period, ['day', 'week', 'month', 'year'], true) || $interval < 1) {
            return null;
        }
        return [$period, $interval];
    }
}
