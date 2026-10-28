<?php

namespace Stackless\Modules\ExternalProduct;

defined('ABSPATH') || exit;

/**
 * Outbound click counter, one row per product. Increments are a single atomic upsert,
 * so busy products never lose clicks to read-modify-write races or trigger product saves.
 */
final class Clicks
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_external_clicks';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
  product_id bigint(20) unsigned NOT NULL,
  clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  last_click_gmt datetime NULL DEFAULT NULL,
  PRIMARY KEY  (product_id)
) {$collate};");
    }

    public static function increment(int $productId): void
    {
        global $wpdb;
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is our own prefix.
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (product_id, clicks, last_click_gmt) VALUES (%d, 1, %s) ON DUPLICATE KEY UPDATE clicks = clicks + 1, last_click_gmt = %s", $productId, $now, $now));
    }

    /** @return array{clicks: int, last: string} */
    public static function get(int $productId): array
    {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is our own prefix.
        $row = $wpdb->get_row($wpdb->prepare("SELECT clicks, last_click_gmt FROM {$table} WHERE product_id = %d", $productId), ARRAY_A);
        return [
            'clicks' => is_array($row) ? (int) $row['clicks'] : 0,
            'last' => is_array($row) ? (string) $row['last_click_gmt'] : '',
        ];
    }

    /**
     * @param int[] $productIds
     * @return array<int, int>
     */
    public static function many(array $productIds): array
    {
        global $wpdb;
        $productIds = array_values(array_filter(array_map('absint', $productIds)));
        if (!$productIds) {
            return [];
        }
        $table = self::table();
        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is ours, placeholders built above.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT product_id, clicks FROM {$table} WHERE product_id IN ({$placeholders})", $productIds), ARRAY_A);
        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row['product_id']] = (int) $row['clicks'];
        }
        return $map;
    }

    public static function reset(int $productId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['product_id' => $productId], ['%d']);
    }
}
