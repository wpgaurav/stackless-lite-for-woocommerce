<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\WebhookSetup;

defined('ABSPATH') || exit;

/**
 * Revolut Merchant for WooCommerce: one-time payments on Revolut's hosted page, and payments
 * that save a card (subscriptions, free trials) in Revolut's checkout widget, saved for merchant
 * use. Renewals are merchant-initiated charges of that card on Stackless's schedule.
 *
 * Saved cards are not offered at checkout: charging one with the customer present needs a 3-D
 * Secure challenge of our own, and the merchant-initiated flag would be untrue there.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    public const ID = Keys::GATEWAY_ID;
    public const MODULE_ID = Keys::MODULE_ID;

    /** Revolut charges a card saved for merchant use for any amount on any date. */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Revolut (Stackless)', 'stackless-lite');
        $this->method_description = __('Cards, Revolut Pay and the other methods switched on in your Revolut merchant account, on Revolut’s hosted page. Subscriptions save the card in Revolut’s checkout window for automatic renewals.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'stackless_subscriptions'];
        if (Settings::savesMethods()) {
            $this->supports[] = 'add_payment_method';
        }

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
                'label' => __('Accept payments with Revolut', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card or Revolut Pay', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Revolut’s secure checkout.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use the Revolut sandbox (a separate sandbox account and key; no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'keys_section' => [
                'title' => __('API keys', 'stackless-lite'),
                'type' => 'title',
                'description' => WebhookSetup::keyButtons([
                    __('Open Revolut Business', 'stackless-lite') => 'https://business.revolut.com/',
                    __('Open Revolut sandbox', 'stackless-lite') => 'https://sandbox-business.revolut.com/',
                ]) . __('Create a Merchant API secret key without request signing, with read and write access to orders, payments, customers and webhooks, and read access to disputes. Saving checks the key and sets the webhook up.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if (!Settings::isDefined($mode, 'secret_key')) {
                $fields[$mode . '_secret_key'] = [
                    'title' => $mode === 'test' ? __('Sandbox secret key', 'stackless-lite') : __('Live secret key', 'stackless-lite'),
                    'type' => 'password',
                    'placeholder' => 'sk_…',
                    'default' => '',
                ];
            }
            if (!Settings::isDefined($mode, 'webhook_secret')) {
                $fields[$mode . '_webhook_secret'] = [
                    'title' => $mode === 'test' ? __('Sandbox webhook signing secret', 'stackless-lite') : __('Live webhook signing secret', 'stackless-lite'),
                    'type' => 'password',
                    'placeholder' => 'wsk_…',
                    'default' => '',
                    'description' => sprintf(
                        /* translators: %s: webhook URL */
                        __('Filled in when you save. For a webhook made by hand, use the URL %s and paste its signing secret.', 'stackless-lite'),
                        // Not before init: rest_url() needs $wp_rewrite, and settings are also built while plugins load.
                        '<code>' . esc_html(did_action('init') ? Settings::webhookUrl($mode) : 'stackless/v1' . Webhook::ROUTE . $mode) . '</code>'
                    ),
                ];
            }
        }
        $fields += [
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Revolut activity to WooCommerce → Status → Logs (stackless-revolut_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: %s: currency codes */
                    __('One-time payments use Revolut’s hosted page. A subscription or free trial takes a card in Revolut’s checkout window and saves it for renewals; a free trial checks the card for 0.00. Only cards can renew. Currencies: %s.', 'stackless-lite'),
                    implode(', ', Money::CURRENCIES)
                ),
            ],
        ];
        $this->form_fields = $fields;
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        foreach (Connection::check() as $problem) {
            \WC_Admin_Settings::add_error($problem);
        }
        // The check may have saved a signing secret.
        $this->init_settings();
        return $saved;
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || Settings::secretKey() === '' || !Money::supported($this->contextCurrency())) {
            return false;
        }
        return parent::is_available();
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::CURRENCIES;
    }

    private function contextCurrency(): string
    {
        $order = $this->payPageOrder();
        return $order ? strtoupper($order->get_currency()) : Platform::currency();
    }

    /** The order on an order-pay page, validated against its key. */
    private function payPageOrder(): ?\WC_Order
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
        stackless_get_template('revolut_gateway/payment-fields.php', [
            'description' => $adding ? '' : $this->get_description(),
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
        if ($this->postedTokenId() > 0) {
            // Whoever's it is: checkout never charges a token id the browser sent.
            return $this->failure(__('Saved Revolut cards are only used for renewals. Enter a card to pay now.', 'stackless-lite'));
        }

        $requiresSave = stackless_order_requires_saved_payment_method($order);
        if ((float) $order->get_total() <= 0 && !$requiresSave) {
            $order->payment_complete();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        try {
            $url = (new Checkout())->start((int) $order->get_id(), $requiresSave);
        } catch (\Throwable $e) {
            Settings::log('Checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_revolut_checkout', __('Revolut could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Revolut payment could not start: %s', 'stackless-lite'), $url->get_error_message()));
            return $this->failure($url->get_error_message());
        }
        return ['result' => 'success', 'redirect' => $url];
    }

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $url = (new Checkout())->startAdd(get_current_user_id());
        if (is_wp_error($url)) {
            wc_add_notice($url->get_error_message(), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        // "pending" keeps WooCommerce from saying the card was added before Revolut confirms it.
        return ['result' => 'pending', 'redirect' => $url];
    }

    /** @return array{result:string, message:string} */
    private function failure(string $message): array
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

    /* ---- Refunds ---------------------------------------------------------------- */

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && RefundService::canRefund((int) $order->get_id());
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
            return new \WP_Error('stackless_revolut_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        $result = (new RefundService())->refund((int) $order->get_id(), $amount, (string) $reason);
        return is_wp_error($result) ? $result : true;
    }

    /* ---- Subscriptions contract ------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * @param mixed $subscription SubscriptionData&WC_Order
     * @return array{result:string, message?:string}
     */
    public function stackless_charge_renewal(\WC_Order $renewal_order, $subscription): array
    {
        return (new Charges())->charge((int) $renewal_order->get_id(), $subscription);
    }

    /** Stackless runs the schedule; nothing to cancel at Revolut. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }
}
