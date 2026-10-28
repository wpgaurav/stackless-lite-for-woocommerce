<?php

namespace Stackless\Modules\PolarGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Creates Polar hosted checkout sessions for WooCommerce orders and handles the
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
     * What Polar should bill for an order.
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
            return new \WP_Error('stackless_polar_subscriptions', count($subscriptions) === 0
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
            return new \WP_Error('stackless_polar_interval', __('Polar cannot bill this subscription schedule. Choose another payment method.', 'stackless-lite'));
        }

        $amount = (float) $subscription->get_recurring_total();
        if ($amount <= 0) {
            return new \WP_Error('stackless_polar_zero', __('Polar needs a recurring amount above zero.', 'stackless-lite'));
        }

        $trialDays = self::trialDays($subscription->get_trial_end_date(), time());
        $expectedFirst = $trialDays > 0 ? 0.0 : $amount;
        if (!Money::equals($order->get_total(), $expectedFirst, $order->get_currency())) {
            return new \WP_Error('stackless_polar_first_payment', __('Polar charges the same amount on every billing cycle, so it cannot take a signup fee or a different first payment. Remove the signup fee or first-payment discount, or choose another payment method.', 'stackless-lite'));
        }

        return ['recurring' => $recurring, 'amount' => $amount, 'trial_days' => $trialDays, 'subscription' => $subscription];
    }

    /** Whole days left in a trial (0 when none). Polar trials run 1–1000 days. */
    public static function trialDays($trialEnd, int $now): int
    {
        if (!$trialEnd instanceof \DateTimeInterface) {
            return 0;
        }
        $seconds = $trialEnd->getTimestamp() - $now;
        return $seconds > 0 ? (int) min(1000, (int) ceil($seconds / 86400)) : 0;
    }

    /**
     * Start (or reuse) a Polar checkout for an order.
     *
     * @return string|\WP_Error Checkout URL.
     */
    public function start(\WC_Order $order)
    {
        $mode = $this->gateway->mode();
        $currency = strtolower($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_polar_currency', sprintf(__('Polar cannot charge in %s. Choose another payment method.', 'stackless-lite'), strtoupper($currency)));
        }

        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        if ($plan['amount'] <= 0) {
            return new \WP_Error('stackless_polar_zero', __('Polar needs an amount greater than zero to start a checkout.', 'stackless-lite'));
        }

        $minor = Money::toMinor($plan['amount'], $currency);
        $reused = $this->reusable($order, $mode, $minor, $currency);
        if ($reused !== '') {
            return $reused;
        }

        $item = Catalog::itemForOrder($order, $plan['subscription']);
        $defaultPrice = $this->defaultPrice($order, $plan['amount'], $currency, $mode);
        $productId = Catalog::productId($this->gateway, $item, $plan['recurring'], $currency, $minor, $mode, false, $defaultPrice);
        if (is_wp_error($productId)) {
            return $productId;
        }

        $api = $this->gateway->api($mode);
        $checkout = $api->request('POST', 'checkouts/', $this->body($order, $productId, $minor, $currency, $plan, $defaultPrice));

        // A product archived or deleted in Polar is recreated once.
        if (is_wp_error($checkout) && Api::isProductError($checkout)) {
            Catalog::forget($this->gateway, $item, $plan['recurring'], $mode);
            $productId = Catalog::productId($this->gateway, $item, $plan['recurring'], $currency, $minor, $mode, true, $defaultPrice);
            if (is_wp_error($productId)) {
                return $productId;
            }
            $checkout = $api->request('POST', 'checkouts/', $this->body($order, $productId, $minor, $currency, $plan, $defaultPrice));
        }
        if (is_wp_error($checkout)) {
            $this->gateway->log('Checkout for order ' . $order->get_id() . ' failed: ' . $checkout->get_error_message(), 'error');
            return $checkout;
        }
        if (empty($checkout['url']) || empty($checkout['id'])) {
            return new \WP_Error('stackless_polar_checkout', __('Polar did not return a checkout page.', 'stackless-lite'));
        }

        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_CHECKOUT_ID, (string) $checkout['id']);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_AMOUNT, (string) $minor);
        $order->save();
        $this->gateway->log('Started Polar checkout ' . $checkout['id'] . ' for order ' . $order->get_id() . '.');
        return (string) $checkout['url'];
    }

    public static function returnUrl(\WC_Order $order): string
    {
        $url = add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], WC()->api_request_url('stackless_polar_return'));
        // {CHECKOUT_ID} is replaced by Polar, so it must not be URL-encoded.
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'checkout_id={CHECKOUT_ID}';
    }

    private function body(\WC_Order $order, string $productId, int $minor, string $currency, array $plan, ?array $defaultPrice): array
    {
        $price = ['amount_type' => 'fixed', 'price_amount' => $minor, 'price_currency' => $currency];
        if ($this->gateway->taxBehavior() !== 'location') {
            $price['tax_behavior'] = $this->gateway->taxBehavior();
        }
        $prices = [$price];
        if ($defaultPrice) {
            $prices[] = array_merge($price, ['price_amount' => $defaultPrice['amount'], 'price_currency' => $defaultPrice['currency']]);
        }

        $subscription = $plan['subscription'];
        $body = [
            'products' => [$productId],
            'prices' => [$productId => $prices],
            'currency' => $currency,
            'success_url' => self::returnUrl($order),
            'return_url' => $order->get_checkout_payment_url(),
            'allow_discount_codes' => false,
            'allow_trial' => $plan['recurring'] !== null && $plan['trial_days'] > 0,
            'metadata' => array_filter([
                'wc_order_id' => (string) $order->get_id(),
                'wc_subscription_id' => $subscription instanceof \WC_Order ? (string) $subscription->get_id() : '',
                'wc_site' => Catalog::siteHost(),
            ]),
        ];
        if ($plan['recurring'] !== null && $plan['trial_days'] > 0) {
            $body['trial_interval'] = 'day';
            $body['trial_interval_count'] = $plan['trial_days'];
        }

        $email = (string) $order->get_billing_email();
        if ($email !== '' && is_email($email)) {
            $body['customer_email'] = $email;
        }
        $name = trim($order->get_formatted_billing_full_name());
        if ($name !== '') {
            $body['customer_name'] = function_exists('mb_substr') ? mb_substr($name, 0, 256) : substr($name, 0, 256);
        }
        $country = strtoupper((string) $order->get_billing_country());
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $body['customer_billing_address'] = ['country' => $country];
        }
        $locale = str_replace('_', '-', (string) get_locale());
        if ($locale !== '') {
            $body['locale'] = $locale;
        }

        return (array) apply_filters('stackless_polar_gateway_checkout_args', $body, $order, $subscription);
    }

    /** Reuse an open checkout for the same order and amount (double submits, back button). */
    private function reusable(\WC_Order $order, string $mode, int $minor, string $currency): string
    {
        $checkoutId = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        if ($checkoutId === '' || (string) $order->get_meta(Gateway::META_MODE) !== $mode || (int) $order->get_meta(Gateway::META_AMOUNT) !== $minor) {
            return '';
        }
        $checkout = $this->gateway->api($mode)->request('GET', 'checkouts/' . rawurlencode($checkoutId));
        if (is_wp_error($checkout) || ($checkout['status'] ?? '') !== 'open' || strtolower((string) ($checkout['currency'] ?? $currency)) !== $currency) {
            return '';
        }
        $expires = strtotime((string) ($checkout['expires_at'] ?? ''));
        if ($expires && $expires < time() + 5 * MINUTE_IN_SECONDS) {
            return '';
        }
        return (string) ($checkout['url'] ?? '');
    }

    /**
     * Polar refuses ad-hoc prices without one in the organization's default currency.
     * That price is never charged (the checkout is pinned to $currency); it only has
     * to be present and plausible.
     *
     * @return array{currency:string,amount:int}|null
     */
    private function defaultPrice(\WC_Order $order, float $amount, string $currency, string $mode): ?array
    {
        $default = $this->gateway->defaultCurrency($mode);
        if ($default === '' || $default === $currency) {
            return null;
        }
        return ['currency' => $default, 'amount' => Money::toMinor(self::convert($amount, $currency, $default, $order), $default)];
    }

    /**
     * Convert an amount for the default-currency price.
     *
     * Omitting the price is not an option: Polar rejects the product and the checkout
     * without it. The order's own price is what the buyer pays, so this one is only
     * cosmetic, but it still appears on the Polar product and its receipts, and a
     * ¥5,000 order copied over as $5,000 looks like a bug there. So the amount goes
     * through Multi-Currency's rate (stackless_convert_amount(), which hands the
     * input back unchanged when the module is off or has no rate for the pair);
     * stackless_polar_gateway_convert_amount lets a site supply its own number first.
     */
    public static function convert(float $amount, string $from, string $to, ?\WC_Order $order): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $converted = apply_filters('stackless_polar_gateway_convert_amount', null, $amount, $from, $to, $order);
        if (is_numeric($converted) && (float) $converted > 0) {
            return (float) $converted;
        }
        $converted = stackless_convert_amount($amount, $to, $from);
        return is_numeric($converted) && (float) $converted > 0 ? (float) $converted : $amount;
    }

    /* ---- Buyer returns from Polar ------------------------------------------------- */

    public function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $checkoutId = isset($_GET['checkout_id']) ? sanitize_text_field(wp_unslash($_GET['checkout_id'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || $order->get_payment_method() !== $this->gateway->id) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }

        $stored = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        // Only the checkout this order started may confirm it.
        if (!$order->is_paid() && $stored !== '' && ($checkoutId === '' || $checkoutId === $stored)) {
            $result = (new Sync($this->gateway))->confirmCheckout($order, $stored);
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
