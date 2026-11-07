<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * Starts Paystack transactions for WooCommerce orders and handles the buyer's return.
 */
class Checkout
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Start (or reuse) a Paystack transaction for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Paystack's checkout, or the
     *                          order-received page when the order turns out to be paid.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_paystack_currency', sprintf(__('Paystack cannot charge in %s. Choose another payment method.', 'stackless-lite'), $currency));
        }
        $total = (float) $order->get_total();
        if ($total <= 0) {
            return new \WP_Error('stackless_paystack_zero', __('Paystack needs an amount greater than zero.', 'stackless-lite'));
        }
        $minimum = Money::minimum($currency);
        if ($minimum > 0 && $total < $minimum) {
            return new \WP_Error('stackless_paystack_minimum', sprintf(
                /* translators: %s: smallest amount Paystack accepts */
                __('Paystack accepts payments of %s or more. Choose another payment method for this order.', 'stackless-lite'),
                wp_strip_all_tags(wc_price($minimum, ['currency' => $currency]))
            ));
        }
        $email = (string) $order->get_billing_email();
        if ($email === '' || !is_email($email)) {
            return new \WP_Error('stackless_paystack_email', __('Paystack needs a valid email address to take a payment.', 'stackless-lite'));
        }

        $minor = Money::toMinor($total, $currency);
        $reused = $this->reusable($order, $mode, $minor);
        if ($reused !== '') {
            return $reused;
        }

        $reference = self::reference($order->get_id());
        $session = $this->gateway->api($mode)->request('POST', 'transaction/initialize', $this->body($order, $reference, $minor, $currency));
        if (is_wp_error($session)) {
            $this->gateway->log('Initialize for order ' . $order->get_id() . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        $url = (string) ($session['authorization_url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error('stackless_paystack_checkout', __('Paystack did not return a checkout page.', 'stackless-lite'));
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_REFERENCE, (string) ($session['reference'] ?? $reference));
        $order->update_meta_data(Gateway::META_CHECKOUT_URL, $url);
        $order->update_meta_data(Gateway::META_STARTED_AT, (string) time());
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_AMOUNT, (string) $minor);
        $order->save();
        $this->gateway->log('Started Paystack transaction ' . $reference . ' for order ' . $order->get_id() . '.');
        return $url;
    }

    /** A fresh transaction reference: "wc{order id}-{random}", unique per attempt. */
    public static function reference(int $orderId): string
    {
        return 'wc' . $orderId . '-' . strtolower(wp_generate_password(10, false, false));
    }

    /** Whether a reference was made by this store for this order. */
    public static function referenceFor(string $reference, int $orderId): bool
    {
        return $orderId > 0 && strpos($reference, 'wc' . $orderId . '-') === 0;
    }

    public function body(\WC_Order $order, string $reference, int $minor, string $currency): array
    {
        $body = [
            'email' => (string) $order->get_billing_email(),
            'amount' => (string) $minor,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => self::returnUrl($order),
            'metadata' => [
                'wc_order_id' => (string) $order->get_id(),
                'wc_site' => Sync::siteHost(),
                'cancel_action' => self::cancelUrl($order),
                'custom_fields' => [[
                    'display_name' => __('Order', 'stackless-lite'),
                    'variable_name' => 'wc_order_number',
                    'value' => (string) $order->get_order_number(),
                ]],
            ],
        ];
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name !== '') {
            $body['metadata']['customer_name'] = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
        }
        return (array) apply_filters('stackless_paystack_gateway_checkout_args', $body, $order, $this->gateway);
    }

    public static function returnUrl(\WC_Order $order): string
    {
        // Paystack appends ?trxref=…&reference=… to this address.
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_paystack_return'));
    }

    /** Where Paystack sends a buyer who cancels: back to paying this order. */
    public static function cancelUrl(\WC_Order $order): string
    {
        foreach ([$order->get_checkout_payment_url(), wc_get_checkout_url(), home_url('/')] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return (string) $url;
            }
        }
        return home_url('/');
    }

    /** Reuse an unpaid transaction for the same order, amount and mode (double submits, back button). */
    private function reusable(\WC_Order $order, string $mode, int $minor): string
    {
        $reference = (string) $order->get_meta(Gateway::META_REFERENCE);
        $url = (string) $order->get_meta(Gateway::META_CHECKOUT_URL);
        if ($reference === '' || $url === '' || (string) $order->get_meta(Gateway::META_MODE) !== $mode || (int) $order->get_meta(Gateway::META_AMOUNT) !== $minor
            || (int) $order->get_meta(Gateway::META_STARTED_AT) < time() - Gateway::REUSE_FOR) {
            return '';
        }
        $transaction = $this->gateway->api($mode)->request('GET', 'transaction/verify/' . rawurlencode($reference));
        if (is_wp_error($transaction)) {
            return '';
        }
        $status = strtolower((string) ($transaction['status'] ?? ''));
        if ($status === 'success') {
            // Paid in another tab: settle it and show the order.
            (new Sync($this->gateway))->settle($order, $transaction, $mode);
            return $this->gateway->get_return_url($order);
        }
        return in_array($status, ['abandoned', 'ongoing', 'pending'], true) ? $url : '';
    }

    /* ---- Buyer returns from Paystack ------------------------------------------------ */

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key; the transaction is verified with Paystack.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $reference = isset($_GET['reference']) ? sanitize_text_field(wp_unslash($_GET['reference'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        $gateway = Gateway::instance();
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $gateway->id || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $target = self::returnTarget($gateway, $order, $reference);
        if ($target['empty_cart'] && WC()->cart) {
            WC()->cart->empty_cart();
        }
        if ($target['notice'] !== '') {
            wc_add_notice($target['notice'], 'error');
        }
        wp_safe_redirect($target['url']);
        exit;
    }

    /**
     * Where a buyer coming back from Paystack goes. The transaction is verified with
     * Paystack; the reference in the address only says which of this order's attempts to check.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, \WC_Order $order, string $reference = ''): array
    {
        $failed = false;
        if (!$order->is_paid()) {
            if (!Checkout::referenceFor($reference, $order->get_id())) {
                $reference = (string) $order->get_meta(Gateway::META_REFERENCE);
            }
            $result = $reference !== '' ? (new Sync($gateway))->confirm($order, $reference) : new \WP_Error('stackless_paystack_pending', '');
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $order->get_id() . ': ' . $result->get_error_message());
                $failed = $result->get_error_code() === 'stackless_paystack_failed';
            }
            $order = wc_get_order($order->get_id()) ?: $order;
        }
        if (!$order->is_paid() && $failed) {
            return [
                'url' => $order->get_checkout_payment_url(),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'),
            ];
        }
        return ['url' => $gateway->get_return_url($order), 'empty_cart' => true, 'notice' => ''];
    }
}
