<?php

namespace Stackless\Modules\CreemGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Creem checkout sessions for WooCommerce orders and handles the buyer's return.
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
     * What Creem should bill for an order.
     *
     * @return array{billing:?array,amount:float,trial_days:int,subscription:?\WC_Order}|\WP_Error
     */
    public function plan(\WC_Order $order)
    {
        $oneTime = ['billing' => null, 'amount' => (float) $order->get_total(), 'trial_days' => 0, 'subscription' => null];

        if (stackless_is_renewal_order($order) || !stackless_order_contains_subscription($order)) {
            return $oneTime;
        }

        $subscriptions = array_values(stackless_get_order_subscriptions($order));
        if (count($subscriptions) !== 1) {
            return new \WP_Error('stackless_creem_subscriptions', count($subscriptions) === 0
                ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite')
                : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
        }
        /** @var SubscriptionData&\WC_Order $subscription */
        $subscription = $subscriptions[0];

        // Creem bills every subscription it takes, so it can't take one the store bills by
        // invoice: the store would never send the next invoice for a Creem subscription.
        if ($subscription->get_renewal_mode() === 'manual') {
            return new \WP_Error('stackless_creem_manual', __('Creem bills subscriptions itself, so this subscription must renew automatically. Choose automatic renewal or another payment method.', 'stackless-lite'));
        }

        $billing = EventMap::interval($subscription->get_billing_period(), $subscription->get_billing_interval());
        if (!$billing) {
            return new \WP_Error('stackless_creem_interval', __('Creem cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite'));
        }

        $amount = (float) $subscription->get_recurring_total();
        if (Money::toMinor($amount) < Money::MINIMUM) {
            return new \WP_Error('stackless_creem_zero', __('Creem needs a recurring amount of at least 1.00.', 'stackless-lite'));
        }

        $trialDays = self::trialDays($subscription->get_trial_end_date(), time());
        $expectedFirst = $trialDays > 0 ? 0.0 : $amount;
        // A Creem subscription product bills one price every cycle, so a first payment
        // that differs from the renewal cannot be represented.
        if (!Money::equals($order->get_total(), $expectedFirst)) {
            return new \WP_Error('stackless_creem_first_payment', __('Creem charges the same amount on every billing cycle, so it cannot take a signup fee or a different first payment. Remove the signup fee or first-payment discount, or choose another payment method.', 'stackless-lite'));
        }

        return ['billing' => $billing, 'amount' => $amount, 'trial_days' => $trialDays, 'subscription' => $subscription];
    }

    /** Whole days left in a trial (0 when none). */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) min(3650, (int) ceil($seconds / 86400)) : 0;
    }

    /**
     * Start (or reuse) a Creem checkout session for an order.
     *
     * @return string|\WP_Error Where to send the buyer: Creem's checkout, or the order-received
     *                          page when the order turns out to be paid.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_creem_currency', sprintf(__('Creem cannot charge in %s. Choose another payment method.', 'stackless-lite'), $currency));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        $minor = Money::toMinor($plan['amount']);
        if ($minor < Money::MINIMUM) {
            return new \WP_Error('stackless_creem_minimum', __('Creem takes payments of 1.00 or more. Choose another payment method for this order.', 'stackless-lite'));
        }

        $reused = $this->reusable($order, $mode, $minor);
        if ($reused !== '') {
            return $reused;
        }

        $catalog = $this->gateway->catalog();
        $item = Catalog::itemForOrder($order, $plan['subscription']);
        $productId = $catalog->productId($item, $plan['billing'], $currency, $minor, $mode, $plan['trial_days']);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $api = $this->gateway->api($mode);
        $session = $api->request('POST', 'v1/checkouts', $this->body($order, $productId, $minor, $plan));
        // A product archived or deleted in Creem is recreated once.
        if (is_wp_error($session) && Api::isProductError($session)) {
            $catalog->forget($item, $plan['billing'], $currency, $minor, $mode, $plan['trial_days']);
            $productId = $catalog->productId($item, $plan['billing'], $currency, $minor, $mode, $plan['trial_days'], true);
            if (is_wp_error($productId)) {
                return $productId;
            }
            $session = $api->request('POST', 'v1/checkouts', $this->body($order, $productId, $minor, $plan));
        }
        if (is_wp_error($session)) {
            $this->gateway->log('Checkout for order ' . $order->get_id() . ' failed: ' . $session->get_error_message(), 'error');
            return $session;
        }
        if (empty($session['checkout_url']) || empty($session['id'])) {
            return new \WP_Error('stackless_creem_checkout', __('Creem did not return a checkout page.', 'stackless-lite'));
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_CHECKOUT_ID, (string) $session['id']);
        $order->update_meta_data(Gateway::META_CHECKOUT_URL, (string) $session['checkout_url']);
        $order->update_meta_data(Gateway::META_SESSION_AT, (string) time());
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_AMOUNT, (string) $minor);
        $order->update_meta_data(Gateway::META_PRODUCT_ID, $productId);
        $order->save();
        $this->gateway->log('Started Creem checkout ' . $session['id'] . ' for order ' . $order->get_id() . '.');
        return (string) $session['checkout_url'];
    }

    public static function returnUrl(\WC_Order $order): string
    {
        // Creem appends checkout_id, order_id, subscription_id and a signature.
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_creem_return'));
    }

    /** request_id Creem echoes back: the order on this site. */
    public static function requestId(\WC_Order $order): string
    {
        return 'wc' . $order->get_id() . '-' . substr(md5(Catalog::siteHost()), 0, 8);
    }

    public function body(\WC_Order $order, string $productId, int $minor, array $plan): array
    {
        $subscription = $plan['subscription'];
        $body = [
            'product_id' => $productId,
            'request_id' => self::requestId($order),
            'units' => 1,
            'success_url' => self::returnUrl($order),
            'metadata' => array_filter([
                'wc_order_id' => (string) $order->get_id(),
                'wc_subscription_id' => $subscription instanceof \WC_Order ? (string) $subscription->get_id() : '',
                'wc_gateway' => $this->gateway->id,
                'wc_site' => Catalog::siteHost(),
            ]),
        ];
        if ($plan['billing'] === null) {
            // One-time products take the order amount as their price for this checkout.
            $body['custom_price'] = $minor;
        }
        $email = (string) $order->get_billing_email();
        if ($email !== '' && is_email($email)) {
            $body['customer'] = ['email' => $email];
        }
        return (array) apply_filters('stackless_creem_gateway_checkout_args', $body, $order, $subscription, $this->gateway);
    }

    /** Reuse an open session for the same order and amount (double submits, back button). */
    private function reusable(\WC_Order $order, string $mode, int $minor): string
    {
        $checkoutId = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        $url = (string) $order->get_meta(Gateway::META_CHECKOUT_URL);
        if ($checkoutId === '' || $url === '' || (string) $order->get_meta(Gateway::META_MODE) !== $mode || (int) $order->get_meta(Gateway::META_AMOUNT) !== $minor
            || (int) $order->get_meta(Gateway::META_SESSION_AT) < time() - Gateway::SESSION_TTL) {
            return '';
        }
        $session = $this->gateway->api($mode)->request('GET', 'v1/checkouts', ['checkout_id' => $checkoutId]);
        if (is_wp_error($session)) {
            return '';
        }
        $status = (string) ($session['status'] ?? '');
        if ($status === 'completed') {
            (new Sync($this->gateway))->applyCheckout($session, $mode, $order);
            return $this->gateway->get_return_url($order);
        }
        return $status === 'pending' ? $url : '';
    }

    /* ---- Buyer returns from Creem ---------------------------------------------------- */

    /** One return route serves Creem and every copy; the order says which one it used. */
    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key; the checkout is read back from Creem.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        $gateway = $order instanceof \WC_Order ? Gateway::forOrder($order) : null;
        if (!$order instanceof \WC_Order || !$gateway || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $target = self::returnTarget($gateway, $order);
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
     * Where a buyer coming back from Creem goes. Only the session this order started is
     * trusted: it is read back from Creem. Creem sends buyers back only after a completed
     * checkout, so anything not confirmed yet is left to the webhook and the buyer sees the
     * order-received page; an expired session sends them back to pay.
     *
     * @return array{url:string,empty_cart:bool,notice:string}
     */
    public static function returnTarget(Gateway $gateway, \WC_Order $order): array
    {
        $expired = false;
        if (!$order->is_paid() && (string) $order->get_meta(Gateway::META_CHECKOUT_ID) !== '') {
            $result = (new Sync($gateway))->confirmCheckout($order);
            if (is_wp_error($result)) {
                $gateway->log('Return for order ' . $order->get_id() . ': ' . $result->get_error_message());
                $expired = $result->get_error_code() === 'stackless_creem_expired';
            }
            $order = wc_get_order($order->get_id()) ?: $order;
        }
        if (!$order->is_paid() && $expired) {
            return [
                'url' => $order->get_checkout_payment_url(),
                'empty_cart' => false,
                'notice' => __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'),
            ];
        }
        return ['url' => $gateway->get_return_url($order), 'empty_cart' => true, 'notice' => ''];
    }
}
