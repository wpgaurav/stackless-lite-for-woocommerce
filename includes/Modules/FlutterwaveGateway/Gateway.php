<?php

namespace Stackless\Modules\FlutterwaveGateway;

use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Flutterwave for WooCommerce: one-time payments on Flutterwave's hosted checkout (cards,
 * bank transfer, mobile money, USSD and the account's other methods) through the v3 API.
 *
 * The order is marked paid only after the transaction is verified with Flutterwave and its
 * reference, currency and amount match the order. Refunds go through the API; refunds made
 * in the Flutterwave dashboard arrive through the webhook. Subscriptions use pay links:
 * this gateway does not renew automatically.
 */
class Gateway extends \WC_Payment_Gateway
{
    public const ID = 'stackless_flutterwave';

    public const META_MODE = '_stackless_flutterwave_mode';
    public const META_TX_REF = '_stackless_flutterwave_tx_ref';
    public const META_CHECKOUT_URL = '_stackless_flutterwave_checkout_url';
    public const META_STARTED_AT = '_stackless_flutterwave_started_at';
    public const META_AMOUNT = '_stackless_flutterwave_amount';
    public const META_TRANSACTION_ID = '_stackless_flutterwave_transaction_id';
    public const META_FLW_REF = '_stackless_flutterwave_flw_ref';
    public const META_PAYMENT_TYPE = '_stackless_flutterwave_payment_type';
    public const META_REFUND_IDS = '_stackless_flutterwave_refund_ids';

    /** An unpaid checkout link is offered again for this long (double submits, back button). */
    public const REUSE_FOR = 1800;

