<?php

namespace Stackless\Modules\StripeGateway\Managed;

use Stackless\Contracts\SubscriptionData;
use Stackless\Modules\StripeGateway\Amount;
use Stackless\Modules\StripeGateway\Customers;
use Stackless\Modules\StripeGateway\IdempotencyKey;
use Stackless\Modules\StripeGateway\Payments;
use Stackless\Modules\StripeGateway\Settings;
use Stackless\Modules\StripeGateway\StripeApiException;

defined('ABSPATH') || exit;

/**
 * Creates the Managed Payments Checkout Session for a WooCommerce order, serves the store's
 * pay page that embeds it, handles the buyer's return, and opens the Stripe customer portal
 * for card updates.
 *
 * Follows Paddle's overlay flow: process_payment() creates (or reuses) the session and sends
 * the buyer to an order-key protected wc-api page, which mounts embedded Checkout with the
 * session's client secret. Stripe returns the buyer to the return URL, where the session is
 * read back from Stripe before anything is marked paid.
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
     * Session parameters (without return_url and metadata) for an order.
     *
     * @return array{mode:string, line_items:array<int,array>, trial_days:int, subscription:?\WC_Order}|\WP_Error
     */
    public function plan(\WC_Order $order)
    {
        $currency = $order->get_currency();
        $context = [
            'currency' => $currency,
            /* translators: %s: order number */
            'label' => sprintf(__('Order #%s', 'stackless-lite'), $order->get_order_number()),
            'tax_code' => $this->gateway->taxCode($order),
            'tax_behavior' => $this->gateway->taxBehavior(),
        ];
        $total = Amount::toMinor($order->get_total(), $currency);
        $recurring = null;
        $subscription = null;

        if (!stackless_is_renewal_order($order) && stackless_order_contains_subscription($order)) {
            $subscriptions = array_values(stackless_get_order_subscriptions($order));
            if (count($subscriptions) !== 1) {
                return new \WP_Error('stackless_stripe_managed_subscriptions', count($subscriptions) === 0
                    ? __('The subscription for this order is not ready yet. Please try again in a moment.', 'stackless-lite')
                    : Eligibility::message(Eligibility::TOO_MANY_SUBSCRIPTIONS));
            }
            /** @var SubscriptionData&\WC_Order $candidate */
            $candidate = $subscriptions[0];
            // Store-billed (manual) subscriptions are paid one order at a time.
            if ($candidate->get_renewal_mode() !== 'manual') {
                $subscription = $candidate;
                $recurring = [
                    'name' => self::subscriptionName($candidate),
                    'amount' => Amount::toMinor($candidate->get_recurring_total(), $currency),
                    'period' => $candidate->get_billing_period(),
                    'interval' => $candidate->get_billing_interval(),
                    'trial_days' => Lines::trialDays($candidate->get_trial_end_date(), time()),
                ];
            }
        }

        $built = Lines::build($total, $recurring === null ? self::orderLines($order) : [], $recurring, $context);
        if (is_string($built)) {
            return new \WP_Error('stackless_stripe_managed_' . $built, self::linesMessage($built));
        }
        return [
            'mode' => $recurring === null ? 'payment' : 'subscription',
            'line_items' => $built['line_items'],
            'trial_days' => $built['trial_days'],
            'subscription' => $subscription,
        ];
    }

    public static function linesMessage(string $code): string
    {
        switch ($code) {
            case Lines::ERROR_FIRST_PAYMENT:
                return __('Stripe charges at least one full billing cycle on the first payment, so it cannot take a first-payment discount. Remove the discount or choose another payment method.', 'stackless-lite');
            case Lines::ERROR_INTERVAL:
                return __('Stripe cannot bill this subscription schedule (the longest is three years). Choose another payment method.', 'stackless-lite');
            default:
                return __('Stripe needs an amount greater than zero to start a checkout.', 'stackless-lite');
        }
    }

    /** Order lines and fees in the lowest denomination. @return array<int,array{name:string,amount:int}> */
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
                'amount' => Amount::toMinor($item->get_total(), $currency),
            ];
        }
        foreach ($order->get_fees() as $fee) {
            $lines[] = ['name' => $fee->get_name(), 'amount' => Amount::toMinor($fee->get_total(), $currency)];
        }
        // WooCommerce tax is left out on purpose: when it is charged, the lines no longer add
        // up and the order goes to Stripe as one line instead of itemizing tax as a product.
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
     * Checkout Session create body for an order.
     *
     * @param array{mode:string, line_items:array<int,array>, trial_days:int, subscription:?\WC_Order} $plan
     */
    public function body(\WC_Order $order, array $plan, string $customer): array
    {
        $metadata = [
            'order_id' => (string) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'stackless_site' => Settings::siteMarker(),
            'stackless_managed' => '1',
        ];
        $body = [
            'mode' => $plan['mode'],
            'ui_mode' => EventMap::uiMode(Gateway::apiVersion()),
            'managed_payments' => ['enabled' => true],
            'line_items' => $plan['line_items'],
            'return_url' => self::returnUrl($order),
            'client_reference_id' => (string) $order->get_id(),
            'metadata' => $metadata,
        ];
        if ($customer !== '') {
            $body['customer'] = $customer;
        } elseif (is_email($order->get_billing_email())) {
            $body['customer_email'] = $order->get_billing_email();
        }
        $locale = strtolower(str_replace('_', '-', (string) get_locale()));
        $body['locale'] = in_array(substr($locale, 0, 2), ['en', 'de', 'fr', 'es', 'it', 'ja', 'nl', 'pt', 'sv', 'da', 'fi', 'nb', 'pl'], true) ? substr($locale, 0, 2) : 'auto';
        if ($plan['mode'] === 'subscription') {
            $subscription = $plan['subscription'];
            $body['subscription_data'] = ['metadata' => array_merge($metadata, ['subscription_id' => $subscription instanceof \WC_Order ? (string) $subscription->get_id() : ''])];
            if ($plan['trial_days'] > 0) {
                $body['subscription_data']['trial_period_days'] = $plan['trial_days'];
            }
        } else {
            $body['payment_intent_data'] = ['metadata' => $metadata];
        }
        return (array) apply_filters('stackless_stripe_managed_session_args', $body, $order, $plan['subscription']);
    }

    /**
     * Create (or reuse) the Checkout Session for an order.
     *
     * @return string|\WP_Error URL of the store's pay page.
     * @throws StripeApiException
     */
    public function start(\WC_Order $order)
    {
        $currency = $order->get_currency();
        if (!Amount::isSupported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_stripe_managed_currency', sprintf(__('Stripe cannot charge in %s. Choose another payment method.', 'stackless-lite'), $currency));
        }
        $plan = $this->plan($order);
        if (is_wp_error($plan)) {
            return $plan;
        }
        $client = Gateway::client();
        $customer = $order->get_customer_id() > 0 ? Customers::forOrder($order, $client, true) : '';
        $body = $this->body($order, $plan, $customer);
        $fingerprint = $client->mode() . ':' . md5((string) wp_json_encode($body));

        if ($this->openSession($order, $fingerprint) === null) {
            // The previous session id is part of the key, so a session that expired is replaced, not replayed.
            $previous = (string) $order->get_meta(Gateway::META_SESSION);
            $session = $client->post('checkout/sessions', $body, IdempotencyKey::order($order->get_id(), 'managed|' . $fingerprint . '|' . $previous));
            if (empty($session['id'])) {
                return new \WP_Error('stackless_stripe_managed', __('Stripe did not return a checkout session.', 'stackless-lite'));
            }
            $order->update_meta_data(Gateway::META_SESSION, (string) $session['id']);
            $order->update_meta_data(Gateway::META_FINGERPRINT, $fingerprint);
            Settings::log('Created Managed Payments session ' . $session['id'] . ' for order ' . $order->get_id() . '.', 'info');
        }
        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Payments::META_MODE, $client->mode());
        if ($customer !== '') {
            $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $order->save();
        return self::payUrl($order);
    }

    /**
     * The order's session when it is still open and was built from the same order contents
     * (double submits, the back button), else null.
     *
     * @return array<string,mixed>|null
     */
    public function openSession(\WC_Order $order, string $fingerprint = ''): ?array
    {
        $id = (string) $order->get_meta(Gateway::META_SESSION);
        if ($id === '' || ($fingerprint !== '' && (string) $order->get_meta(Gateway::META_FINGERPRINT) !== $fingerprint)) {
            return null;
        }
        try {
            $session = Gateway::clientFor($order)->get('checkout/sessions/' . rawurlencode($id));
        } catch (StripeApiException $e) {
            return null;
        }
        return ($session['status'] ?? '') === 'open' && !empty($session['client_secret']) ? $session : null;
    }

    public static function payUrl(\WC_Order $order): string
    {
        return add_query_arg(['order_id' => $order->get_id(), 'key' => $order->get_order_key()], WC()->api_request_url('stackless_stripe_managed_pay'));
    }

    public static function returnUrl(\WC_Order $order): string
    {
        // Stripe replaces {CHECKOUT_SESSION_ID}; add_query_arg would encode the braces.
        $base = add_query_arg(['order_id' => $order->get_id(), 'key' => $order->get_order_key()], WC()->api_request_url('stackless_stripe_managed_return'));
        return $base . '&session_id={CHECKOUT_SESSION_ID}';
    }

    public static function portalUrl(\WC_Order $subscription): string
    {
        return wp_nonce_url(add_query_arg(['subscription_id' => $subscription->get_id()], WC()->api_request_url('stackless_stripe_managed_portal')), 'stackless_stripe_managed_portal_' . $subscription->get_id());
    }

    /* ---- Pay page ---------------------------------------------------------------- */

    /** The order a pay or return request names, when its key checks out. */
    private function requestedOrder(): ?\WC_Order
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key.
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable
        $order = $order_id ? wc_get_order($order_id) : false;
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
        if ($order->is_paid() || $order->has_status('on-hold')) {
            wp_safe_redirect($this->gateway->get_return_url($order));
            exit;
        }
        $session = $this->openSession($order);
        if ($session === null) {
            // Expired or never created: start a fresh one for the same order.
            try {
                $started = $this->start($order);
            } catch (StripeApiException $e) {
                Settings::log('Managed Payments session for order ' . $order->get_id() . ' failed: ' . $e->logSummary(), 'error');
                $started = new \WP_Error('stackless_stripe_managed', Gateway::apiErrorMessage($e));
            }
            $session = is_wp_error($started) ? null : $this->openSession($order);
            if ($session === null) {
                wc_add_notice(is_wp_error($started) ? $started->get_error_message() : __('Stripe could not start the checkout. Please try again.', 'stackless-lite'), 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }
        }
        $mode = (string) $order->get_meta(Payments::META_MODE);
        $config = [
            'publishableKey' => Settings::publishableKey($mode !== '' ? $mode : null),
            'clientSecret' => (string) $session['client_secret'],
            'cancelUrl' => $order->get_checkout_payment_url(),
            'testMode' => $mode !== 'live',
            'accent' => '#066fd1', // Stackless Blue
            'i18n' => [
                'failed' => __('Stripe checkout could not load. Check your connection and try again.', 'stackless-lite'),
            ],
        ];
        $config = (array) apply_filters('stackless_stripe_managed_pay_page_config', $config, $order);
        $config['accent'] = sanitize_hex_color((string) ($config['accent'] ?? '')) ?: '#066fd1';
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        header('X-Robots-Tag: noindex, nofollow');
        stackless_get_template('stripe_gateway/managed-pay-page.php', [
            'order' => $order,
            'config' => $config,
            'summary' => $this->summary($order),
            'script_url' => self::assetUrl('managed-pay', 'js'),
            'style_url' => self::assetUrl('managed-pay', 'css'),
        ]);
        exit;
    }

    /**
     * What the pay page shows beside Stripe Checkout: the store, the order's lines and totals,
     * what renews and when, and who sells it. Amounts are plain text in the order's currency.
     *
     * @return array{store:array{name:string,logo:string,url:string}, number:string, items:array<int,array{name:string,quantity:int,total:string,image:string}>, rows:array<int,array{label:string,value:string}>, total:string, recurring:string, trial:string, tax_note:string, seller_note:string}
     */
    public function summary(\WC_Order $order): array
    {
        $currency = $order->get_currency();
        $money = static function ($amount) use ($currency): string {
            return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount, ['currency' => $currency])), ENT_QUOTES, 'UTF-8');
        };
        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            $image = $product instanceof \WC_Product && $product->get_image_id() ? wp_get_attachment_image_url((int) $product->get_image_id(), 'thumbnail') : '';
            $items[] = ['name' => $item->get_name(), 'quantity' => (int) $item->get_quantity(), 'total' => $money($item->get_total()), 'image' => is_string($image) ? $image : ''];
        }
        $rows = [['label' => __('Subtotal', 'stackless-lite'), 'value' => $money($order->get_subtotal())]];
        if ((float) $order->get_discount_total() > 0) {
            $rows[] = ['label' => __('Discount', 'stackless-lite'), 'value' => '−' . $money($order->get_discount_total())];
        }
        foreach ($order->get_fees() as $fee) {
            $rows[] = ['label' => $fee->get_name(), 'value' => $money($fee->get_total())];
        }
        if ((float) $order->get_total_tax() > 0) {
            $rows[] = ['label' => __('Tax', 'stackless-lite'), 'value' => $money($order->get_total_tax())];
        }

        $recurring = '';
        $trial = '';
        if (!stackless_is_renewal_order($order) && stackless_order_contains_subscription($order)) {
            $subscriptions = array_values(stackless_get_order_subscriptions($order));
            $subscription = $subscriptions[0] ?? null;
            if ($subscription instanceof SubscriptionData && $subscription->get_renewal_mode() !== 'manual') {
                $recurring = sprintf(
                    /* translators: 1: recurring amount, 2: billing schedule such as "every month" */
                    __('Then %1$s %2$s', 'stackless-lite'),
                    $money($subscription->get_recurring_total()),
                    self::every($subscription->get_billing_period(), $subscription->get_billing_interval())
                );
                $trialEnd = $subscription->get_trial_end_date();
                if ($trialEnd instanceof \WC_DateTime && $trialEnd->getTimestamp() > time()) {
                    /* translators: %s: date the free trial ends */
                    $trial = sprintf(__('Free trial until %s', 'stackless-lite'), wc_format_datetime($trialEnd));
                }
            }
        }

        // A theme logo usually carries the store name already; a site icon doesn't.
        $logo = '';
        $logoType = '';
        $logoId = (int) get_theme_mod('custom_logo');
        if ($logoId > 0) {
            $logo = (string) wp_get_attachment_image_url($logoId, 'medium');
            $logoType = $logo !== '' ? 'logo' : '';
        }
        if ($logo === '' && function_exists('get_site_icon_url')) {
            $logo = (string) get_site_icon_url(96);
            $logoType = $logo !== '' ? 'icon' : '';
        }
        $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return [
            'store' => ['name' => $name, 'logo' => $logo, 'logo_type' => $logoType, 'url' => home_url('/')],
            'number' => (string) $order->get_order_number(),
            'items' => $items,
            'rows' => $rows,
            'total' => $money($order->get_total()),
            'recurring' => $recurring,
            'trial' => $trial,
            'tax_note' => $this->gateway->taxBehavior() === 'inclusive'
                ? __('Prices include tax. Stripe works out the tax for your location.', 'stackless-lite')
                : __('Stripe adds sales tax or VAT for your location before you pay.', 'stackless-lite'),
            'seller_note' => __('Stripe is the merchant of record for this purchase and emails your receipt.', 'stackless-lite'),
        ];
    }

    /** "every month", "every 3 weeks". */
    public static function every(string $period, int $interval): string
    {
        $interval = max(1, $interval);
        switch ($period) {
            case 'day':
                /* translators: %d: number of days */
                return sprintf(_n('every day', 'every %d days', $interval, 'stackless-lite'), $interval);
            case 'week':
                /* translators: %d: number of weeks */
                return sprintf(_n('every week', 'every %d weeks', $interval, 'stackless-lite'), $interval);
            case 'year':
                /* translators: %d: number of years */
                return sprintf(_n('every year', 'every %d years', $interval, 'stackless-lite'), $interval);
            default:
                /* translators: %d: number of months */
                return sprintf(_n('every month', 'every %d months', $interval, 'stackless-lite'), $interval);
        }
    }

    /** A pay page asset, minified unless SCRIPT_DEBUG (the page is printed without wp_enqueue_*). */
    private static function assetUrl(string $name, string $ext): string
    {
        $file = 'assets/stripe_gateway/' . $name . '.min.' . $ext;
        if ((defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) || !file_exists(STACKLESS_PLUGIN_PATH . $file)) {
            $file = 'assets/stripe_gateway/' . $name . '.' . $ext;
        }
        return STACKLESS_PLUGIN_URL . $file . '?ver=' . rawurlencode(defined('STACKLESS_VERSION') ? STACKLESS_VERSION : '0');
    }

    /* ---- Buyer returns from Stripe ------------------------------------------------- */

    public function handleReturn(): void
    {
        $order = $this->requestedOrder();
        if (!$order) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $stored = (string) $order->get_meta(Gateway::META_SESSION);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with the stored id, never trusted on its own.
        $returned = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
        if (!$order->is_paid() && $stored !== '' && ($returned === '' || hash_equals($stored, $returned))) {
            $result = (new Sync($this->gateway))->confirmById($order, $stored);
            if (is_wp_error($result)) {
                Settings::log('Managed Payments return for order ' . $order->get_id() . ': ' . $result->get_error_message(), 'info');
                if ($result->get_error_code() === 'stackless_stripe_managed_open') {
                    wp_safe_redirect(self::payUrl($order));
                    exit;
                }
            }
        }
        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
        // Paid, still settling, or failed: the order-received page shows the real state.
        wp_safe_redirect($this->gateway->get_return_url($order));
        exit;
    }

    /* ---- Customer portal (card updates) ------------------------------------------- */

    /**
     * Opens the Stripe customer portal so the customer can replace the card on a Managed
     * Payments subscription; Stripe does not let the API change its default_payment_method.
     */
    public function handlePortal(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
        $id = isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0;
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        $subscription = $id ? stackless_get_subscription($id) : null;
        $back = wc_get_account_endpoint_url('dashboard');
        if (!$subscription instanceof \WC_Order || !is_user_logged_in() || !wp_verify_nonce($nonce, 'stackless_stripe_managed_portal_' . $id)
            || (int) $subscription->get_customer_id() !== get_current_user_id()
            || $subscription->get_payment_method() !== $this->gateway->id) {
            wp_safe_redirect($back);
            exit;
        }
        $customer = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER);
        $view = (string) $subscription->get_view_order_url();
        if ($customer === '') {
            wc_add_notice(__('Stripe has no customer record for this subscription yet. Please try again later.', 'stackless-lite'), 'error');
            wp_safe_redirect($view);
            exit;
        }
        try {
            $portal = Gateway::clientFor($subscription)->post('billing_portal/sessions', [
                'customer' => $customer,
                'return_url' => $view,
                'flow_data' => ['type' => 'payment_method_update'],
            ]);
        } catch (StripeApiException $e) {
            Settings::log('Customer portal for subscription ' . $id . ' failed: ' . $e->logSummary(), 'error');
            wc_add_notice(__('Stripe could not open the payment method update. Please try again later.', 'stackless-lite'), 'error');
            wp_safe_redirect($view);
            exit;
        }
        $url = (string) ($portal['url'] ?? '');
        if (strpos($url, 'https://') !== 0) {
            wp_safe_redirect($view);
            exit;
        }
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the portal URL Stripe returned (billing.stripe.com or the account's own billing domain).
        wp_redirect($url);
        exit;
    }
}
