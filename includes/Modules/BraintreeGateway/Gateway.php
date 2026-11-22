<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Contracts\SavedMethodCharger;
use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Braintree for WooCommerce: cards in Braintree's Hosted Fields with 3-D Secure on classic and
 * block checkout and the order-pay page, vaulted cards for saved cards and automatic renewals,
 * refunds and voids, and signed webhooks for disputes. Card data goes from Braintree's iframes
 * to Braintree; this site only sees a one-time nonce.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway, SavedMethodCharger
{
    public const ID = 'stackless_braintree';
    public const MODULE_ID = 'braintree_gateway';
    public const OPTION = 'woocommerce_stackless_braintree_settings';

    public const FIELD_NONCE = 'stackless_braintree_nonce';
    public const FIELD_DEVICE = 'stackless_braintree_device_data';

    /**
     * A vaulted card takes any amount on any date as a merchant-initiated charge
     * (paymentInitiator RECURRING or UNSCHEDULED), and Stackless runs the schedule.
     */
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

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * A saved setting, without building the gateway (webhooks and background jobs).
     *
     * @param mixed $default
     * @return mixed
     */
    public static function setting(string $key, $default = '')
    {
        $settings = get_option(self::OPTION, []);
        return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function writeLog(string $message, string $level): void
    {
        Logger::log(self::MODULE_ID, $message, $level);
    }

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Braintree (Stackless)', 'stackless-lite');
        $this->method_description = __('Cards through Braintree Hosted Fields with 3-D Secure, saved cards and automatic subscription renewals.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
        if (!did_action('init')) {
            // WooCommerce keeps these fields for the whole request, so build them again once the full webhook address exists.
            add_action('init', [$this, 'init_form_fields'], 0);
        }
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');
        $this->new_method_label = __('Use a new card', 'stackless-lite');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'saveSettings']);
    }

    /* ---- Settings -------------------------------------------------------------- */

    public function init_form_fields()
    {
        $fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept cards with Braintree', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Credit or debit card', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('Pay securely with your card through Braintree.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Braintree sandbox (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'keys_section' => [
                'title' => __('Braintree account', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([
                    __('Open the production Control Panel', 'stackless-lite') => 'https://www.braintreegateway.com/login',
                    __('Open the sandbox Control Panel', 'stackless-lite') => 'https://sandbox.braintreegateway.com/login',
                ])
                    . __('In the Control Panel open the gear icon, then API, and copy the Merchant ID, public key and private key. Saving checks the connection and reads your merchant accounts.', 'stackless-lite'),
            ],
        ];
        foreach (Config::MODES as $mode) {
            $label = $mode === 'test' ? __('Sandbox', 'stackless-lite') : __('Production', 'stackless-lite');
            $map = [
                'merchant_id' => [__('Merchant ID', 'stackless-lite'), 'text', ''],
                'public_key' => [__('public key', 'stackless-lite'), 'text', ''],
                'private_key' => [__('private key', 'stackless-lite'), 'stackless_secret', ''],
                'merchant_accounts' => [__('merchant accounts', 'stackless-lite'), 'text', 'EUR:store_eur, GBP:store_gbp'],
            ];
            foreach ($map as $field => [$name, $type, $placeholder]) {
                if (Config::isDefined($mode, $field)) {
                    continue;
                }
                $fields[$mode . '_' . $field] = ['title' => $label . ' ' . $name, 'type' => $type, 'placeholder' => $placeholder, 'default' => ''];
                if ($field === 'merchant_accounts') {
                    $fields[$mode . '_' . $field]['description'] = __('Optional. A merchant account takes one currency. Name one per currency only where you have several; otherwise the account in the order’s currency is used.', 'stackless-lite');
                }
            }
        }
        $fields += [
            'checkout_section' => [
                'title' => __('Checkout', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->currencyLine(),
            ],
            'three_d_secure' => [
                'title' => __('3-D Secure', 'stackless-lite'),
                'type' => 'select',
                'default' => ThreeDSecure::REQUIRED,
                'options' => [
                    ThreeDSecure::REQUIRED => __('Required (recommended): refuse failed or skipped bank checks', 'stackless-lite'),
                    ThreeDSecure::STRICT => __('Strict: accept only payments with a liability shift', 'stackless-lite'),
                    ThreeDSecure::OFF => __('Off', 'stackless-lite'),
                ],
                'description' => __('Checked on the server with Braintree, so it cannot be skipped from the browser. Braintree must switch on 3-D Secure for your account. European and UK cards need it.', 'stackless-lite'),
            ],
            'saved_cards' => [
                'title' => __('Saved cards', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Let signed-in customers save cards with Braintree. Subscription orders always save the card for renewals.', 'stackless-lite'),
                'default' => 'yes',
            ],
            'device_data' => [
                'title' => __('Fraud tools', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Send device data for Braintree’s fraud tools. Turn this on if your account uses Advanced Fraud Tools or Fraud Protection; it loads PayPal’s device script at checkout.', 'stackless-lite'),
                'default' => 'no',
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Braintree activity to WooCommerce → Status → Logs (stackless-braintree_gateway). Keys, tokens and card data are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: 1: webhook URL, 2: event names */
                    __('In the Control Panel open API, then Webhooks, and create one with this URL: %1$s and these notifications: %2$s Card payments, refunds and voids send no webhook, so Stackless also asks Braintree for those every six hours.', 'stackless-lite'),
                    '<br><code>' . esc_html(Config::webhookUrl()) . '</code><br>',
                    '<br><code>' . implode('</code> <code>', array_map('esc_html', Webhook::EVENTS)) . '</code><br>'
                ),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('A payment that has not settled (usually within a day or two) can only be refunded whole, which voids it. A part can be refunded once it settles. When Braintree does not answer a charge, the order waits and Stackless looks the charge up before anything is charged again.', 'stackless-lite'),
            ],
        ];
        $this->form_fields = $fields;
    }

    private function currencyLine(): string
    {
        $currencies = Connection::currencies(Config::mode());
        if (!$currencies) {
            return '';
        }
        /* translators: %s: currency codes */
        return sprintf(__('Braintree shows for carts in %s, the currencies of your active merchant accounts, and hides for the rest.', 'stackless-lite'), '<strong>' . esc_html(implode(', ', $currencies)) . '</strong>');
    }

    /**
     * A password field that never prints the saved value.
     *
     * @param string $key
     * @param array<string,mixed> $data
     * @return string
     */
    public function generate_stackless_secret_html($key, $data)
    {
        $settings = $this->settings;
        $saved = !empty($settings[$key]);
        $this->settings[$key] = '';
        $data['type'] = 'password';
        if ($saved) {
            $data['placeholder'] = __('Saved. Leave empty to keep it, or type "clear" to remove it.', 'stackless-lite');
        }
        $html = $this->generate_password_html($key, $data);
        $this->settings = $settings;
        return $html;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return string
     */
    public function validate_stackless_secret_field($key, $value)
    {
        $value = is_scalar($value) ? trim(wp_unslash((string) $value)) : '';
        if ($value === 'clear') {
            return '';
        }
        return $value !== '' ? sanitize_text_field($value) : (string) self::setting($key, '');
    }

    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        $this->init_settings();
        foreach (Config::MODES as $mode) {
            if (!Config::hasKeys($mode)) {
                delete_option(Connection::option($mode));
                continue;
            }
            foreach (Connection::check($mode) as $error) {
                \WC_Admin_Settings::add_error($error);
            }
        }
        if (Config::hasKeys(Config::mode())) {
            Sync::ensureScheduled();
        }
        if ($this->get_option('enabled') === 'yes' && !Config::hasKeys(Config::mode())) {
            \WC_Admin_Settings::add_error(__('Braintree is enabled but the selected mode needs a Merchant ID, a public key and a private key.', 'stackless-lite'));
        }
        return $saved;
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || !Config::hasKeys(Config::mode())) {
            return false;
        }
        $mode = Config::mode();
        $connection = Connection::ensure($mode);
        if (!$connection) {
            return false;
        }
        // My Account verifies in any currency the account takes.
        if (function_exists('is_add_payment_method_page') && is_add_payment_method_page()) {
            return (bool) Connection::currencies($mode) && parent::is_available();
        }
        if (!Connection::merchantAccount($mode, $this->contextCurrency(), $connection)) {
            return false;
        }
        return parent::is_available();
    }

    /** @return string[] ISO codes with a merchant account, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Connection::currencies(Config::mode());
    }

    public function contextCurrency(): string
    {
        $order = $this->payPageOrder();
        if ($order) {
            return strtoupper($order->get_currency());
        }
        return strtoupper(function_exists('stackless_current_currency') ? stackless_current_currency() : get_woocommerce_currency());
    }

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
     * What the browser needs. No key, token or amount to charge is taken from it.
     *
     * @return array<string,mixed>
     */
    public function scriptData(string $flow = 'checkout', ?\WC_Order $order = null): array
    {
        $mode = Config::mode();
        $store = (string) get_woocommerce_currency();
        $decimals = [];
        foreach (Connection::currencies($mode) as $code) {
            $decimals[$code] = Money::decimals($code);
        }
        $billing = [];
        if ($order) {
            $requiresSave = stackless_order_requires_saved_payment_method($order);
            $billing = [
                'first' => $order->get_billing_first_name(),
                'last' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'line1' => $order->get_billing_address_1(),
                'line2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'region' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ];
        } elseif ($flow === 'add') {
            $requiresSave = true;
            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                $billing = [
                    'first' => (string) $user->first_name,
                    'last' => (string) $user->last_name,
                    'email' => (string) $user->user_email,
                    'postcode' => (string) get_user_meta($user->ID, 'billing_postcode', true),
                    'country' => (string) get_user_meta($user->ID, 'billing_country', true),
                ];
            }
        } else {
            $requiresSave = stackless_cart_requires_saved_payment_method();
        }
        return [
            'gatewayId' => self::ID,
            'version' => Browser::SDK_VERSION,
            'scripts' => Browser::scripts(),
            'secure' => Config::threeDSecure() !== ThreeDSecure::OFF,
            'deviceData' => Config::deviceData(),
            'endpoint' => \WC_AJAX::get_endpoint(Checkout::SESSION_ACTION),
            'security' => wp_create_nonce(Checkout::SESSION_ACTION),
            'flow' => $flow,
            'order' => $order ? $order->get_id() : 0,
            'orderKey' => $order ? $order->get_order_key() : '',
            'currency' => $flow === 'add' ? Connection::cardCurrency($mode, $store) : $this->contextCurrency(),
            'decimals' => $decimals,
            'requiresSave' => $requiresSave,
            'canSave' => Config::savedCards() && is_user_logged_in() && $flow !== 'add',
            'billing' => array_filter($billing),
            'fields' => ['nonce' => self::FIELD_NONCE, 'device' => self::FIELD_DEVICE],
            'i18n' => Browser::messages() + [
                'number' => __('Card number', 'stackless-lite'),
                'expiry' => __('Expiry date', 'stackless-lite'),
                'cvv' => __('Security code', 'stackless-lite'),
                'testmode' => __('Sandbox: use Braintree’s test cards; no real payment will be taken.', 'stackless-lite'),
            ],
        ];
    }

    public function payment_fields()
    {
        $adding = function_exists('is_add_payment_method_page') && is_add_payment_method_page();
        $order = $this->payPageOrder();
        $data = $this->scriptData($adding ? 'add' : ($order ? 'pay' : 'checkout'), $order);
        if (is_user_logged_in() && !$adding && (Config::savedCards() || $data['requiresSave'])) {
            $this->tokenization_script();
            $this->saved_payment_methods();
        }
        stackless_get_template('braintree_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'testmode' => Config::mode() === 'test',
            'data' => $data,
        ]);
        if ($data['canSave'] && !$data['requiresSave']) {
            $this->save_payment_method_checkbox();
        }
    }

    public function validate_fields()
    {
        if ($this->postedTokenId() > 0 || $this->posted(self::FIELD_NONCE) !== '') {
            return true;
        }
        wc_add_notice(__('Enter your card details to continue.', 'stackless-lite'), 'error');
        return false;
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
        $result = (new Payments())->checkout($order->get_id(), [
            'token_id' => $this->postedTokenId(),
            'nonce' => $this->posted(self::FIELD_NONCE),
            'save' => $this->postedSaveRequest(),
            'device_data' => $this->posted(self::FIELD_DEVICE),
        ]);
        $unconfirmed = $result['result'] === 'pending' && empty($result['busy']);
        if ($unconfirmed && function_exists('WC') && WC()->cart) {
            // An on-hold order is not resumed, so a cart left behind would pay again as a second order.
            WC()->cart->empty_cart();
        }
        if ($result['result'] === 'success' || $unconfirmed) {
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        return $this->failure((string) ($result['message'] ?? __('Your card was declined. Try another card.', 'stackless-lite')));
    }

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $currency = Connection::cardCurrency(Config::mode(), (string) get_woocommerce_currency());
        $card = Vault::addCard(get_current_user_id(), $this->posted(self::FIELD_NONCE), $currency);
        if (is_wp_error($card)) {
            wc_add_notice($card->get_error_message(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        return ['result' => 'success', 'redirect' => wc_get_account_endpoint_url('payment-methods')];
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
        $value = $this->posted('wc-' . $this->id . '-payment-token');
        return ($value !== '' && $value !== 'new') ? absint($value) : 0;
    }

    private function posted(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        return isset($_POST[$key]) && is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }

    private function postedSaveRequest(): bool
    {
        return in_array(strtolower($this->posted('wc-' . $this->id . '-new-payment-method')), ['1', 'true', 'yes', 'on'], true);
    }

    /* ---- Refunds ---------------------------------------------------------------- */

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && (string) $order->get_meta(Meta::TRANSACTION) !== '';
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
            return new \WP_Error('stackless_braintree_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        // WooCommerce saves the refund before it calls this; its id names the refund.
        $latest = null;
        foreach ($order->get_refunds() as $refund) {
            if ($refund instanceof \WC_Order_Refund && (!$latest || $refund->get_id() > $latest->get_id())) {
                $latest = $refund;
            }
        }
        $sequence = $latest && abs((float) $latest->get_amount() - $amount) < 0.001 ? 'refund-' . $latest->get_id() : '';
        $result = (new Reversals())->refund($order->get_id(), $amount, $sequence);
        if (is_wp_error($result)) {
            Config::log('Refund on order ' . $order->get_id() . ' failed: ' . $result->get_error_message(), 'error');
            return $result;
        }
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
        $token = 0;
        $customer = '';
        if ($subscription instanceof \WC_Order) {
            $token = (int) $subscription->get_meta(SubscriptionData::META_TOKEN_ID);
            $customer = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER);
        }
        return self::contractResult((new Payments())->renew($renewal_order->get_id(), $token, $customer));
    }

    /** Stackless runs the schedule; nothing to cancel at Braintree. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_charge_saved_method(\WC_Order $order, \WC_Payment_Token $token, bool $on_session): array
    {
        if ($token->get_gateway_id() !== self::ID) {
            return ['result' => 'failed', 'message' => __('That saved card does not belong to this customer.', 'stackless-lite')];
        }
        return self::contractResult((new Payments())->chargeSaved($order->get_id(), (int) $token->get_id(), $on_session));
    }

    /**
     * @param array<string,mixed> $result
     * @return array{result:string, message?:string, redirect?:string}
     */
    private static function contractResult(array $result): array
    {
        $out = ['result' => (string) $result['result']];
        foreach (['message', 'redirect'] as $key) {
            if (!empty($result[$key])) {
                $out[$key] = (string) $result[$key];
            }
        }
        return $out;
    }
}
