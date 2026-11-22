<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/** The few order lookups Billing Health needs. Payments never look an order up by a query. */
final class OrderLists
{
    private const LIMIT = 20;

    /** @return string[] Numbers of orders with money a person must look at. */
    public static function needingReview(): array
    {
        return self::numbers([], Meta::REVIEW);
    }

    /** @return string[] Numbers of charges without a result since $before. */
    public static function stuck(int $before): array
    {
        return self::numbers([
            'status' => ['pending'],
            'payment_method' => Settings::GATEWAY_ID,
            'date_created' => '<' . $before,
        ], Meta::OFF_SESSION);
    }

    /**
     * @param array<string,mixed> $args
     * @return string[]
     */
    private static function numbers(array $args, string $key): array
    {
        $args += [
            'type' => 'shop_order',
            'limit' => self::LIMIT,
            'meta_query' => [['key' => $key, 'value' => 'yes']], // phpcs:ignore WordPress.DB.SlowDBQuery -- Billing Health only, at most 20 rows.
        ];
        $numbers = [];
        foreach ((array) wc_get_orders(OrderQuery::args($args)) as $order) {
            if ($order instanceof \WC_Order && (string) $order->get_meta($key) === 'yes') {
                $numbers[] = '#' . $order->get_order_number();
            }
        }
        return $numbers;
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=wc-orders');
    }

    public static function logsUrl(): string
    {
        return admin_url('admin.php?page=wc-status&tab=logs');
    }
}
