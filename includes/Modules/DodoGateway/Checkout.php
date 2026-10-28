<?php

namespace Stackless\Modules\DodoGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Dodo Payments checkout sessions for WooCommerce orders and handles the
 * buyer's return from them.
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
     * What Dodo should bill for an order.
     *
     * @return array{recurring:?array,amount:float,trial_days:int,subscription:?\WC_Order}|\WP_Error
     */
    public function plan(\WC_Order $order)
    {
        $oneTime = ['recurring' => null, 'amount' => (float) $order->get_total(), 'trial_days' => 0, 'subscription' => null];

        if (stackless_is_renewal_order($order) || !stackless_order_contains_subscription($order)) {
            return $oneTime;
        }

        $subscriptions = array_values(stackless_get_order_subscriptions($order));
        if (count($subscriptions) !== 1) {
            return new \WP_Error('stackless_dodo_subscriptions', count($subscriptions) === 0
                ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite')
                : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
        }
        /** @var SubscriptionData&\WC_Order $subscription */
        $subscription = $subscriptions[0];

        // Store-billed subscriptions are paid one order at a time.
        if ($subscription->get_renewal_mode() === 'manual') {
            return $oneTime;
        }

        $recurring = EventMap::interval($subscription->get_billing_period(), $subscription->get_billing_interval());
        if (!$recurring) {
            return new \WP_Error('stackless_dodo_interval', __('Dodo Payments cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite'));
        }

        $amount = (float) $subscription->get_recurring_total();
        if ($amount <= 0) {
            return new \WP_Error('stackless_dodo_zero', __('Dodo Payments needs a recurring amount above zero.', 'stackless-lite'));
        }

        $trialDays = self::trialDays($subscription->get_trial_end_date(), time());
        $expectedFirst = $trialDays > 0 ? 0.0 : $amount;
        // A Dodo subscription product bills one price every cycle, so a first payment
        // that differs from the renewal cannot be represented.
        if (!Money::equals($order->get_total(), $expectedFirst, $order->get_currency())) {
            return new \WP_Error('stackless_dodo_first_payment', __('Dodo Payments charges the same amount on every billing cycle, so it cannot take a signup fee or a different first payment. Remove the signup fee or first-payment discount, or choose another payment method.', 'stackless-lite'));
        }

        return ['recurring' => $recurring, 'amount' => $amount, 'trial_days' => $trialDays, 'subscription' => $subscription];
    }

    /** Whole days left in a trial (0 when none). Dodo trials run up to 10,000 days. */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) min(10000, (int) ceil($seconds / 86400)) : 0;
    }

    /**
     * Start (or reuse) a Dodo checkout session for an order.
     *
     * @return string|\WP_Error Checkout URL.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_dodo_currency', sprintf(__('Dodo Payments cannot charge in %s. Choose another payment method.', 'stackless-lite'), $currency));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if ($plan['amount'] <= 0) {
            return new \WP_Error('stackless_dodo_zero', __('Dodo Payments needs an amount greater than zero to start a checkout.', 'stackless-lite'));
        }

        $minor = Money::toMinor($plan['amount'], $currency);
        $reused = $this->reusable($order, $mode, $minor);
        if ($reused !== '') {
            return $reused;
        }

        $catalog = $this->gateway->catalog();
        $item = Catalog::itemForOrder($order, $plan['subscription']);
        $productId = $catalog->productId($item, $plan['recurring'], $currency, $minor, $mode);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $api = $this->gateway->api($mode);
        $session = $api->request('POST', 'checkouts', $this->body($order, $productId, $minor, $currency, $plan));

        // A product archived or deleted in Dodo is recreated once.
        if (is_wp_error($session) && Api::isProductError($session)) {
            $catalog->forget($item, $plan['recurring'], $currency, $minor, $mode);
            $productId = $catalog->productId($item, $plan['recurring'], $currency, $minor, $mode, true);
            if (is_wp_error($productId)) {
                return $productId;
            }
            $session = $api->request('POST', 'checkouts', $this->body($order, $productId, $minor, $currency, $plan));
        }
        if (is_wp_error($session)) {
            $this->gateway->log('Checkout for order ' . $order->get_id() . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        if (empty($session['checkout_url']) || empty($session['session_id'])) {
            return new \WP_Error('stackless_dodo_checkout', __('Dodo Payments did not return a checkout page.', 'stackless-lite'));
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_SESSION_ID, (string) $session['session_id']);
        $order->update_meta_data(Gateway::META_CHECKOUT_URL, (string) $session['checkout_url']);
        $order->update_meta_data(Gateway::META_SESSION_AT, (string) time());
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_AMOUNT, (string) $minor);
        $order->update_meta_data(Gateway::META_PRODUCT_ID, $productId);
        $order->update_meta_data(Gateway::META_TAX_INCLUSIVE, $this->gateway->taxInclusive() ? 'yes' : 'no');
        $order->save();
        $this->gateway->log('Started Dodo checkout ' . $session['session_id'] . ' for order ' . $order->get_id() . '.');
        return (string) $session['checkout_url'];
    }

    public static function returnUrl(\WC_Order $order): string
    {
        // Dodo appends payment_id or subscription_id and status to this address.
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_dodo_return'));
    }

    /** Where the buyer goes when they leave Dodo's checkout: back to paying this order. */
    public static function cancelUrl(\WC_Order $order): string
    {
        // Dodo refuses a session whose cancel URL is not a full address.
        foreach ([$order->get_checkout_payment_url(), wc_get_checkout_url(), home_url('/')] as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return (string) $url;
            }
        }
        return home_url('/');
    }

    public function body(\WC_Order $order, string $productId, int $minor, string $currency, array $plan): array
    {
        $recurring = $plan['recurring'] !== null;
        $line = ['product_id' => $productId, 'quantity' => 1];
        if (!$recurring) {
            $line['amount'] = $minor;
        }
        $subscription = $plan['subscription'];

        $body = [
            'product_cart' => [$line],
            // Pinned, so adaptive pricing cannot move the buyer to another currency.
            'billing_currency' => strtoupper($currency),
            'return_url' => self::returnUrl($order),
            'cancel_url' => self::cancelUrl($order),
            'feature_flags' => [
                'allow_discount_code' => false,
                'allow_currency_selection' => false,
                'redirect_immediately' => true,
            ],
            'metadata' => array_filter([
                'wc_order_id' => (string) $order->get_id(),
                'wc_subscription_id' => $subscription instanceof \WC_Order ? (string) $subscription->get_id() : '',
                'wc_gateway' => $this->gateway->id,
                'wc_site' => Catalog::siteHost(),
            ]),
        ];
        if ($recurring) {
            $body['subscription_data'] = ['trial_period_days' => max(0, min(10000, (int) $plan['trial_days']))];
        }

        $email = (string) $order->get_billing_email();
        if ($email !== '' && is_email($email)) {
            $body['customer'] = ['email' => $email];
            $name = trim($order->get_formatted_billing_full_name());
            if ($name !== '') {
                $body['customer']['name'] = function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200);
            }
        }
        $country = strtoupper((string) $order->get_billing_country());
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $body['billing_address'] = array_filter([
                'country' => $country,
                'state' => (string) $order->get_billing_state(),
                'city' => (string) $order->get_billing_city(),
                'street' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
                'zipcode' => (string) $order->get_billing_postcode(),
            ], static function (string $value): bool {
                return $value !== '';
            });
        }

        return (array) apply_filters('stackless_dodo_gateway_checkout_args', $body, $order, $subscription, $this->gateway);
    }

    /** Reuse an unpaid session for the same order and amount (double submits, back button). */
    private function reusable(\WC_Order $order, string $mode, int $minor): string
    {
        $sessionId = (string) $order->get_meta(Gateway::META_SESSION_ID);
        $url = (string) $order->get_meta(Gateway::META_CHECKOUT_URL);
        if ($sessionId === '' || $url === '' || (string) $order->get_meta(Gateway::META_MODE) !== $mode || (int) $order->get_meta(Gateway::META_AMOUNT) !== $minor) {
            return '';
        }
        if ((int) $order->get_meta(Gateway::META_SESSION_AT) < time() - Gateway::SESSION_TTL) {
            return '';
        }
        $session = $this->gateway->api($mode)->request('GET', 'checkouts/' . rawurlencode($sessionId));
        if (is_wp_error($session) || !empty($session['payment_status']) || !empty($session['payment_id'])) {
            return '';
        }
        return $url;
    }

    /* ---- Buyer returns from Dodo ------------------------------------------------- */

    /** One return route serves Dodo and every copy; the order says which one it used. */
    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $subscriptionHint = isset($_GET['subscription_id']) ? sanitize_text_field(wp_unslash($_GET['subscription_id'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        $gateway = $order instanceof \WC_Order ? Gateway::forOrder($order) : null;
        if (!$order instanceof \WC_Order || !$gateway || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a hint only; the payment is looked up at Dodo.
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $target = self::returnTarget($gateway, $order, $subscriptionHint, $status);
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
     * Where a buyer coming back from Dodo goes, and whether their cart is done with.
     *
     * Only the session this order started is trusted; the query string ($status from Dodo) is a
     * hint. A failed or cancelled payment sends the buyer back to pay with their cart kept.
     * Paid orders, and payments still settling (confirmed later by the webhook), go to the
     * order-received page, which shows the real state.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, \WC_Order $order, string $subscriptionHint = '', string $status = ''): array
    {
        $failed = false;
        if (!$order->is_paid() && (string) $order->get_meta(Gateway::META_SESSION_ID) !== '') {
            $result = (new Sync($gateway))->confirmCheckout($order, $subscriptionHint);
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $order->get_id() . ': ' . $result->get_error_message());
                $failed = $result->get_error_code() === 'stackless_dodo_failed';
            }
            $order = wc_get_order($order->get_id()) ?: $order;
        }
        if (!$order->is_paid() && ($failed || in_array($status, ['failed', 'cancelled'], true))) {
            return [
                'url' => $order->get_checkout_payment_url(),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'),
            ];
        }
        return ['url' => $gateway->get_return_url($order), 'empty_cart' => true, 'notice' => ''];
    }
}
