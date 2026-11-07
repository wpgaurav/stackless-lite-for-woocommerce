<?php

namespace Stackless\Modules\StripeGateway\Managed;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Stripe Checkout and Billing objects (Managed Payments) to Stackless
 * behavior. Reads both the pre-basil shapes and the 2025-03-31.basil ones, because Stripe
 * moved several fields in that version (invoice.subscription → invoice.parent, subscription
 * current_period_end → its items, invoice.payment_intent → invoice.payments).
 */
final class EventMap
{
    /** Handler in Events for an event type, or null to acknowledge and ignore. */
    public static function handler(string $type): ?string
    {
        switch ($type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
                return 'session';
            case 'invoice.paid':
                return 'invoicePaid';
            case 'invoice.payment_failed':
                return 'invoiceFailed';
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
            case 'customer.subscription.paused':
            case 'customer.subscription.resumed':
                return 'subscription';
        }
        return null;
    }

    /**
     * Stackless subscription status for a Stripe subscription.
     *
     * A cancellation scheduled for the period end shows as pending-cancel; pause_collection
     * shows as paused (Stripe keeps the status active while collection is paused).
     *
     * @return string|null SubscriptionData::STATUS_* slug, or null to leave the status alone.
     */
    public static function status(array $remote): ?string
    {
        $status = (string) ($remote['status'] ?? '');
        if ($status === 'canceled' || $status === 'incomplete_expired') {
            return SubscriptionData::STATUS_CANCELLED;
        }
        if (in_array($status, ['active', 'trialing'], true) && self::collectionPaused($remote)) {
            return SubscriptionData::STATUS_PAUSED;
        }
        switch ($status) {
            case 'trialing':
                return self::cancelScheduled($remote) ? SubscriptionData::STATUS_PENDING_CANCEL : SubscriptionData::STATUS_TRIAL;
            case 'active':
                return self::cancelScheduled($remote) ? SubscriptionData::STATUS_PENDING_CANCEL : SubscriptionData::STATUS_ACTIVE;
            case 'past_due':
            case 'unpaid':
            case 'paused':
                return SubscriptionData::STATUS_ON_HOLD;
            default:
                return null; // incomplete, or a status this version doesn't know.
        }
    }

    public static function cancelScheduled(array $remote): bool
    {
        return !empty($remote['cancel_at_period_end']) || (!empty($remote['cancel_at']) && ($remote['status'] ?? '') !== 'canceled');
    }

    public static function collectionPaused(array $remote): bool
    {
        return isset($remote['pause_collection']) && is_array($remote['pause_collection']) && !empty($remote['pause_collection']['behavior']);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [SubscriptionData::STATUS_CANCELLED, SubscriptionData::STATUS_EXPIRED], true);
    }

    /** first|renewal|other for an invoice, from billing_reason. */
    public static function invoiceKind(array $invoice): string
    {
        switch ((string) ($invoice['billing_reason'] ?? '')) {
            case 'subscription_create':
                return 'first';
            case 'subscription_cycle':
                return 'renewal';
            default:
                return 'other';
        }
    }

    /** Subscription id of an invoice (basil: parent.subscription_details.subscription). */
    public static function invoiceSubscription(array $invoice): string
    {
        $parent = (array) ($invoice['parent'] ?? []);
        if (($parent['type'] ?? '') === 'subscription_details' && !empty($parent['subscription_details']['subscription'])) {
            return self::idOf($parent['subscription_details']['subscription']);
        }
        return self::idOf($invoice['subscription'] ?? '');
    }

    /** Metadata of the subscription an invoice belongs to (copied by Stripe onto the invoice). @return array<string,string> */
    public static function invoiceSubscriptionMetadata(array $invoice): array
    {
        $parent = (array) ($invoice['parent'] ?? []);
        $metadata = $parent['subscription_details']['metadata'] ?? ($invoice['subscription_details']['metadata'] ?? []);
        return is_array($metadata) ? array_map('strval', $metadata) : [];
    }

    /** PaymentIntent that paid an invoice (basil: payments.data[].payment.payment_intent). */
    public static function invoicePaymentIntent(array $invoice): string
    {
        $direct = self::idOf($invoice['payment_intent'] ?? '');
        if ($direct !== '') {
            return $direct;
        }
        $payments = (array) ($invoice['payments']['data'] ?? []);
        $fallback = '';
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $intent = self::idOf($payment['payment']['payment_intent'] ?? '');
            if ($intent === '') {
                continue;
            }
            if (($payment['status'] ?? '') === 'paid') {
                return $intent;
            }
            $fallback = $fallback !== '' ? $fallback : $intent;
        }
        return $fallback;
    }

    /** Unix time the current period ends (basil keeps it on the subscription items). */
    public static function periodEnd(array $remote): int
    {
        if (!empty($remote['current_period_end'])) {
            return (int) $remote['current_period_end'];
        }
        $end = 0;
        foreach ((array) ($remote['items']['data'] ?? []) as $item) {
            if (is_array($item) && !empty($item['current_period_end'])) {
                $end = max($end, (int) $item['current_period_end']);
            }
        }
        return $end;
    }

    /** Next date Stripe will charge (Unix time), or 0 when nothing more is due. */
    public static function nextCharge(array $remote): int
    {
        $status = (string) ($remote['status'] ?? '');
        if (self::cancelScheduled($remote) || self::collectionPaused($remote) || !in_array($status, ['active', 'trialing', 'past_due'], true)) {
            return 0;
        }
        if ($status === 'trialing' && !empty($remote['trial_end'])) {
            return (int) $remote['trial_end'];
        }
        return self::periodEnd($remote);
    }

    /** Decline code from an invoice's last finalization or payment error, if any. */
    public static function invoiceDeclineCode(array $invoice): string
    {
        $error = (array) ($invoice['last_finalization_error'] ?? []);
        return (string) ($error['decline_code'] ?? '') ?: (string) ($error['code'] ?? '');
    }

    /**
     * Checkout Session ui_mode for a Stripe-Version: "embedded" until 2026-03-25.dahlia
     * renamed it to "embedded_page".
     */
    public static function uiMode(string $apiVersion): string
    {
        return strcmp(substr($apiVersion, 0, 10), '2026-03-25') >= 0 ? 'embedded_page' : 'embedded';
    }

    /** @param mixed $value Expandable field: id string or object. */
    public static function idOf($value): string
    {
        if (is_array($value)) {
            return (string) ($value['id'] ?? '');
        }
        return is_string($value) ? $value : '';
    }
}
