<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Paystack webhook events and objects to Stackless behavior.
 */
final class EventMap
{
    /** Events this gateway acts on. Paystack sends every event to the one webhook URL. */
    public const EVENTS = [
        'charge.success',
        'refund.processed',
        'refund.failed',
        'refund.needs-attention',
        'charge.dispute.create',
    ];

    /** Handler name in Sync for an event type, or null to acknowledge and ignore. */
    public static function handler(string $event): ?string
    {
        switch ($event) {
            case 'charge.success':
                return 'payment';
            case 'refund.processed':
            case 'refund.failed':
            case 'refund.needs-attention':
                return 'refund';
            case 'charge.dispute.create':
            case 'charge.dispute.remind':
            case 'charge.dispute.resolve':
                return 'dispute';
            default:
                return null;
        }
    }

    /**
     * Replay key for a delivery, or '' when deliveries of this event are never deduplicated.
     *
     * Paystack sends no event id. A charge is identified by its transaction id. Refund events
     * carry no refund id, so two refunds of the same amount look alike; they are not deduped
     * here but reconciled against Paystack's refund list, which is safe to repeat.
     */
    public static function eventKey(string $event, array $data): string
    {
        if (self::handler($event) === 'refund') {
            return '';
        }
        $id = self::resourceId($data);
        return $id === '' ? '' : $event . ':' . $id;
    }

    /** The id of the object an event carries, for the webhook log. */
    public static function resourceId(array $data): string
    {
        foreach (['id', 'reference', 'transaction_reference'] as $key) {
            if (isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key])) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }
        return '';
    }

    /** paid|failed|pending for a verified Paystack transaction's status. */
    public static function transactionState(string $status): string
    {
        switch (strtolower($status)) {
            case 'success':
                return 'paid';
            case 'failed':
            case 'abandoned':
            case 'reversed':
                return 'failed';
            default:
                return 'pending'; // ongoing, pending, processing, queued
        }
    }

    /** done|failed|pending for a Paystack refund status. */
    public static function refundState(string $status): string
    {
        switch (strtolower($status)) {
            case 'processed':
                return 'done';
            case 'failed':
            case 'needs-attention':
                return 'failed';
            default:
                return 'pending';
        }
    }

    /** test|live for a Paystack secret or restricted key, '' when the prefix is unknown. */
    public static function keyMode(string $key): string
    {
        $key = trim($key);
        foreach (['sk_', 'rk_', 'pk_'] as $prefix) {
            if (strpos($key, $prefix . 'test_') === 0) {
                return 'test';
            }
            if (strpos($key, $prefix . 'live_') === 0) {
                return 'live';
            }
        }
        return '';
    }

    /**
     * Paystack refund reference: 8–64 letters, digits, "_" and "-", unique per environment. The
     * same refund retried after a timeout gets the same reference (Paystack answers 409 instead
     * of paying twice); a different amount gets a new one.
     */
    public static function refundReference(int $orderId, int $refundCount, int $minor, string $site): string
    {
        return 'wc' . $orderId . '-r' . max(1, $refundCount) . '-' . max(0, $minor) . '-' . substr(md5($site), 0, 8);
    }
}