    /** @var Gateway|null */
    private static $instance;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Forget the shared instance (staging scripts swap settings). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Flutterwave (Stackless)', 'stackless-lite');
        $this->method_description = __('Take payments across Africa and beyond through Flutterwave: cards, bank transfer, mobile money, USSD and more on Flutterwave’s secure checkout.', 'stackless-lite');
        $this->supports = ['products', 'refunds'];
        $this->view_transaction_url = 'https://app.flutterwave.com/dashboard/transactions/%s';

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
        $fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Flutterwave', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, bank transfer or mobile money (Flutterwave)', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Flutterwave’s secure checkout.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use Flutterwave test mode (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([
                    __('Get API keys', 'stackless-lite') => 'https://app.flutterwave.com/dashboard/settings/apis/live',
                    __('Open webhook settings', 'stackless-lite') => 'https://app.flutterwave.com/dashboard/settings/webhooks/live',
                ]) . __('Use the v3 secret key. If your dashboard offers “Switch to v4 live API keys”, stay on v3.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if ($mode === 'test') {
                $fields['test_section'] = [
                    'title' => __('Test mode', 'stackless-lite'),
                    'type' => 'title',
                    'description' => __('Test keys start with FLWSECK_TEST. Switch the Flutterwave dashboard to test mode to copy them and to set the test webhook.', 'stackless-lite'),
                ];
            }
            if (!$this->isDefined($mode, 'secret_key')) {
                $fields[$mode . '_secret_key'] = [
                    'title' => $mode === 'test' ? __('Test secret key', 'stackless-lite') : __('Live secret key', 'stackless-lite'),
                    'type' => 'password',
                    'default' => '',
                ];
            }
            if (!$this->isDefined($mode, 'secret_hash')) {
                $fields[$mode . '_secret_hash'] = [
                    'title' => $mode === 'test' ? __('Test webhook secret hash', 'stackless-lite') : __('Live webhook secret hash', 'stackless-lite'),
                    'type' => 'password',
                    'default' => '',
                    'description' => __('Any long random text. Enter the same value as the Secret hash in Flutterwave’s webhook settings.', 'stackless-lite'),
                ];
            }
        }
        $fields += [
            'webhook_section' => [
                'title' => __('Webhook', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->webhookInstructions(),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Flutterwave takes one-time payments. Subscription products can still be bought with Flutterwave when they renew by pay link: each renewal sends the customer an invoice to pay. Which currencies and methods work depends on your Flutterwave account’s country and settings.', 'stackless-lite'),
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Flutterwave activity to WooCommerce → Status → Logs (stackless-flutterwave_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
        $this->form_fields = $fields;
    }

    public static function webhookUrl(): string
    {
        return rest_url('stackless/v1/flutterwave/webhook');
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: %s: webhook URL */
            __('In the Flutterwave dashboard, open Settings → Webhooks (once in test mode, once in live mode), choose the V3 webhook, paste this URL, enter your secret hash, and tick “Receive webhook response in JSON format”, “Enable webhook retries” and “Enable webhook for refunds”: %s Webhooks confirm payments when a buyer closes the tab before returning to your store, and bring refunds made in Flutterwave into WooCommerce. Flutterwave does not let plugins add the webhook for you.', 'stackless-lite'),
            '<br><code>' . esc_html(self::webhookUrl()) . '</code><br>'
        );
    }

    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    public function process_admin_options()
    {
        $previous = ['live' => $this->secretKey('live'), 'test' => $this->secretKey('test')];
        $saved = parent::process_admin_options();
        $this->init_settings();

        foreach (['live', 'test'] as $mode) {
            $key = $this->secretKey($mode);
            if ($key === '') {
                continue;
            }
            $keyMode = EventMap::keyMode($key);
            if ($keyMode !== '' && $keyMode !== $mode) {
                \WC_Admin_Settings::add_error($mode === 'test'
                    ? __('The Flutterwave test secret key field holds a live key. Paste the key that starts with FLWSECK_TEST.', 'stackless-lite')
                    : __('The Flutterwave live secret key field holds a test key. Paste the live v3 secret key.', 'stackless-lite'));
                continue;
            }
            if ($key !== $previous[$mode]) {
                $check = $this->api($mode)->request('GET', 'transactions', ['page' => 1]);
                if (is_wp_error($check)) {
                    \WC_Admin_Settings::add_error($check->get_error_message());
                }
            }
        }

        if ($this->get_option('enabled') === 'yes') {
            $mode = $this->mode();
            if ($this->secretKey($mode) === '') {
                \WC_Admin_Settings::add_error(__('Flutterwave is enabled but has no secret key for the selected mode.', 'stackless-lite'));
            }
            if ($this->secretHash($mode) === '') {
                \WC_Admin_Settings::add_error(__('Add the Flutterwave webhook secret hash. Payments and refunds stay in sync through the webhook.', 'stackless-lite'));
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

    /** Whether a value is pinned in wp-config.php, e.g. STACKLESS_FLUTTERWAVE_LIVE_SECRET_KEY. */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = 'STACKLESS_FLUTTERWAVE_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
        return defined($constant) && constant($constant);
    }

    private function setting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_FLUTTERWAVE_' . strtoupper($mode) . '_' . strtoupper($field)));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function secretKey(string $mode = ''): string
    {
        return $this->setting($mode !== '' ? $mode : $this->mode(), 'secret_key');
    }

    public function secretHash(string $mode = ''): string
    {
        return $this->setting($mode !== '' ? $mode : $this->mode(), 'secret_hash');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->secretKey($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('flutterwave_gateway', $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || $this->secretKey() === '') {
            return false;
        }
        $order = $this->orderPayOrder();
        $currency = $order ? $order->get_currency() : stackless_current_currency();
        if (!Money::supported($currency)) {
            return false;
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
        stackless_get_template('flutterwave_gateway/payment-fields.php', [
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
        try {
            $url = (new Checkout($this))->start($order);
        } catch (\Throwable $e) {
            $this->log('Checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_flutterwave_checkout', __('Flutterwave could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Flutterwave checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
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

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_flutterwave_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_flutterwave_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $transaction = (string) $order->get_meta(self::META_TRANSACTION_ID);
        if ($transaction === '') {
            return new \WP_Error('stackless_flutterwave_refund', __('This order has no Flutterwave transaction to refund.', 'stackless-lite'));
        }

        $currency = strtoupper($order->get_currency());
        $body = ['amount' => Money::format($amount, $currency)];
        $reason = trim(wp_strip_all_tags((string) $reason));
        if ($reason !== '') {
            $body['comments'] = function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200);
        }
        $api = $this->api($this->orderMode($order));
        // Under the order's refund lock, so a refund webhook can't mirror this refund before its id is stored.
        $refund = Lock::run('refund-' . $order->get_id(), static function () use ($api, $transaction, $body, $order) {
            $refund = $api->request('POST', 'transactions/' . rawurlencode($transaction) . '/refund', $body);
            if (is_wp_error($refund)) {
                return $refund;
            }
            $refundId = (string) ($refund['id'] ?? '');
            if ($refundId === '' || EventMap::refundState((string) ($refund['status'] ?? '')) === 'failed') {
                return new \WP_Error('stackless_flutterwave_refund', __('Flutterwave could not process this refund. Check the transaction in your Flutterwave dashboard.', 'stackless-lite'));
            }
            Sync::recordRefundId($order, $refundId);
            return $refund;
        }, null);
        if ($refund === null) {
            return new \WP_Error('stackless_flutterwave_refund', __('This order is being updated by another request. Try the refund again in a moment.', 'stackless-lite'));
        }
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $refundId = (string) $refund['id'];
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Flutterwave refund id, 3: status */
            __('Refunded %1$s through Flutterwave (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            $refundId,
            (string) ($refund['status'] ?? 'pending')
        ));
        return true;
    }
}
