<?php

namespace Stackless\Modules\BackInStock;

defined('ABSPATH') || exit;

/**
 * Pure rules for alert sign-ups and notification batching.
 */
final class Batch
{
    public const SIZE = 50;
    public const PENDING_TTL = 7 * 86400;

    public const STATUSES = ['pending', 'confirmed', 'notified', 'failed', 'unsubscribed'];

    /** Stock statuses that mean customers can buy again. */
    public static function isAvailable(string $stock_status, bool $include_backorder = false): bool
    {
        return $stock_status === 'instock' || ($include_backorder && $stock_status === 'onbackorder');
    }

    /**
     * Cursor for the next batch, or null when this batch was the last.
     *
     * @param int[] $ids row ids fetched for this batch, ascending
     */
    public static function nextCursor(array $ids, int $size = self::SIZE)
    {
        if (count($ids) < $size || !$ids) {
            return null;
        }
        return (int) max($ids);
    }

    /**
     * Whether a sign-up should start a fresh confirmation cycle (new token, new email).
     *
     * @param array<string, mixed>|null $row existing row for this email + product
     */
    public static function startsFresh($row, int $now, int $ttl = self::PENDING_TTL): bool
    {
        if (!$row) {
            return true;
        }
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['notified', 'failed', 'unsubscribed'], true)) {
            return true;
        }
        if ($status === 'pending') {
            $created = strtotime((string) ($row['created_at'] ?? '') . ' UTC');
            return $created === false || $created < $now - $ttl;
        }
        return false;
    }

    /** A pending confirmation link stops working after the TTL. */
    public static function confirmationExpired(array $row, int $now, int $ttl = self::PENDING_TTL): bool
    {
        if (($row['status'] ?? '') !== 'pending') {
            return false;
        }
        $created = strtotime((string) ($row['created_at'] ?? '') . ' UTC');
        return $created === false || $created < $now - $ttl;
    }

    /**
     * Fold per-status counts into a full map with every status present.
     *
     * @param array<int, array<string, mixed>> $rows each with status + total
     * @return array<string, int>
     */
    public static function counts(array $rows): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] += (int) ($row['total'] ?? 0);
            }
        }
        return $counts;
    }
}
