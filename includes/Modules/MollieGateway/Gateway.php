<?php

namespace Stackless\Modules\MollieGateway;

use Stackless\Contracts\SavedMethodCharger;
use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Mollie for WooCommerce: the buyer pays on Mollie's hosted checkout (cards, iDEAL,
 * Bancontact, PayPal, Apple Pay and every other method switched on in Mollie), and comes back
 * to the order-received page. Mollie's webhook carries only a payment id, so every status is
 * read from the Mollie API before anything changes.
 *
 * Saving a method for subscriptions uses a first payment (sequenceType=first) on a Mollie
 * customer, which leaves a mandate. Renewals are recurring payments on that mandate; cards
 * usually settle at once, SEPA Direct Debit days later through the webhook.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway, SavedMethodCharger
{
    public const ID = 'stackless_mollie';
    public const MODULE_ID = 'mollie_gateway';

    public const META_PAYMENT_ID = '_stackless_mollie_payment_id';
    public const META_CHECKOUT_URL = '_stackless_mollie_checkout_url';
    public const META_MODE = '_stackless_mollie_mode';
    public const META_SEQUENCE = '_stackless_mollie_sequence';
    public const META_VERIFICATION = '_stackless_mollie_verification';
    public const META_METHOD = '_stackless_mollie_method';
    public const META_REFUND_IDS = '_stackless_mollie_refund_ids';
    public const META_CHARGEBACK = '_stackless_mollie_charged_back';
    public const USER_META_CUSTOMER = '_stackless_mollie_customer_';
    public const USER_META_PENDING_ADD = '_stackless_mollie_pending_add';

    public const FIELD_METHOD = 'stackless_mollie_method';

    /** auto_renewal and the rest; Mollie charges every mandate for any amount on any date. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

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

    /** Forget the standalone instance (tests and staging scripts that swap settings). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Mollie (Stackless)', 'stackless-lite');
        $this->method_description = __('Cards, iDEAL, Bancontact, PayPal, Apple Pay and the other methods switched on in your Mollie account, on Mollie’s hosted checkout. Saves cards, PayPal and SEPA Direct Debit mandates for automatic subscription renewals.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');
        $this->new_method_label = __('Use a new payment method', 'stackless-lite');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'saveSettings']);
    }

    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    /* ---- Settings -------------------------------------------------------------- */

    public function init_form_fields()
    {
        $fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Mollie', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, iDEAL, Bancontact or PayPal', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Mollie’s secure checkout.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Mollie test API key (no real money moves; test payments take euros only)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'keys_section' => [
                'title' => __('API keys', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Open Mollie API keys', 'stackless-lite') => 'https://my.mollie.com/dashboard/developers/api-keys'])
                    . __('Copy the live key (live_…) and the test key (test_…) of your website profile. Mollie calls this site for every payment by itself, so there is no webhook to set up.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if (!$this->isDefined($mode)) {
                $fields[$mode . '_api_key'] = [
                    'title' => $mode === 'test' ? __('Test API key', 'stackless-lite') : __('Live API key', 'stackless-lite'),
                    'type' => 'password',
                    'placeholder' => $mode . '_…',
                    'default' => '',
                ];
            }
        }
        $fields += [
            'checkout_section' => [
                'title' => __('Checkout', 'stackless-lite'),
                'type' => 'title',
            ],
            'methods' => [
                'title' => __('Methods to offer', 'stackless-lite'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => Methods::labels(),
                'default' => [],
                'description' => __('Leave empty to offer every method switched on in Mollie for the order’s currency and amount. Subscriptions only see methods that can save a mandate.', 'stackless-lite'),
            ],
            'method_picker' => [
                'title' => __('Method choice', 'stackless-lite'),
                'type' => 'select',
                'default' => 'checkout',
                'options' => [
                    'checkout' => __('Let buyers pick the method at checkout, then go straight to it', 'stackless-lite'),
                    'mollie' => __('Let buyers pick on Mollie’s page', 'stackless-lite'),
                ],
            ],
            'saved_methods' => [
                'title' => __('Saved payment methods', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Let signed-in customers save cards, PayPal and bank mandates for later. Subscription orders always save the method for renewals.', 'stackless-lite'),
                'default' => 'yes',
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Mollie activity to WooCommerce → Status → Logs (stackless-mollie_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Saving a method for a free trial uses a 0.00 card or PayPal payment, or a one-cent payment for iDEAL and the other bank methods, which is refunded once Mollie confirms the mandate. Bank mandates renew by SEPA Direct Debit in euros and settle a few days after the charge, so those renewals stay pending until Mollie confirms them. Test mode takes euros only.', 'stackless-lite'),
            ],
        ];
        $this->form_fields = $fields;
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        $this->init_settings();
        foreach (['live', 'test'] as $mode) {
            $key = $this->apiKey($mode);
            if ($key === '') {
                continue;
            }
            if (!Api::keyMatchesMode($key, $mode)) {
                \WC_Admin_Settings::add_error($mode === 'test'
                    ? __('The Mollie test API key must start with test_.', 'stackless-lite')
                    : __('The Mollie live API key must start with live_.', 'stackless-lite'));
                continue;
            }
            $check = $this->api($mode)->request('GET', 'methods', ['amount' => ['value' => '10.00', 'currency' => 'EUR']]);
            if (is_wp_error($check)) {
                \WC_Admin_Settings::add_error($check->get_error_message());
            }
        }
        if ($this->get_option('enabled') === 'yes' && $this->apiKey() === '') {
            \WC_Admin_Settings::add_error(__('Mollie is enabled but has no API key for the selected mode.', 'stackless-lite'));
        }
        return $saved;
    }

    /** test|live */
    public function mode(): string
    {
        return $this->get_option('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether the key is pinned in wp-config.php (STACKLESS_MOLLIE_LIVE_API_KEY / _TEST_). */
    public function isDefined(string $mode): bool
    {
        $constant = 'STACKLESS_MOLLIE_' . ($mode === 'test' ? 'TEST' : 'LIVE') . '_API_KEY';
        return defined($constant) && constant($constant);
    }

    public function apiKey(string $mode = ''): string
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        if ($this->isDefined($mode)) {
            return trim((string) constant('STACKLESS_MOLLIE_' . strtoupper($mode) . '_API_KEY'));
        }
        return trim((string) $this->get_option($mode . '_api_key', ''));
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->apiKey($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    /** @return string[] */
    public function configuredMethods(): array
    {
        $methods = $this->get_option('methods', []);
        return is_array($methods) ? array_values(array_filter(array_map('strval', $methods))) : [];
    }

    public function pickerAtCheckout(): bool
    {
        return $this->get_option('method_picker', 'checkout') === 'checkout';
    }

    public function savedMethodsEnabled(): bool
    {
        return $this->get_option('saved_methods', 'yes') === 'yes';
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/mollie/webhook');
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
        if ($this->enabled !== 'yes' || $this->apiKey() === '') {
            return false;
        }
        $currency = $this->contextCurrency();
        if (!Money::supported($currency) || ($this->mode() === 'test' && $currency !== 'EUR')) {
            return false;
        }
        return parent::is_available();
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    private function contextCurrency(): string
    {
        $order = $this->payPageOrder();
        if ($order) {
            return strtoupper($order->get_currency());
        }
        return strtoupper(function_exists('stackless_current_currency') ? stackless_current_currency() : get_woocommerce_currency());
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
     * Methods for the picker on this page: what Mollie offers for the cart or order total
     * (first-payment methods when a method must be saved).
     *
     * @return array<string,string> id => label
     */
    public function pickerMethods(?\WC_Order $order = null, bool $adding = false): array
    {
        if (!$this->pickerAtCheckout() || $this->apiKey() === '') {
            return [];
        }
        if ($adding) {
            $currency = strtoupper(get_woocommerce_currency());
            $total = 1.0;
            $first = true;
        } elseif ($order) {
            $currency = strtoupper($order->get_currency());
            $total = (float) $order->get_total();
            $first = stackless_order_requires_saved_payment_method($order);
        } else {
            $currency = $this->contextCurrency();
            $total = function_exists('WC') && WC()->cart && did_action('wp_loaded') ? (float) WC()->cart->get_total('edit') : 0.0;
            $first = stackless_cart_requires_saved_payment_method();
        }
        $available = Methods::enabled($this->api(), $currency, Money::value(max($total, 0.01), $currency), $first);
        $ids = Methods::pick($this->configuredMethods(), $available, $first);
        $methods = [];
        foreach ($ids as $id) {
            if ($first && !Methods::mandateTakes(Methods::MANDATE_OF[$id] ?? '', $currency)) {
                continue;
            }
            $methods[$id] = $available[$id] ?? Methods::label($id);
        }
        return $methods;
    }

    public function payment_fields()
    {
        $adding = function_exists('is_add_payment_method_page') && is_add_payment_method_page();
        $order = $this->payPageOrder();
        $requiresSave = $adding || ($order ? stackless_order_requires_saved_payment_method($order) : stackless_cart_requires_saved_payment_method());
        $showTokens = is_user_logged_in() && !$adding && ($this->savedMethodsEnabled() || $requiresSave);
        if ($showTokens) {
            $this->tokenization_script();
            $this->saved_payment_methods();
        }
        stackless_get_template('mollie_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'testmode' => $this->mode() === 'test',
            'methods' => $this->pickerMethods($order, $adding),
            'field' => self::FIELD_METHOD,
            'requires_save' => $requiresSave,
        ]);
        if (!$requiresSave && $this->savedMethodsEnabled() && is_user_logged_in() && !$adding) {
            $this->save_payment_method_checkbox();
        }
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

        $tokenId = $this->postedTokenId();
        if ($tokenId > 0) {
            return $this->payWithToken($order, $tokenId);
        }

        $requiresSave = stackless_order_requires_saved_payment_method($order);
        $save = $requiresSave || ($this->postedSaveRequest() && $this->savedMethodsEnabled() && $order->get_customer_id() > 0);
        if ((float) $order->get_total() <= 0 && !$save) {
            $order->payment_complete();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        try {
            $url = (new Checkout($this))->start($order, $save, $this->postedMethod());
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_mollie_checkout', __('Mollie could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Mollie payment could not start: %s', 'stackless-lite'), $url->get_error_message()));
            return $this->failure($url->get_error_message());
        }
        return ['result' => 'success', 'redirect' => $url];
    }

    /**
     * Pay a checkout or pay-page order with a saved mandate: a recurring payment, charged
     * without sending the buyer away.
     *
     * @return array<string,string>
     */
    private function payWithToken(\WC_Order $order, int $tokenId): array
    {
        $token = Tokens::getOwned($tokenId, (int) $order->get_customer_id());
        if (!$token || Tokens::mode($token) !== $this->mode()) {
            return $this->failure(__('That saved payment method is not available. Choose another one.', 'stackless-lite'));
        }
        $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
        $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
        $order->update_meta_data(self::META_MODE, Tokens::mode($token));
        $order->save();
        if ((float) $order->get_total() <= 0) {
            $order->add_order_note(__('Saved Mollie payment method attached for future payments.', 'stackless-lite'));
            $order->payment_complete();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        $result = (new Renewals($this))->chargeToken($order, $token, 'checkout');
        if ($result['result'] === 'failed') {
            return $this->failure($result['message'] ?? __('Mollie declined the payment.', 'stackless-lite'));
        }
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wc_add_notice(__('Sign in to save a payment method.', 'stackless-lite'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        $url = (new Checkout($this))->startAdd($userId, $this->postedMethod());
        if (is_wp_error($url)) {
            wc_add_notice($url->get_error_message(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        // "pending" keeps WooCommerce from saying the method was added before Mollie confirms it.
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

    private function postedTokenId(): int
    {
        $key = 'wc-' . $this->id . '-payment-token';
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        return ($value !== '' && $value !== 'new') ? absint($value) : 0;
    }

    private function postedSaveRequest(): bool
    {
        $key = 'wc-' . $this->id . '-new-payment-method';
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }
        $value = strtolower(sanitize_text_field(wp_unslash((string) $_POST[$key]))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function postedMethod(): string
    {
        $value = isset($_POST[self::FIELD_METHOD]) ? sanitize_key(wp_unslash((string) $_POST[self::FIELD_METHOD])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return isset(Methods::labels()[$value]) ? $value : '';
    }

    /* ---- Customers -------------------------------------------------------------- */

    /**
     * The Mollie customer for an order: the account's customer in this mode, created once,
     * or a customer of its own for a guest order.
     *
     * @return string|\WP_Error cst_… id
     */
    public function customerFor(\WC_Order $order, string $mode)
    {
        $existing = (string) $order->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER);
        if (strpos($existing, 'cst_') === 0 && $this->orderMode($order) === $mode) {
            return $existing;
        }
        $userId = (int) $order->get_customer_id();
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        return $this->customerForUser($userId, $mode, $name, (string) $order->get_billing_email());
    }

    /** @return string|\WP_Error */
    public function customerForUser(int $userId, string $mode, string $name = '', string $email = '')
    {
        $metaKey = self::USER_META_CUSTOMER . $mode;
        if ($userId > 0) {
            $stored = (string) get_user_meta($userId, $metaKey, true);
            if (strpos($stored, 'cst_') === 0) {
                return $stored;
            }
            $user = get_userdata($userId);
            if ($user) {
                $name = $name !== '' ? $name : trim($user->first_name . ' ' . $user->last_name);
                $email = $email !== '' ? $email : (string) $user->user_email;
            }
        }
        $body = array_filter([
            'name' => $name !== '' ? $name : null,
            'email' => is_email($email) ? $email : null,
            'metadata' => ['wp_user_id' => (string) $userId, 'wc_site' => (string) wp_parse_url(home_url(), PHP_URL_HOST)],
        ]);
        $created = $this->api($mode)->request('POST', 'customers', $body, 'stackless-customer-' . md5($mode . '|' . $userId . '|' . $email . '|' . ($userId > 0 ? '' : wp_generate_password(8, false))));
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['id'] ?? '');
        if (strpos($id, 'cst_') !== 0) {
            return new \WP_Error('stackless_mollie_customer', __('Mollie did not create a customer.', 'stackless-lite'));
        }
        if ($userId > 0) {
            update_user_meta($userId, $metaKey, $id);
        }
        return $id;
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
            return new \WP_Error('stackless_mollie_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_mollie_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $paymentId = (string) $order->get_meta(self::META_PAYMENT_ID);
        if ($paymentId === '' || $order->get_meta(self::META_VERIFICATION) === 'yes') {
            return new \WP_Error('stackless_mollie_refund', __('This order has no Mollie payment to refund.', 'stackless-lite'));
        }
        $refunds = $order->get_refunds();
        $latest = $refunds ? reset($refunds) : null;
        $refundId = $latest instanceof \WC_Order_Refund ? $latest->get_id() : count($refunds);
        $currency = $order->get_currency();
        $body = [
            'amount' => Money::amount($amount, $currency),
            'description' => substr(trim((string) $reason) !== '' ? wp_strip_all_tags((string) $reason) : sprintf('Refund for order %s', $order->get_order_number()), 0, 255),
            'metadata' => ['wc_order_id' => (string) $order->get_id(), 'wc_refund_id' => (string) $refundId],
        ];
        $refund = $this->api($this->orderMode($order))->request('POST', 'payments/' . rawurlencode($paymentId) . '/refunds', $body, 'stackless-refund-' . $order->get_id() . '-' . $refundId . '-' . Money::toMinor($amount, $currency));
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $status = (string) ($refund['status'] ?? '');
        if ($status === 'failed' || $status === 'canceled' || empty($refund['id'])) {
            return new \WP_Error('stackless_mollie_refund', __('Mollie could not process this refund. Check the payment in your Mollie dashboard.', 'stackless-lite'));
        }
        Sync::rememberRefund($order, (string) $refund['id']);
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Mollie refund id, 3: status */
            __('Refunded %1$s through Mollie (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            (string) $refund['id'],
            $status
        ));
        return true;
    }

    /* ---- Subscriptions and saved-method contracts ------------------------------- */

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

    /** Stackless runs the schedule; nothing to cancel at Mollie. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_charge_saved_method(\WC_Order $order, \WC_Payment_Token $token, bool $on_session): array
    {
        return (new Renewals($this))->chargeSaved($order, $token);
    }
}
