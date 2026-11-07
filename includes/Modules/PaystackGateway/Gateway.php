<?php

namespace Stackless\Modules\PaystackGateway;

use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Paystack for WooCommerce: one-time payments on Paystack's hosted checkout (cards, bank
 * transfer, USSD, mobile money, Apple Pay and the rest of the account's channels).
 *
 * The order is marked paid only after the transaction is verified with Paystack's API and
 * its currency and amount match the order. Refunds go through the API; Paystack-side
 * refunds and disputes arrive through the signed webhook. Subscriptions use pay links:
 * this gateway does not renew automatically.
 */
class Gateway extends \WC_Payment_Gateway
{
    public const ID = 'stackless_paystack';

    public const META_MODE = '_stackless_paystack_mode';
    public const META_REFERENCE = '_stackless_paystack_reference';
    public const META_CHECKOUT_URL = '_stackless_paystack_checkout_url';
    public const META_STARTED_AT = '_stackless_paystack_started_at';
    public const META_AMOUNT = '_stackless_paystack_amount';
    public const META_TRANSACTION_ID = '_stackless_paystack_transaction_id';
    public const META_CHANNEL = '_stackless_paystack_channel';
    public const META_REFUND_IDS = '_stackless_paystack_refund_ids';
    public const META_REFUND_FAILED = '_stackless_paystack_refund_failed';

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
        $this->method_title = __('Paystack (Stackless)', 'stackless-lite');
        $this->method_description = __('Take payments in Nigeria, Ghana, South Africa, Kenya and Côte d’Ivoire through Paystack: cards, bank transfer, USSD, mobile money and Apple Pay on Paystack’s secure checkout.', 'stackless-lite');
        $this->supports = ['products', 'refunds'];
        $this->view_transaction_url = 'https://dashboard.paystack.com/#/transactions/%s';

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
                'label' => __('Accept payments with Paystack', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, bank transfer or mobile money (Paystack)', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Paystack’s secure checkout.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use Paystack test mode (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Live', 'stackless-lite'),
                'type' => 'title',
                'description' => \Stackless\Core\WebhookSetup::keyButtons([
                    __('Get API keys', 'stackless-lite') => 'https://dashboard.paystack.com/v2/developers',
                    __('Get webhook signing secret', 'stackless-lite') => 'https://dashboard.paystack.com/v2/developers/api-configuration',
                ]) . __('Paste the live secret key, or a restricted key with the transactions and refunds permissions. The webhook signing secret is optional while your account still has a secret key, and required once you use only restricted keys.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if ($mode === 'test') {
                $fields['test_section'] = [
                    'title' => __('Test mode', 'stackless-lite'),
                    'type' => 'title',
                    'description' => __('Test keys start with sk_test or rk_test. Switch the Paystack dashboard to Test Mode to copy them and the test signing secret.', 'stackless-lite'),
                ];
            }
            if (!$this->isDefined($mode, 'secret_key')) {
                $fields[$mode . '_secret_key'] = [
                    'title' => $mode === 'test' ? __('Test secret key', 'stackless-lite') : __('Live secret key', 'stackless-lite'),
                    'type' => 'password',
                    'default' => '',
                ];
            }
            if (!$this->isDefined($mode, 'webhook_secret')) {
                $fields[$mode . '_webhook_secret'] = [
                    'title' => $mode === 'test' ? __('Test webhook signing secret', 'stackless-lite') : __('Live webhook signing secret', 'stackless-lite'),
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
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => __('Paystack takes one-time payments. Subscription products can still be bought with Paystack when they renew by pay link: each renewal sends the customer an invoice to pay. Which currencies work depends on the country your Paystack business is registered in.', 'stackless-lite'),
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Paystack activity to WooCommerce → Status → Logs (stackless-paystack_gateway). Keys are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
        $this->form_fields = $fields;
    }

    public static function webhookUrl(): string
    {
        return rest_url('stackless/v1/paystack/webhook');
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: %s: webhook URL */
            __('In the Paystack dashboard, open Settings → API Keys & Webhooks and paste this address into both the Test Webhook URL and the Live Webhook URL: %s Paystack sends every event there. Webhooks confirm payments when a buyer closes the tab before returning to your store, and bring refunds and disputes made in Paystack into WooCommerce. Paystack does not let plugins add the webhook URL for you.', 'stackless-lite'),
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
                    ? __('The Paystack test secret key field holds a live key. Paste the key that starts with sk_test or rk_test.', 'stackless-lite')
                    : __('The Paystack live secret key field holds a test key. Paste the key that starts with sk_live or rk_live.', 'stackless-lite'));
                continue;
            }
            if ($key !== $previous[$mode]) {
                $check = $this->api($mode)->request('GET', 'transaction', ['perPage' => 1]);
                if (is_wp_error($check)) {
                    \WC_Admin_Settings::add_error($check->get_error_message());
                }
            }
        }

        if ($this->get_option('enabled') === 'yes' && $this->secretKey($this->mode()) === '') {
            \WC_Admin_Settings::add_error(__('Paystack is enabled but has no secret key for the selected mode.', 'stackless-lite'));
        }
        return $saved;
    }

    /**
     * The refund Paystack already holds under our reference, read from the transaction's refund
     * list and only when its amount is the one asked for.
     */
    private static function existingRefund(Api $api, string $transaction, string $reference, int $minor): ?array
    {
        $list = $api->request('GET', 'refund', ['transaction' => $transaction, 'perPage' => 100]);
        foreach (is_wp_error($list) ? [] : $list as $refund) {
            if (is_array($refund) && strtolower((string) ($refund['reference'] ?? '')) === strtolower($reference) && (int) ($refund['amount'] ?? -1) === $minor) {
                return $refund;
            }
        }
        return null;
    }

    /* ---- Configuration --------------------------------------------------------- */

    /** test|live */
    public function mode(): string
    {
        return $this->get_option('testmode', 'yes') === 'yes' ? 'test' : 'live';
    }

    /** Whether a key is pinned in wp-config.php, e.g. STACKLESS_PAYSTACK_LIVE_SECRET_KEY. */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = 'STACKLESS_PAYSTACK_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
        return defined($constant) && constant($constant);
    }

    private function setting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_PAYSTACK_' . strtoupper($mode) . '_' . strtoupper($field)));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function secretKey(string $mode = ''): string
    {
        return $this->setting($mode !== '' ? $mode : $this->mode(), 'secret_key');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->setting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
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
        Logger::log('paystack_gateway', $message, $level, $context);
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
        stackless_get_template('paystack_gateway/payment-fields.php', [
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
            $url = new \WP_Error('stackless_paystack_checkout', __('Paystack could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Paystack checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
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

    /**
     * Refund through Paystack. The refund reference is derived from the order and the number
     * of refunds it has, so a retry after a timeout reuses it and Paystack answers 409 instead
     * of paying the customer twice.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_paystack_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_paystack_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $transaction = (string) $order->get_meta(self::META_TRANSACTION_ID);
        if ($transaction === '') {
            return new \WP_Error('stackless_paystack_refund', __('This order has no Paystack transaction to refund.', 'stackless-lite'));
        }

        $currency = strtoupper($order->get_currency());
        $minor = Money::toMinor($amount, $currency);
        $reference = EventMap::refundReference($order->get_id(), count($order->get_refunds()), $minor, Sync::siteHost());
        $body = [
            'transaction' => $transaction,
            'amount' => $minor,
            'currency' => $currency,
            'reference' => $reference,
        ];
        $reason = trim(wp_strip_all_tags((string) $reason));
        if ($reason !== '') {
            $body['merchant_note'] = function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200);
        }

        $api = $this->api($this->orderMode($order));
        // Under the order's refund lock, so a refund webhook can't mirror this refund before its id is stored.
        $refund = Lock::run('refund-' . $order->get_id(), static function () use ($api, $body, $order, $transaction, $reference, $minor) {
            $refund = $api->request('POST', 'refund', $body);
            if (is_wp_error($refund) && (int) (((array) $refund->get_error_data())['status'] ?? 0) === 409) {
                // Already made with this reference (an earlier attempt timed out): use that refund.
                $found = self::existingRefund($api, $transaction, $reference, $minor);
                $refund = $found ?: new \WP_Error('stackless_paystack_refund', __('Paystack already has a refund with this reference for a different amount. Check the transaction in your Paystack dashboard.', 'stackless-lite'));
            }
            if (is_wp_error($refund)) {
                return $refund;
            }
            $refundId = (string) ($refund['id'] ?? '');
            if ($refundId === '' || EventMap::refundState((string) ($refund['status'] ?? '')) === 'failed') {
                return new \WP_Error('stackless_paystack_refund', __('Paystack could not process this refund. Check the transaction in your Paystack dashboard.', 'stackless-lite'));
            }
            Sync::recordRefundId($order, $refundId);
            return $refund;
        }, null);
        if ($refund === null) {
            return new \WP_Error('stackless_paystack_refund', __('This order is being updated by another request. Try the refund again in a moment.', 'stackless-lite'));
        }
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $refundId = (string) $refund['id'];
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Paystack refund id, 3: status */
            __('Refunded %1$s through Paystack (refund %2$s, %3$s). Paystack can take up to 10 business days to return the money.', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            $refundId,
            (string) ($refund['status'] ?? 'pending')
        ));
        return true;
    }
}
