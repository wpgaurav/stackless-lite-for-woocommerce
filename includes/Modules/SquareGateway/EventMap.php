<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Square webhook events this gateway subscribes to, and what handles each.
 */
final class EventMap
{
    public const EVENTS = [
        'payment.updated',
        'refund.created',
        'refund.updated',
        'card.disabled',
        'card.forgotten',
        'card.automatically_updated',
    ];

    private const HANDLERS = [
        'payment.created' => 'payment',
        'payment.updated' => 'payment',
        'refund.created' => 'refund',
        'refund.updated' => 'refund',
        'card.disabled' => 'card_removed',
        'card.forgotten' => 'card_removed',
        'card.automatically_updated' => 'card_updated',
        'card.updated' => 'card_updated',
    ];

    public static function handler(string $type): ?string
    {
        return self::HANDLERS[strtolower(trim($type))] ?? null;
    }

    /** data.object.{payment|refund|card} of an event. */
    public static function object(array $event, string $kind): array
    {
        $object = $event['data']['object'][$kind] ?? null;
        return is_array($object) ? $object : [];
    }

    public static function resourceId(array $event): string
    {
        return (string) ($event['data']['id'] ?? '');
    }
}
