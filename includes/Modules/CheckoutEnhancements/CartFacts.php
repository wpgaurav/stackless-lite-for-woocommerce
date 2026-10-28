<?php

namespace Stackless\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * The few cart facts consents and the shorter checkout depend on, read the same way from a
 * live cart or a placed order so the stored record matches what the customer was shown.
 */
final class CartFacts
{
    /** @return array{needs_shipping: bool, has_digital: bool, product_ids: int[]} */
    public static function fromCart($cart = null): array
    {
        if ($cart === null && function_exists('WC') && WC()->cart) {
            $cart = WC()->cart;
        }
        $products = [];
        if ($cart instanceof \WC_Cart) {
            foreach ($cart->get_cart() as $item) {
                if (isset($item['data']) && $item['data'] instanceof \WC_Product) {
                    $products[] = $item['data'];
                }
            }
        }
        return self::fromProducts($products);
    }

    /** @return array{needs_shipping: bool, has_digital: bool, product_ids: int[]} */
    public static function fromOrder(\WC_Order $order): array
    {
        $products = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $product = $item->get_product();
                if ($product instanceof \WC_Product) {
                    $products[] = $product;
                }
            }
        }
        return self::fromProducts($products);
    }

    /**
     * @param \WC_Product[] $products
     * @return array{needs_shipping: bool, has_digital: bool, product_ids: int[]}
     */
    public static function fromProducts(array $products): array
    {
        $facts = ['needs_shipping' => false, 'has_digital' => false, 'product_ids' => []];
        foreach ($products as $product) {
            if ($product->needs_shipping()) {
                $facts['needs_shipping'] = true;
            }
            if ($product->is_virtual() || $product->is_downloadable()) {
                $facts['has_digital'] = true;
            }
            $facts['product_ids'][] = (int) $product->get_id();
            if ($product->get_parent_id()) {
                $facts['product_ids'][] = (int) $product->get_parent_id();
            }
        }
        $facts['product_ids'] = array_values(array_unique($facts['product_ids']));
        return $facts;
    }

    /**
     * What the cart is worth, for the shorter checkout and the free-order button.
     * items_total uses each item's current price (after any runtime pricing), so it does not
     * depend on totals having been calculated; fees_total and total do, and read 0 before.
     *
     * @return array{items_total: float, fees_total: float, total: float, subscription: bool}
     */
    public static function valueFromCart($cart = null): array
    {
        if ($cart === null && function_exists('WC') && WC()->cart) {
            $cart = WC()->cart;
        }
        $value = ['items_total' => 0.0, 'fees_total' => 0.0, 'total' => 0.0, 'subscription' => false];
        if (!$cart instanceof \WC_Cart) {
            return $value;
        }
        foreach ($cart->get_cart() as $item) {
            $product = isset($item['data']) && $item['data'] instanceof \WC_Product ? $item['data'] : null;
            if (!$product) {
                continue;
            }
            $value['items_total'] += (float) wc_format_decimal($product->get_price()) * max(0, (float) ($item['quantity'] ?? 1));
            if (!$value['subscription'] && function_exists('stackless_is_subscription_product') && stackless_is_subscription_product($product)) {
                $value['subscription'] = true;
            }
        }
        foreach ($cart->get_fees() as $fee) {
            if (is_object($fee) && isset($fee->amount)) {
                $value['fees_total'] += max(0.0, (float) $fee->amount);
            }
        }
        $value['total'] = (float) $cart->get_total('edit');
        if (!$value['subscription'] && function_exists('stackless_cart_contains_subscription') && stackless_cart_contains_subscription()) {
            $value['subscription'] = true;
        }
        return $value;
    }

    /** True when the cart has items and none of them ships. */
    public static function virtualOnly(array $facts): bool
    {
        return !empty($facts['product_ids']) && empty($facts['needs_shipping']);
    }
}
