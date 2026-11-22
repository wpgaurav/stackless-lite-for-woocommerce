<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * While a Braintree charge of an order has no known result, paying that order another way would
 * charge the customer twice. Braintree itself stays offered: paying with it settles the open
 * charge first and only charges when that one is unpaid or absent.
 */
final class Guard
{
    public static function registerHooks(): void
    {
        add_filter('woocommerce_available_payment_gateways', [self::class, 'gateways'], 70);
        add_action('woocommerce_before_pay_action', [self::class, 'guardPay'], 2);
        add_action('woocommerce_checkout_order_processed', [self::class, 'guardClassic'], 1, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'guardStoreApi'], 1);
        add_filter('stackless_order_pay_link_allowed', [self::class, 'payLink'], 10, 2);
    }

    /** @param mixed $order */
    public static function waiting($order): bool
    {
        if (!$order instanceof \WC_Order) {
            return false;
        }
        $attempt = $order->get_meta(Meta::ATTEMPT);
        return is_array($attempt) && !empty($attempt['key']) && !$order->is_paid();
    }

    private static function message(): string
    {
        return __('An earlier card payment for this order is still being confirmed. Wait a little before paying another way, so you are not charged twice.', 'stackless-lite');
    }

    /** @param mixed $gateways @return mixed */
    public static function gateways($gateways)
    {
        if (!is_array($gateways) || !function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return $gateways;
        }
        $order = wc_get_order(absint(get_query_var('order-pay')));
        return self::waiting($order) ? array_intersect_key($gateways, [Gateway::ID => true]) : $gateways;
    }

    /** @param mixed $order */
    public static function guardPay($order): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the pay form's nonce before this action.
        $method = isset($_POST['payment_method']) ? sanitize_key(wp_unslash((string) $_POST['payment_method'])) : '';
        if ($method === Gateway::ID || !$order instanceof \WC_Order || !self::waiting($order)) {
            return;
        }
        wc_add_notice(self::message(), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * @param mixed $orderId
     * @param mixed $posted
     * @param mixed $order
     */
    public static function guardClassic($orderId, $posted = [], $order = null): void
    {
        if ($order instanceof \WC_Order && $order->get_payment_method() !== Gateway::ID && self::waiting($order)) {
            // WooCommerce shows the exception as the checkout error.
            throw new \Exception(esc_html(self::message()));
        }
    }

    /** @param mixed $order */
    public static function guardStoreApi($order): void
    {
        if (!$order instanceof \WC_Order || $order->get_payment_method() === Gateway::ID || !class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
            return;
        }
        if (self::waiting($order)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('stackless_braintree_confirming', esc_html(self::message()), 400);
        }
    }

    /**
     * @param mixed $allowed
     * @param mixed $order
     */
    public static function payLink($allowed, $order = null): bool
    {
        return (bool) $allowed && !self::waiting($order);
    }
}
