<?php

namespace Stackless\Modules\Wishlist;

defined('ABSPATH') || exit;

/**
 * Pure list logic for wishlists: identity of an entry, normalising input and
 * merging a guest list into an account list.
 */
final class Merge
{
    /** Stable identity for a product/variation pair. */
    public static function key(int $product_id, int $variation_id): string
    {
        return $product_id . ':' . $variation_id;
    }

    /**
     * Normalise raw entries into unique [product_id, variation_id] pairs, preserving order.
     *
     * @param array<int, mixed> $entries each an array with product_id / variation_id
     * @return array<int, array{product_id:int, variation_id:int}>
     */
    public static function normalise(array $entries): array
    {
        $out = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $product = isset($entry['product_id']) && is_numeric($entry['product_id']) ? (int) $entry['product_id'] : 0;
            $variation = isset($entry['variation_id']) && is_numeric($entry['variation_id']) ? (int) $entry['variation_id'] : 0;
            if ($product <= 0 || $variation < 0) {
                continue;
            }
            $key = self::key($product, $variation);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['product_id' => $product, 'variation_id' => $variation];
        }
        return $out;
    }

    /**
     * Entries from the guest list that should be added to the account list.
     *
     * Account entries keep their place; guest entries already in the account list are
     * dropped; the result never pushes the account list past $limit.
     *
     * @param array<int, mixed> $account
     * @param array<int, mixed> $guest
     * @return array<int, array{product_id:int, variation_id:int}>
     */
    public static function additions(array $account, array $guest, int $limit): array
    {
        $account = self::normalise($account);
        $room = max(0, $limit - count($account));
        if ($room === 0) {
            return [];
        }
        $existing = [];
        foreach ($account as $entry) {
            $existing[self::key($entry['product_id'], $entry['variation_id'])] = true;
        }
        $add = [];
        foreach (self::normalise($guest) as $entry) {
            if (isset($existing[self::key($entry['product_id'], $entry['variation_id'])])) {
                continue;
            }
            $add[] = $entry;
            if (count($add) >= $room) {
                break;
            }
        }
        return $add;
    }

    /**
     * The full merged list (account first, then guest additions).
     *
     * @param array<int, mixed> $account
     * @param array<int, mixed> $guest
     * @return array<int, array{product_id:int, variation_id:int}>
     */
    public static function merged(array $account, array $guest, int $limit): array
    {
        $account = array_slice(self::normalise($account), 0, max(0, $limit));
        return array_merge($account, self::additions($account, $guest, $limit));
    }
}
