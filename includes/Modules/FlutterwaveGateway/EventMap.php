<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Flutterwave v3 webhooks and objects to Stackless behavior.
 */
final class EventMap
{
    /** Deliveries this gateway acts on: charges, and refunds once "Enable webhook for refunds" is on. */
    public const EVENTS = ['charge.completed', 'refund'];

    /**
     * Normalize a delivery to [event, data]. Charge events are {event, data}; v3 refund
     * webhooks are a flat refund object with no event name.
     *
     * @return array{0:string,1:array}
     */
    public static function normalize(array $payload): array
    {
        if (isset($payload['event']) && is_array($payload['data'] ?? null)) {
            return [(string) $payload['event'], (array) $payload['data']];
        }
        if (isset($payload['AmountRefunded']) || isset($payload['amount_refunded']) || isset($payload['TransactionId'])) {
            return ['refund', $payload];
        }
        return ['', []];
    }

    /** Handler name in Sync for an event type, or null to acknowledge and ignore. */
    public static function handler(string $event): ?string
    {
        switch ($event) {
            case 'charge.completed':
                return 'payment';
            case 'refund':
            case 'refund.completed':
                return 'refund';
            default:
                return null;
        }
    }

    /** Replay key: Flutterwave v3 sends no event id, so event, object id and status identify a delivery. */
    public static function eventKey(string $event, array $data): string
    {
        $id = self::resourceId($data);
        return $id === '' ? '' : $event . ':' . $id . ':' . strtolower((string) ($data['status'] ?? ''));
    }

    public static function resourceId(array $data): string
    {
        foreach (['id', 'tx_ref'] as $key) {
            if (isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key])) && (string) $data[$key] !== '') {
                return (string) $data[$key];
            }
        }
        return '';
    }

    /** paid|failed|pending for a verified transaction's status. */
    public static function transactionState(string $status): string
    {
        switch (strtolower($status)) {
            case 'successful':
            case 'success':
            case 'succeeded':
                return 'paid';
            case 'failed':
            case 'cancelled':
            case 'canceled':
                return 'failed';
            default:
                return 'pending';
        }
    }

    /** done|failed|pending for a refund status ("completed" and "completed-mpgs" are done). */
    public static function refundState(string $status): string
    {
        $status = strtolower($status);
        if ($status === 'completed' || strpos($status, 'completed-') === 0 || $status === 'succeeded') {
            return 'done';
        }
        return in_array($status, ['failed', 'declined', 'cancelled'], true) ? 'failed' : 'pending';
    }

    /** test|live for a Flutterwave v3 key, '' when the prefix is unknown. */
    public static function keyMode(string $key): string
    {
        $key = strtoupper(trim($key));
        foreach (['FLWSECK', 'FLWPUBK'] as $prefix) {
            if (strpos($key, $prefix . '_TEST') === 0) {
                return 'test';
            }
            if (strpos($key, $prefix . '-') === 0) {
                return 'live';
            }
        }
        return '';
    }
}
