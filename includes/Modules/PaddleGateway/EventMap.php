<?php

namespace Stackless\Modules\PaddleGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Paddle Billing webhook events and objects to STACKLESS behavior.
 */
final class EventMap
{
    /** Events to subscribe to in Paddle → Developer tools → Notifications. */
    public const EVENTS = [
        'transaction.paid',
        'transaction.completed',
        'transaction.payment_failed',
        'subscription.created',
        'subscription.activated',
        'subscription.trialing',
        'subscription.updated',
        'subscription.past_due',
        'subscription.paused',
        'subscription.resumed',
        'subscription.canceled',
        'adjustment.created',
        'adjustment.updated',
    ];

    /** Handler name in Sync for an event type, or null to acknowledge and ignore. */
    public static function handler(string $type): ?string
    {
        switch ($type) {
            case 'transaction.paid':
            case 'transaction.completed':
                return 'transaction';
            case 'transaction.payment_failed':
                return 'paymentFailed';
            case 'adjustment.created':
            case 'adjustment.updated':
                return 'adjustment';
            default:
                return strpos($type, 'subscription.') === 0 ? 'subscription' : null;
        }
    }

    /**
     * STACKLESS subscription status for a Paddle subscription object.
     *
     * Paddle keeps a subscription "active" until the paid period ends when a
     * cancellation is scheduled; STACKLESS shows that as pending-cancel.
     *
     * @return string|null SubscriptionData::STATUS_* slug, or null to leave the status alone.
     */
    public static function status(array $remote): ?string
    {
        $cancelScheduled = self::cancelScheduled($remote);
        switch ((string) ($remote['status'] ?? '')) {
            case 'trialing':
                return $cancelScheduled ? SubscriptionData::STATUS_PENDING_CANCEL : SubscriptionData::STATUS_TRIAL;
            case 'active':
                return $cancelScheduled ? SubscriptionData::STATUS_PENDING_CANCEL : SubscriptionData::STATUS_ACTIVE;
            case 'past_due':
            case 'paused':
                return SubscriptionData::STATUS_ON_HOLD;
            case 'canceled':
                return SubscriptionData::STATUS_CANCELLED;
            default:
                return null; // A status this version doesn't know.
        }
    }

    public static function cancelScheduled(array $remote): bool
    {
        return (string) ($remote['scheduled_change']['action'] ?? '') === 'cancel';
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [SubscriptionData::STATUS_CANCELLED, SubscriptionData::STATUS_EXPIRED], true);
    }

    /** Whether a Paddle transaction has been paid. */
    public static function isPaid(array $transaction): bool
    {
        return in_array($transaction['status'] ?? '', ['paid', 'completed'], true);
    }

    /** first|renewal|other for a paid Paddle transaction. */
    public static function transactionKind(array $transaction): string
    {
        $origin = (string) ($transaction['origin'] ?? 'api');
        if ($origin === 'subscription_recurring') {
            return 'renewal';
        }
        return in_array($origin, ['api', 'web'], true) ? 'first' : 'other';
    }

    /** WooCommerce billing period/interval → Paddle billing cycle, or null when Paddle can't bill it. @return array{interval:string,frequency:int}|null */
    public static function cycle(string $period, int $interval): ?array
    {
        $period = strtolower($period);
        if (!in_array($period, ['day', 'week', 'month', 'year'], true) || $interval < 1) {
            return null;
        }
        return ['interval' => $period, 'frequency' => $interval];
    }

    /** Next date Paddle will charge, or '' when nothing more is due. */
    public static function nextCharge(array $remote): string
    {
        if (self::cancelScheduled($remote) || in_array($remote['status'] ?? '', ['canceled', 'paused'], true)) {
            return '';
        }
        return (string) ($remote['next_billed_at'] ?? '');
    }

    /** Short reason for a Paddle adjustment from a free-text WooCommerce refund reason. */
    public static function refundReason(string $reason): string
    {
        $reason = trim(wp_strip_all_tags($reason));
        return $reason === '' ? 'Refund requested by the store' : (function_exists('mb_substr') ? mb_substr($reason, 0, 240) : substr($reason, 0, 240));
    }
}
