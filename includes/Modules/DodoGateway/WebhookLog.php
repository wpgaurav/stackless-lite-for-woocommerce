<?php

namespace Stackless\Modules\DodoGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- webhook events are claimed and finished atomically in a custom table, so every read must see the live row.

defined('ABSPATH') || exit;

/**
 * Event log and replay guard for Dodo Payments webhooks (keyed by the Standard Webhooks webhook-id).
 *
 * Each delivery is claimed by inserting its event id into a table with a
 * unique key, so a replayed or concurrently retried event runs once. Failed
 * events are released so Dodo’s retry can process them.
 */
final class WebhookLog
{
    public const TABLE = 'stackless_dodo_gateway_events';

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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (event_id, event_type, resource_id, status, attempts, created_at, updated_at) VALUES (%s, %s, %s, 'processing', 1, %s, %s)", $table,
            $eventId,
            substr($type, 0, 100),
            substr($resourceId, 0, 191),
            $now,
            $now
        ));
        if ($inserted) {
            self::maybePrune();
            return 'claimed';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, updated_at FROM %i WHERE event_id = %s", $table, $eventId), ARRAY_A);
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $taken = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE event_id = %s AND status = %s AND updated_at = %s", $table,
            $now,
            $eventId,
            $row['status'],
            $row['updated_at']
        ));
        return $taken ? 'claimed' : 'busy';
    }

    public static function finish(string $eventId, string $status, string $message = ''): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(self::table(), [
            'status' => $status === 'done' ? 'done' : 'failed',
            'message' => substr($message, 0, 255),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['event_id' => $eventId]);
    }

    /** Keep 90 days of history; runs on roughly one claim in fifty. */
    private static function maybePrune(): void
    {
        if (wp_rand(1, 50) !== 1) {
            return;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE created_at < %s LIMIT 1000", $table, gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)));
    }

    /**
     * Failed events, and events stuck mid-processing, from the last $days days (Billing Health).
     *
     * @return array<int,array{type:string,status:string,note:string,at:int}>
     */
    public static function problems(int $days = 7, int $limit = 20): array
    {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return [];
        }
        $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $stale = gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare("SELECT event_type, status, message, updated_at FROM {$table} WHERE created_at >= %s AND (status = 'failed' OR (status = 'processing' AND updated_at < %s)) ORDER BY id DESC LIMIT %d", $since, $stale, max(1, $limit)), ARRAY_A);
        $problems = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $problems[] = [
                'type' => (string) $row['event_type'],
                'status' => (string) $row['status'],
                'note' => (string) $row['message'],
                'at' => (int) strtotime((string) $row['updated_at'] . ' UTC'),
            ];
        }
        return $problems;
    }

    /** Billing Health source: recent webhook failures for this gateway. @return array<string,mixed> */
    public static function healthReport(string $label): array
    {
        $events = self::problems();
        $issues = [];
        if ($events) {
            $issues[] = [
                'severity' => 'warning',
                /* translators: 1: number of events, 2: payment provider */
                'title' => sprintf(_n('%1$d %2$s webhook failed or got stuck in the last 7 days.', '%1$d %2$s webhooks failed or got stuck in the last 7 days.', count($events), 'stackless-lite'), count($events), $label),
                'detail' => __('The provider retries failed webhooks. If failures keep coming, check the webhook secret in the gateway settings and the log under WooCommerce → Status → Logs.', 'stackless-lite'),
            ];
        }
        return ['label' => $label, 'issues' => $issues, 'events' => $events];
    }
}
