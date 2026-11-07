<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * Creates Checkout Pro preferences for WooCommerce orders and handles the buyer's return.
 *
 * A preference is one line for the order total, so shipping, discounts and taxes always add
 * up to what WooCommerce charges. The external reference "wc{order}-{site}" ties every
 * payment made against it back to the order.
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
     * Start (or reuse) a Checkout Pro preference for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Checkout Pro, or the order-received
     *                          page when the order turns out to be paid.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency, $this->gateway->siteId($mode))) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_mercadopago_currency', sprintf(__('Mercado Pago cannot charge in %s for this store. Choose another payment method.', 'stackless-lite'), $currency));
        }
        if ((float) $order->get_total() <= 0) {
            return new \WP_Error('stackless_mercadopago_zero', __('Mercado Pago needs an amount greater than zero.', 'stackless-lite'));
        }

        $amount = Money::amount($order->get_total(), $currency);
        $reused = $this->reusable($order, $mode, $amount);
        if ($reused !== '') {
            return $reused;
        }

        $preference = $this->gateway->api($mode)->request('POST', 'checkout/preferences', $this->body($order, $amount, $currency));
        if (is_wp_error($preference)) {
            $this->gateway->log('Preference for order ' . $order->get_id() . ' failed: ' . $preference->get_error_message(), 'error');
            return $preference;
        }
        $url = (string) ($preference['init_point'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || empty($preference['id'])) {
            return new \WP_Error('stackless_mercadopago_checkout', __('Mercado Pago did not return a checkout page.', 'stackless-lite'));
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_PREFERENCE_ID, (string) $preference['id']);
        $order->update_meta_data(Gateway::META_CHECKOUT_URL, $url);
        $order->update_meta_data(Gateway::META_STARTED_AT, (string) time());
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_AMOUNT, (string) $amount);
        $order->save();
        $this->gateway->log('Created Mercado Pago preference ' . $preference['id'] . ' for order ' . $order->get_id() . '.');
        return $url;
    }

    public static function externalReference(\WC_Order $order): string
    {
        return EventMap::externalReference($order->get_id(), Sync::siteHost());
    }

    public function body(\WC_Order $order, float $amount, string $currency): array
    {
        $store = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        /* translators: 1: order number, 2: store name */
        $title = sprintf(__('Order %1$s at %2$s', 'stackless-lite'), $order->get_order_number(), $store);
        $return = self::returnUrl($order);
        $body = [
            'items' => [[
                'id' => 'wc-' . $order->get_id(),
                'title' => function_exists('mb_substr') ? mb_substr($title, 0, 250) : substr($title, 0, 250),
                'quantity' => 1,
                'unit_price' => $amount,
                'currency_id' => $currency,
            ]],
            'payer' => array_filter([
                'email' => (string) $order->get_billing_email(),
                'name' => (string) $order->get_billing_first_name(),
                'surname' => (string) $order->get_billing_last_name(),
            ]),
            'back_urls' => ['success' => $return, 'pending' => $return, 'failure' => $return],
            'external_reference' => self::externalReference($order),
            'metadata' => ['wc_order_id' => (string) $order->get_id(), 'wc_site' => Sync::siteHost()],
        ];
        // Mercado Pago only returns buyers automatically to HTTPS addresses.
        if (strpos($return, 'https://') === 0) {
            $body['auto_return'] = 'approved';
        }
        return (array) apply_filters('stackless_mercadopago_gateway_checkout_args', $body, $order, $this->gateway);
    }

    public static function returnUrl(\WC_Order $order): string
    {
        // Mercado Pago appends payment_id, status, external_reference and preference_id.
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_mercadopago_return'));
    }

    /** Reuse the order's preference for the same amount and mode, unless a payment already went through. */
    private function reusable(\WC_Order $order, string $mode, float $amount): string
    {
        $url = (string) $order->get_meta(Gateway::META_CHECKOUT_URL);
        if ($url === '' || (string) $order->get_meta(Gateway::META_MODE) !== $mode || (string) $order->get_meta(Gateway::META_AMOUNT) !== (string) $amount
            || (int) $order->get_meta(Gateway::META_STARTED_AT) < time() - Gateway::REUSE_FOR) {
            return '';
        }
        $sync = new Sync($this->gateway);
        $payment = $sync->latestPayment($order, $mode);
        if (is_wp_error($payment)) {
            return '';
        }
        if ($payment && EventMap::paymentState((string) ($payment['status'] ?? '')) !== 'failed') {
            $sync->apply($order, $payment, $mode);
            return $this->gateway->get_return_url($order);
        }
        return $url;
    }

    /* ---- Buyer returns from Mercado Pago ------------------------------------------- */

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key; the payment is read back from Mercado Pago.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $paymentId = isset($_GET['payment_id']) ? sanitize_text_field(wp_unslash($_GET['payment_id'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        $gateway = Gateway::instance();
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $gateway->id || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $target = self::returnTarget($gateway, $order, $paymentId, $status);
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
     * Where a buyer coming back from Mercado Pago goes. The payment is read back from Mercado
     * Pago; the address only says which payment to read.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, \WC_Order $order, string $paymentId = '', string $status = ''): array
    {
        $state = '';
        if (!$order->is_paid()) {
            $sync = new Sync($gateway);
            $mode = $gateway->orderMode($order);
            $payment = $paymentId !== '' && ctype_digit($paymentId) ? $sync->payment($paymentId, $mode) : $sync->latestPayment($order, $mode);
            if (is_array($payment) && $payment && $sync->belongsTo($payment, $order)) {
                $state = EventMap::paymentState((string) ($payment['status'] ?? ''));
                $sync->apply($order, $payment, $mode);
            } elseif (is_wp_error($payment)) {
                $gateway->log('Return for order ' . $order->get_id() . ': ' . $payment->get_error_message());
            }
            $order = wc_get_order($order->get_id()) ?: $order;
        }
        $failed = $state === 'failed' || ($state === '' && in_array($status, ['rejected', 'failure', 'null'], true));
        if (!$order->is_paid() && !$order->has_status('on-hold') && $failed) {
            return [
                'url' => $order->get_checkout_payment_url(),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'),
            ];
        }
        return ['url' => $gateway->get_return_url($order), 'empty_cart' => true, 'notice' => ''];
    }
}
