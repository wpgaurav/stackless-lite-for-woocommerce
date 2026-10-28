<?php

namespace Stackless\Core;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined('ABSPATH') || exit;

/**
 * wc_get_orders() meta lookups that work on both order stores. HPOS reads meta_query; the posts
 * store silently drops it and returns every order, newest first. On that store the clauses travel
 * in a private query var and are restored through woocommerce_order_data_store_cpt_get_orders_query.
 */
final class OrderQuery
{
    public const VAR = 'stackless_meta_query';

    public static function boot(): void
    {
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', [self::class, 'cptQuery'], 10, 2);
    }

    /**
     * wc_get_orders() arguments whose meta_query also applies on the posts store.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function args(array $args): array
    {
        if (!empty($args['meta_query']) && !self::hpos()) {
            $args[self::VAR] = $args['meta_query'];
            unset($args['meta_query']);
        }
        return $args;
    }

    /**
     * Restores a meta_query on the posts store. Top-level clauses are ANDed with WooCommerce's own,
     * so callers put any OR group inside a nested clause.
     *
     * @param array<string,mixed> $query WP_Query args
     * @param array<string,mixed> $vars wc_get_orders() query vars
     * @return array<string,mixed>
     */
    public static function cptQuery(array $query, array $vars): array
    {
        if (isset($vars[self::VAR]) && is_array($vars[self::VAR])) {
            $existing = isset($query['meta_query']) && is_array($query['meta_query']) ? $query['meta_query'] : [];
            $query['meta_query'] = array_merge($existing, $vars[self::VAR]); // phpcs:ignore WordPress.DB.SlowDBQuery
        }
        unset($query[self::VAR]);
        return $query;
    }

    /**
     * The newest order whose $key meta is exactly $value, or null. The match is re-checked on the
     * order itself, so a store that ignored the clause can never hand back an unrelated order.
     *
     * @param array<string,mixed> $args extra wc_get_orders() arguments (type, payment_method, ...)
     */
    public static function findByMeta(string $key, string $value, array $args = []): ?\WC_Order
    {
        if ($key === '' || $value === '') {
            return null;
        }
        $args = array_merge(['type' => 'shop_order', 'limit' => 1], $args);
        $args['meta_query'] = [['key' => $key, 'value' => $value]]; // phpcs:ignore WordPress.DB.SlowDBQuery
        unset($args['return'], $args['paginate']);
        foreach ((array) wc_get_orders(self::args($args)) as $order) {
            if ($order instanceof \WC_Order && (string) $order->get_meta($key) === $value) {
                return $order;
            }
        }
        return null;
    }

    public static function hpos(): bool
    {
        return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
