<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * PayPal (Orders v2 + Vault v3) for WooCommerce, with automatic subscription renewals.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = 'stackless_paypal';

    public const META_ORDER_ID = '_stackless_paypal_order_id';
    public const META_CAPTURE_ID = '_stackless_paypal_capture_id';
    public const META_AUTH_ID = '_stackless_paypal_authorization_id';
    public const META_VAULT_ID = '_stackless_paypal_vault_id';
    public const META_SETUP_TOKEN = '_stackless_paypal_setup_token';
    public const META_ENV = '_stackless_paypal_environment';
    public const META_REFUND_IDS = '_stackless_paypal_refund_ids';
    public const META_RENEWAL_ATTEMPTS = '_stackless_paypal_renewal_attempts';
    public const META_PAYER_EMAIL = '_stackless_paypal_payer_email';

    /** Features this gateway offers to the Subscriptions module. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    /** @var Gateway|null */
    private static $instance;

    /** @var Payments|null */
    private $payments;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->id = self::ID;
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('PayPal (Stackless)', 'stackless-lite');
        $this->method_description = __('PayPal Smart Buttons, Pay Later and saved PayPal accounts for automatic subscription renewals. Talks to PayPal directly; no other PayPal plugin needed.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');
        $this->view_transaction_url = $this->isSandbox()
            ? 'https://www.sandbox.paypal.com/activity/payment/%s'
            : 'https://www.paypal.com/activity/payment/%s';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'saveSettings']);
    }

    /* ---- Settings ------------------------------------------------------ */

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with PayPal', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('PayPal', 'stackless-lite'),
                'desc_tip' => true,
                'description' => __('Shown to buyers at checkout.', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('Pay with your PayPal account, Pay Later, or a card through PayPal.', 'stackless-lite'),
            ],
            'sandbox' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the PayPal sandbox (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live credentials', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Get live credentials', 'stackless-lite') => 'https://developer.paypal.com/dashboard/applications/live'])
                    . __('Open your app (or create one) and copy its Client ID and Secret. Turn on the Vault feature for subscriptions. The webhook is created for you when you save.', 'stackless-lite'),
            ],
            'client_id' => [
                'title' => __('Client ID', 'stackless-lite'),
                'type' => 'text',
                'default' => '',
            ],
            'client_secret' => [
                'title' => __('Secret', 'stackless-lite'),
                'type' => 'password',
                'default' => '',
            ],
            'webhook_id' => [
                'title' => __('Webhook ID', 'stackless-lite'),
                'type' => 'text',
                'default' => '',
                'description' => __('Filled in automatically when you save the credentials. Otherwise, the ID PayPal shows after you add the webhook below to the live app.', 'stackless-lite'),
            ],
            'sandbox_section' => [
                'title' => __('Sandbox credentials', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Get sandbox credentials', 'stackless-lite') => 'https://developer.paypal.com/dashboard/applications/sandbox'])
                    . __('Same steps in the sandbox app.', 'stackless-lite'),
            ],
            'sandbox_client_id' => [
                'title' => __('Sandbox client ID', 'stackless-lite'),
                'type' => 'text',
                'default' => '',
            ],
            'sandbox_client_secret' => [
                'title' => __('Sandbox secret', 'stackless-lite'),
                'type' => 'password',
                'default' => '',
            ],
            'sandbox_webhook_id' => [
                'title' => __('Sandbox webhook ID', 'stackless-lite'),
                'type' => 'text',
                'default' => '',
            ],
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => self::webhookInstructions(),
            ],
            'payment_section' => [
                'title' => __('Payments', 'stackless-lite'),
                'type' => 'title',
            ],
            'intent' => [
                'title' => __('Payment action', 'stackless-lite'),
                'type' => 'select',
                'default' => 'capture',
                'options' => [
                    'capture' => __('Capture immediately', 'stackless-lite'),
                    'authorize' => __('Authorize only (capture when the order moves to Processing or Completed)', 'stackless-lite'),
                ],
                'description' => __('Subscription orders are always captured immediately.', 'stackless-lite'),
            ],
            'brand_name' => [
                'title' => __('Brand name', 'stackless-lite'),
                'type' => 'text',
                'default' => '',
                'description' => __('Shown on PayPal. Defaults to the site name.', 'stackless-lite'),
            ],
            'invoice_prefix' => [
                'title' => __('Invoice prefix', 'stackless-lite'),
                'type' => 'text',
                'default' => 'WC-',
                'description' => __('PayPal rejects repeated invoice numbers, so use a unique prefix per store on the same PayPal account.', 'stackless-lite'),
            ],
            'buttons_section' => [
                'title' => __('Buttons', 'stackless-lite'),
                'type' => 'title',
            ],
            'button_layout' => [
                'title' => __('Layout', 'stackless-lite'),
                'type' => 'select',
                'default' => 'vertical',
                'options' => ['vertical' => __('Vertical', 'stackless-lite'), 'horizontal' => __('Horizontal', 'stackless-lite')],
            ],
            'button_color' => [
                'title' => __('Color', 'stackless-lite'),
                'type' => 'select',
                'default' => 'gold',
                'options' => [
                    'gold' => __('Gold', 'stackless-lite'),
                    'blue' => __('Blue', 'stackless-lite'),
                    'silver' => __('Silver', 'stackless-lite'),
                    'white' => __('White', 'stackless-lite'),
                    'black' => __('Black', 'stackless-lite'),
                ],
            ],
            'button_shape' => [
                'title' => __('Shape', 'stackless-lite'),
                'type' => 'select',
                'default' => 'rect',
                'options' => ['rect' => __('Rectangle', 'stackless-lite'), 'pill' => __('Pill', 'stackless-lite'), 'sharp' => __('Sharp', 'stackless-lite')],
            ],
            'button_label' => [
                'title' => __('Label', 'stackless-lite'),
                'type' => 'select',
                'default' => 'paypal',
                'options' => [
                    'paypal' => 'PayPal',
                    'checkout' => __('PayPal Checkout', 'stackless-lite'),
                    'buynow' => __('Buy Now', 'stackless-lite'),
                    'pay' => __('Pay with PayPal', 'stackless-lite'),
                ],
            ],
            'paylater_messaging' => [
                'title' => __('Pay Later messaging', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Show Pay Later offers under the buttons where PayPal supports them', 'stackless-lite'),
                'default' => 'yes',
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log API activity to WooCommerce → Status → Logs (stackless-paypal_gateway). Secrets are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
    }

    public static function webhookUrl(): string
    {
        return rest_url('stackless/v1/paypal/webhook');
    }

    private static function webhookInstructions(): string
    {
        $events = '<code>' . implode('</code> <code>', array_map('esc_html', Webhook::EVENTS)) . '</code>';
        return sprintf(
            /* translators: 1: webhook URL, 2: list of event names */
            __('Saving the credentials adds this webhook to the PayPal app for you. On a site PayPal cannot reach yet, or when that fails, add this URL as a webhook in each PayPal app (live and sandbox): %1$s Subscribe to: %2$s Then paste the webhook ID above.', 'stackless-lite'),
            '<br><code>' . esc_html(self::webhookUrl()) . '</code><br>',
            '<br>' . $events . '<br>'
        );
    }

    /** woocommerce_update_options_payment_gateways_{id} callback. */
    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    public function process_admin_options()
    {
        $before = [$this->apiFor('live'), $this->apiFor('sandbox')];
        $saved = parent::process_admin_options();
        $this->init_settings();
        foreach ($before as $api) {
            $api->forgetToken();
        }
        foreach (['live', 'sandbox'] as $environment) {
            $this->ensureWebhook($environment);
        }

        if ($this->get_option('enabled') === 'yes') {
            $environment = $this->environment();
            $api = $this->api();
            if (!$api->hasCredentials()) {
                \WC_Admin_Settings::add_error(__('PayPal is enabled but the client ID or secret for the selected environment is empty.', 'stackless-lite'));
            } else {
                $token = $api->accessToken(true);
                if (is_wp_error($token)) {
                    \WC_Admin_Settings::add_error($token->get_error_message());
                }
            }
            if ($this->webhookId($environment) === '') {
                \WC_Admin_Settings::add_error(__('Add the PayPal webhook ID. Without it, refunds made in PayPal, delayed payments and saved-account changes will not reach WooCommerce.', 'stackless-lite'));
            }
        }
        return $saved;
    }

    /**
     * Find or create this site's webhook in the PayPal app and save its ID, so the merchant only
     * pastes the client ID and secret. PayPal lists webhook IDs, so an existing one is reused.
     */
    private function ensureWebhook(string $environment): void
    {
        $api = $this->apiFor($environment);
        if ($this->webhookId($environment) !== '' || !$api->hasCredentials() || !\Stackless\Core\WebhookSetup::reachable(self::webhookUrl())) {
            return;
        }
        $label = $environment === 'sandbox' ? __('sandbox', 'stackless-lite') : __('live', 'stackless-lite');
        $id = '';
        $list = $api->request('GET', 'v1/notifications/webhooks');
        if (is_array($list)) {
            foreach ((array) ($list['webhooks'] ?? []) as $webhook) {
                if (is_array($webhook) && (string) ($webhook['url'] ?? '') === self::webhookUrl()) {
                    $id = (string) ($webhook['id'] ?? '');
                    break;
                }
            }
        }
        if ($id === '') {
            $created = $api->request('POST', 'v1/notifications/webhooks', [
                'url' => self::webhookUrl(),
                'event_types' => array_map(static function (string $name): array {
                    return ['name' => $name];
                }, Webhook::EVENTS),
            ]);
            if (is_wp_error($created)) {
                /* translators: 1: sandbox or live, 2: PayPal error message */
                \WC_Admin_Settings::add_error(sprintf(__('Creating the %1$s PayPal webhook failed: %2$s', 'stackless-lite'), $label, $created->get_error_message()));
                return;
            }
            $id = (string) ($created['id'] ?? '');
        }
        if ($id === '') {
            return;
        }
        $this->update_option(($environment === 'sandbox' ? 'sandbox_' : '') . 'webhook_id', $id);
        /* translators: %s: sandbox or live */
        \WC_Admin_Settings::add_message(sprintf(__('PayPal %s webhook connected.', 'stackless-lite'), $label));
    }

    /* ---- Configuration ------------------------------------------------- */

    public function isSandbox(): bool
    {
        return $this->get_option('sandbox', 'yes') === 'yes';
    }

    /** sandbox|live */
    public function environment(): string
    {
        return $this->isSandbox() ? 'sandbox' : 'live';
    }

    private function credential(string $environment, string $key): string
    {
        $constant = 'STACKLESS_PAYPAL_' . ($environment === 'sandbox' ? 'SANDBOX_' : '') . strtoupper($key);
        if (defined($constant) && constant($constant)) {
            return trim((string) constant($constant));
        }
        return trim((string) $this->get_option(($environment === 'sandbox' ? 'sandbox_' : '') . $key, ''));
    }

    public function clientId(string $environment = ''): string
    {
        return $this->credential($environment ?: $this->environment(), 'client_id');
    }

    public function webhookId(string $environment = ''): string
    {
        return $this->credential($environment ?: $this->environment(), 'webhook_id');
    }

    public function apiFor(string $environment): Api
    {
        return new Api($environment === 'sandbox', $this->credential($environment, 'client_id'), $this->credential($environment, 'client_secret'));
    }

    public function api(): Api
    {
        return $this->apiFor($this->environment());
    }

    public function payments(): Payments
    {
        if (!$this->payments) {
            $this->payments = new Payments($this);
        }
        return $this->payments;
    }

    /** capture|authorize for orders that don't need a saved account. */
    public function intent(): string
    {
        return $this->get_option('intent', 'capture') === 'authorize' ? 'authorize' : 'capture';
    }

    public function brandName(): string
    {
        $name = trim((string) $this->get_option('brand_name', ''));
        if ($name === '') {
            $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        }
        return Amount::truncate($name, 127);
    }

    public function invoicePrefix(): string
    {
        return (string) $this->get_option('invoice_prefix', 'WC-');
    }

    public function buttonStyle(): array
    {
        $style = [
            'layout' => $this->get_option('button_layout', 'vertical') === 'horizontal' ? 'horizontal' : 'vertical',
            'color' => in_array($this->get_option('button_color'), ['gold', 'blue', 'silver', 'white', 'black'], true) ? $this->get_option('button_color') : 'gold',
            'shape' => in_array($this->get_option('button_shape'), ['rect', 'pill', 'sharp'], true) ? $this->get_option('button_shape') : 'rect',
            'label' => in_array($this->get_option('button_label'), ['paypal', 'checkout', 'buynow', 'pay'], true) ? $this->get_option('button_label') : 'paypal',
        ];
        if ($style['layout'] === 'horizontal') {
            $style['tagline'] = false;
        }
        return (array) apply_filters('stackless_paypal_gateway_button_style', $style);
    }

    public function payLaterMessaging(): bool
    {
        return $this->get_option('paylater_messaging', 'yes') === 'yes';
    }

    /** Debug lines only when the merchant turned logging on; errors always. */
    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if ($level === 'info' || $level === 'debug') {
            if ($this->get_option('debug', 'no') !== 'yes') {
                return;
            }
        }
        Logger::log('paypal_gateway', $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || $this->clientId() === '' || !$this->api()->hasCredentials()) {
            return false;
        }

        $order = $this->orderPayOrder();
        $currency = $order ? $order->get_currency() : stackless_current_currency();
        if (!Amount::supported($currency)) {
            return false;
        }

        return parent::is_available();
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Amount::currencies();
    }

    /** The order being paid on the order-pay page, if this is one. */
    public function orderPayOrder(): ?\WC_Order
    {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return null;
        }
        $orderId = absint(get_query_var('order-pay'));
        $order = $orderId ? wc_get_order($orderId) : false;
        return $order instanceof \WC_Order ? $order : null;
    }

    /* ---- Checkout UI ----------------------------------------------------- */

    public function payment_fields()
    {
        $context = 'checkout';
        $order = $this->orderPayOrder();
        if ($order) {
            $context = 'order-pay';
            $requiresVault = stackless_order_requires_saved_payment_method($order);
            $total = (float) $order->get_total();
            $currency = $order->get_currency();
        } elseif (function_exists('is_add_payment_method_page') && is_add_payment_method_page()) {
            $context = 'add-payment-method';
            $requiresVault = true;
            $total = 0.0;
            $currency = get_woocommerce_currency();
        } else {
            $requiresVault = stackless_cart_requires_saved_payment_method();
            $total = WC()->cart ? (float) WC()->cart->get_total('edit') : 0.0;
            $currency = stackless_current_currency();
        }

        stackless_get_template('paypal_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'context' => $context,
            'mode' => ($requiresVault && $total <= 0) ? 'setup' : 'order',
            'requires_vault' => $requiresVault,
            'intent' => $requiresVault ? 'capture' : $this->intent(),
            'currency' => strtoupper($currency),
            'total' => Amount::format($total, $currency),
            'order_id' => $order ? $order->get_id() : 0,
            'order_key' => $order ? $order->get_order_key() : '',
        ]);
    }

    public function validate_fields()
    {
        return true;
    }

    /* ---- Payments --------------------------------------------------------- */

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return $this->failure(__('Order not found.', 'stackless-lite'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        $paypalOrderId = isset($_POST['stackless_paypal_order_id']) ? sanitize_text_field(wp_unslash($_POST['stackless_paypal_order_id'])) : '';
        $setupToken = isset($_POST['stackless_paypal_setup_token']) ? sanitize_text_field(wp_unslash($_POST['stackless_paypal_setup_token'])) : '';
        // phpcs:enable

        try {
            $result = $this->payments()->process($order, $paypalOrderId, $setupToken);
        } catch (\Throwable $e) {
            $this->log('Checkout failed for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error');
            $result = ['result' => 'failure', 'message' => __('PayPal could not take this payment. Please try again or choose another payment method.', 'stackless-lite')];
        }

        if (($result['result'] ?? '') !== 'success') {
            return $this->failure((string) ($result['message'] ?? __('PayPal could not take this payment.', 'stackless-lite')));
        }
        return $result;
    }

    /** Failure result that shows the message on both checkouts without leaving a stale notice behind on the block checkout. */
    public function failure(string $message): array
    {
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            wc_add_notice($message, 'error');
        }
        return ['result' => 'failure', 'message' => $message];
    }

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && ($order->get_meta(self::META_CAPTURE_ID) !== '' || $order->get_transaction_id() !== '');
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_paypal_refund', __('Order not found.', 'stackless-lite'));
        }
        return $this->payments()->refund($order, $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount, (string) $reason);
    }

    /** My Account → Add payment method: vault a PayPal account without a purchase. */
    public function add_payment_method()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the add-payment-method nonce.
        $setupToken = isset($_POST['stackless_paypal_setup_token']) ? sanitize_text_field(wp_unslash($_POST['stackless_paypal_setup_token'])) : '';
        $result = $this->payments()->addPaymentMethod(get_current_user_id(), $setupToken);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
            return ['result' => 'failure'];
        }
        return ['result' => 'success', 'redirect' => wc_get_endpoint_url('payment-methods')];
    }

    /* ---- Subscriptions contract ------------------------------------------ */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    public function stackless_charge_renewal(\WC_Order $renewal_order, $subscription): array
    {
        try {
            return $this->payments()->chargeRenewal($renewal_order, $subscription);
        } catch (\Throwable $e) {
            $this->log('Renewal ' . $renewal_order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            // The attempt counter is left alone, so a retry reuses the same PayPal-Request-Id
            // and can never charge twice if PayPal did process this one.
            $message = __('PayPal renewal hit an unexpected error before it could be confirmed.', 'stackless-lite');
            if (!$renewal_order->is_paid()) {
                $renewal_order->update_status('failed', $message);
            }
            return ['result' => 'failed', 'message' => $message];
        }
    }

    /** The store runs the schedule, so there is nothing to cancel at PayPal. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }
}
