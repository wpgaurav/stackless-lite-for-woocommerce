<?php

namespace Stackless\Modules\PolarGateway;

defined('ABSPATH') || exit;

/**
 * Which carts and orders Polar can take.
 *
 * Polar's hosted checkout sells one product per session. One-time purchases of
 * any size work (they become a single "store order" product), but a
 * subscription must be the only thing in the cart, and only one subscription.
 */
final class Eligibility
{
    public const OK = '';
    public const TOO_MANY_SUBSCRIPTIONS = 'too_many_subscriptions';
    public const MIXED_CART = 'mixed_cart';

    /**
     * @param bool[] $lines           One entry per cart line/order item: true when it is a subscription product.
     * @param int    $subscriptions   Distinct subscriptions the cart/order creates (one per billing schedule).
     * @return string Reason code, or self::OK.
     */
    public static function reason(array $lines, int $subscriptions): string
    {
        $subscriptionLines = count(array_filter($lines));
        $subscriptions = max($subscriptions, $subscriptionLines > 0 ? 1 : 0);

        if ($subscriptions === 0) {
            return self::OK;
        }
        if ($subscriptions > 1 || $subscriptionLines > 1) {
            return self::TOO_MANY_SUBSCRIPTIONS;
        }
        if (count($lines) !== 1) {
            return self::MIXED_CART;
        }
        return self::OK;
    }

    /** @param bool[] $lines */
    public static function allowed(array $lines, int $subscriptions): bool
    {
        return self::reason($lines, $subscriptions) === self::OK;
    }

    public static function message(string $reason): string
    {
        switch ($reason) {
            case self::TOO_MANY_SUBSCRIPTIONS:
                return __('Polar can bill one subscription per checkout. Buy each subscription separately or choose another payment method.', 'stackless-lite');
            case self::MIXED_CART:
                return __('Polar can bill a subscription only when it is the only item in the cart. Buy other items separately or choose another payment method.', 'stackless-lite');
            default:
                return '';
        }
    }

    /** Lines of the current cart. @return bool[] */
    public static function cartLines(): array
    {
        $lines = [];
        if (!function_exists('WC') || !WC()->cart) {
            return $lines;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            $lines[] = $product instanceof \WC_Product && stackless_is_subscription_product($product);
        }
        return $lines;
    }

    /** Reason for the current cart. */
    public static function cartReason(): string
    {
        if (!function_exists('WC') || !WC()->cart) {
            return self::OK;
        }
        return self::reason(self::cartLines(), stackless_cart_subscription_count());
    }

    /** Reason for an existing order (order-pay page, process_payment). */
    public static function orderReason(\WC_Order $order): string
    {
        // A renewal paid through Polar is a one-time payment of that renewal order.
        if (stackless_is_renewal_order($order)) {
            return self::OK;
        }
        $lines = [];
        foreach ($order->get_items() as $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            $lines[] = $product instanceof \WC_Product && stackless_is_subscription_product($product);
        }
        $subscriptions = stackless_order_contains_subscription($order) ? max(1, count(stackless_get_order_subscriptions($order))) : 0;
        return self::reason($lines, $subscriptions);
    }
}
