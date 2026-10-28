<?php

namespace Stackless\Modules\Wishlist;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- wishlists live in two custom tables; each read is per list and changes on every add or remove, so there is no shared result to cache.

defined('ABSPATH') || exit;

/**
 * Storage for wishlists: one list per account or per guest token.
 *
 * Guests are identified by a random token kept in a cookie; only its SHA-256 hash is stored.
 */
final class Repository
{
    public static function listsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_wishlists';
    }

    public static function itemsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_wishlist_items';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $lists = self::listsTable();
        $items = self::itemsTable();
        dbDelta("CREATE TABLE {$lists} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            guest_hash char(64) NOT NULL DEFAULT '',
            share_key varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY share_key (share_key),
            KEY user_id (user_id),
            KEY guest_hash (guest_hash),
            KEY updated_at (updated_at)
        ) {$collate};");
        dbDelta("CREATE TABLE {$items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wishlist_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY list_product (wishlist_id,product_id,variation_id),
            KEY product_id (product_id)
        ) {$collate};");
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed>|null */
    public function forUser(int $user_id, bool $create)
    {
        global $wpdb;
        $table = self::listsTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT 1", $table, $user_id), ARRAY_A);
        if ($row || !$create || $user_id <= 0) {
            return $row ?: null;
        }
        return $this->create($user_id, '');
    }

    /** @return array<string, mixed>|null */
    public function forGuest(string $token, bool $create)
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $hash = self::hashToken($token);
        $table = self::listsTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE guest_hash = %s AND user_id = 0 LIMIT 1", $table, $hash), ARRAY_A);
        if ($row || !$create) {
            return $row ?: null;
        }
        return $this->create(0, $hash);
    }

    /** @return array<string, mixed>|null */
    public function byShareKey(string $key)
    {
        global $wpdb;
        if (!preg_match('/^[A-Za-z0-9]{16,32}$/', $key)) {
            return null;
        }
        $table = self::listsTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE share_key = %s LIMIT 1", $table, $key), ARRAY_A);
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function create(int $user_id, string $guest_hash)
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'user_id' => $user_id,
            'guest_hash' => $guest_hash,
            'share_key' => wp_generate_password(20, false, false),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (!$wpdb->insert(self::listsTable(), $data, ['%d', '%s', '%s', '%s', '%s'])) {
            return null;
        }
        $data['id'] = (int) $wpdb->insert_id;
        return $data;
    }

    /** @return array<int, array{product_id:int, variation_id:int}> newest first */
    public function items(int $list_id): array
    {
        global $wpdb;
        $table = self::itemsTable();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT product_id, variation_id FROM %i WHERE wishlist_id = %d ORDER BY id DESC", $table, $list_id), ARRAY_A);
        return Merge::normalise(is_array($rows) ? $rows : []);
    }

    public function count(int $list_id): int
    {
        global $wpdb;
        $table = self::itemsTable();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE wishlist_id = %d", $table, $list_id));
    }

    public function has(int $list_id, int $product_id, int $variation_id): bool
    {
        global $wpdb;
        $table = self::itemsTable();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM %i WHERE wishlist_id = %d AND product_id = %d AND variation_id = %d", $table, $list_id, $product_id, $variation_id));
    }

    /** Insert ignoring duplicates. Returns true when the pair is in the list afterwards. */
    public function add(int $list_id, int $product_id, int $variation_id): bool
    {
        global $wpdb;
        $table = self::itemsTable();
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (wishlist_id, product_id, variation_id, created_at) VALUES (%d, %d, %d, %s)", $table,
            $list_id,
            $product_id,
            $variation_id,
            gmdate('Y-m-d H:i:s')
        ));
        $this->touch($list_id);
        return $this->has($list_id, $product_id, $variation_id);
    }

    public function remove(int $list_id, int $product_id, int $variation_id): void
    {
        global $wpdb;
        $wpdb->delete(self::itemsTable(), ['wishlist_id' => $list_id, 'product_id' => $product_id, 'variation_id' => $variation_id], ['%d', '%d', '%d']);
        $this->touch($list_id);
    }

    public function deleteList(int $list_id): void
    {
        global $wpdb;
        $wpdb->delete(self::itemsTable(), ['wishlist_id' => $list_id], ['%d']);
        $wpdb->delete(self::listsTable(), ['id' => $list_id], ['%d']);
    }

    /** Move a guest list's entries into the account list, then delete the guest list. */
    public function merge(int $guest_list_id, int $account_list_id, int $limit): int
    {
        if ($guest_list_id === $account_list_id) {
            return 0;
        }
        $additions = Merge::additions($this->items($account_list_id), array_reverse($this->items($guest_list_id)), $limit);
        foreach ($additions as $entry) {
            $this->add($account_list_id, $entry['product_id'], $entry['variation_id']);
        }
        $this->deleteList($guest_list_id);
        return count($additions);
    }

    public function newShareKey(int $list_id): string
    {
        global $wpdb;
        $key = wp_generate_password(20, false, false);
        $wpdb->update(self::listsTable(), ['share_key' => $key, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $list_id], ['%s', '%s'], ['%d']);
        return $key;
    }

    /** Delete guest lists untouched for $days days. Returns the number of lists removed. */
    public function purgeGuests(int $days, int $batch = 500): int
    {
        global $wpdb;
        $lists = self::listsTable();
        $items = self::itemsTable();
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE user_id = 0 AND updated_at < %s LIMIT %d", $lists, $cutoff, $batch));
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        if (!$ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE wishlist_id IN ({$in})", $items, ...$ids));
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ({$in})", $lists, ...$ids));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return count($ids);
    }

    public function deleteForUser(int $user_id): void
    {
        $list = $this->forUser($user_id, false);
        if ($list) {
            $this->deleteList((int) $list['id']);
        }
    }

    private function touch(int $list_id): void
    {
        global $wpdb;
        $wpdb->update(self::listsTable(), ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $list_id], ['%s'], ['%d']);
    }
}
