<?php

namespace Stackless\Modules\CreemGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Creem (merchant of record) for WooCommerce.
 *
 * Buyers pay on Creem's hosted checkout; Creem calculates and remits sales tax and VAT, and
 * bills subscriptions itself. Orders, renewals, refunds and cancellations stay in step
 * through the browser return and signed webhooks.
 *
 * One instance per Creem store: the original ("stackless_creem") and each copy
 * ("stackless_creem_copyN") has its own settings, webhook URL, catalog and orders.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = 'stackless_creem';

    public const META_CHECKOUT_ID = '_stackless_creem_checkout_id';
    public const META_CHECKOUT_URL = '_stackless_creem_checkout_url';
    public const META_SESSION_AT = '_stackless_creem_session_at';
    public const META_MODE = '_stackless_creem_mode';
    public const META_AMOUNT = '_stackless_creem_amount';
    public const META_PRODUCT_ID = '_stackless_creem_product_id';
    public const META_TRANSACTION_ID = '_stackless_creem_transaction_id';
    public const META_ORDER_ID = '_stackless_creem_order_id';
    public const META_TAX = '_stackless_creem_tax_amount';
    public const META_TOTAL = '_stackless_creem_total_minor';
    public const META_REFUND_IDS = '_stackless_creem_refund_ids';
    public const META_AWAITING = '_stackless_creem_awaiting_renewal';
    public const META_NEXT_BILLING = '_stackless_creem_next_billing_date';
    public const META_CANCEL_AT_END = '_stackless_creem_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_BEHAVIORS = ['exclusive', 'inclusive'];

    /** Tax categories Creem accepts on a product. */
    public const TAX_CATEGORIES = ['saas', 'digital-goods-service', 'ebooks'];

    /** An open checkout session is offered again for this long. */
    public const SESSION_TTL = 82800;

    /** @var array<string,Gateway> */
    private static $instances = [];

    /** Whether this save already said the site cannot receive webhooks. */
    private $unreachableNoted = false;

    public static function instance(string $id = self::ID): self
    {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = new self($id);
        }
        return self::$instances[$id];
    }

    /** Forget every instance (staging scripts swap settings). */
    public static function reset(): void
    {
        self::$instances = [];
    }

    /** The original gateway and every copy. @return Gateway[] */
    public static function all(): array
    {
        return array_map([self::class, 'instance'], Copies::ids());
    }

    /** The gateway (original or copy) an order was placed with. */
    public static function forOrder(\WC_Order $order): ?self
    {
        $id = (string) $order->get_payment_method();
        return Copies::belongs($id) ? self::instance($id) : null;
    }

    public function __construct(string $id = self::ID)
    {
        $this->id = $id;
        $this->has_fields = true;
        $badge = Copies::badge($id);
        $this->method_title = __('Creem (Stackless)', 'stackless-lite') . ($badge !== '' ? ' · ' . $badge : '');
        $this->method_description = __('Sell software, digital products and subscriptions through Creem, a merchant of record that handles sales tax and VAT for you. Buyers pay by card, Apple Pay or Google Pay on Creem’s secure checkout.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'stackless_subscriptions'];

        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'saveSettings']);
    }

    public function isOriginal(): bool
    {
        return $this->id === self::ID;
    }

    /** '' for the original, '_copy2' for copy 2: suffix of per-copy options. */
    public function suffix(): string
    {
        return Copies::suffix($this->id);
    }

    /* ---- Settings -------------------------------------------------------------- */

    public function init_form_fields()
    {
        $fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Creem', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, Apple Pay or Google Pay (Creem)', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Creem’s secure checkout. Creem adds any sales tax or VAT for your location before you pay.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use Creem test mode (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([__('Get API keys', 'stackless-lite') => 'https://www.creem.io/dashboard/developers'])
                    . __('In Developers → API keys, copy the live key and paste it below. On a public HTTPS site the webhook and its secret are set up for you when you save.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if ($mode === 'test') {
                $fields['test_section'] = [
                    'title' => __('Test mode', 'stackless-lite'),
                    'type' => 'title',
                    'description' => __('Test and live keys are separate: switch the Creem dashboard to test mode (bottom of the sidebar) to copy the key that starts with creem_test.', 'stackless-lite'),
                ];
            }
            if (!$this->isDefined($mode, 'api_key')) {
                $fields[$mode . '_api_key'] = [
                    'title' => $mode === 'test' ? __('Test API key', 'stackless-lite') : __('Live API key', 'stackless-lite'),
                    'type' => 'password',
                    'default' => '',
                ];
            }
            if (!$this->isDefined($mode, 'webhook_secret')) {
                $fields[$mode . '_webhook_secret'] = [
                    'title' => $mode === 'test' ? __('Test webhook secret', 'stackless-lite') : __('Live webhook secret', 'stackless-lite'),
                    'type' => 'password',
                    'placeholder' => 'whsec_…',
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
            'tax_behavior' => [
                'title' => __('Prices and tax', 'stackless-lite'),
                'type' => 'select',
                'default' => 'exclusive',
                'options' => [
                    'exclusive' => __('Add tax on top of my prices', 'stackless-lite'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite'),
                ],
                'description' => __('Creem is the merchant of record and calculates sales tax and VAT itself. Turn off WooCommerce tax for products sold through Creem so buyers are not taxed twice.', 'stackless-lite'),
            ],
            'tax_category' => [
                'title' => __('Tax category', 'stackless-lite'),
                'type' => 'select',
                'default' => 'saas',
                'options' => [
                    'saas' => __('Software as a service', 'stackless-lite'),
                    'digital-goods-service' => __('Digital goods or services', 'stackless-lite'),
                    'ebooks' => __('E-books', 'stackless-lite'),
                ],
                'description' => __('Creem uses this category to pick the tax rate for each buyer’s country.', 'stackless-lite'),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Creem charges in US dollars and euros, from 1.00. It bills the same amount every cycle, so subscriptions with a signup fee or a different first payment cannot use Creem; free trials work. A subscription must be the only item in the cart. One-time carts of any size work and show in Creem as one store order. Products are created in your Creem catalog automatically. Creem runs the renewal schedule; renewals it charges become paid WooCommerce renewal orders. Refunds from WooCommerce refund the whole payment; make partial refunds in the Creem dashboard and they appear here.', 'stackless-lite'),
            ],
        ];
        $copies = (string) apply_filters('stackless_gateway_copies_panel', '', $this->id);
        if ($copies !== '') {
            $fields['copies_section'] = [
                'title' => __('Copies', 'stackless-lite'),
                'type' => 'title',
                'description' => $copies,
            ];
        }
        $fields['debug'] = [
            'title' => __('Debug log', 'stackless-lite'),
            'type' => 'checkbox',
            'label' => __('Log Creem activity to WooCommerce → Status → Logs (stackless-creem_gateway). Keys are never logged.', 'stackless-lite'),
            'default' => 'no',
        ];
        $this->form_fields = $fields;
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/creem/webhook' . ($this->isOriginal() ? '' : '/' . ltrim($this->suffix(), '_')));
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: 1: webhook URL, 2: event names */
            __('On a public HTTPS site, saving a key creates this webhook in Creem and stores its secret. To add it by hand, open Developers → Webhooks in Creem (switch to test mode first for the test endpoint), add this URL: %1$s Select these events, then paste the webhook secret into the field for the same mode: %2$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals and failed renewals, and bring refunds and cancellations made in Creem into WooCommerce.', 'stackless-lite'),
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
        $previous = [
            'live' => $this->apiKey('live'),
            'test' => $this->apiKey('test'),
            'tax' => $this->taxBehavior() . '|' . $this->taxCategory(),
        ];
        $saved = parent::process_admin_options();
        $this->init_settings();

        // Products carry the tax settings, so changing them starts fresh products.
        $taxChanged = $previous['tax'] !== $this->taxBehavior() . '|' . $this->taxCategory();
        foreach (['live', 'test'] as $mode) {
            $key = $this->apiKey($mode);
            if ($key === '') {
                continue;
            }
            $keyMode = EventMap::keyMode($key);
            if ($keyMode !== '' && $keyMode !== $mode) {
                \WC_Admin_Settings::add_error($mode === 'test'
                    ? __('The Creem test API key field holds a live key. Paste the key that starts with creem_test.', 'stackless-lite')
                    : __('The Creem live API key field holds a test key. Paste a live key from the dashboard with test mode off.', 'stackless-lite'));
                continue;
            }
            if ($key !== $previous[$mode]) {
                $check = $this->api($mode)->request('GET', 'v1/products/search', ['page_number' => 1, 'page_size' => 1]);
                if (is_wp_error($check)) {
                    \WC_Admin_Settings::add_error($check->get_error_message());
                    continue;
                }
                // Products created with another store's key cannot be sold by this one.
                if ($previous[$mode] !== '') {
                    $this->catalog()->forgetMode($mode);
                }
            }
            if ($taxChanged) {
                $this->catalog()->forgetMode($mode);
            }
            $this->ensureWebhook($mode);
        }

        if ($this->get_option('enabled') === 'yes') {
            $mode = $this->mode();
            if ($this->apiKey($mode) === '') {
                \WC_Admin_Settings::add_error(__('Creem is enabled but has no API key for the selected mode.', 'stackless-lite'));
            }
            if ($this->webhookSecret($mode) === '') {
                \WC_Admin_Settings::add_error(__('Add the Creem webhook secret. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite'));
            }
        }
        return $saved;
    }

    private function webhookOption(string $mode): string
    {
        return 'stackless_creem_gateway_webhook_' . ($mode === 'test' ? 'test' : 'live') . $this->suffix();
    }

    /**
     * Find or create this site's webhook endpoint in Creem and save its secret. An endpoint
     * already pointing here is reused; Creem hands out its secret on request.
     */
    private function ensureWebhook(string $mode): void
    {
        $url = $this->webhookUrl();
        if ($this->webhookSecret($mode) !== '' || $this->isDefined($mode, 'webhook_secret')) {
            return;
        }
        if (!\Stackless\Core\WebhookSetup::reachable($url)) {
            if (!$this->unreachableNoted) {
                $this->unreachableNoted = true;
                \WC_Admin_Settings::add_message(\Stackless\Core\WebhookSetup::unreachableNote());
            }
            return;
        }
        $label = $mode === 'test' ? __('test', 'stackless-lite') : __('live', 'stackless-lite');
        $api = $this->api($mode);
        $webhookId = '';
        $secret = '';
        $list = $api->request('GET', 'v1/webhooks', ['page_number' => 1, 'page_size' => 100]);
        foreach (is_array($list) ? (array) ($list['items'] ?? []) : [] as $webhook) {
            if (is_array($webhook) && (string) ($webhook['url'] ?? '') === $url) {
                $webhookId = (string) ($webhook['id'] ?? '');
                $secret = (string) ($webhook['secret'] ?? '');
                break;
            }
        }
        if ($webhookId === '') {
            $created = $api->request('POST', 'v1/webhooks', [
                'url' => $url,
                'name' => 'Stackless for WooCommerce',
                'events' => EventMap::EVENTS,
                'delivery_mode' => 'http',
            ]);
            if (is_wp_error($created)) {
                /* translators: 1: test or live, 2: Creem error message */
                \WC_Admin_Settings::add_error(sprintf(__('Creating the %1$s Creem webhook failed: %2$s Add it by hand (see Webhook below), or give the API key the webhook permissions.', 'stackless-lite'), $label, $created->get_error_message()));
                return;
            }
            $webhookId = (string) ($created['id'] ?? '');
            $secret = (string) ($created['secret'] ?? '');
        }
        if ($secret === '' && $webhookId !== '') {
            $fetched = $api->request('GET', 'v1/webhooks/' . rawurlencode($webhookId) . '/secret');
            $secret = is_wp_error($fetched) ? '' : (string) ($fetched['secret'] ?? '');
        }
        if ($secret === '') {
            return;
        }
        $this->update_option($mode . '_webhook_secret', $secret);
        update_option($this->webhookOption($mode), $webhookId, false);
        /* translators: %s: test or live */
        \WC_Admin_Settings::add_message(sprintf(__('Creem %s webhook connected and its secret saved.', 'stackless-lite'), $label));
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** test|live */
    public function mode(): string
    {
        return $this->get_option('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether a key or secret is pinned in wp-config.php (original gateway only). */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = 'STACKLESS_CREEM_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
        return $this->isOriginal() && defined($constant) && constant($constant);
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_CREEM_' . strtoupper($mode) . '_' . strtoupper($field)));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function apiKey(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'api_key');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->apiKey($mode));
    }

    public function catalog(): Catalog
    {
        return new Catalog($this);
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    public function taxBehavior(): string
    {
        $behavior = (string) $this->get_option('tax_behavior', 'exclusive');
        return in_array($behavior, self::TAX_BEHAVIORS, true) ? $behavior : 'exclusive';
    }

    public function taxInclusive(): bool
    {
        return $this->taxBehavior() === 'inclusive';
    }

    public function taxCategory(): string
    {
        $category = (string) $this->get_option('tax_category', 'saas');
        return in_array($category, self::TAX_CATEGORIES, true) ? $category : 'saas';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('creem_gateway', ($this->isOriginal() ? '' : '[' . $this->id . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || $this->apiKey() === '') {
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

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies();
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
        stackless_get_template('creem_gateway/payment-fields.php', [
            'gateway' => $this,
            'description' => $this->get_description(),
            'testmode' => $this->mode() === 'test',
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
            $url = new \WP_Error('stackless_creem_checkout', __('Creem could not start the checkout. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Creem checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
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
        return $order instanceof \WC_Order && (string) $order->get_meta(self::META_TRANSACTION_ID) !== '';
    }

    /** Creem's API refunds a whole payment; partial refunds are made in the Creem dashboard. */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_creem_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        $transactionId = (string) $order->get_meta(self::META_TRANSACTION_ID);
        if ($transactionId === '') {
            return new \WP_Error('stackless_creem_refund', __('This order has no Creem payment to refund.', 'stackless-lite'));
        }
        if (!Money::equals($amount, $order->get_total())) {
            return new \WP_Error('stackless_creem_refund', __('Creem refunds the whole payment from WooCommerce. Refund the full order total here, or make a partial refund in the Creem dashboard; it appears on this order automatically.', 'stackless-lite'));
        }

        $refund = $this->api($this->orderMode($order))->request('POST', 'v1/refunds', ['transaction_id' => $transactionId]);
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $status = (string) ($refund['status'] ?? '');
        if (in_array($status, ['failed', 'canceled'], true)) {
            return new \WP_Error('stackless_creem_refund', __('Creem could not process this refund. Check the payment in your Creem dashboard.', 'stackless-lite'));
        }
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Creem transaction id, 3: status */
            __('Refunded %1$s through Creem (transaction %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
            $transactionId,
            $status !== '' ? $status : 'pending'
        ));
        return true;
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Creem charges renewals on its own schedule, so nothing is charged here. The renewal
     * order is parked and completed when Creem's subscription.paid webhook arrives.
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
        $renewal_order->add_order_note(__('Creem bills this subscription itself. This order is marked paid when Creem reports the renewal charge.', 'stackless-lite'));
        return ['result' => 'pending', 'message' => __('Waiting for Creem to charge the renewal.', 'stackless-lite')];
    }

    /** Cancel at Creem: at the end of the paid period, or now. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        if (!$subscription instanceof \WC_Order) {
            return false;
        }
        $creemId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($creemId === '') {
            return true; // Never reached Creem, nothing to stop there.
        }
        $api = $this->api($this->orderMode($subscription));
        $remote = $api->request('GET', 'v1/subscriptions', ['subscription_id' => $creemId]);
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $creemId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        $status = (string) ($remote['status'] ?? '');
        if ($status === 'canceled' || ($at_period_end && $status === 'scheduled_cancel')) {
            return true;
        }
        $result = $api->request('POST', 'v1/subscriptions/' . rawurlencode($creemId) . '/cancel', $at_period_end
            ? ['mode' => 'scheduled', 'onExecute' => 'cancel']
            : ['mode' => 'immediate']);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $creemId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta_data(self::META_CANCEL_AT_END, $at_period_end ? 'yes' : 'no');
        $subscription->save();
        $subscription->add_order_note($at_period_end
            ? __('Creem subscription set to stop at the end of the paid period.', 'stackless-lite')
            : __('Creem subscription cancelled immediately.', 'stackless-lite'));
        return true;
    }
}
