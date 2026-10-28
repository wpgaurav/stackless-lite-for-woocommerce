<?php

namespace Stackless\Modules\PaddleGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Paddle Billing (merchant of record) for WooCommerce.
 *
 * Buyers pay in Paddle's overlay checkout, opened from a page on this store;
 * Paddle calculates and remits sales tax and VAT, and runs subscription billing
 * itself. Orders, renewals, refunds and cancellations stay in step through the
 * browser return and signed webhooks.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = 'stackless_paddle';

    public const META_TRANSACTION_ID = '_stackless_paddle_transaction_id';
    public const META_MODE = '_stackless_paddle_mode';
    public const META_FINGERPRINT = '_stackless_paddle_fingerprint';
    public const META_ADJUSTMENT_IDS = '_stackless_paddle_adjustment_ids';
    public const META_TAX = '_stackless_paddle_tax_amount';
    public const META_TOTAL = '_stackless_paddle_total_amount';
    public const META_AWAITING = '_stackless_paddle_awaiting_renewal';
    public const META_PERIOD_END = '_stackless_paddle_current_period_end';
    public const META_CANCEL_AT_END = '_stackless_paddle_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_MODES = ['account_setting', 'external', 'internal'];

    /** Paddle tax categories. Everything but "standard" needs approval in the Paddle account first. */
    public const TAX_CATEGORIES = ['standard', 'digital-goods', 'ebooks', 'implementation-services', 'professional-services', 'saas', 'software-programming-services', 'training-services', 'website-hosting'];

    /** @var array<string,Gateway> */
    private static $instances = [];

    public static function instance(string $id = self::ID): self
    {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = new self($id);
        }
        return self::$instances[$id];
    }

    /** The original gateway and every copy. @return Gateway[] */
    public static function all(): array
    {
        return array_map([self::class, 'instance'], Copies::ids());
    }

    /** The gateway (original or copy) an order or subscription was placed with. */
    public static function forOrder(\WC_Order $order): ?self
    {
        $id = (string) $order->get_payment_method();
        return Copies::belongs($id) ? self::instance($id) : null;
    }

    public function isOriginal(): bool
    {
        return $this->id === self::ID;
    }

    public function __construct(string $id = self::ID)
    {
        $this->id = $id;
        $this->has_fields = true;
        $badge = Copies::badge($id);
        $this->method_title = __('Paddle (Stackless)', 'stackless-lite') . ($badge !== '' ? ' · ' . $badge : '');
        $this->method_description = __('Sell digital products and subscriptions through Paddle Billing, a merchant of record that handles sales tax and VAT for you. Buyers pay by card, PayPal, Apple Pay or Google Pay in Paddle’s secure checkout.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'stackless_subscriptions'];

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
        $permissions = 'transaction.read, transaction.write, subscription.read, subscription.write, adjustment.read, adjustment.write, notification_setting.read, notification_setting.write';
        // Payment Gateway Tools fills this in on the settings screen: duplicate, rename, remove.
        $copies = is_admin() ? (string) apply_filters('stackless_gateway_copies_panel', '', $this->id) : '';
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Paddle', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, PayPal, Apple Pay or Google Pay (Paddle)', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying in Paddle’s secure checkout. Paddle adds any sales tax or VAT for your location before you pay.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Paddle sandbox (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live', 'stackless-lite'),
                'type' => 'title',
                /* translators: %s: list of API key permissions */
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Get live keys', 'stackless-lite') => $this->dashboardUrl('live', 'authentication-v2')])
                    . sprintf(__('Create an API key with these permissions: %s. Then create a client-side token on the same screen. With the notification permissions, the webhook and its secret key are set up for you when you save.', 'stackless-lite'), '<code>' . esc_html($permissions) . '</code>'),
            ],
            'live_api_key' => [
                'title' => __('Live API key', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'pdl_live_apikey_…',
                'default' => '',
            ],
            'live_client_token' => [
                'title' => __('Live client-side token', 'stackless-lite'),
                'type' => 'text',
                'placeholder' => 'live_…',
                'default' => '',
            ],
            'live_webhook_secret' => [
                'title' => __('Live webhook secret key', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'pdl_ntfset_…',
                'default' => '',
            ],
            'sandbox_section' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Get sandbox keys', 'stackless-lite') => $this->dashboardUrl('sandbox', 'authentication-v2')])
                    . __('Same permissions, created in the Paddle sandbox.', 'stackless-lite'),
            ],
            'sandbox_api_key' => [
                'title' => __('Sandbox API key', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'pdl_sdbx_apikey_…',
                'default' => '',
            ],
            'sandbox_client_token' => [
                'title' => __('Sandbox client-side token', 'stackless-lite'),
                'type' => 'text',
                'placeholder' => 'test_…',
                'default' => '',
            ],
            'sandbox_webhook_secret' => [
                'title' => __('Sandbox webhook secret key', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'pdl_ntfset_…',
                'default' => '',
            ],
            'checkout_section' => [
                'title' => __('Checkout page and webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->setupInstructions(),
            ],
            'tax_mode' => [
                'title' => __('Prices and tax', 'stackless-lite'),
                'type' => 'select',
                'default' => 'account_setting',
                'options' => [
                    'account_setting' => __('Use my Paddle account setting', 'stackless-lite'),
                    'external' => __('Add tax on top of my prices', 'stackless-lite'),
                    'internal' => __('My prices already include tax', 'stackless-lite'),
                ],
                'description' => __('Paddle is the merchant of record and calculates sales tax and VAT itself. Turn off WooCommerce tax for products sold through Paddle so buyers are not taxed twice.', 'stackless-lite'),
            ],
            'tax_category' => [
                'title' => __('Tax category', 'stackless-lite'),
                'type' => 'select',
                'default' => 'standard',
                'options' => array_combine(self::TAX_CATEGORIES, [
                    __('Standard digital goods (approved for every account)', 'stackless-lite'),
                    __('Digital goods', 'stackless-lite'),
                    __('Ebooks', 'stackless-lite'),
                    __('Implementation services', 'stackless-lite'),
                    __('Professional services', 'stackless-lite'),
                    __('Software as a service', 'stackless-lite'),
                    __('Software programming services', 'stackless-lite'),
                    __('Training services', 'stackless-lite'),
                    __('Website hosting', 'stackless-lite'),
                ]),
                'description' => __('Paddle uses the tax category to pick tax rates. Any category other than Standard must be approved for your Paddle account first, or checkouts fail.', 'stackless-lite'),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Paddle bills one subscription schedule per checkout. One-time items, a signup fee or first shipping can share the checkout with it; Paddle charges them once. Free trials work when the first payment is zero. A first payment smaller than a full cycle (a first-payment discount) cannot use Paddle. Paddle runs the renewal schedule; renewals it charges become paid WooCommerce renewal orders.', 'stackless-lite'),
            ],
            'copies_section' => [
                'title' => __('Copies', 'stackless-lite'),
                'type' => 'title',
                'description' => $copies,
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Paddle activity to WooCommerce → Status → Logs (stackless-paddle_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
        if ($copies === '') {
            unset($this->form_fields['copies_section']);
        }
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/paddle/webhook' . ($this->isOriginal() ? '' : '/' . substr($this->id, strlen(self::ID) + 1)));
    }

    public static function payPageBase(): string
    {
        return (string) WC()->api_request_url('stackless_paddle_pay');
    }

    private function setupInstructions(): string
    {
        return sprintf(
            /* translators: 1: store domain, 2: store URL, 3: webhook URL, 4: event names */
            __('1. In Paddle → Settings → Website approval, add %1$s and wait for approval. 2. In Paddle → Checkout → Checkout configuration, set the default payment link to %2$s (Stackless sends its own checkout page with every payment; Paddle only needs an approved default). 3. Saving an API key with the notification permissions adds the webhook for you. Otherwise, in Paddle → Developer tools → Notifications, add a destination with this URL: %3$s Select these events, then copy the secret key into the matching webhook secret field above: %4$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals, and bring refunds and cancellations made in Paddle into WooCommerce.', 'stackless-lite'),
            '<code>' . esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '</code>',
            '<code>' . esc_html(home_url('/')) . '</code>',
            '<br><code>' . esc_html($this->webhookUrl()) . '</code><br>',
            '<br><code>' . implode('</code> <code>', array_map('esc_html', EventMap::EVENTS)) . '</code><br>'
        );
    }

    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    public function process_admin_options()
    {
        $previous = ['live' => $this->apiKey('live'), 'sandbox' => $this->apiKey('sandbox')];
        $saved = parent::process_admin_options();
        $this->init_settings();

        foreach (['live', 'sandbox'] as $mode) {
            $key = $this->apiKey($mode);
            if ($key === '') {
                continue;
            }
            $problem = Api::keyProblem($mode, $key, $this->clientToken($mode));
            if ($problem !== '') {
                \WC_Admin_Settings::add_error($problem);
                continue;
            }
            if ($key !== $previous[$mode]) {
                $check = $this->api($mode)->request('GET', 'transactions', ['per_page' => 1]);
                if (is_wp_error($check)) {
                    \WC_Admin_Settings::add_error($check->get_error_message());
                    continue;
                }
            }
            $this->ensureWebhook($mode);
        }

        if ($this->get_option('enabled') === 'yes') {
            $mode = $this->mode();
            if ($this->apiKey($mode) === '' || $this->clientToken($mode) === '') {
                \WC_Admin_Settings::add_error(__('Paddle is enabled but the selected environment needs both an API key and a client-side token.', 'stackless-lite'));
            }
            if ($this->webhookSecret($mode) === '') {
                \WC_Admin_Settings::add_error(__('Add the Paddle webhook secret key. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite'));
            }
        }
        return $saved;
    }

    /**
     * Find or create this site's notification destination in Paddle and save its secret key.
     * Paddle returns the secret with every destination, so an existing one is reused as is.
     */
    private function ensureWebhook(string $mode): void
    {
        $url = $this->webhookUrl();
        if ($this->webhookSecret($mode) !== '' || !\Stackless\Core\WebhookSetup::reachable($url)) {
            return;
        }
        $label = $mode === 'sandbox' ? __('sandbox', 'stackless-lite') : __('live', 'stackless-lite');
        $api = $this->api($mode);
        $secret = '';
        $list = $api->request('GET', 'notification-settings', ['per_page' => 200]);
        if (is_array($list)) {
            foreach ($list as $index => $setting) {
                if (is_int($index) && is_array($setting) && (string) ($setting['destination'] ?? '') === $url && !empty($setting['active'])) {
                    $secret = (string) ($setting['endpoint_secret_key'] ?? '');
                    break;
                }
            }
        }
        if ($secret === '') {
            $created = $api->request('POST', 'notification-settings', [
                'description' => 'Stackless for WooCommerce',
                'type' => 'url',
                'destination' => $url,
                'subscribed_events' => EventMap::EVENTS,
                'traffic_source' => 'platform',
            ]);
            if (is_wp_error($created)) {
                /* translators: 1: sandbox or live, 2: Paddle error message */
                \WC_Admin_Settings::add_error(sprintf(__('Creating the %1$s Paddle webhook failed: %2$s Add it by hand (see Checkout page and webhook below), or give the API key the notification permissions.', 'stackless-lite'), $label, $created->get_error_message()));
                return;
            }
            $secret = (string) ($created['endpoint_secret_key'] ?? '');
        }
        if ($secret === '') {
            return;
        }
        $this->update_option($mode . '_webhook_secret', $secret);
        /* translators: %s: sandbox or live */
        \WC_Admin_Settings::add_message(sprintf(__('Paddle %s webhook connected and its secret key saved.', 'stackless-lite'), $label));
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** sandbox|live */
    public function mode(): string
    {
        return $this->get_option('testmode', 'yes') === 'yes' ? 'sandbox' : 'live';
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'sandbox' ? 'sandbox' : 'live';
        $constant = 'STACKLESS_PADDLE_' . strtoupper($mode) . '_' . strtoupper($field);
        // Constants in wp-config.php belong to the original gateway; copies keep their own keys.
        if ($this->isOriginal() && defined($constant) && constant($constant)) {
            return trim((string) constant($constant));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function apiKey(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'api_key');
    }

    public function clientToken(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'client_token');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'sandbox' ? $mode : $this->mode();
        return new Api($mode, $this->apiKey($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'sandbox'], true) ? $mode : $this->mode();
    }

    public function taxMode(): string
    {
        $mode = (string) $this->get_option('tax_mode', 'account_setting');
        return in_array($mode, self::TAX_MODES, true) ? $mode : 'account_setting';
    }

    public function taxCategory(): string
    {
        $category = (string) $this->get_option('tax_category', 'standard');
        return in_array($category, self::TAX_CATEGORIES, true) ? $category : 'standard';
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
    }

    public function dashboardUrl(string $mode, string $path): string
    {
        return ($mode === 'sandbox' ? 'https://sandbox-vendors.paddle.com/' : 'https://vendors.paddle.com/') . ltrim($path, '/');
    }

    public function get_transaction_url($order)
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }
        $transaction = (string) $order->get_meta(self::META_TRANSACTION_ID);
        return $transaction !== '' ? $this->dashboardUrl($this->orderMode($order), 'transactions-v2/' . rawurlencode($transaction)) : '';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('paddle_gateway', ($this->isOriginal() ? '' : '[' . $this->id . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || $this->apiKey() === '' || $this->clientToken() === '') {
            return false;
        }

        $order = $this->orderPayOrder();
        if ($order) {
            if (!Money::supported($order->get_currency()) || Eligibility::orderReason($order) !== Eligibility::OK) {
                return false;
            }
        } else {
            if (!Money::supported(stackless_current_currency())) {
                return false;
            }
            if (function_exists('WC') && WC()->cart && Eligibility::cartReason() !== Eligibility::OK) {
                return false;
            }
        }
        return parent::is_available();
    }

    public function orderPayOrder(): ?\WC_Order
    {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return null;
        }
        $orderId = absint(get_query_var('order-pay'));
        $order = $orderId ? wc_get_order($orderId) : false;
        return $order instanceof \WC_Order ? $order : null;
    }

    public function payment_fields()
    {
        stackless_get_template('paddle_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'sandbox' => $this->mode() === 'sandbox',
        ]);
    }

    /* ---- Payments --------------------------------------------------------------- */

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return $this->failure(__('Order not found.', 'stackless-lite'));
        }

        $reason = Eligibility::orderReason($order);
        if ($reason !== Eligibility::OK) {
            return $this->failure(Eligibility::message($reason));
        }

        try {
            $url = (new Checkout($this))->start($order);
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_paddle_checkout', __('Paddle could not start the checkout. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Paddle checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
            return $this->failure($url->get_error_message());
        }
        return ['result' => 'success', 'redirect' => $url];
    }

    public function failure(string $message): array
    {
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            wc_add_notice($message, 'error');
        }
        return ['result' => 'failure', 'message' => $message];
    }

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && (string) $order->get_meta(self::META_TRANSACTION_ID) !== '' && $order->is_paid();
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_paddle_refund', __('Order not found.', 'stackless-lite'));
        }
        return (new Refunds($this))->refund($order, $amount === null ? null : (float) $amount, (string) $reason);
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Paddle charges renewals on its own schedule, so nothing is charged here.
     * The renewal order is parked and completed when Paddle's transaction webhook arrives.
     */
    public function stackless_charge_renewal(\WC_Order $renewal_order, $subscription): array
    {
        if ($renewal_order->is_paid()) {
            return ['result' => 'success'];
        }
        $renewal_order->set_payment_method($this->id);
        $renewal_order->set_payment_method_title($this->get_title());
        $renewal_order->update_meta_data(self::META_AWAITING, 'yes');
        $renewal_order->save();
        if ($subscription instanceof \WC_Order) {
            $subscription->update_meta_data(self::META_AWAITING, (string) $renewal_order->get_id());
            $subscription->save();
        }
        $renewal_order->add_order_note(__('Paddle bills this subscription itself. This order is marked paid when Paddle reports the renewal charge.', 'stackless-lite'));
        return ['result' => 'pending', 'message' => __('Waiting for Paddle to charge the renewal.', 'stackless-lite')];
    }

    /** Cancel at Paddle: at the end of the billing period, or immediately. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        if (!$subscription instanceof \WC_Order) {
            return false;
        }
        $paddleId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($paddleId === '') {
            return true; // Never reached Paddle, nothing to stop there.
        }
        $api = $this->api($this->orderMode($subscription));
        $remote = $api->request('GET', 'subscriptions/' . rawurlencode($paddleId));
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $paddleId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        if (($remote['status'] ?? '') === 'canceled') {
            return true;
        }
        if ($at_period_end && EventMap::cancelScheduled($remote)) {
            return true;
        }
        // Paddle cannot schedule a cancellation for a paused or past-due subscription, only end it now.
        $deferred = $at_period_end && in_array($remote['status'] ?? '', ['active', 'trialing'], true);
        $result = $api->request('POST', 'subscriptions/' . rawurlencode($paddleId) . '/cancel', [
            'effective_from' => $deferred ? 'next_billing_period' : 'immediately',
        ]);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $paddleId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta_data(self::META_CANCEL_AT_END, $deferred ? 'yes' : 'no');
        $subscription->save();
        $subscription->add_order_note($deferred
            ? __('Paddle subscription set to end at the close of the paid period.', 'stackless-lite')
            : __('Paddle subscription canceled immediately.', 'stackless-lite'));
        return true;
    }
}
