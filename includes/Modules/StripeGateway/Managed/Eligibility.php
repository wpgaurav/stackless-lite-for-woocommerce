<?php

namespace Stackless\Modules\StripeGateway\Managed;

defined('ABSPATH') || exit;

/**
 * Which carts and orders Stripe Managed Payments can take.
 *
 * Stripe is the merchant of record only for digital goods (software, digital media, online
 * courses, electronically supplied services), so every line must be virtual or
 * downloadable. One Checkout Session creates at most one Stripe subscription with one
 * billing schedule; one-time items may ride along on its first invoice.
 */
final class Eligibility
{
    public const OK = '';
    public const PHYSICAL = 'physical';
    public const TOO_MANY_SUBSCRIPTIONS = 'too_many_subscriptions';

    /**
     * @param array<int,array{digital:bool,subscription:bool}> $lines One per cart line/order item.
     * @param int $subscriptions Distinct subscriptions the cart/order creates (one per billing schedule).
     * @return string Reason code, or self::OK.
     */
    public static function reason(array $lines, int $subscriptions): string
    {
        $subscriptionLines = 0;
        foreach ($lines as $line) {
            if (empty($line['digital'])) {
                return self::PHYSICAL;
            }
            if (!empty($line['subscription'])) {
                $subscriptionLines++;
            }
        }
        $subscriptions = max($subscriptions, $subscriptionLines > 0 ? 1 : 0);
        return $subscriptions > 1 ? self::TOO_MANY_SUBSCRIPTIONS : self::OK;
    }

    public static function message(string $reason): string
    {
        switch ($reason) {
            case self::PHYSICAL:
                return __('Stripe Managed Payments sells digital products only. Remove physical products or choose another payment method.', 'stackless-lite');
            case self::TOO_MANY_SUBSCRIPTIONS:
                return __('Stripe Managed Payments can bill one subscription schedule per checkout. Buy subscriptions with different billing schedules separately or choose another payment method.', 'stackless-lite');
        }
        return '';
    }

    /** True for products Stripe can sell as merchant of record (virtual or downloadable). */
    public static function isDigital(\WC_Product $product): bool
    {
        $digital = $product->is_virtual() || $product->is_downloadable();
        return (bool) apply_filters('stackless_stripe_managed_is_digital', $digital, $product);
    }

    /** Lines of the current cart. @return array<int,array{digital:bool,subscription:bool}> */
    public static function cartLines(): array
    {
        $lines = [];
        if (!function_exists('WC') || !WC()->cart) {
            return $lines;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $lines[] = ['digital' => self::isDigital($product), 'subscription' => stackless_is_subscription_product($product)];
        }
        return $lines;
    }

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
        $lines = [];
        foreach ($order->get_items() as $item) {
            $product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $lines[] = ['digital' => self::isDigital($product), 'subscription' => stackless_is_subscription_product($product)];
        }
        if ($order->get_shipping_total() > 0 || count($order->get_shipping_methods()) > 0) {
            $lines[] = ['digital' => false, 'subscription' => false];
        }
        $subscriptions = stackless_order_contains_subscription($order) ? max(1, count(stackless_get_order_subscriptions($order))) : 0;
        return self::reason($lines, $subscriptions);
    }
}
