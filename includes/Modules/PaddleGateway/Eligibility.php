<?php

namespace Stackless\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * Which carts and orders Paddle can take.
 *
 * One Paddle transaction creates at most one Paddle subscription, and every
 * recurring item in it shares one billing cycle. One-time items can ride along
 * with it (they are charged once, on the first payment), so a cart may hold any
 * number of one-time products plus one subscription schedule.
 */
final class Eligibility
{
    public const OK = '';
    public const TOO_MANY_SUBSCRIPTIONS = 'too_many_subscriptions';

    /**
     * @param bool[] $lines         One entry per cart line/order item: true when it is a subscription product.
     * @param int    $subscriptions Distinct subscriptions the cart/order creates (one per billing schedule).
     * @return string Reason code, or self::OK.
     */
    public static function reason(array $lines, int $subscriptions): string
    {
        $subscriptionLines = count(array_filter($lines));
        $subscriptions = max($subscriptions, $subscriptionLines > 0 ? 1 : 0);
        return $subscriptions > 1 ? self::TOO_MANY_SUBSCRIPTIONS : self::OK;
    }

    /** @param bool[] $lines */
    public static function allowed(array $lines, int $subscriptions): bool
    {
        return self::reason($lines, $subscriptions) === self::OK;
    }

    public static function message(string $reason): string
    {
        if ($reason === self::TOO_MANY_SUBSCRIPTIONS) {
            return __('Paddle can bill one subscription schedule per checkout. Buy subscriptions with different billing schedules separately or choose another payment method.', 'stackless-lite');
        }
        return '';
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
        // A renewal paid through Paddle is a one-time payment of that renewal order.
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
