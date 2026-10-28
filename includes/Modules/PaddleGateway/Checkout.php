<?php

namespace Stackless\Modules\PaddleGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Paddle transactions for WooCommerce orders, serves the page that opens
 * Paddle's overlay checkout for them, and handles the buyer's return.
 *
 * Paddle checkout runs in Paddle.js on a page of an approved domain, so the
 * buyer is sent to this store's own pay page (an order-key protected wc-api
 * endpoint), which opens the overlay for the order's transaction.
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
     * Paddle items for an order.
     *
     * @return array{items:array,subscription:?\WC_Order}|\WP_Error
     */
    public function plan(\WC_Order $order)
    {
        $currency = $order->get_currency();
        $context = [
            'currency' => $currency,
            /* translators: %s: order number */
            'label' => sprintf(__('Order #%s', 'stackless-lite'), $order->get_order_number()),
            'tax_mode' => $this->gateway->taxMode(),
            'tax_category' => $this->gateway->taxCategory(),
        ];
        $total = Money::toMinor($order->get_total(), $currency);
        $recurring = null;
        $subscription = null;

        if (!stackless_is_renewal_order($order) && stackless_order_contains_subscription($order)) {
            $subscriptions = array_values(stackless_get_order_subscriptions($order));
            if (count($subscriptions) !== 1) {
                return new \WP_Error('stackless_paddle_subscriptions', count($subscriptions) === 0
                    ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite')
                    : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
            }
            /** @var SubscriptionData&\WC_Order $candidate */
            $candidate = $subscriptions[0];
            // Store-billed subscriptions are paid one order at a time.
            if ($candidate->get_renewal_mode() !== 'manual') {
                $cycle = EventMap::cycle($candidate->get_billing_period(), $candidate->get_billing_interval());
                if (!$cycle) {
                    return new \WP_Error('stackless_paddle_interval', __('Paddle cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite'));
                }
                $subscription = $candidate;
                $recurring = [
                    'name' => self::subscriptionName($candidate),
                    'amount' => Money::toMinor($candidate->get_recurring_total(), $currency),
                    'cycle' => $cycle,
                    'trial_days' => Items::trialDays($candidate->get_trial_end_date(), time()),
                ];
            }
        }

        $items = Items::build($total, $recurring === null ? self::orderLines($order) : [], $recurring, $context);
        if (is_string($items)) {
            return new \WP_Error('stackless_paddle_' . $items, self::itemsMessage($items));
        }
        return ['items' => $items, 'subscription' => $subscription];
    }

    public static function itemsMessage(string $code): string
    {
        switch ($code) {
            case Items::ERROR_FIRST_PAYMENT:
                return __('Paddle charges at least one full billing cycle on the first payment, so it cannot take a first-payment discount. Remove the discount or choose another payment method.', 'stackless-lite');
            case Items::ERROR_TRIAL_CHARGE:
                return __('Paddle cannot charge a signup fee or other items during a free trial. Buy them separately or choose another payment method.', 'stackless-lite');
            default:
                return __('Paddle needs an amount greater than zero to start a checkout.', 'stackless-lite');
        }
    }

    /** Order lines, shipping and fees in the lowest denomination. @return array<int,array{name:string,amount:int}> */
    public static function orderLines(\WC_Order $order): array
    {
        $currency = $order->get_currency();
        $lines = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $quantity = (int) $item->get_quantity();
            $lines[] = [
                'name' => $item->get_name() . ($quantity > 1 ? ' × ' . $quantity : ''),
                'amount' => Money::toMinor($item->get_total(), $currency),
            ];
        }
        $shipping = (float) $order->get_shipping_total();
        if ($shipping !== 0.0) {
            $lines[] = ['name' => __('Shipping', 'stackless-lite'), 'amount' => Money::toMinor($shipping, $currency)];
        }
        foreach ($order->get_fees() as $fee) {
            $lines[] = ['name' => $fee->get_name(), 'amount' => Money::toMinor($fee->get_total(), $currency)];
        }
        // WooCommerce tax is left out on purpose: when it is charged, the lines no longer
        // add up and the order goes to Paddle as one line instead of itemizing tax as a product.
        return $lines;
    }

    private static function subscriptionName(\WC_Order $subscription): string
    {
        $names = [];
        foreach ($subscription->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $quantity = (int) $item->get_quantity();
                $names[] = $item->get_name() . ($quantity > 1 ? ' × ' . $quantity : '');
            }
        }
        return $names ? implode(', ', $names) : __('Subscription', 'stackless-lite');
    }

    /**
     * Create (or reuse) the Paddle transaction for an order.
     *
     * @return string|\WP_Error URL of the store's Paddle pay page.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_paddle_currency', sprintf(__('Paddle cannot charge in %s. Choose another payment method.', 'stackless-lite'), $currency));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }

        $body = $this->body($order, $plan['items'], $plan['subscription']);
        $fingerprint = $mode . ':' . md5((string) wp_json_encode($body));
        if (!$this->reusable($order, $fingerprint)) {
            $transaction = $this->gateway->api($mode)->request('POST', 'transactions', $body);
            if (is_wp_error($transaction)) {
                $this->gateway->log('Transaction for order ' . $order->get_id() . ' failed: ' . $transaction->get_error_message(), 'error');
                return $transaction;
            }
            if (empty($transaction['id'])) {
                return new \WP_Error('stackless_paddle_checkout', __('Paddle did not return a transaction.', 'stackless-lite'));
            }
            $order->update_meta_data(Gateway::META_TRANSACTION_ID, (string) $transaction['id']);
            $order->update_meta_data(Gateway::META_FINGERPRINT, $fingerprint);
            $this->gateway->log('Created Paddle transaction ' . $transaction['id'] . ' for order ' . $order->get_id() . '.');
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->save();
        return self::payUrl($order);
    }

    private function body(\WC_Order $order, array $items, ?\WC_Order $subscription): array
    {
        $body = [
            'items' => $items,
            'currency_code' => strtoupper($order->get_currency()),
            'custom_data' => array_filter([
                'wc_order_id' => (string) $order->get_id(),
                'wc_subscription_id' => $subscription ? (string) $subscription->get_id() : '',
                'wc_site' => self::siteHost(),
            ]),
            'checkout' => ['url' => self::payUrl($order)],
        ];
        return (array) apply_filters('stackless_paddle_gateway_transaction_args', $body, $order, $subscription);
    }

    /** Reuse the order's open transaction when nothing about it changed (double submits, back button). */
    private function reusable(\WC_Order $order, string $fingerprint): bool
    {
        $transactionId = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if ($transactionId === '' || (string) $order->get_meta(Gateway::META_FINGERPRINT) !== $fingerprint) {
            return false;
        }
        $mode = (string) strtok($fingerprint, ':');
        $transaction = $this->gateway->api($mode)->request('GET', 'transactions/' . rawurlencode($transactionId));
        return !is_wp_error($transaction) && in_array($transaction['status'] ?? '', ['draft', 'ready'], true);
    }

    public static function siteHost(): string
    {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    public static function payUrl(\WC_Order $order): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], Gateway::payPageBase());
    }

    public static function returnUrl(\WC_Order $order): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_paddle_return'));
    }

    public static function updatePaymentUrl(\WC_Order $subscription): string
    {
        return wp_nonce_url(add_query_arg(['subscription_id' => $subscription->get_id()], WC()->api_request_url('stackless_paddle_update_payment')), 'stackless_paddle_update_payment_' . $subscription->get_id());
    }

    /* ---- Pay page ---------------------------------------------------------------- */

    /** The order a pay or return request names, when its key checks out. */
    private function requestedOrder(): ?\WC_Order
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable
        $order = $orderId ? wc_get_order($orderId) : false;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || $order->get_payment_method() !== $this->gateway->id) {
            return null;
        }
        return $order;
    }

    public function renderPayPage(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        if ($order->is_paid()) {
            wp_safe_redirect($this->gateway->get_return_url($order));
            exit;
        }
        $transactionId = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if ($transactionId === '') {
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }
        $this->output([
            'transactionId' => $transactionId,
            'successUrl' => self::returnUrl($order),
            'cancelUrl' => $order->get_checkout_payment_url(),
            'email' => (string) $order->get_billing_email(),
            'sandbox' => $this->gateway->orderMode($order) === 'sandbox',
            'token' => $this->gateway->clientToken($this->gateway->orderMode($order)),
        ], $order);
    }

    /** Paddle's own checkout for replacing the card on a subscription it bills. */
    public function handleUpdatePayment(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
        $id = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
        $subscription = $id ? stackless_get_subscription($id) : null;
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        $back = wc_get_account_endpoint_url('dashboard');
        if (!$subscription instanceof \WC_Order || !is_user_logged_in() || !wp_verify_nonce($nonce, 'stackless_paddle_update_payment_' . $id)
            || ((int) $subscription->get_customer_id() !== get_current_user_id() && !current_user_can('manage_woocommerce'))
            || $subscription->get_payment_method() !== $this->gateway->id) {
            wp_safe_redirect($back);
            exit;
        }
        $paddleId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        $mode = $this->gateway->orderMode($subscription);
        $transaction = $paddleId !== '' ? $this->gateway->api($mode)->request('GET', 'subscriptions/' . rawurlencode($paddleId) . '/update-payment-method-transaction') : new \WP_Error('stackless_paddle_none', '');
        if (is_wp_error($transaction) || empty($transaction['id'])) {
            $this->gateway->log('Update payment method for subscription ' . $id . ' failed: ' . (is_wp_error($transaction) ? $transaction->get_error_message() : 'no transaction'), 'error');
            wc_add_notice(__('Paddle could not open the payment method update. Please try again later.', 'stackless-lite'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        $view = (string) $subscription->get_view_order_url();
        $this->output([
            'transactionId' => (string) $transaction['id'],
            'successUrl' => $view,
            'cancelUrl' => $view,
            'email' => (string) $subscription->get_billing_email(),
            'sandbox' => $mode === 'sandbox',
            'token' => $this->gateway->clientToken($mode),
        ], $subscription);
    }

    /** @param array<string,mixed> $config */
    private function output(array $config, \WC_Order $order): void
    {
        if ($config['token'] === '') {
            wc_add_notice(__('Paddle is missing its client-side token. Please choose another payment method.', 'stackless-lite'), 'error');
            wp_safe_redirect((string) $config['cancelUrl']);
            exit;
        }
        $config['locale'] = substr((string) get_locale(), 0, 2);
        $config['i18n'] = [
            'failed' => __('Paddle checkout could not load. Check your connection and try again.', 'stackless-lite'),
        ];
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Robots-Tag: noindex, nofollow');
        stackless_get_template('paddle_gateway/pay-page.php', [
            'order' => $order,
            'config' => (array) apply_filters('stackless_paddle_gateway_checkout_settings', $config, $order),
            'script_url' => self::scriptUrl(),
        ]);
        exit;
    }

    /** The pay page script, minified unless SCRIPT_DEBUG (the page is printed without wp_enqueue_script). */
    private static function scriptUrl(): string
    {
        $file = 'assets/paddle_gateway/pay.min.js';
        if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !file_exists(STACKLESS_PLUGIN_PATH . $file)) {
            $file = 'assets/paddle_gateway/pay.js';
        }
        return STACKLESS_PLUGIN_URL . $file . '?ver=' . rawurlencode(STACKLESS_VERSION);
    }

    /* ---- Buyer returns from Paddle ------------------------------------------------- */

    public function handleReturn(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }

        $transactionId = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if (!$order->is_paid() && $transactionId !== '') {
            $result = (new Sync($this->gateway))->confirmById($order, $transactionId);
            if (is_wp_error($result)) {
                $this->gateway->log('Return for order ' . $order->get_id() . ': ' . $result->get_error_message());
            }
        }

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
        // Paid, still settling, or abandoned: the order-received page shows the real state.
        wp_safe_redirect($this->gateway->get_return_url($order));
        exit;
    }
}
