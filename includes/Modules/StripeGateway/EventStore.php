<?php

namespace Stackless\Modules\StripeGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- webhook events are claimed and finished atomically in a custom table, so every read must see the live row.

defined('ABSPATH') || exit;

/**
 * Webhook event log and replay guard: {$wpdb->prefix}stackless_stripe_events.
 *
 * claim() inserts the event id under a UNIQUE key before any work happens, so a redelivered
 * or replayed event is processed at most once. A handler that fails releases its claim so
 * Stripe's automatic retry gets another go. Only a finished event counts as a duplicate: a live
 * claim held by another request, or a log that cannot be read or written, is reported as such so
 * the webhook asks Stripe to deliver the event again.
 */
final class EventStore
{
    /** This request owns the event and must process it. */
    public const CLAIMED = 'claimed';
    /** The event was already handled. */
    public const PROCESSED = 'processed';
    /** Another request holds a live claim on the event. */
    public const BUSY = 'busy';
    /** The event log could not be read or written. */
    public const STORAGE_ERROR = 'storage_error';

    /** Seconds after which a "processing" claim belongs to a request that died. */
    public const STALE_AFTER = 600;

    /** @var array<string,string> event id => created_at of the claim this request holds */
    private static $claims = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_stripe_events';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id varchar(191) NOT NULL,
  event_type varchar(100) NOT NULL DEFAULT '',
  livemode tinyint(1) NOT NULL DEFAULT 0,
  object_id varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'processing',
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY created_at (created_at)
) {$charset};");
    }

    /**
     * @return string CLAIMED, PROCESSED, BUSY or STORAGE_ERROR.
     */
    public static function claim(string $event_id, string $type, bool $livemode, string $object_id): string
    {
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql', true);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (event_id, event_type, livemode, object_id, status, created_at) VALUES (%s, %s, %d, %s, 'processing', %s)", $table,
            $event_id,
            substr($type, 0, 100),
            $livemode ? 1 : 0,
            substr($object_id, 0, 191),
            $now
        ));
        if ($inserted === false) {
            return self::STORAGE_ERROR;
        }
        if ($inserted) {
            self::$claims[$event_id] = $now;
            return self::CLAIMED;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT status, created_at FROM %i WHERE event_id = %s", $table, $event_id), ARRAY_A);
        if (!is_array($row)) {
            // Unreadable, or released by a failing request since the insert: either way, not done.
            return $wpdb->last_error !== '' ? self::STORAGE_ERROR : self::BUSY;
        }
        if ($row['status'] !== 'processing') {
            return self::PROCESSED;
        }
        $claimed_at = (string) $row['created_at'];
        if ($claimed_at >= gmdate('Y-m-d H:i:s', time() - self::STALE_AFTER)) {
            return self::BUSY;
        }

        // The claim belongs to a request that died. Take it over only if no other retry got there
        // first: the update matches the exact claim time read above, so one request wins.
        $taken = $wpdb->query($wpdb->prepare(
            "UPDATE %i SET created_at = %s WHERE event_id = %s AND status = 'processing' AND created_at = %s", $table,
            $now,
            $event_id,
            $claimed_at
        ));
        if ($taken === false) {
            return self::STORAGE_ERROR;
        }
        if (!$taken) {
            return self::BUSY;
        }
        self::$claims[$event_id] = $now;
        return self::CLAIMED;
    }

    public static function complete(string $event_id, string $status, string $note = ''): void
    {
        global $wpdb;
        unset(self::$claims[$event_id]);
        $wpdb->update(self::table(), ['status' => substr($status, 0, 20), 'note' => substr($note, 0, 255)], ['event_id' => $event_id], ['%s', '%s'], ['%s']);
    }

    /**
     * Drops this request's claim so Stripe's retry can process the event. A claim that another
     * request has since taken over (this one ran past STALE_AFTER) is left with its new owner.
     */
    public static function release(string $event_id): void
    {
        global $wpdb;
        $claimed_at = self::$claims[$event_id] ?? '';
        unset(self::$claims[$event_id]);
        if ($claimed_at === '') {
            $wpdb->delete(self::table(), ['event_id' => $event_id, 'status' => 'processing'], ['%s', '%s']);
            return;
        }
        $wpdb->delete(self::table(), ['event_id' => $event_id, 'status' => 'processing', 'created_at' => $claimed_at], ['%s', '%s', '%s']);
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 10): array
    {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }
        return (array) $wpdb->get_results($wpdb->prepare("SELECT event_id, event_type, livemode, status, note, created_at FROM %i ORDER BY id DESC LIMIT %d", $table, $limit), ARRAY_A);
    }

    /** Drops log rows older than 30 days, at most once a day. Stripe never redelivers events that old. */
    public static function maybePrune(): void
    {
        if (get_transient('stackless_stripe_gateway_pruned')) {
            return;
        }
        set_transient('stackless_stripe_gateway_pruned', 1, DAY_IN_SECONDS);
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE created_at < %s", $table, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
    }
}
