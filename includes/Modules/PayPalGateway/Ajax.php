<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * wc-ajax endpoints the PayPal buttons call. They run on the storefront request
 * stack, so the cart and session are loaded for guests and customers alike.
 */
final class Ajax
{
    public const NONCE = 'stackless-paypal-gateway';

    public static function register(): void
    {
        add_action('wc_ajax_stackless_paypal_create_order', [self::class, 'createOrder']);
        add_action('wc_ajax_stackless_paypal_create_setup_token', [self::class, 'createSetupToken']);
    }

    public static function urls(): array
    {
        return [
            'createOrder' => \WC_AJAX::get_endpoint('stackless_paypal_create_order'),
            'createSetupToken' => \WC_AJAX::get_endpoint('stackless_paypal_create_setup_token'),
        ];
    }

    public static function createOrder(): void
    {
        self::verify();
        $gateway = Gateway::instance();
        $funding = self::funding();
        $context = self::context();

        if ($context === 'order-pay') {
            $order = self::order();
            $result = $gateway->payments()->createForOrder($order, $funding);
        } else {
            $result = $gateway->payments()->createForCart($funding);
        }
        self::respond($result);
    }

    public static function createSetupToken(): void
    {
        self::verify();
        $gateway = Gateway::instance();
        $payments = $gateway->payments();
        $context = self::context();

        if ($context === 'order-pay') {
            $order = self::order();
            if ((float) $order->get_total() > 0 || !stackless_order_requires_saved_payment_method($order)) {
                self::respond(new \WP_Error('stackless_paypal_setup', __('This order needs a payment, not just a saved account.', 'stackless-lite')));
            }
            $result = $payments->createSetupToken($payments->returnUrl($order, 'setup'), $order->get_checkout_payment_url(), $order);
        } elseif ($context === 'add-payment-method') {
            if (!is_user_logged_in()) {
                self::respond(new \WP_Error('stackless_paypal_login', __('Log in to save a PayPal account.', 'stackless-lite')));
            }
            $url = wc_get_endpoint_url('add-payment-method', '', wc_get_page_permalink('myaccount'));
            $result = $payments->createSetupToken($url, $url);
        } else {
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) {
                self::respond(new \WP_Error('stackless_paypal_cart', __('Your cart is empty.', 'stackless-lite')));
            }
            $cart->calculate_totals();
            if ((float) $cart->get_total('edit') > 0 || !stackless_cart_requires_saved_payment_method()) {
                self::respond(new \WP_Error('stackless_paypal_setup', __('This cart needs a payment, not just a saved account.', 'stackless-lite')));
            }
            $url = wc_get_checkout_url();
            $result = $payments->createSetupToken($url, $url);
        }

        if (is_array($result)) {
            unset($result['approve']);
        }
        self::respond($result);
    }

    private static function verify(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Refresh the page and try again.', 'stackless-lite')], 403);
        }
        $gateway = Gateway::instance();
        if ($gateway->enabled !== 'yes' || !$gateway->api()->hasCredentials()) {
            wp_send_json_error(['message' => __('PayPal is not available.', 'stackless-lite')], 400);
        }
    }

    private static function context(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().
        $context = isset($_POST['context']) ? sanitize_key(wp_unslash($_POST['context'])) : 'checkout';
        return in_array($context, ['checkout', 'order-pay', 'add-payment-method'], true) ? $context : 'checkout';
    }

    private static function funding(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().
        $funding = isset($_POST['funding']) ? sanitize_key(wp_unslash($_POST['funding'])) : 'paypal';
        return preg_match('/^[a-z0-9_-]{0,30}$/', $funding) ? $funding : 'paypal';
    }

    /** Order-pay requests prove ownership with the order key. */
    private static function order(): \WC_Order
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
        // phpcs:enable
        $order = $orderId ? wc_get_order($orderId) : false;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            wp_send_json_error(['message' => __('Order not found.', 'stackless-lite')], 404);
        }
        $customer = (int) $order->get_customer_id();
        if ($customer > 0 && $customer !== get_current_user_id()) {
            wp_send_json_error(['message' => __('Log in to the account that placed this order to pay for it.', 'stackless-lite')], 403);
        }
        return $order;
    }

    /** @param array|\WP_Error $result */
    private static function respond($result): void
    {
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        wp_send_json_success($result);
    }
}
