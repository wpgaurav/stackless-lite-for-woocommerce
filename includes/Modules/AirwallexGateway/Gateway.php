<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;

defined('ABSPATH') || exit;

/**
 * Airwallex for WooCommerce: the buyer pays on Airwallex's hosted Checkout Session page and
 * comes back to the order-received page. Nothing the browser or a webhook says settles an
 * order: Sync reads the session and the PaymentIntent from the Airwallex API first.
 *
 * Subscriptions save a card as a merchant-triggered PaymentConsent. Saved cards are not offered
 * for one-click payments: those are customer-initiated and need Airwallex's browser SDK for CVC
 * and 3-D Secure.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = Settings::GATEWAY_ID;
    public const MODULE_ID = Settings::MODULE_ID;

    /** Card consents are unscheduled merchant-initiated agreements: any amount, on any date. */
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
        $this->method_title = __('Airwallex Beta (Stackless)', 'stackless-lite');
        $this->method_description = __('Cards, wallets and the local methods switched on in your Airwallex account, on Airwallex’s hosted payment page. Saves cards for automatic subscription renewals.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
        if (!did_action('init')) {
            // WooCommerce keeps these fields for the whole request, so build them again once the full webhook address exists.
            add_action('init', [$this, 'init_form_fields'], 0);
        }
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');

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
                'label' => __('Accept payments with Airwallex (beta)', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card and more payment methods', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Airwallex’s secure payment page.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Airwallex sandbox (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
        ];
        $sections = [
            'live' => [__('Live account', 'stackless-lite'), __('Open Airwallex', 'stackless-lite'), 'https://www.airwallex.com/app/'],
            'test' => [__('Sandbox account', 'stackless-lite'), __('Open the Airwallex sandbox', 'stackless-lite'), 'https://www.sandbox.airwallex.com/app/'],
        ];
        foreach ($sections as $mode => list($heading, $button, $url)) {
            $fields[$mode . '_section'] = [
                'title' => $heading,
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([$button => $url])
                    . __('In Developer → API keys create a scoped key with Payment Acceptance (write), Checkout Sessions (write) and Payment Disputes (read). The account ID is under Settings → Account details.', 'stackless-lite'),
            ];
            $labels = [
                'client_id' => [__('Client ID', 'stackless-lite'), 'text'],
                'api_key' => [__('API key', 'stackless-lite'), 'password'],
                'account_id' => [__('Account ID', 'stackless-lite'), 'text'],
                'webhook_secret' => [__('Webhook secret', 'stackless-lite'), 'password'],
            ];
            foreach ($labels as $key => list($label, $type)) {
                if (!Settings::isDefined($mode, $key)) {
                    $fields[$mode . '_' . $key] = ['title' => $label, 'type' => $type, 'default' => ''];
                }
            }
            $fields[$mode . '_login_as'] = [
                'title' => __('Several accounts', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('This API key covers more than one account (sign in as the account ID above)', 'stackless-lite'),
                'default' => 'no',
            ];
        }
        $fields += [
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: 1: webhook URL, 2: API version, 3: event names */
                    __('In Developer → Webhooks, in the sandbox and in the live account, add %1$s with API version %2$s and these events: %3$s. Each webhook has its own secret. Test events sent from Airwallex use another secret, so this site refuses them.', 'stackless-lite'),
                    // The REST URL needs the rewrite rules, which do not exist before init.
                    '<code>' . esc_html(did_action('init') ? Settings::webhookUrl() : Webhook::REST_NAMESPACE . Webhook::ROUTE) . '</code>',
                    '<code>' . esc_html(Api::VERSION) . '</code>',
                    '<code>' . implode('</code>, <code>', array_map('esc_html', Webhook::EVENTS)) . '</code>'
                ),
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Airwallex activity to WooCommerce → Status → Logs (stackless-airwallex_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Subscriptions offer cards only, and a free trial saves the card without a charge. Turn Automatic Currency Conversion off in Airwallex → Payments → Settings: a payment in another currency cannot match its order and is held for review. Airwallex labels the Checkout Session API as beta.', 'stackless-lite'),
            ],
        ];
        $this->form_fields = $fields;
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        $this->init_settings();
        $enabled = $this->get_option('enabled') === 'yes';
        foreach (Connection::problems($enabled) as $problem) {
            \WC_Admin_Settings::add_error($problem);
        }
        if ($enabled && !\Stackless\Core\WebhookSetup::reachable(Settings::webhookUrl())) {
            \WC_Admin_Settings::add_error(__('Airwallex cannot send webhooks to this site until it is on public HTTPS.', 'stackless-lite'));
        }
        return $saved;
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || !Settings::configured()) {
            return false;
        }
        if (!Money::supported($this->contextCurrency())) {
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

    public function payment_fields()
    {
        $adding = function_exists('is_add_payment_method_page') && is_add_payment_method_page();
        $order = $this->payPageOrder();
        stackless_get_template('airwallex_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'testmode' => Settings::mode() === 'test',
            'requires_save' => $adding || ($order ? stackless_order_requires_saved_payment_method($order) : stackless_cart_requires_saved_payment_method()),
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
        if ($this->postedSavedToken()) {
            // Never charge a token named by the browser (see the class note).
            return $this->failure(__('Saved cards are used for automatic payments only. Choose a new payment method.', 'stackless-lite'));
        }
        try {
            $url = (new Checkout())->start((int) $order->get_id());
        } catch (\Throwable $e) {
            Settings::log('Checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_airwallex_checkout', __('Airwallex could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Airwallex payment could not start: %s', 'stackless-lite'), $url->get_error_message()));
            return $this->failure($url->get_error_message());
        }
        return ['result' => 'success', 'redirect' => $url];
    }

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wc_add_notice(__('Sign in to save a payment method.', 'stackless-lite'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        $url = (new Checkout())->startAdd($userId, get_woocommerce_currency());
        if (is_wp_error($url)) {
            wc_add_notice($url->get_error_message(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        // "pending" keeps WooCommerce from saying the method was added before Airwallex confirms it.
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

    private function postedSavedToken(): bool
    {
        $key = 'wc-' . $this->id . '-payment-token';
        $value = isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        return $value !== '' && $value !== 'new';
    }

    /* ---- Refunds ---------------------------------------------------------------- */

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && (string) $order->get_meta(Meta::INTENT) !== '';
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
            return new \WP_Error('stackless_airwallex_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        // WooCommerce creates the refund before it calls this, so the highest id names this refund
        // (the list is sorted by date, and two refunds can share a second).
        $refunds = (array) $order->get_refunds();
        $latest = 0;
        foreach ($refunds as $refund) {
            if ($refund instanceof \WC_Order_Refund) {
                $latest = max($latest, (int) $refund->get_id());
            }
        }
        $key = $latest > 0 ? 'id-' . $latest : 'n-' . count($refunds);
        $result = (new Refunds())->create((int) $order->get_id(), $amount, $key, (string) $reason);
        return is_wp_error($result) ? $result : true;
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

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
        $tokenId = $subscription instanceof \WC_Order ? (string) $subscription->get_meta(SubscriptionData::META_TOKEN_ID) : '';
        return (new Renewals())->charge((int) $renewal_order->get_id(), $tokenId);
    }

    /** Stackless runs the schedule; nothing to cancel at Airwallex. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }
}
