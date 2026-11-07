<?php

namespace Stackless\Modules\RazorpayGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Razorpay Standard Checkout: creates the Razorpay order, opens Razorpay's checkout on the
 * order's payment page (or on a small page of its own for My Account → Add payment method),
 * and takes the signed result back.
 *
 * A subscription checkout is an authorization transaction: a Razorpay order for one recurring
 * method (card, UPI Autopay or eNACH) with a customer and a token block whose max_amount
 * covers the largest renewal plus the plan-switch allowance. Cards and UPI take the order total
 * (at least ₹1, refunded when the order itself is free); eNACH authorizes ₹0 and the first
 * payment follows as its own debit once the bank confirms the mandate.
 */
final class Checkout
{
    public const RETURN_ACTION = 'stackless_razorpay_return';
    public const AUTHORIZE_ACTION = 'stackless_razorpay_authorize';
    public const SDK = 'https://checkout.razorpay.com/v1/checkout.js';

    /** Razorpay orders are reused for this long (it caps authorization orders at the same day). */
    private const ORDER_TTL = 6 * HOUR_IN_SECONDS;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Create (or reuse) the Razorpay order a WooCommerce order is paid against.
     *
     * @return true|\WP_Error
     */
    public function prepare(\WC_Order $order, bool $save, string $method = '')
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_razorpay_currency', sprintf(__('Razorpay does not take payments in %s.', 'stackless-lite'), $currency));
        }
        $orderMinor = Money::toMinor($order->get_total(), $currency);
        $body = [
            'currency' => $currency,
            'receipt' => substr('wc_' . $order->get_id() . '_' . wp_generate_password(6, false), 0, 40),
            'notes' => Sync::notes($order, $save ? 'auth' : 'checkout'),
        ];
        $flow = 'checkout';
        if ($save) {
            if (!Money::recurringSupported($currency)) {
                return new \WP_Error('stackless_razorpay_currency', __('Razorpay saves payment methods for subscriptions in Indian rupees only.', 'stackless-lite'));
            }
            $eligible = $this->gateway->eligibleMethods($order);
            $method = in_array($method, $eligible, true) ? $method : ($eligible[0] ?? '');
            $max = $method !== '' ? Mandate::maxAmount($method, $this->gateway->largestRenewal($order), $this->gateway->switchAllowance()) : null;
            if ($method === '' || $max === null) {
                return new \WP_Error('stackless_razorpay_method', __('None of the Razorpay renewal methods can cover this subscription’s renewal amount. Choose another payment method.', 'stackless-lite'));
            }
            $customer = $this->gateway->customerFor(
                (int) $order->get_customer_id(),
                $mode,
                trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                (string) $order->get_billing_email(),
                (string) $order->get_billing_phone()
            );
            if (is_wp_error($customer)) {
                return $customer;
            }
            $amount = Mandate::authorizationMinor($method, $orderMinor);
            $body += [
                'amount' => $amount,
                'customer_id' => $customer,
                'method' => $method,
                'payment_capture' => true,
                'token' => Mandate::tokenBlock($method, $max),
            ];
            $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            $order->update_meta_data(Gateway::META_METHOD, $method);
            $order->update_meta_data(Gateway::META_VERIFICATION, $orderMinor <= 0 && $amount > 0 ? 'yes' : 'no');
            $flow = 'auth';
        } else {
            if ($orderMinor < Money::MINIMUM_MINOR && $currency === 'INR') {
                return new \WP_Error('stackless_razorpay_amount', __('Razorpay takes payments of ₹1 or more.', 'stackless-lite'));
            }
            $body['amount'] = $orderMinor;
        }

        // Reuse an unpaid Razorpay order for the same amount and flow (back button, retries).
        $previous = (string) $order->get_meta(Gateway::META_ORDER_ID);
        if ($previous !== '' && $this->gateway->orderMode($order) === $mode
            && (int) $order->get_meta(Gateway::META_AMOUNT) === (int) $body['amount']
            && (string) $order->get_meta(Gateway::META_FLOW) === $flow
            && ($flow !== 'auth' || (string) $order->get_meta(Gateway::META_METHOD) === $method)
            && (int) $order->get_meta(Gateway::META_ORDER_AT) > time() - self::ORDER_TTL) {
            $existing = $this->gateway->api($mode)->request('GET', 'orders/' . rawurlencode($previous));
            if (is_array($existing) && ($existing['status'] ?? '') !== 'paid') {
                $order->save();
                return true;
            }
        }

        $created = $this->gateway->api($mode)->request('POST', 'orders', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        if (empty($created['id'])) {
            return new \WP_Error('stackless_razorpay_order', __('Razorpay did not create an order.', 'stackless-lite'));
        }
        $order->update_meta_data(Gateway::META_ORDER_ID, (string) $created['id']);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_FLOW, $flow);
        $order->update_meta_data(Gateway::META_AMOUNT, (string) (int) $body['amount']);
        $order->update_meta_data(Gateway::META_ORDER_AT, (string) time());
        $order->save();
        $this->gateway->log(sprintf('Razorpay order %s created for order %d (%d %s, %s%s).', (string) $created['id'], $order->get_id(), (int) $body['amount'], $currency, $flow, $flow === 'auth' ? ' ' . $method : ''));
        return true;
    }

    /**
     * My Account → Add payment method: an authorization order for the chosen method.
     *
     * @return string|\WP_Error URL of the page that opens Razorpay Checkout.
     */
    public function prepareAdd(int $userId, string $method)
    {
        $mode = $this->gateway->mode();
        $methods = $this->gateway->recurringMethods();
        $method = in_array($method, $methods, true) ? $method : ($methods[0] ?? '');
        if ($method === '') {
            return new \WP_Error('stackless_razorpay_method', __('No Razorpay renewal method is switched on.', 'stackless-lite'));
        }
        $user = get_userdata($userId);
        $phone = (string) get_user_meta($userId, 'billing_phone', true);
        $customer = $this->gateway->customerFor($userId, $mode, $user ? trim($user->first_name . ' ' . $user->last_name) : '', $user ? (string) $user->user_email : '', $phone);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $caps = Mandate::caps();
        $max = Mandate::maxAmount($method, (float) min($caps[$method], 10000), $this->gateway->switchAllowance());
        $ref = wp_generate_password(20, false);
        $created = $this->gateway->api($mode)->request('POST', 'orders', [
            'amount' => Mandate::VERIFY_MINOR[$method],
            'currency' => Money::RECURRING,
            'customer_id' => $customer,
            'method' => $method,
            'payment_capture' => true,
            'receipt' => substr('wc_add_' . $userId . '_' . substr($ref, 0, 8), 0, 40),
            'token' => Mandate::tokenBlock($method, (int) $max),
            'notes' => ['flow' => 'add', 'wp_user_id' => (string) $userId, 'wc_site' => Sync::site(), 'wc_gateway' => Gateway::ID],
        ]);
        if (is_wp_error($created)) {
            return $created;
        }
        if (empty($created['id'])) {
            return new \WP_Error('stackless_razorpay_order', __('Razorpay did not create an order.', 'stackless-lite'));
        }
        update_user_meta($userId, Gateway::USER_META_PENDING_ADD, [
            'order' => (string) $created['id'],
            'customer' => $customer,
            'method' => $method,
            'mode' => $mode,
            'amount' => Mandate::VERIFY_MINOR[$method],
            'ref' => $ref,
            'at' => time(),
        ]);
        return add_query_arg(['ref' => $ref], WC()->api_request_url(self::AUTHORIZE_ACTION));
    }

    /**
     * Options for Razorpay Checkout (checkout.js) on the order's payment page.
     *
     * @return array<string,mixed>
     */
    public function orderConfig(\WC_Order $order): array
    {
        $flow = (string) $order->get_meta(Gateway::META_FLOW);
        $options = [
            'key' => $this->gateway->keyId($this->gateway->orderMode($order)),
            'order_id' => (string) $order->get_meta(Gateway::META_ORDER_ID),
            'amount' => (int) $order->get_meta(Gateway::META_AMOUNT),
            'currency' => strtoupper($order->get_currency()),
            'name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            /* translators: %s: order number */
            'description' => sprintf(__('Order %s', 'stackless-lite'), $order->get_order_number()),
            'prefill' => array_filter([
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email' => (string) $order->get_billing_email(),
                'contact' => Gateway::contact((string) $order->get_billing_phone()),
            ]),
            'notes' => ['wc_order_id' => (string) $order->get_id()],
        ];
        if ($flow === 'auth') {
            $options['customer_id'] = (string) $order->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER);
            $options['recurring'] = '1';
        }
        return $this->withTheme($options, Sync::returnUrl(['order' => $order->get_id(), 'key' => $order->get_order_key()]), $order->get_checkout_payment_url());
    }

    /** @return array<string,mixed> */
    private function withTheme(array $options, string $returnUrl, string $cancelUrl): array
    {
        if ($this->gateway->themeColor() !== '') {
            $options['theme'] = ['color' => $this->gateway->themeColor()];
        }
        // UPI Collect is closed for new mandates; Checkout shows Intent or QR instead.
        $options['config'] = ['display' => ['hide' => [['method' => 'upi', 'flows' => ['collect']]]]];
        return [
            'options' => $options,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'i18n' => [
                'pay' => __('Pay now', 'stackless-lite'),
                'failed' => __('The payment did not go through. You can try again.', 'stackless-lite'),
                'closed' => __('Payment window closed. Click Pay now to try again.', 'stackless-lite'),
                'unavailable' => __('Razorpay Checkout could not load. Check your connection and reload the page.', 'stackless-lite'),
            ],
        ];
    }

    /** woocommerce_receipt_stackless_razorpay: the order's payment page after Place order. */
    public static function receipt($orderId): void
    {
        $order = wc_get_order($orderId);
        $gateway = Gateway::instance();
        if (!$order instanceof \WC_Order || !$order->needs_payment()) {
            return;
        }
        if ((string) $order->get_meta(Gateway::META_ORDER_ID) === '') {
            $prepared = (new self($gateway))->prepare($order, stackless_order_requires_saved_payment_method($order));
            if (is_wp_error($prepared)) {
                echo '<p class="woocommerce-error">' . esc_html($prepared->get_error_message()) . '</p>';
                return;
            }
        }
        self::enqueue((new self($gateway))->orderConfig($order));
        stackless_get_template('razorpay_gateway/checkout.php', [
            'gateway' => $gateway,
            'order' => $order,
            'testmode' => $gateway->orderMode($order) === 'test',
            'first_debit' => Mandate::needsFirstDebit((string) $order->get_meta(Gateway::META_METHOD), Money::toMinor($order->get_total(), $order->get_currency())),
        ]);
    }

    /** woocommerce_api_stackless_razorpay_authorize: Checkout for My Account → Add payment method. */
    public static function authorizePage(): void
    {
        $gateway = Gateway::instance();
        $userId = get_current_user_id();
        $ref = isset($_GET['ref']) ? sanitize_text_field(wp_unslash((string) $_GET['ref'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the stored reference authenticates the request.
        $pending = $userId > 0 ? get_user_meta($userId, Gateway::USER_META_PENDING_ADD, true) : null;
        if (!is_array($pending) || $ref === '' || !hash_equals((string) ($pending['ref'] ?? ''), $ref)) {
            wp_safe_redirect(wc_get_account_endpoint_url('payment-methods'));
            exit;
        }
        $user = wp_get_current_user();
        $options = [
            'key' => $gateway->keyId((string) $pending['mode']),
            'order_id' => (string) $pending['order'],
            'customer_id' => (string) $pending['customer'],
            'recurring' => '1',
            'amount' => (int) $pending['amount'],
            'currency' => Money::RECURRING,
            'name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'description' => __('Save a payment method', 'stackless-lite'),
            'prefill' => array_filter(['name' => trim($user->first_name . ' ' . $user->last_name), 'email' => (string) $user->user_email]),
        ];
        $config = (new self($gateway))->withTheme($options, Sync::returnUrl(['flow' => 'add', 'ref' => $ref]), wc_get_account_endpoint_url('payment-methods'));
        nocache_headers();
        stackless_get_template('razorpay_gateway/authorize-page.php', [
            'config' => $config,
            'sdk' => self::SDK,
            'script' => \Stackless\Core\Assets::url('razorpay_gateway/checkout.js'),
            'method' => Mandate::label((string) $pending['method']),
            'amount' => (int) $pending['amount'],
            'testmode' => (string) $pending['mode'] === 'test',
            'cancel' => wc_get_account_endpoint_url('payment-methods'),
        ]);
        exit;
    }

    private static function enqueue(array $config): void
    {
        wp_enqueue_script('stackless-razorpay_gateway-sdk', self::SDK, [], null, true); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Razorpay serves the current checkout.js; a version query would not pin it.
        wp_enqueue_script('stackless-razorpay_gateway-checkout', STACKLESS_PLUGIN_URL . 'assets/razorpay_gateway/checkout.js', ['stackless-razorpay_gateway-sdk'], STACKLESS_VERSION, true);
        wp_add_inline_script('stackless-razorpay_gateway-checkout', 'window.stacklessRazorpay=' . wp_json_encode($config) . ';', 'before');
    }
}
