<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * Creates Flutterwave hosted payment links for WooCommerce orders and handles the return.
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
     * Start (or reuse) a Flutterwave payment for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Flutterwave's checkout, or the
     *                          order-received page when the order turns out to be paid.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_flutterwave_currency', sprintf(__('Flutterwave cannot charge in %s. Choose another payment method.', 'stackless-lite'), $currency));
        }
        if ((float) $order->get_total() <= 0) {
            return new \WP_Error('stackless_flutterwave_zero', __('Flutterwave needs an amount greater than zero.', 'stackless-lite'));
        }
        $email = (string) $order->get_billing_email();
        if ($email === '' || !is_email($email)) {
            return new \WP_Error('stackless_flutterwave_email', __('Flutterwave needs a valid email address to take a payment.', 'stackless-lite'));
        }

        $amount = Money::format($order->get_total(), $currency);
        $reused = $this->reusable($order, $mode, $amount);
        if ($reused !== '') {
            return $reused;
        }

        $txRef = self::reference($order->get_id());
        $session = $this->gateway->api($mode)->request('POST', 'payments', $this->body($order, $txRef, $amount, $currency));
        if (is_wp_error($session)) {
            $this->gateway->log('Payment link for order ' . $order->get_id() . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        $url = (string) ($session['link'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error('stackless_flutterwave_checkout', __('Flutterwave did not return a checkout page.', 'stackless-lite'));
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_TX_REF, $txRef);
        $order->update_meta_data(Gateway::META_CHECKOUT_URL, $url);
        $order->update_meta_data(Gateway::META_STARTED_AT, (string) time());
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_AMOUNT, $amount);
        $order->save();
        $this->gateway->log('Started Flutterwave payment ' . $txRef . ' for order ' . $order->get_id() . '.');
        return $url;
    }

    /** A fresh tx_ref: "wc{order id}-{random}", unique per attempt. */
    public static function reference(int $orderId): string
    {
        return 'wc' . $orderId . '-' . strtolower(wp_generate_password(10, false, false));
    }

    /** Whether a tx_ref was made by this store for this order. */
    public static function referenceFor(string $txRef, int $orderId): bool
    {
        return $orderId > 0 && strpos($txRef, 'wc' . $orderId . '-') === 0;
    }

    /** The order id a tx_ref was made for, 0 when it is not one of ours. */
    public static function orderIdFrom(string $txRef): int
    {
        return preg_match('/^wc(\d+)-[a-z0-9]+$/', $txRef, $m) ? (int) $m[1] : 0;
    }

    public function body(\WC_Order $order, string $txRef, string $amount, string $currency): array
    {
        $customer = ['email' => (string) $order->get_billing_email()];
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name !== '') {
            $customer['name'] = function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
        }
        $phone = preg_replace('/[^0-9+]/', '', (string) $order->get_billing_phone());
        if ($phone !== '') {
            $customer['phonenumber'] = $phone;
        }
        $body = [
            'tx_ref' => $txRef,
            'amount' => $amount,
            'currency' => $currency,
            'redirect_url' => self::returnUrl($order),
            'customer' => $customer,
            'customizations' => [
                'title' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
                /* translators: %s: order number */
                'description' => sprintf(__('Order %s', 'stackless-lite'), $order->get_order_number()),
            ],
            'meta' => [
                'wc_order_id' => (string) $order->get_id(),
                'wc_site' => Sync::siteHost(),
            ],
        ];
        return (array) apply_filters('stackless_flutterwave_gateway_checkout_args', $body, $order, $this->gateway);
    }

    public static function returnUrl(\WC_Order $order): string
    {
        // Flutterwave appends status, tx_ref and transaction_id to this address.
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_flutterwave_return'));
    }

    /** Reuse an unpaid payment link for the same order, amount and mode. */
    private function reusable(\WC_Order $order, string $mode, string $amount): string
    {
        $txRef = (string) $order->get_meta(Gateway::META_TX_REF);
        $url = (string) $order->get_meta(Gateway::META_CHECKOUT_URL);
        if ($txRef === '' || $url === '' || (string) $order->get_meta(Gateway::META_MODE) !== $mode || (string) $order->get_meta(Gateway::META_AMOUNT) !== $amount
            || (int) $order->get_meta(Gateway::META_STARTED_AT) < time() - Gateway::REUSE_FOR) {
            return '';
        }
        $transaction = $this->gateway->api($mode)->request('GET', 'transactions/verify_by_reference', ['tx_ref' => $txRef]);
        if (is_wp_error($transaction)) {
            // Nothing paid against this link yet.
            return Api::isNotFound($transaction) ? $url : '';
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state === 'paid') {
            (new Sync($this->gateway))->settle($order, $transaction, $mode);
            return $this->gateway->get_return_url($order);
        }
        return $state === 'pending' ? $url : '';
    }

    /* ---- Buyer returns from Flutterwave --------------------------------------------- */

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key; the transaction is verified with Flutterwave.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $txRef = isset($_GET['tx_ref']) ? sanitize_text_field(wp_unslash($_GET['tx_ref'])) : '';
        $transactionId = isset($_GET['transaction_id']) ? sanitize_text_field(wp_unslash($_GET['transaction_id'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        $gateway = Gateway::instance();
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $gateway->id || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $target = self::returnTarget($gateway, $order, $txRef, $transactionId, $status);
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
     * Where a buyer coming back from Flutterwave goes. The query string is only a hint: the
     * transaction is verified with Flutterwave before anything changes.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, \WC_Order $order, string $txRef = '', string $transactionId = '', string $status = ''): array
    {
        $failed = false;
        if (!$order->is_paid()) {
            if (!self::referenceFor($txRef, $order->get_id())) {
                $txRef = (string) $order->get_meta(Gateway::META_TX_REF);
                $transactionId = '';
            }
            $result = $txRef !== '' ? (new Sync($gateway))->confirm($order, $txRef, $transactionId) : new \WP_Error('stackless_flutterwave_pending', '');
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $order->get_id() . ': ' . $result->get_error_message());
                $failed = $result->get_error_code() === 'stackless_flutterwave_failed'
                    || (in_array($status, ['cancelled', 'failed'], true) && $result->get_error_code() !== 'stackless_flutterwave_pending_payment');
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
