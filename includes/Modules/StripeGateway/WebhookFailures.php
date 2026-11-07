<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * The last webhook events whose handler failed (2.0.0), for Billing Health. EventStore
 * releases a failed claim so Stripe's retry can process the event; this keeps a short record
 * of the failure until a retry succeeds. No payloads, customer data or secrets are stored:
 * event id, type, a short note and the time.
 */
final class WebhookFailures
{
    public const OPTION = 'stackless_stripe_gateway_webhook_failures';
    private const KEEP = 20;

    public static function record(string $event_id, string $type, string $note): void
    {
        $rows = self::all();
        $rows[$event_id] = [
            'id' => substr($event_id, 0, 191),
            'type' => substr($type, 0, 100),
            'note' => substr(wp_strip_all_tags($note), 0, 200),
            'at' => gmdate('Y-m-d H:i:s'),
        ];
        uasort($rows, static function (array $a, array $b): int {
            return strcmp($b['at'], $a['at']);
        });
        update_option(self::OPTION, array_slice($rows, 0, self::KEEP, true), false);
    }

    /** A retry of the event succeeded. */
    public static function resolve(string $event_id): void
    {
        $rows = self::all();
        if (isset($rows[$event_id])) {
            unset($rows[$event_id]);
            update_option(self::OPTION, $rows, false);
        }
    }

    /** @return array<string,array{id:string,type:string,note:string,at:string}> */
    public static function all(): array
    {
        $rows = get_option(self::OPTION, []);
        return is_array($rows) ? $rows : [];
    }
}
