<?php

namespace Stackless\Modules\DodoGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Dodo Payments (merchant of record) for WooCommerce.
 *
 * Buyers pay on Dodo's hosted checkout; Dodo calculates and remits sales tax,
 * VAT and GST, and bills subscriptions itself. Orders, renewals, refunds and
 * cancellations stay in step through the browser return and signed webhooks.
 *
 * One instance per Dodo business: the original ("stackless_dodo") and each copy
 * ("stackless_dodo_copyN") has its own settings, webhook URL, catalog and orders.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = 'stackless_dodo';

    public const META_SESSION_ID = '_stackless_dodo_session_id';
    public const META_CHECKOUT_URL = '_stackless_dodo_checkout_url';
    public const META_SESSION_AT = '_stackless_dodo_session_at';
    public const META_MODE = '_stackless_dodo_mode';
    public const META_AMOUNT = '_stackless_dodo_amount';
    public const META_PRODUCT_ID = '_stackless_dodo_product_id';
    public const META_TAX_INCLUSIVE = '_stackless_dodo_tax_inclusive';
    public const META_PAYMENT_ID = '_stackless_dodo_payment_id';
    public const META_TAX = '_stackless_dodo_tax_amount';
    public const META_TOTAL = '_stackless_dodo_total_minor';
    public const META_REFUND_IDS = '_stackless_dodo_refund_ids';
    public const META_AWAITING = '_stackless_dodo_awaiting_renewal';
    public const META_NEXT_BILLING = '_stackless_dodo_next_billing_date';
    public const META_CANCEL_AT_END = '_stackless_dodo_cancel_at_next_billing';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_BEHAVIORS = ['exclusive', 'inclusive'];

    /** Tax categories Dodo accepts on a product. */
    public const TAX_CATEGORIES = ['digital_products', 'saas', 'e_book', 'edtech'];

    /** Dodo checkout sessions last 24 hours; an older one is not offered again. */
    public const SESSION_TTL = 82800;

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
        $this->method_title = __('Dodo Payments (Stackless)', 'stackless-lite') . ($badge !== '' ? ' · ' . $badge : '');
        $this->method_description = __('Sell digital products and subscriptions through Dodo Payments, a merchant of record that handles sales tax, VAT and GST for you. Buyers pay by card, UPI, Apple Pay, Google Pay and local methods on Dodo’s secure checkout.', 'stackless-lite');
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
                'label' => __('Accept payments with Dodo Payments', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, UPI, Apple Pay or Google Pay (Dodo Payments)', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Dodo Payments’ secure checkout. Dodo adds any sales tax, VAT or GST for your location before you pay.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use Dodo Payments test mode (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->connectionLine('live') . \Stackless\Core\WebhookSetup::keyButtons([__('Open Dodo Payments', 'stackless-lite') => 'https://app.dodopayments.com/'])
                    . __('In Developer → API Keys, create a key with Write access and paste it below.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if ($mode === 'test') {
                $fields['test_section'] = [
                    'title' => __('Test mode', 'stackless-lite'),
                    'type' => 'title',
                    'description' => $this->connectionLine('test') . __('Test and live keys are separate: switch the Dodo dashboard to Test Mode to create the test key and the test webhook.', 'stackless-lite'),
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
                    'exclusive' => __('Add tax on top of my prices (Dodo default)', 'stackless-lite'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite'),
                ],
                'description' => __('Dodo Payments is the merchant of record and calculates sales tax, VAT and GST itself. Turn off WooCommerce tax for products sold through Dodo so buyers are not taxed twice.', 'stackless-lite'),
            ],
            'tax_category' => [
                'title' => __('Tax category', 'stackless-lite'),
                'type' => 'select',
                'default' => 'digital_products',
                'options' => [
                    'digital_products' => __('Digital products', 'stackless-lite'),
                    'saas' => __('Software as a service', 'stackless-lite'),
                    'e_book' => __('E-books', 'stackless-lite'),
                    'edtech' => __('Education and courses', 'stackless-lite'),
                ],
                'description' => __('Dodo uses this category to pick the tax rate for each buyer’s country.', 'stackless-lite'),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Dodo bills the same amount every cycle, so subscriptions with a signup fee or a different first payment cannot use Dodo. Free trials work. A subscription must be the only item in the cart. One-time carts of any size work and show in Dodo as one store order. Products are created in your Dodo catalog automatically: one per product for one-time sales, one per plan and price for subscriptions. Dodo runs the renewal schedule; renewals it charges become paid WooCommerce renewal orders.', 'stackless-lite'),
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
            'label' => __('Log Dodo Payments activity to WooCommerce → Status → Logs (stackless-dodo_gateway). Keys are never logged.', 'stackless-lite'),
            'default' => 'no',
        ];
        $this->form_fields = $fields;
    }

    private function connectionLine(string $mode): string
    {
        $business = $this->business($mode);
        if (empty($business['name'])) {
            return '';
        }
        /* translators: %s: Dodo Payments business name */
        return sprintf(__('Connected to %s.', 'stackless-lite'), '<strong>' . esc_html((string) $business['name']) . '</strong>') . ' ';
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/dodo/webhook' . ($this->isOriginal() ? '' : '/' . ltrim($this->suffix(), '_')));
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: 1: webhook URL, 2: event names */
            __('In Dodo Payments, open Developer → Webhooks, choose Add endpoint and paste this URL (switch to Test Mode first to add the test endpoint): %1$s Select these events, then copy the signing secret from the endpoint’s Overview tab into the webhook secret field for the same mode: %2$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals, and bring refunds and cancellations made in Dodo into WooCommerce.', 'stackless-lite'),
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
            $business = $this->business($mode);
            if ($key === '') {
                delete_option($this->businessOption($mode));
                continue;
            }
            if ($key !== $previous[$mode] || empty($business['id'])) {
                $remote = $this->fetchBusiness($mode);
                if (is_wp_error($remote)) {
                    \WC_Admin_Settings::add_error($remote->get_error_message());
                    continue;
                }
                // Products created for another business cannot be sold by this one.
                if ((string) ($business['id'] ?? '') !== (string) $remote['id']) {
                    $this->catalog()->forgetMode($mode);
                }
            }
            if ($taxChanged) {
                $this->catalog()->forgetMode($mode);
            }
        }

        if ($this->get_option('enabled') === 'yes') {
            $mode = $this->mode();
            if ($this->apiKey($mode) === '') {
                \WC_Admin_Settings::add_error(__('Dodo Payments is enabled but has no API key for the selected mode.', 'stackless-lite'));
            }
            if ($this->webhookSecret($mode) === '') {
                \WC_Admin_Settings::add_error(__('Add the Dodo Payments webhook secret. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite'));
            }
        }
        return $saved;
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
        $constant = 'STACKLESS_DODO_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
        return $this->isOriginal() && defined($constant) && constant($constant);
    }

    private function secretSetting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_DODO_' . strtoupper($mode) . '_' . strtoupper($field)));
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
        $category = (string) $this->get_option('tax_category', 'digital_products');
        return in_array($category, self::TAX_CATEGORIES, true) ? $category : 'digital_products';
    }

    private function businessOption(string $mode): string
    {
        return 'stackless_dodo_gateway_business_' . ($mode === 'test' ? 'test' : 'live') . $this->suffix();
    }

    /** @return array{id?:string,name?:string} The business (brand) the key belongs to. */
    public function business(string $mode): array
    {
        $business = get_option($this->businessOption($mode), []);
        return is_array($business) ? $business : [];
    }

    /** @return array{id:string,name:string}|\WP_Error */
    public function fetchBusiness(string $mode)
    {
        $response = $this->api($mode)->request('GET', 'brands');
        if (is_wp_error($response)) {
            return $response;
        }
        $remote = (array) ($response['items'][0] ?? ($response[0] ?? []));
        if (empty($remote['business_id'])) {
            return new \WP_Error('stackless_dodo_business', __('Dodo Payments did not return a business for this API key.', 'stackless-lite'));
        }
        $business = [
            'id' => (string) $remote['business_id'],
            'name' => (string) (($remote['name'] ?? '') ?: ($remote['statement_descriptor'] ?? '')),
        ];
        update_option($this->businessOption($mode), $business, false);
        return $business;
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('dodo_gateway', ($this->isOriginal() ? '' : '[' . $this->id . '] ') . $message, $level, $context);
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
        stackless_get_template('dodo_gateway/payment-fields.php', [
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
            $url = new \WP_Error('stackless_dodo_checkout', __('Dodo Payments could not start the checkout. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Dodo Payments checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
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
        return $order instanceof \WC_Order && (string) $order->get_meta(self::META_PAYMENT_ID) !== '';
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_dodo_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_dodo_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $paymentId = (string) $order->get_meta(self::META_PAYMENT_ID);
        if ($paymentId === '') {
            return new \WP_Error('stackless_dodo_refund', __('This order has no Dodo Payments payment to refund.', 'stackless-lite'));
        }

        $currency = $order->get_currency();
        $body = [
            'payment_id' => $paymentId,
            'metadata' => ['wc_order_id' => (string) $order->get_id()],
        ];
        $reason = trim((string) $reason);
        if ($reason !== '') {
            $body['reason'] = function_exists('mb_substr') ? mb_substr($reason, 0, 3000) : substr($reason, 0, 3000);
        }

        // Less than the whole payment: refund part of the single line, in the order's own
        // terms. Dodo adds the matching tax when it was charged on top.
        if (!Money::equals($amount, $order->get_total(), $currency)) {
            $productId = (string) $order->get_meta(self::META_PRODUCT_ID);
            if ($productId === '') {
                return new \WP_Error('stackless_dodo_refund', __('Dodo Payments can only refund this order in full from WooCommerce. Make the partial refund in the Dodo Payments dashboard.', 'stackless-lite'));
            }
            $body['items'] = [[
                'item_id' => $productId,
                'amount' => Money::toMinor($amount, $currency),
                'tax_inclusive' => $order->get_meta(self::META_TAX_INCLUSIVE) === 'yes',
            ]];
        }

        $refund = $this->api($this->orderMode($order))->request('POST', 'refunds', $body);
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        if (($refund['status'] ?? '') === 'failed' || empty($refund['refund_id'])) {
            return new \WP_Error('stackless_dodo_refund', __('Dodo Payments could not process this refund. Check the payment in your Dodo Payments dashboard.', 'stackless-lite'));
        }
        Sync::recordRefundId($order, (string) $refund['refund_id']);
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Dodo refund id, 3: status */
            __('Refunded %1$s through Dodo Payments (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            (string) $refund['refund_id'],
            (string) ($refund['status'] ?? 'pending')
        ));
        return true;
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Dodo charges renewals on its own schedule, so nothing is charged here.
     * The renewal order is parked and completed when Dodo's payment webhook arrives.
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
        $renewal_order->add_order_note(__('Dodo Payments bills this subscription itself. This order is marked paid when Dodo reports the renewal charge.', 'stackless-lite'));
        return ['result' => 'pending', 'message' => __('Waiting for Dodo Payments to charge the renewal.', 'stackless-lite')];
    }

    /** Cancel at Dodo: stop renewing at the next billing date, or cancel now. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        if (!$subscription instanceof \WC_Order) {
            return false;
        }
        $dodoId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($dodoId === '') {
            return true; // Never reached Dodo, nothing to stop there.
        }
        $api = $this->api($this->orderMode($subscription));
        $path = 'subscriptions/' . rawurlencode($dodoId);
        $remote = $api->request('GET', $path);
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $dodoId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        if (in_array($remote['status'] ?? '', ['cancelled', 'expired', 'failed'], true)) {
            return true;
        }
        if ($at_period_end && !empty($remote['cancel_at_next_billing_date'])) {
            return true;
        }
        $result = $api->request('PATCH', $path, $at_period_end ? ['cancel_at_next_billing_date' => true] : ['status' => 'cancelled']);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $dodoId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta_data(self::META_CANCEL_AT_END, $at_period_end ? 'yes' : 'no');
        $subscription->save();
        $subscription->add_order_note($at_period_end
            ? __('Dodo Payments subscription set to stop at the next billing date.', 'stackless-lite')
            : __('Dodo Payments subscription cancelled immediately.', 'stackless-lite'));
        return true;
    }
}
