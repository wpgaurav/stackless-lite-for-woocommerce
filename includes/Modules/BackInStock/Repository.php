<?php

namespace Stackless\Modules\BackInStock;

defined('ABSPATH') || exit;

/**
 * Alert storage. One row per email + product (simple product or variation id).
 */
final class Repository
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'stackless_stock_alerts';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            email varchar(200) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            token varchar(64) NOT NULL,
            locale varchar(20) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            notified_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email_product (email,product_id),
            KEY status_product (status,product_id),
            KEY parent_id (parent_id)
        ) {$collate};");
    }

    /** @return array<string, mixed>|null */
    public function get(int $id)
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $email, int $product_id)
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s AND product_id = %d", $email, $product_id), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Insert or reset a row to a fresh state. Returns the row or null on failure.
     *
     * @return array<string, mixed>|null
     */
    public function upsert(string $email, int $product_id, int $parent_id, int $user_id, string $status, ?array $existing)
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'product_id' => $product_id,
            'parent_id' => $parent_id,
            'email' => $email,
            'user_id' => $user_id,
            'status' => $status,
            'token' => bin2hex(random_bytes(24)),
            'locale' => substr((string) get_locale(), 0, 20),
            'created_at' => $now,
            'updated_at' => $now,
            'notified_at' => null,
        ];
        if ($existing) {
            $ok = $wpdb->update(self::table(), $data, ['id' => (int) $existing['id']]);
            $data['id'] = (int) $existing['id'];
        } else {
            $ok = $wpdb->insert(self::table(), $data);
            $data['id'] = (int) $wpdb->insert_id;
        }
        return $ok === false || !$data['id'] ? null : $data;
    }

    public function setStatus(int $id, string $status): bool
    {
        global $wpdb;
        $data = ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($status === 'notified') {
            $data['notified_at'] = $data['updated_at'];
        }
        return (bool) $wpdb->update(self::table(), $data, ['id' => $id]);
    }

    /**
     * Atomically move a row from one status to another. False when someone else got there first.
     */
    public function claim(int $id, string $from, string $to): bool
    {
        global $wpdb;
        $table = self::table();
        $now = gmdate('Y-m-d H:i:s');
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, updated_at = %s, notified_at = IF(%s = 'notified', %s, notified_at) WHERE id = %d AND status = %s",
            $to,
            $now,
            $to,
            $now,
            $id,
            $from
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function confirmedBatch(int $product_id, int $after, int $limit): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE product_id = %d AND status = 'confirmed' AND id > %d ORDER BY id ASC LIMIT %d",
            $product_id,
            $after,
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return int[] products with confirmed alerts */
    public function productsWaiting(int $limit = 1000): array
    {
        global $wpdb;
        $table = self::table();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT product_id FROM {$table} WHERE status = 'confirmed' LIMIT %d", $limit));
        return array_map('intval', is_array($ids) ? $ids : []);
    }

    /**
     * Per-product request counts by status, most waiting first.
     *
     * @return array<int, array{product_id: string, confirmed: string, pending: string, notified: string, failed: string, last_request: string}>
     */
    public function waitingSummary(int $limit = 50): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, SUM(status = 'confirmed') AS confirmed, SUM(status = 'pending') AS pending, SUM(status = 'notified') AS notified, SUM(status = 'failed') AS failed, MAX(created_at) AS last_request FROM {$table} GROUP BY product_id ORDER BY confirmed DESC, last_request DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function confirmedCount(int $product_id): int
    {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = 'confirmed'", $product_id)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL -- no input.
        return Batch::counts(is_array($rows) ? $rows : []);
    }

    public function recentCount(int $days): int
    {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
    }

    /**
     * Rows grouped by product with per-status counts.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function report(string $search, string $status, int $page, int $per_page): array
    {
        global $wpdb;
        $table = self::table();
        $where = 'WHERE 1=1';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(
                " AND (a.email LIKE %s OR a.product_id IN (SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_title LIKE %s) OR a.parent_id IN (SELECT p2.ID FROM {$wpdb->posts} p2 WHERE p2.post_title LIKE %s) OR a.product_id IN (SELECT m.post_id FROM {$wpdb->postmeta} m WHERE m.meta_key = '_sku' AND m.meta_value LIKE %s))",
                $like,
                $like,
                $like,
                $like
            );
        }
        if ($status !== 'all' && in_array($status, Batch::STATUSES, true)) {
            $where .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$table} m2 WHERE m2.product_id = a.product_id AND m2.status = %s)", $status);
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT a.product_id) FROM {$table} a {$where}"); // phpcs:ignore WordPress.DB.PreparedSQL -- $where is prepared above.
        $sql = "SELECT a.product_id, MAX(a.parent_id) AS parent_id, COUNT(*) AS total, MAX(a.created_at) AS last_request,
            SUM(a.status = 'pending') AS pending, SUM(a.status = 'confirmed') AS confirmed, SUM(a.status = 'notified') AS notified,
            SUM(a.status = 'failed') AS failed, SUM(a.status = 'unsubscribed') AS unsubscribed
            FROM {$table} a {$where} GROUP BY a.product_id ORDER BY last_request DESC, a.product_id DESC "
            . $wpdb->prepare('LIMIT %d OFFSET %d', $per_page, max(0, ($page - 1) * $per_page));
        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL -- prepared parts above.
        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** @return array<int, array<string, mixed>> */
    public function byEmail(string $email, int $limit, int $offset): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, $limit, $offset), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function deleteByEmail(string $email): int
    {
        global $wpdb;
        return (int) $wpdb->delete(self::table(), ['email' => $email], ['%s']);
    }

    public function deleteByProduct(int $product_id): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['product_id' => $product_id], ['%d']);
    }
}
