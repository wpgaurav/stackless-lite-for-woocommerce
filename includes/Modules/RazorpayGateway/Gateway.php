<?php

namespace Stackless\Modules\RazorpayGateway;

use Stackless\Contracts\AdvanceCharge;
use Stackless\Contracts\SavedMethodCharger;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Razorpay for WooCommerce: Standard Checkout (UPI, cards, netbanking, wallets) for one-time
 * orders, and recurring tokens for subscriptions: card e-mandates, UPI Autopay and eNACH.
 *
 * Place order creates a Razorpay order and opens Razorpay's checkout on the order's payment
 * page; the signed result comes back to this site and is checked against the Razorpay API.
 * Renewals are recurring debits on the saved token. Razorpay tells the customer before every
 * debit and takes a day or more, so renewals return "pending", are started early
 * (Contracts\AdvanceCharge) and settle through the signed webhook.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway, SavedMethodCharger, AdvanceCharge
{
    public const ID = 'stackless_razorpay';
    public const MODULE_ID = 'razorpay_gateway';

    public const META_ORDER_ID = '_stackless_razorpay_order_id';
    public const META_PAYMENT_ID = '_stackless_razorpay_payment_id';
    public const META_MODE = '_stackless_razorpay_mode';
    public const META_FLOW = '_stackless_razorpay_flow';
    public const META_METHOD = '_stackless_razorpay_method';
    public const META_AMOUNT = '_stackless_razorpay_amount';
    public const META_ORDER_AT = '_stackless_razorpay_order_at';
    public const META_VERIFICATION = '_stackless_razorpay_verification';
    public const META_FIRST_DEBIT = '_stackless_razorpay_first_debit_token';
    public const META_REFUND_IDS = '_stackless_razorpay_refund_ids';
    public const USER_META_CUSTOMER = '_stackless_razorpay_customer_';
    public const USER_META_PENDING_ADD = '_stackless_razorpay_pending_add';

    public const FIELD_METHOD = 'stackless_razorpay_recurring_method';

    /**
     * Razorpay debits any amount up to the mandate ceiling on any date, so amount and date
     * changes work (amounts above the ceiling need a new mandate). The mandate is ₹0 for eNACH,
     * so a trial checkout can save a method; cards and UPI take a ₹1 authorization that is
     * refunded.
     */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    /** Renewal charges start this many days early by default (pre-debit notice plus settlement). */
    public const DEFAULT_LEAD_DAYS = 3;

    /** @var Gateway|null */
    private static $instance;

    public static function instance(): self
    {
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways[self::ID]) && $gateways[self::ID] instanceof self) {
                return $gateways[self::ID];
            }
        }
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Razorpay (Stackless)', 'stackless-lite');
        $this->method_description = __('UPI, cards, netbanking and wallets through Razorpay Checkout, with card e-mandates, UPI Autopay and eNACH for automatic subscription renewals in rupees.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'saveSettings']);
    }

    /* ---- Settings -------------------------------------------------------------- */

    public function init_form_fields()
    {
        $fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Razorpay', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('UPI, cards, netbanking and wallets', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('Pay securely with Razorpay.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use Razorpay test keys (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'keys_section' => [
                'title' => __('API keys', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Open Razorpay API keys', 'stackless-lite') => 'https://dashboard.razorpay.com/app/website-app-settings/api-keys'])
                    . __('Generate keys in Account & Settings → API keys, once in Test mode and once in Live mode.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            $label = $mode === 'test' ? __('Test', 'stackless-lite') : __('Live', 'stackless-lite');
            foreach (['key_id' => __('key id', 'stackless-lite'), 'key_secret' => __('key secret', 'stackless-lite'), 'webhook_secret' => __('webhook secret', 'stackless-lite')] as $field => $name) {
                if ($this->isDefined($mode, $field)) {
                    continue;
                }
                $fields[$mode . '_' . $field] = [
                    'title' => $label . ' ' . $name,
                    'type' => $field === 'key_id' ? 'text' : 'password',
                    'placeholder' => $field === 'key_id' ? 'rzp_' . $mode . '_…' : '',
                    'default' => '',
                ];
            }
        }
        $fields += [
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->webhookInstructions(),
            ],
            'recurring_section' => [
                'title' => __('Subscriptions', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Automatic renewals use Razorpay recurring tokens, which Razorpay switches on for your account on request. Renewals are charged in rupees only.', 'stackless-lite'),
            ],
            'recurring_methods' => [
                'title' => __('Renewal methods', 'stackless-lite'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => Mandate::labels(),
                'default' => Mandate::METHODS,
                'description' => __('Methods buyers can choose for subscriptions. Card mandates cover renewals up to ₹15,000, UPI Autopay up to ₹99,999 and eNACH up to ₹1 crore.', 'stackless-lite'),
            ],
            'switch_allowance' => [
                'title' => __('Plan-switch allowance (%)', 'stackless-lite'),
                'type' => 'number',
                'default' => '50',
                'custom_attributes' => ['min' => 0, 'max' => 500, 'step' => 1],
                'description' => __('The mandate covers the largest renewal plus this much, so upgrades keep renewing without a new mandate.', 'stackless-lite'),
            ],
            'lead_days' => [
                'title' => __('Start renewal charges early (days)', 'stackless-lite'),
                'type' => 'number',
                'default' => (string) self::DEFAULT_LEAD_DAYS,
                'custom_attributes' => ['min' => 1, 'max' => 7, 'step' => 1],
                'description' => __('Razorpay notifies the customer at least 24 hours before each debit, card debits take about 36 hours and eNACH two working days, so renewals start this many days before they are due.', 'stackless-lite'),
            ],
            'theme_color' => [
                'title' => __('Checkout color', 'stackless-lite'),
                'type' => 'color',
                'default' => '',
                'css' => 'width:8em',
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Razorpay activity to WooCommerce → Status → Logs (stackless-razorpay_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
        $this->form_fields = $fields;
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/razorpay/webhook');
    }

    private function webhookInstructions(): string
    {
        $note = \Stackless\Core\WebhookSetup::reachable($this->webhookUrl()) ? '' : ' ' . \Stackless\Core\WebhookSetup::unreachableNote();
        return \Stackless\Core\WebhookSetup::keyButtons([__('Open Razorpay webhooks', 'stackless-lite') => 'https://dashboard.razorpay.com/app/website-app-settings/webhooks'])
            . sprintf(
                /* translators: 1: webhook URL, 2: event names */
                __('Razorpay has no API for adding webhooks to your own account, so add it once per mode: in Account & Settings → Webhooks choose Add New Webhook, paste this URL: %1$s pick a secret, tick these events, and enter the same secret above: %2$s Webhooks confirm payments when a buyer closes the tab, settle renewals and bring refunds made in Razorpay into WooCommerce.', 'stackless-lite'),
                '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
                '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
            ) . esc_html($note);
    }

    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        $this->init_settings();
        foreach (['live', 'test'] as $mode) {
            $keyId = $this->keyId($mode);
            if ($keyId === '' && $this->keySecret($mode) === '') {
                continue;
            }
            if (!Api::keyMatchesMode($keyId, $mode)) {
                \WC_Admin_Settings::add_error($mode === 'test'
                    ? __('The Razorpay test key id must start with rzp_test_.', 'stackless-lite')
                    : __('The Razorpay live key id must start with rzp_live_.', 'stackless-lite'));
                continue;
            }
            $check = $this->api($mode)->request('GET', 'payments', ['count' => 1]);
            if (is_wp_error($check)) {
                \WC_Admin_Settings::add_error($check->get_error_message());
            }
        }
        if ($this->get_option('enabled') === 'yes') {
            if (!$this->api()->hasKeys()) {
                \WC_Admin_Settings::add_error(__('Razorpay is enabled but the selected mode has no key id and secret.', 'stackless-lite'));
            }
            if ($this->webhookSecret() === '') {
                \WC_Admin_Settings::add_error(__('Add the Razorpay webhook secret. Renewals and refunds stay in sync through the webhook.', 'stackless-lite'));
            }
        }
        return $saved;
    }

    /** test|live */
    public function mode(): string
    {
        return $this->get_option('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether a setting is pinned in wp-config.php, e.g. STACKLESS_RAZORPAY_LIVE_KEY_SECRET. */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = 'STACKLESS_RAZORPAY_' . ($mode === 'test' ? 'TEST' : 'LIVE') . '_' . strtoupper($field);
        return defined($constant) && constant($constant);
    }

    private function secret(string $mode, string $field): string
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_RAZORPAY_' . strtoupper($mode) . '_' . strtoupper($field)));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function keyId(string $mode = ''): string
    {
        return $this->secret($mode, 'key_id');
    }

    public function keySecret(string $mode = ''): string
    {
        return $this->secret($mode, 'key_secret');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secret($mode, 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->keyId($mode), $this->keySecret($mode));
    }

    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    /** @return string[] */
    public function recurringMethods(): array
    {
        $methods = $this->get_option('recurring_methods', Mandate::METHODS);
        $methods = is_array($methods) ? array_values(array_intersect(Mandate::METHODS, array_map('strval', $methods))) : Mandate::METHODS;
        return $methods;
    }

    public function switchAllowance(): int
    {
        return max(0, min(500, (int) $this->get_option('switch_allowance', '50')));
    }

    public function themeColor(): string
    {
        $color = (string) $this->get_option('theme_color', '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log(self::MODULE_ID, $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || !$this->api()->hasKeys()) {
            return false;
        }
        $order = $this->payPageOrder();
        $currency = $order ? $order->get_currency() : (function_exists('stackless_current_currency') ? stackless_current_currency() : get_woocommerce_currency());
        if (!Money::supported($currency)) {
            return false;
        }
        $requiresSave = $order ? stackless_order_requires_saved_payment_method($order) : (did_action('wp_loaded') && stackless_cart_requires_saved_payment_method());
        if ($requiresSave && (!Money::recurringSupported($currency) || !$this->eligibleMethods($order))) {
            return false;
        }
        return parent::is_available();
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    /** The order on an order-pay page, validated against its key. */
    public function payPageOrder(): ?\WC_Order
    {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return null;
        }
        $orderId = absint(get_query_var('order-pay'));
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            return null;
        }
        return $order;
    }

    /**
     * Largest single renewal the saved method must cover: the order's (or cart's) biggest
     * subscription renewal, in rupees.
     */
    public function largestRenewal(?\WC_Order $order = null): float
    {
        $largest = 0.0;
        if ($order) {
            foreach (stackless_get_order_subscriptions($order) as $subscription) {
                if (is_object($subscription) && method_exists($subscription, 'get_recurring_total')) {
                    $largest = max($largest, (float) $subscription->get_recurring_total());
                }
            }
            if ($largest <= 0) {
                $largest = (float) $order->get_total();
            }
            return $largest;
        }
        if (!function_exists('WC') || !WC()->cart || !did_action('wp_loaded')) {
            return 0.0;
        }
        // In the cart there are no subscriptions yet: price each subscription line at its
        // regular price (what renews), in the visitor's currency.
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product || apply_filters('stackless_subscription_product_terms', null, $product) === null) {
                continue;
            }
            $base = (string) $product->get_regular_price('edit');
            $base = $base !== '' ? $base : (string) $product->get_price('edit');
            $price = (float) wc_get_price_including_tax($product, ['qty' => max(1, (int) ($item['quantity'] ?? 1)), 'price' => (float) $base]);
            if (function_exists('stackless_convert_amount')) {
                $price = (float) stackless_convert_amount($price);
            }
            $largest = max($largest, $price);
        }
        return $largest > 0 ? $largest : (float) WC()->cart->get_total('edit');
    }

    /** @return string[] recurring methods that can carry this order's (or cart's) renewals */
    public function eligibleMethods(?\WC_Order $order = null): array
    {
        return Mandate::eligible($this->recurringMethods(), $this->largestRenewal($order));
    }

    public function payment_fields()
    {
        $adding = function_exists('is_add_payment_method_page') && is_add_payment_method_page();
        $order = $this->payPageOrder();
        $requiresSave = $adding || ($order ? stackless_order_requires_saved_payment_method($order) : stackless_cart_requires_saved_payment_method());
        stackless_get_template('razorpay_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'testmode' => $this->mode() === 'test',
            'methods' => $requiresSave ? array_intersect_key(Mandate::labels(), array_flip($adding ? $this->recurringMethods() : $this->eligibleMethods($order))) : [],
            'field' => self::FIELD_METHOD,
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    /**
     * @param int $order_id
     * @return array<string,string>
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return $this->failure(__('Order not found.', 'stackless-lite'));
        }
        if ($order->get_type() === 'stackless_sub') {
            return $this->failure(__('Change the payment method from My Account → Payment methods.', 'stackless-lite'));
        }
        $save = stackless_order_requires_saved_payment_method($order);
        if ((float) $order->get_total() <= 0 && !$save) {
            $order->payment_complete();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        try {
            $prepared = (new Checkout($this))->prepare($order, $save, $this->postedMethod());
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $prepared = new \WP_Error('stackless_razorpay_checkout', __('Razorpay could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($prepared)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Razorpay payment could not start: %s', 'stackless-lite'), $prepared->get_error_message()));
            return $this->failure($prepared->get_error_message());
        }
        // Razorpay Checkout opens on the order's payment page (the receipt step).
        return ['result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)];
    }

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wc_add_notice(__('Sign in to save a payment method.', 'stackless-lite'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        $url = (new Checkout($this))->prepareAdd($userId, $this->postedMethod());
        if (is_wp_error($url)) {
            wc_add_notice($url->get_error_message(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        return ['result' => 'pending', 'redirect' => $url];
    }

    /** @return array{result:string, message:string} */
    public function failure(string $message): array
    {
        if (!$this->isRestRequest() && function_exists('WC') && WC()->session) {
            wc_add_notice($message, 'error');
        }
        return ['result' => 'failure', 'message' => $message];
    }

    private function isRestRequest(): bool
    {
        return (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request());
    }

    private function postedMethod(): string
    {
        $value = isset($_POST[self::FIELD_METHOD]) ? sanitize_key(wp_unslash((string) $_POST[self::FIELD_METHOD])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        return in_array($value, Mandate::METHODS, true) ? $value : '';
    }

    /* ---- Customers -------------------------------------------------------------- */

    /** @return string|\WP_Error cust_… id */
    public function customerFor(int $userId, string $mode, string $name, string $email, string $contact)
    {
        $metaKey = self::USER_META_CUSTOMER . $mode;
        if ($userId > 0) {
            $stored = (string) get_user_meta($userId, $metaKey, true);
            if (strpos($stored, 'cust_') === 0) {
                return $stored;
            }
        }
        $name = trim($name);
        $body = array_filter([
            'name' => strlen($name) >= 3 ? substr($name, 0, 50) : null,
            'email' => is_email($email) ? substr($email, 0, 64) : null,
            'contact' => self::contact($contact) !== '' ? self::contact($contact) : null,
            // Return the existing customer for the same email and phone instead of an error.
            'fail_existing' => '0',
            'notes' => ['wp_user_id' => (string) $userId, 'wc_site' => (string) wp_parse_url(home_url(), PHP_URL_HOST)],
        ]);
        $created = $this->api($mode)->request('POST', 'customers', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['id'] ?? '');
        if (strpos($id, 'cust_') !== 0) {
            return new \WP_Error('stackless_razorpay_customer', __('Razorpay did not create a customer.', 'stackless-lite'));
        }
        if ($userId > 0) {
            update_user_meta($userId, $metaKey, $id);
        }
        return $id;
    }

    /** Phone number as Razorpay takes it: digits with an optional leading +, 8–15 digits. */
    public static function contact(string $phone): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        $digits = preg_replace('/\D/', '', (string) $phone);
        return strlen((string) $digits) >= 8 && strlen((string) $digits) <= 15 ? (string) $phone : '';
    }

    /* ---- Refunds ---------------------------------------------------------------- */

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && (string) $order->get_meta(self::META_PAYMENT_ID) !== '' && $order->get_meta(self::META_VERIFICATION) !== 'yes';
    }

    /**
     * @param int $order_id
     * @param float|string|null $amount
     * @param string $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_razorpay_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_razorpay_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $paymentId = (string) $order->get_meta(self::META_PAYMENT_ID);
        if ($paymentId === '' || $order->get_meta(self::META_VERIFICATION) === 'yes') {
            return new \WP_Error('stackless_razorpay_refund', __('This order has no Razorpay payment to refund.', 'stackless-lite'));
        }
        $refunds = $order->get_refunds();
        $latest = $refunds ? reset($refunds) : null;
        $refundId = $latest instanceof \WC_Order_Refund ? $latest->get_id() : count($refunds);
        $currency = $order->get_currency();
        $minor = Money::toMinor($amount, $currency);
        $refund = $this->api($this->orderMode($order))->request('POST', 'payments/' . rawurlencode($paymentId) . '/refund', [
            'amount' => $minor,
            'speed' => 'normal',
            'receipt' => substr('wc_' . $order->get_id() . '_' . $refundId, 0, 40),
            'notes' => array_filter([
                'wc_order_id' => (string) $order->get_id(),
                'wc_refund_id' => (string) $refundId,
                'reason' => substr(wp_strip_all_tags((string) $reason), 0, 250),
            ]),
        ], ['X-Refund-Idempotency' => 'stackless-refund-' . $order->get_id() . '-' . $refundId . '-' . $minor]);
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        if (($refund['status'] ?? '') === 'failed' || empty($refund['id'])) {
            return new \WP_Error('stackless_razorpay_refund', __('Razorpay could not process this refund. Check the payment in your Razorpay dashboard.', 'stackless-lite'));
        }
        Sync::rememberRefund($order, (string) $refund['id']);
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Razorpay refund id, 3: status */
            __('Refunded %1$s through Razorpay (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            (string) $refund['id'],
            (string) ($refund['status'] ?? 'pending')
        ));
        return true;
    }

    /* ---- Contracts -------------------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * @param \WC_Order $subscription SubscriptionData&WC_Order
     * @return array{result:string, message?:string}
     */
    public function stackless_charge_renewal(\WC_Order $renewal_order, $subscription): array
    {
        return (new Renewals($this))->charge($renewal_order, $subscription instanceof \WC_Order ? $subscription : null);
    }

    /** Stackless runs the schedule; the mandate stays until the customer removes it. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_charge_saved_method(\WC_Order $order, \WC_Payment_Token $token, bool $on_session): array
    {
        return (new Renewals($this))->chargeSaved($order, $token);
    }

    public function stackless_renewal_lead_seconds(): int
    {
        $days = (int) $this->get_option('lead_days', (string) self::DEFAULT_LEAD_DAYS);
        return max(1, min(7, $days > 0 ? $days : self::DEFAULT_LEAD_DAYS)) * DAY_IN_SECONDS;
    }
}
