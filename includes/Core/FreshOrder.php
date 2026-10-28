<?php

namespace Stackless\Core;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

defined('ABSPATH') || exit;

/**
 * Re-read an order from the database. wc_get_order() answers from this request's caches (the
 * order object cache, post and meta caches, the HPOS datastore cache), so an order loaded before
 * a lock was taken still shows the state from before another request paid it.
 */
final class FreshOrder
{
    /** @return \WC_Order|null */
    public static function get(int $order_id)
    {
        if ($order_id <= 0) {
            return null;
        }
        self::forget($order_id);
        $order = wc_get_order($order_id);
        return $order instanceof \WC_Order ? $order : null;
    }

    public static function forget(int $order_id): void
    {
        if (function_exists('wc_get_container')) {
            try {
                $container = wc_get_container();
                if (class_exists(OrderCache::class)) {
                    $container->get(OrderCache::class)->remove($order_id);
                }
                if (class_exists(OrdersTableDataStore::class)) {
                    $store = $container->get(OrdersTableDataStore::class);
                    if (method_exists($store, 'clear_cached_data')) {
                        $store->clear_cached_data([$order_id]);
                    }
                }
            } catch (\Throwable $e) {
                // An older WooCommerce without these services: the post caches below still apply.
            }
        }
        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::invalidate_cache_group('object_' . $order_id);
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($order_id);
        }
    }
}
