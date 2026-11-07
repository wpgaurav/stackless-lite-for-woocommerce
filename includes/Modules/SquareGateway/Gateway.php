<?php

namespace Stackless\Modules\SquareGateway;

use Stackless\Contracts\SavedMethodCharger;
use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Square for WooCommerce: the Web Payments SDK card form, Apple Pay and Google Pay on classic
 * and block checkout, cards on file (Customers and Cards APIs) for saved cards and automatic
 * subscription renewals, refunds, and a signed webhook. Card data goes from the browser to
 * Square; this site only sees Square's one-time token. Square charges in the currency of the
 * business location only.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway, SavedMethodCharger
{
    public const ID = 'stackless_square';
    public const MODULE_ID = 'square_gateway';

    public const META_PAYMENT_ID = '_stackless_square_payment_id';
    public const META_MODE = '_stackless_square_mode';
    public const META_REFUND_IDS = '_stackless_square_refund_ids';
    public const META_CARD_ID = '_stackless_square_card_id';
    public const USER_META_CUSTOMER = '_stackless_square_customer_';

    public const FIELD_TOKEN = 'stackless_square_token';
    public const FIELD_METHOD = 'stackless_square_method';

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

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Square (Stackless)', 'stackless-lite');
        $this->method_description = __('Cards, Apple Pay and Google Pay through Square’s Web Payments SDK, with cards on file for saved cards and automatic subscription renewals.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
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
                'label' => __('Accept payments with Square', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, Apple Pay or Google Pay', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('Pay securely with your card through Square.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Square sandbox (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'keys_section' => [
                'title' => __('Square application', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Open the Square Developer Console', 'stackless-lite') => 'https://developer.squareup.com/apps'])
                    . __('Open your application, then copy its Application ID and access token for Production and for Sandbox. The location is filled in with your main location when left empty, and the webhook is created for you on save (it needs a personal access token, which these are).', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            $label = $mode === 'test' ? __('Sandbox', 'stackless-lite') : __('Production', 'stackless-lite');
            $map = [
                'app_id' => [__('application ID', 'stackless-lite'), 'text', $mode === 'test' ? 'sandbox-sq0idb-…' : 'sq0idp-…'],
                'access_token' => [__('access token', 'stackless-lite'), 'password', 'EAAA…'],
                'location_id' => [__('location ID', 'stackless-lite'), 'text', ''],
                'webhook_signature_key' => [__('webhook signature key', 'stackless-lite'), 'password', ''],
            ];
            foreach ($map as $field => [$name, $type, $placeholder]) {
                if ($this->isDefined($mode, $field)) {
                    continue;
                }
                $fields[$mode . '_' . $field] = ['title' => $label . ' ' . $name, 'type' => $type, 'placeholder' => $placeholder, 'default' => ''];
            }
        }
        $fields += [
            'checkout_section' => [
                'title' => __('Checkout', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->locationLine(),
            ],
            'wallets' => [
                'title' => __('Wallets', 'stackless-lite'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => ['google_pay' => __('Google Pay', 'stackless-lite'), 'apple_pay' => __('Apple Pay', 'stackless-lite')],
                'default' => ['google_pay', 'apple_pay'],
                'description' => __('Shown above the card form for one-time orders. Wallet payments cannot be saved, so subscription checkouts show the card form only. Apple Pay also needs your domain registered in the Square Developer Console (Apple Pay tab).', 'stackless-lite'),
            ],
            'saved_cards' => [
                'title' => __('Saved cards', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Let signed-in customers save cards with Square. Subscription orders always save the card for renewals.', 'stackless-lite'),
                'default' => 'yes',
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Square activity to WooCommerce → Status → Logs (stackless-square_gateway). Tokens and card data are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: 1: webhook URL, 2: event names */
                    __('Created automatically when the signature key is empty. To add it by hand, open Webhooks → Subscriptions in your application, add this URL: %1$s with these events, and paste its signature key above: %2$s', 'stackless-lite'),
                    '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
                    '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code>'
                ),
            ],
        ];
        $this->form_fields = $fields;
    }

    private function locationLine(): string
    {
        $location = $this->location();
        if (empty($location['id'])) {
            return '';
        }
        return sprintf(
            /* translators: 1: location name, 2: currency code */
            __('Charging through %1$s in %2$s. Square takes payments in the location’s currency only, so Square is hidden when a cart is in another currency.', 'stackless-lite'),
            '<strong>' . esc_html((string) ($location['name'] ?? $location['id'])) . '</strong>',
            '<strong>' . esc_html((string) ($location['currency'] ?? '')) . '</strong>'
        );
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
            if ($this->accessToken($mode) === '') {
                delete_option($this->locationOption($mode));
                continue;
            }
            $location = (new Setup($this))->location($mode);
            if (is_wp_error($location)) {
                \WC_Admin_Settings::add_error($location->get_error_message());
                continue;
            }
            $webhook = (new Setup($this))->webhook($mode);
            if (is_wp_error($webhook)) {
                \WC_Admin_Settings::add_error($webhook->get_error_message());
            } elseif ($webhook !== '') {
                \WC_Admin_Settings::add_message($webhook);
            }
        }
        if ($this->get_option('enabled') === 'yes') {
            if ($this->appId() === '' || $this->accessToken() === '') {
                \WC_Admin_Settings::add_error(__('Square is enabled but the selected mode needs both an application ID and an access token.', 'stackless-lite'));
            }
            if ($this->webhookKey() === '') {
                \WC_Admin_Settings::add_error(__('Add the Square webhook signature key so refunds and card changes made in Square reach WooCommerce.', 'stackless-lite'));
            }
        }
        return $saved;
    }

    /** test (sandbox) | live (production) */
    public function mode(): string
    {
        return $this->get_option('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** STACKLESS_SQUARE_{LIVE|SANDBOX}_{APP_ID|ACCESS_TOKEN|LOCATION_ID|WEBHOOK_SIGNATURE_KEY} */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = self::constant($mode, $field);
        return defined($constant) && constant($constant);
    }

    private static function constant(string $mode, string $field): string
    {
        return 'STACKLESS_SQUARE_' . ($mode === 'test' ? 'SANDBOX' : 'LIVE') . '_' . strtoupper($field);
    }

    private function setting(string $mode, string $field): string
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant(self::constant($mode, $field)));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function appId(string $mode = ''): string
    {
        return $this->setting($mode, 'app_id');
    }

    public function accessToken(string $mode = ''): string
    {
        return $this->setting($mode, 'access_token');
    }

    public function webhookKey(string $mode = ''): string
    {
        return $this->setting($mode, 'webhook_signature_key');
    }

    public function locationId(string $mode = ''): string
    {
        $id = $this->setting($mode, 'location_id');
        if ($id === '') {
            $id = (string) ($this->location($mode)['id'] ?? '');
        }
        return $id;
    }

    /** Update a setting saved in the gateway's options (the webhook key and location from setup). */
    public function saveSetting(string $key, string $value): void
    {
        $this->update_option($key, $value);
    }

    public function locationOption(string $mode): string
    {
        return 'stackless_square_gateway_location_' . ($mode === 'test' ? 'test' : 'live');
    }

    /** @return array{id?:string, name?:string, currency?:string, country?:string} */
    public function location(string $mode = ''): array
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        $location = get_option($this->locationOption($mode), []);
        return is_array($location) ? $location : [];
    }

    public function currency(string $mode = ''): string
    {
        return strtoupper((string) ($this->location($mode)['currency'] ?? ''));
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->accessToken($mode));
    }

    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/square/webhook');
    }

    /** @return string[] google_pay and/or apple_pay */
    public function wallets(): array
    {
        $wallets = $this->get_option('wallets', ['google_pay', 'apple_pay']);
        return is_array($wallets) ? array_values(array_intersect(['google_pay', 'apple_pay'], $wallets)) : [];
    }

    public function savedCardsEnabled(): bool
    {
        return $this->get_option('saved_cards', 'yes') === 'yes';
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
        if ($this->enabled !== 'yes' || $this->appId() === '' || $this->accessToken() === '' || $this->locationId() === '') {
            return false;
        }
        $order = $this->payPageOrder();
        $currency = strtoupper($order ? $order->get_currency() : (function_exists('stackless_current_currency') ? stackless_current_currency() : get_woocommerce_currency()));
        if ($this->currency() !== '' && $currency !== $this->currency()) {
            return false;
        }
        return parent::is_available();
    }

    /** @return string[] ISO codes: the location's currency only (read by Currency-Aware Payment Gateways). */
    public function supported_currencies(): array
    {
        $currency = $this->currency();
        return $currency !== '' ? [$currency] : Money::CURRENCIES;
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
     * What the browser needs to tokenize: the SDK, ids, amount, currency and intent.
     *
     * @return array<string,mixed>
     */
    public function scriptData(string $flow = 'checkout', ?\WC_Order $order = null): array
    {
        $currency = $this->currency() !== '' ? $this->currency() : strtoupper(get_woocommerce_currency());
        if ($flow === 'add') {
            $total = 0.0;
            $requiresSave = true;
        } elseif ($order) {
            $total = (float) $order->get_total();
            $requiresSave = stackless_order_requires_saved_payment_method($order);
        } else {
            $total = function_exists('WC') && WC()->cart && did_action('wp_loaded') ? (float) WC()->cart->get_total('edit') : 0.0;
            $requiresSave = stackless_cart_requires_saved_payment_method();
        }
        $mode = $this->mode();
        $location = $this->location($mode);
        $billing = [];
        if ($order) {
            $billing = [
                'givenName' => $order->get_billing_first_name(),
                'familyName' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'addressLines' => array_values(array_filter([$order->get_billing_address_1(), $order->get_billing_address_2()])),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postalCode' => $order->get_billing_postcode(),
                'countryCode' => $order->get_billing_country(),
            ];
        } elseif ($flow === 'add' && is_user_logged_in()) {
            $user = wp_get_current_user();
            $billing = [
                'givenName' => (string) $user->first_name,
                'familyName' => (string) $user->last_name,
                'email' => (string) $user->user_email,
                'postalCode' => (string) get_user_meta($user->ID, 'billing_postcode', true),
                'countryCode' => (string) get_user_meta($user->ID, 'billing_country', true),
            ];
        }
        return [
            'billing' => array_filter($billing),
            'gatewayId' => self::ID,
            'sdk' => $mode === 'test' ? 'https://sandbox.web.squarecdn.com/v1/square.js' : 'https://web.squarecdn.com/v1/square.js',
            'appId' => $this->appId($mode),
            'locationId' => $this->locationId($mode),
            'country' => (string) ($location['country'] ?? ''),
            'currency' => $currency,
            'amount' => Money::display($total, $currency),
            'flow' => $flow,
            'requiresSave' => $requiresSave,
            'canSave' => $this->savedCardsEnabled() && is_user_logged_in() && $flow !== 'add',
            'wallets' => $requiresSave || $flow === 'add' ? [] : $this->wallets(),
            'fields' => ['token' => self::FIELD_TOKEN, 'method' => self::FIELD_METHOD],
            'storeName' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'i18n' => [
                'loading' => __('Loading the card form…', 'stackless-lite'),
                'failed' => __('Square could not load the card form. Reload the page or choose another payment method.', 'stackless-lite'),
                'invalid' => __('Check your card details and try again.', 'stackless-lite'),
                'verify' => __('Your bank could not verify the card. Try again or use another card.', 'stackless-lite'),
                'testmode' => __('Sandbox: use Square’s test cards; no real payment will be taken.', 'stackless-lite'),
                'total' => __('Total', 'stackless-lite'),
            ],
        ];
    }

    public function payment_fields()
    {
        $adding = function_exists('is_add_payment_method_page') && is_add_payment_method_page();
        $order = $this->payPageOrder();
        $data = $this->scriptData($adding ? 'add' : ($order ? 'pay' : 'checkout'), $order);
        if (is_user_logged_in() && !$adding && ($this->savedCardsEnabled() || $data['requiresSave'])) {
            $this->tokenization_script();
            $this->saved_payment_methods();
        }
        stackless_get_template('square_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'testmode' => $this->mode() === 'test',
            'data' => $data,
        ]);
        if ($data['canSave'] && !$data['requiresSave']) {
            $this->save_payment_method_checkbox();
        }
    }

    public function validate_fields()
    {
        if ($this->postedTokenId() > 0 || $this->postedSource() !== '') {
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
        $payments = new Payments($this);
        $tokenId = $this->postedTokenId();
        if ($tokenId > 0) {
            $token = Tokens::getOwned($tokenId, (int) $order->get_customer_id());
            if (!$token || Tokens::mode($token) !== $this->mode()) {
                return $this->failure(__('That saved card is not available. Choose another one.', 'stackless-lite'));
            }
            $result = $payments->chargeCard($order, $token, 'checkout');
        } else {
            $source = $this->postedSource();
            if ($source === '') {
                return $this->failure(__('Enter your card details to continue.', 'stackless-lite'));
            }
            $requiresSave = stackless_order_requires_saved_payment_method($order);
            if ($requiresSave && $this->postedMethod() !== 'card') {
                return $this->failure(__('Wallet payments cannot be saved for renewals. Pay with a card for this subscription.', 'stackless-lite'));
            }
            $save = $requiresSave || ($this->postedSaveRequest() && $this->savedCardsEnabled() && $order->get_customer_id() > 0 && $this->postedMethod() === 'card');
            $result = $payments->chargeNew($order, $source, $save);
        }
        if ($result['result'] === 'success') {
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
        return $this->failure($result['message'] ?? __('Your card was declined. Try another card.', 'stackless-lite'));
    }

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $userId = get_current_user_id();
        $source = $this->postedSource();
        if ($userId <= 0 || $source === '') {
            wc_add_notice(__('Enter your card details to continue.', 'stackless-lite'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        $token = (new Payments($this))->storeCard($source, $userId, $this->mode());
        if (is_wp_error($token)) {
            wc_add_notice($token->get_error_message(), 'error');
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
        $key = 'wc-' . $this->id . '-payment-token';
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        return ($value !== '' && $value !== 'new') ? absint($value) : 0;
    }

    /** The one-time Square token from the card form or a wallet (cnon:… or a wallet token). */
    private function postedSource(): string
    {
        $value = isset($_POST[self::FIELD_TOKEN]) ? sanitize_text_field(wp_unslash((string) $_POST[self::FIELD_TOKEN])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return preg_match('/^[A-Za-z0-9:_\-]{8,400}$/', $value) ? $value : '';
    }

    /** card, google_pay or apple_pay: wallet tokens cannot be stored. */
    private function postedMethod(): string
    {
        $value = isset($_POST[self::FIELD_METHOD]) ? sanitize_key(wp_unslash((string) $_POST[self::FIELD_METHOD])) : 'card'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return in_array($value, ['card', 'google_pay', 'apple_pay'], true) ? $value : 'card';
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

    /* ---- Customers -------------------------------------------------------------- */

    /** @return string|\WP_Error Square customer id */
    public function customerFor(int $userId, string $mode, ?\WC_Order $order = null)
    {
        $metaKey = self::USER_META_CUSTOMER . $mode;
        if ($userId > 0) {
            $stored = (string) get_user_meta($userId, $metaKey, true);
            if ($stored !== '') {
                return $stored;
            }
        }
        $user = $userId > 0 ? get_userdata($userId) : false;
        $body = array_filter([
            'idempotency_key' => Api::key('cus', $mode . '-' . ($userId > 0 ? 'u' . $userId : 'o' . ($order ? $order->get_id() : wp_generate_password(8, false)))),
            'given_name' => $order ? $order->get_billing_first_name() : ($user ? $user->first_name : ''),
            'family_name' => $order ? $order->get_billing_last_name() : ($user ? $user->last_name : ''),
            'email_address' => $order ? $order->get_billing_email() : ($user ? $user->user_email : ''),
            'reference_id' => $userId > 0 ? 'wp_user_' . $userId : ($order ? 'wc_order_' . $order->get_id() : ''),
        ]);
        if (count($body) < 2) {
            $body['email_address'] = (string) get_option('admin_email');
        }
        $created = $this->api($mode)->request('POST', 'customers', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['customer']['id'] ?? '');
        if ($id === '') {
            return new \WP_Error('stackless_square_customer', __('Square did not create a customer.', 'stackless-lite'));
        }
        if ($userId > 0) {
            update_user_meta($userId, $metaKey, $id);
        }
        return $id;
    }

    /* ---- Refunds ---------------------------------------------------------------- */

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && (string) $order->get_meta(self::META_PAYMENT_ID) !== '';
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
            return new \WP_Error('stackless_square_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_square_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $paymentId = (string) $order->get_meta(self::META_PAYMENT_ID);
        if ($paymentId === '') {
            return new \WP_Error('stackless_square_refund', __('This order has no Square payment to refund.', 'stackless-lite'));
        }
        $refunds = $order->get_refunds();
        $latest = $refunds ? reset($refunds) : null;
        $refundId = $latest instanceof \WC_Order_Refund ? $latest->get_id() : count($refunds);
        $currency = $order->get_currency();
        $refund = $this->api($this->orderMode($order))->request('POST', 'refunds', array_filter([
            'idempotency_key' => Api::key('rf', $order->get_id() . '-' . $refundId . '-' . Money::toMinor($amount, $currency)),
            'payment_id' => $paymentId,
            'amount_money' => Money::money($amount, $currency),
            'reason' => substr(wp_strip_all_tags((string) $reason), 0, 192),
        ]));
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $object = is_array($refund['refund'] ?? null) ? $refund['refund'] : [];
        $status = (string) ($object['status'] ?? '');
        if (in_array($status, ['REJECTED', 'FAILED'], true) || empty($object['id'])) {
            return new \WP_Error('stackless_square_refund', __('Square could not process this refund. Check the payment in your Square Dashboard.', 'stackless-lite'));
        }
        Payments::rememberRefund($order, (string) $object['id']);
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Square refund id, 3: status */
            __('Refunded %1$s through Square (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            (string) $object['id'],
            strtolower($status)
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
        return (new Payments($this))->renew($renewal_order, $subscription instanceof \WC_Order ? $subscription : null);
    }

    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function stackless_charge_saved_method(\WC_Order $order, \WC_Payment_Token $token, bool $on_session): array
    {
        return (new Payments($this))->chargeSaved($order, $token, $on_session);
    }

    /** Points the order at a saved card (renewals read it from there). */
    public static function attachToken(\WC_Order $order, \WC_Payment_Token $token): void
    {
        $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
        $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
        $order->update_meta_data(self::META_CARD_ID, $token->get_token());
        $order->save();
    }
}
