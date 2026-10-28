<?php

namespace Stackless\Modules\PolarGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Polar (merchant of record) for WooCommerce.
 *
 * Buyers pay on Polar's hosted checkout; Polar calculates and remits sales tax
 * and VAT, and runs subscription billing itself. Orders, renewals, refunds and
 * cancellations stay in step through the browser return and signed webhooks.
 *
 * One instance per Polar organization: the original ("stackless_polar") and each
 * copy ("stackless_polar_copyN") has its own settings, webhook URL, catalog and orders.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = 'stackless_polar';

    public const META_CHECKOUT_ID = '_stackless_polar_checkout_id';
    public const META_ORDER_ID = '_stackless_polar_order_id';
    public const META_MODE = '_stackless_polar_mode';
    public const META_AMOUNT = '_stackless_polar_amount';
    public const META_REFUND_IDS = '_stackless_polar_refund_ids';
    public const META_TAX = '_stackless_polar_tax_amount';
    public const META_TOTAL = '_stackless_polar_total_amount';
    public const META_AWAITING = '_stackless_polar_awaiting_renewal';
    public const META_PERIOD_END = '_stackless_polar_current_period_end';
    public const META_CANCEL_AT_END = '_stackless_polar_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    public const TAX_BEHAVIORS = ['location', 'exclusive', 'inclusive'];

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
        $this->method_title = __('Polar (Stackless)', 'stackless-lite') . ($badge !== '' ? ' · ' . $badge : '');
        $this->method_description = __('Sell digital products and subscriptions through Polar, a merchant of record that handles sales tax and VAT for you. Buyers pay by card, Apple Pay or Google Pay on Polar’s secure checkout.', 'stackless-lite');
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
        $scopes = 'products:read, products:write, checkouts:read, checkouts:write, orders:read, refunds:read, refunds:write, subscriptions:read, subscriptions:write, organizations:read';
        // Payment Gateway Tools fills this in on the settings screen: duplicate, rename, remove.
        $copies = is_admin() ? (string) apply_filters('stackless_gateway_copies_panel', '', $this->id) : '';
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Polar', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, Apple Pay or Google Pay (Polar)', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Polar’s secure checkout. Polar adds any sales tax or VAT for your location before you pay.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Polar sandbox (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live', 'stackless-lite'),
                'type' => 'title',
                /* translators: %s: list of token scopes */
                'description' => $this->connectionLine('live') . \Stackless\Core\WebhookSetup::keyButtons([__('Get live token', 'stackless-lite') => $this->dashboardUrl('live', 'settings')])
                    . sprintf(__('In Settings → General → Developers, create an organization access token with these scopes: %s', 'stackless-lite'), '<code>' . esc_html($scopes) . '</code>'),
            ],
            'live_access_token' => [
                'title' => __('Live access token', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'polar_oat_…',
                'default' => '',
            ],
            'live_webhook_secret' => [
                'title' => __('Live webhook secret', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'whsec_…',
                'default' => '',
            ],
            'sandbox_section' => [
                'title' => __('Sandbox', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->connectionLine('sandbox') . \Stackless\Core\WebhookSetup::keyButtons([__('Get sandbox token', 'stackless-lite') => $this->dashboardUrl('sandbox', 'settings')])
                    . __('Same scopes, created in the Polar sandbox.', 'stackless-lite'),
            ],
            'sandbox_access_token' => [
                'title' => __('Sandbox access token', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'polar_oat_…',
                'default' => '',
            ],
            'sandbox_webhook_secret' => [
                'title' => __('Sandbox webhook secret', 'stackless-lite'),
                'type' => 'password',
                'placeholder' => 'whsec_…',
                'default' => '',
            ],
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->webhookInstructions(),
            ],
            'tax_behavior' => [
                'title' => __('Prices and tax', 'stackless-lite'),
                'type' => 'select',
                'default' => 'location',
                'options' => [
                    'location' => __('Follow the buyer’s country (Polar default)', 'stackless-lite'),
                    'exclusive' => __('Add tax on top of my prices', 'stackless-lite'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite'),
                ],
                'description' => __('Polar is the merchant of record and calculates sales tax and VAT itself. Turn off WooCommerce tax for products sold through Polar so buyers are not taxed twice.', 'stackless-lite'),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Polar sells one product per checkout. One-time carts of any size work and show in Polar as one store order. A subscription must be the only item in the cart, and Polar bills the same amount every cycle, so subscriptions with a signup fee or a different first payment cannot use Polar. Free trials work. Polar runs the renewal schedule; renewals it charges become paid WooCommerce renewal orders.', 'stackless-lite'),
            ],
            'copies_section' => [
                'title' => __('Copies', 'stackless-lite'),
                'type' => 'title',
                'description' => $copies,
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Polar activity to WooCommerce → Status → Logs (stackless-polar_gateway). Tokens are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
        if ($copies === '') {
            unset($this->form_fields['copies_section']);
        }
    }

    private function connectionLine(string $mode): string
    {
        $org = $this->organization($mode);
        if (empty($org['name'])) {
            return '';
        }
        /* translators: %s: Polar organization name */
        return sprintf(__('Connected to %s.', 'stackless-lite'), '<strong>' . esc_html((string) $org['name']) . '</strong>') . ' ';
    }

    public function webhookUrl(): string
    {
        return rest_url('stackless/v1/polar/webhook' . ($this->isOriginal() ? '' : '/' . ltrim($this->suffix(), '_')));
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: 1: webhook URL, 2: event names */
            __('In Polar (live and sandbox), open Settings → Webhooks, add an endpoint with this URL and the Raw format: %1$s Select these events, then copy the endpoint secret into the matching webhook secret field above: %2$s Webhooks confirm payments when a buyer closes the tab before returning, record renewals, and bring refunds and cancellations made in Polar into WooCommerce.', 'stackless-lite'),
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
        $previous = ['live' => $this->accessToken('live'), 'sandbox' => $this->accessToken('sandbox')];
        $saved = parent::process_admin_options();
        $this->init_settings();

        foreach (['live', 'sandbox'] as $mode) {
            $token = $this->accessToken($mode);
            $org = $this->organization($mode);
            if ($token === '') {
                delete_option($this->orgOption($mode));
                continue;
            }
            if ($token === $previous[$mode] && !empty($org['id'])) {
                continue;
            }
            $remote = $this->fetchOrganization($mode);
            if (is_wp_error($remote)) {
                \WC_Admin_Settings::add_error($remote->get_error_message());
                continue;
            }
            // Products created for another organization cannot be sold by this one.
            if ((string) ($org['id'] ?? '') !== (string) $remote['id']) {
                Catalog::forgetMode($this, $mode);
            }
        }

        if ($this->get_option('enabled') === 'yes') {
            $mode = $this->mode();
            if ($this->accessToken($mode) === '') {
                \WC_Admin_Settings::add_error(__('Polar is enabled but has no access token for the selected environment.', 'stackless-lite'));
            }
            if ($this->webhookSecret($mode) === '') {
                \WC_Admin_Settings::add_error(__('Add the Polar webhook secret. Payments, renewals and refunds stay in sync through the webhook.', 'stackless-lite'));
            }
        }
        return $saved;
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
        $constant = 'STACKLESS_POLAR_' . strtoupper($mode) . '_' . strtoupper($field);
        // Constants in wp-config.php belong to the original gateway; copies keep their own keys.
        if ($this->isOriginal() && defined($constant) && constant($constant)) {
            return trim((string) constant($constant));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function accessToken(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'access_token');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->secretSetting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'sandbox' ? $mode : $this->mode();
        return new Api($mode, $this->accessToken($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'sandbox'], true) ? $mode : $this->mode();
    }

    public function taxBehavior(): string
    {
        $behavior = (string) $this->get_option('tax_behavior', 'location');
        return in_array($behavior, self::TAX_BEHAVIORS, true) ? $behavior : 'location';
    }

    private function orgOption(string $mode): string
    {
        return 'stackless_polar_gateway_org_' . ($mode === 'sandbox' ? 'sandbox' : 'live') . $this->suffix();
    }

    /** @return array{id?:string,slug?:string,name?:string,currency?:string} */
    public function organization(string $mode): array
    {
        $org = get_option($this->orgOption($mode), []);
        return is_array($org) ? $org : [];
    }

    /** @return array|\WP_Error */
    public function fetchOrganization(string $mode)
    {
        $response = $this->api($mode)->request('GET', 'organizations/', ['limit' => 1]);
        if (is_wp_error($response)) {
            return $response;
        }
        $remote = (array) ($response['items'][0] ?? []);
        if (empty($remote['id'])) {
            return new \WP_Error('stackless_polar_org', __('Polar did not return an organization for this access token.', 'stackless-lite'));
        }
        $org = [
            'id' => (string) $remote['id'],
            'slug' => (string) ($remote['slug'] ?? ''),
            'name' => (string) ($remote['name'] ?? ''),
            'currency' => strtolower((string) ($remote['default_presentment_currency'] ?? '')),
        ];
        update_option($this->orgOption($mode), $org, true);
        return $org;
    }

    /** The organization's default presentment currency (lowercase), looked up once when unknown. */
    public function defaultCurrency(string $mode): string
    {
        $org = $this->organization($mode);
        if (!empty($org['currency'])) {
            return (string) $org['currency'];
        }
        $remote = $this->fetchOrganization($mode);
        return is_wp_error($remote) ? '' : (string) $remote['currency'];
    }

    public function dashboardUrl(string $mode, string $path): string
    {
        $host = $mode === 'sandbox' ? 'https://sandbox.polar.sh/dashboard/' : 'https://polar.sh/dashboard/';
        $slug = (string) ($this->organization($mode)['slug'] ?? '');
        return $slug !== '' ? $host . rawurlencode($slug) . '/' . $path : $host;
    }

    public function get_transaction_url($order)
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }
        $polarOrder = (string) $order->get_meta(self::META_ORDER_ID);
        return $polarOrder !== '' ? $this->dashboardUrl($this->orderMode($order), 'sales/' . rawurlencode($polarOrder)) : '';
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('polar_gateway', ($this->isOriginal() ? '' : '[' . $this->id . '] ') . $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || $this->accessToken() === '') {
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
        stackless_get_template('polar_gateway/payment-fields.php', [
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
            $url = new \WP_Error('stackless_polar_checkout', __('Polar could not start the checkout. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Polar checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
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
        return $order instanceof \WC_Order && (string) $order->get_meta(self::META_ORDER_ID) !== '';
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_polar_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_polar_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $polarOrder = (string) $order->get_meta(self::META_ORDER_ID);
        if ($polarOrder === '') {
            return new \WP_Error('stackless_polar_refund', __('This payment has no Polar order to refund.', 'stackless-lite'));
        }

        $reason = (string) $reason;
        $polarReason = EventMap::refundReason($reason);
        $body = [
            'order_id' => $polarOrder,
            'reason' => $polarReason,
            'amount' => Money::toMinor($amount, $order->get_currency()),
            'metadata' => ['wc_order_id' => (string) $order->get_id()],
        ];
        if ($reason !== '' && $polarReason === 'other') {
            $body['comment'] = function_exists('mb_substr') ? mb_substr($reason, 0, 500) : substr($reason, 0, 500);
        }

        $refund = $this->api($this->orderMode($order))->request('POST', 'refunds/', $body);
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        if (!in_array($refund['status'] ?? '', ['pending', 'succeeded'], true)) {
            return new \WP_Error('stackless_polar_refund', __('Polar could not process this refund. Check the order in your Polar dashboard.', 'stackless-lite'));
        }
        Sync::recordRefundId($order, (string) ($refund['id'] ?? ''));
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Polar refund id, 3: status */
            __('Refunded %1$s through Polar (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
            (string) ($refund['id'] ?? ''),
            (string) ($refund['status'] ?? '')
        ));
        return true;
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Polar charges renewals on its own schedule, so nothing is charged here.
     * The renewal order is parked and completed when Polar's order.paid webhook arrives.
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
        $renewal_order->add_order_note(__('Polar bills this subscription itself. This order is marked paid when Polar reports the renewal charge.', 'stackless-lite'));
        return ['result' => 'pending', 'message' => __('Waiting for Polar to charge the renewal.', 'stackless-lite')];
    }

    /** Cancel at Polar: stop renewing at period end, or revoke immediately. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        if (!$subscription instanceof \WC_Order) {
            return false;
        }
        $polarId = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($polarId === '') {
            return true; // Never reached Polar, nothing to stop there.
        }
        $api = $this->api($this->orderMode($subscription));
        $path = 'subscriptions/' . rawurlencode($polarId);
        $remote = $api->request('GET', $path);
        if (is_wp_error($remote)) {
            $this->log('Cancel lookup for ' . $polarId . ' failed: ' . $remote->get_error_message(), 'error');
            return false;
        }
        if (($remote['status'] ?? '') === 'canceled' || (!empty($remote['ended_at']))) {
            return true;
        }
        if ($at_period_end && !empty($remote['cancel_at_period_end'])) {
            return true;
        }
        $result = $api->request('PATCH', $path, $at_period_end ? ['cancel_at_period_end' => true] : ['revoke' => true]);
        if (is_wp_error($result)) {
            $this->log('Cancel of ' . $polarId . ' failed: ' . $result->get_error_message(), 'error');
            return false;
        }
        $subscription->update_meta_data(self::META_CANCEL_AT_END, $at_period_end ? 'yes' : 'no');
        $subscription->save();
        $subscription->add_order_note($at_period_end
            ? __('Polar subscription set to end at the close of the paid period.', 'stackless-lite')
            : __('Polar subscription revoked immediately.', 'stackless-lite'));
        return true;
    }
}
