<?php

namespace Stackless\Modules\RazorpayGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- webhook events are claimed and finished atomically in a custom table, so every read must see the live row.

defined('ABSPATH') || exit;

/**
 * Event log and replay guard for Razorpay webhooks.
 *
 * Each delivery is claimed by inserting its event id into a table with a unique key, so a
 * replayed or concurrently retried event runs once. Failed events are released so the
 * provider's retry can process them. Billing Health reads the recent failures from here.
 */
final class WebhookLog
{
    public const TABLE = 'stackless_razorpay_gateway_events';

    /** Seconds after which a "processing" claim is considered abandoned. */
    public const STALE_AFTER = 300;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(191) NOT NULL,
            event_type varchar(100) NOT NULL DEFAULT '',
            resource_id varchar(191) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'processing',
            attempts int(10) unsigned NOT NULL DEFAULT 1,
            message varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};");
    }

    /**
     * Claim an event for processing.
     *
     * @return string "claimed", "duplicate" (already done) or "busy" (another request is on it).
     */
    public static function claim(string $eventId, string $type, string $resourceId): string
    {
        global $wpdb;
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (event_id, event_type, resource_id, status, attempts, created_at, updated_at) VALUES (%s, %s, %s, 'processing', 1, %s, %s)",
            $table,
            substr($eventId, 0, 191),
            substr($type, 0, 100),
            substr($resourceId, 0, 191),
            $now,
            $now
        ));
        if ($inserted) {
            self::maybePrune();
            return 'claimed';
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT status, updated_at FROM %i WHERE event_id = %s', $table, substr($eventId, 0, 191)), ARRAY_A);
        if (!$row) {
            return 'busy';
        }
        if ($row['status'] === 'done') {
            return 'duplicate';
        }
        $stale = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER);
        if ($row['status'] === 'processing' && $row['updated_at'] > $stale) {
            return 'busy';
        }

        // Failed or abandoned: take it over atomically.
        $taken = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE event_id = %s AND status = %s AND updated_at = %s",
            $table,
            $now,
            substr($eventId, 0, 191),
            $row['status'],
            $row['updated_at']
        ));
        return $taken ? 'claimed' : 'busy';
    }

    public static function finish(string $eventId, string $status, string $message = ''): void
    {
        global $wpdb;
        $wpdb->update(self::table(), [
            'status' => $status === 'done' ? 'done' : 'failed',
            'message' => substr($message, 0, 255),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['event_id' => substr($eventId, 0, 191)]);
    }

    /**
     * Recent events that failed or never finished, newest first (for Billing Health).
     *
     * @return array<int, array{id:string, type:string, status:string, note:string, at:string}>
     */
    public static function problems(int $days = 7, int $limit = 20): array
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $stale = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, event_type, status, message, updated_at FROM %i WHERE created_at >= %s AND (status = 'failed' OR (status = 'processing' AND updated_at < %s)) ORDER BY updated_at DESC LIMIT %d",
            self::table(),
            $since,
            $stale,
            max(1, $limit)
        ), ARRAY_A);
        $events = [];
        foreach ((array) $rows as $row) {
            $events[] = [
                'id' => (string) $row['event_id'],
                'type' => (string) $row['event_type'],
                'status' => (string) $row['status'] === 'failed' ? 'failed' : 'stuck',
                'note' => (string) $row['message'],
                'at' => (string) $row['updated_at'],
            ];
        }
        return $events;
    }

    /** Keep 90 days of history; runs on roughly one claim in fifty. */
    private static function maybePrune(): void
    {
        if (wp_rand(1, 50) !== 1) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s LIMIT 1000', self::table(), gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)));
    }
}
