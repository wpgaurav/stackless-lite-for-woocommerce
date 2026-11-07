<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Razorpay webhook events this gateway subscribes to, and what handles each.
 */
final class EventMap
{
    /** Events to tick in Razorpay → Account & Settings → Webhooks. */
    public const EVENTS = [
        'payment.authorized',
        'payment.captured',
        'payment.failed',
        'order.paid',
        'refund.processed',
        'refund.failed',
        'token.confirmed',
        'token.rejected',
        'token.cancelled',
        'token.paused',
    ];

    private const HANDLERS = [
        'payment.authorized' => 'payment',
        'payment.captured' => 'payment',
        'payment.failed' => 'payment',
        'order.paid' => 'payment',
        'refund.processed' => 'refund',
        'refund.failed' => 'refund',
        'refund.created' => 'refund',
        'token.confirmed' => 'token',
        'token.rejected' => 'token',
        'token.cancelled' => 'token',
        'token.paused' => 'token',
    ];

    public static function handler(string $event): ?string
    {
        return self::HANDLERS[strtolower(trim($event))] ?? null;
    }

    /** The entity an event is about, from payload.{payment|refund|token}.entity. */
    public static function entity(array $event, string $kind): array
    {
        $entity = $event['payload'][$kind]['entity'] ?? null;
        return is_array($entity) ? $entity : [];
    }

    /** Id of the main entity, for the event log. */
    public static function resourceId(array $event): string
    {
        foreach (['payment', 'refund', 'token', 'order'] as $kind) {
            $id = (string) (self::entity($event, $kind)['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }
}
