<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * The card form's endpoint (wc-ajax): a client token for the currency being paid, the total to
 * verify with 3-D Secure, and a nonce for the customer's own saved card. The amount charged is
 * always the order's; Braintree refuses the charge when the verified amount differs.
 */
final class Checkout
{
    public const SESSION_ACTION = 'stackless_braintree_session';

    public static function registerHooks(): void
    {
        add_action('wc_ajax_' . self::SESSION_ACTION, [self::class, 'session']);
    }

    public static function session(): void
    {
        if (!check_ajax_referer(self::SESSION_ACTION, 'security', false)) {
            self::refuse(Browser::messages()['expired']);
        }
        $flow = isset($_POST['flow']) ? sanitize_key(wp_unslash((string) $_POST['flow'])) : 'checkout';
        $saved = isset($_POST['saved']) ? absint($_POST['saved']) : 0;
        $user = get_current_user_id();

        if ($saved > 0) {
            $card = Browser::savedCard($saved, $user);
            if (is_wp_error($card)) {
                self::refuse($card->get_error_message());
            }
            wp_send_json_success($card);
        }

        if ($flow === 'add') {
            if ($user <= 0) {
                self::refuse(__('Sign in to save a payment method.', 'stackless-lite'));
            }
            $currency = Connection::cardCurrency(Config::mode(), (string) get_woocommerce_currency());
            $total = 0;
            $requiresSave = true;
        } elseif ($flow === 'pay') {
            $orderId = isset($_POST['order']) ? absint($_POST['order']) : 0;
            $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash((string) $_POST['key'])) : '';
            $order = $orderId ? wc_get_order($orderId) : null;
            if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || !current_user_can('pay_for_order', $orderId)) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's capability.
                self::refuse(__('Order not found.', 'stackless-lite'));
            }
            $currency = strtoupper($order->get_currency());
            $total = $order->get_total();
            $requiresSave = stackless_order_requires_saved_payment_method($order);
        } else {
            $currency = strtoupper(stackless_current_currency());
            $total = 0;
            if (function_exists('WC') && WC()->cart) {
                // Fees and taxes that only apply at checkout look at this constant.
                if (!defined('WOOCOMMERCE_CHECKOUT')) {
                    define('WOOCOMMERCE_CHECKOUT', true);
                }
                WC()->cart->calculate_totals();
                $total = WC()->cart->get_total('edit');
            }
            $requiresSave = stackless_cart_requires_saved_payment_method();
        }

        $minor = Money::minor($total, $currency);
        if ($minor === null) {
            self::refuse(Declines::message(['category' => '', 'code' => 'amount_precision']));
        }
        $token = Browser::clientToken(Config::mode(), $currency);
        if (is_wp_error($token)) {
            self::refuse($token->get_error_message());
        }
        wp_send_json_success([
            'token' => $token,
            'currency' => $currency,
            'amount' => Money::value((int) $minor, $currency),
            'requiresSave' => $requiresSave,
        ]);
    }

    /** @return never */
    private static function refuse(string $message)
    {
        wp_send_json_error(['message' => $message]);
    }
}
