<?php

namespace Stackless\Modules\MercadoPagoGateway;

use Stackless\Core\Logger;

defined('ABSPATH') || exit;

/**
 * Mercado Pago for WooCommerce: one-time payments on Checkout Pro (cards, Pix, boleto,
 * OXXO, Mercado Pago balance and the rest of the country's methods).
 *
 * The order is marked paid only after the payment is read back from Mercado Pago and its
 * reference, currency and amount match the order; cash and bank methods that settle later
 * hold the order until Mercado Pago approves them. Refunds go through the API; refunds and
 * chargebacks made in Mercado Pago arrive through the signed webhook. Subscriptions use pay
 * links: this gateway does not renew automatically.
 */
class Gateway extends \WC_Payment_Gateway
{
    public const ID = 'stackless_mercadopago';

    public const META_MODE = '_stackless_mercadopago_mode';
    public const META_PREFERENCE_ID = '_stackless_mercadopago_preference_id';
    public const META_CHECKOUT_URL = '_stackless_mercadopago_checkout_url';
    public const META_STARTED_AT = '_stackless_mercadopago_started_at';
    public const META_AMOUNT = '_stackless_mercadopago_amount';
    public const META_PAYMENT_ID = '_stackless_mercadopago_payment_id';
    public const META_PAYMENT_TYPE = '_stackless_mercadopago_payment_type';
    public const META_AWAITING = '_stackless_mercadopago_awaiting_payment';
    public const META_REFUND_IDS = '_stackless_mercadopago_refund_ids';

    /** An unpaid Checkout Pro link is offered again for this long (double submits, back button). */
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
        $this->method_title = __('Mercado Pago (Stackless)', 'stackless-lite');
        $this->method_description = __('Take payments in Argentina, Brazil, Chile, Colombia, Mexico, Peru and Uruguay through Mercado Pago Checkout Pro: cards, Pix, boleto, OXXO, Mercado Pago balance and local methods.', 'stackless-lite');
        $this->supports = ['products', 'refunds'];

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
                'label' => __('Accept payments with Mercado Pago', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Mercado Pago', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll finish paying on Mercado Pago with a card, your Mercado Pago balance or a local payment method.', 'stackless-lite'),
            ],
            'testmode' => [
                'title' => __('Test mode', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Use Mercado Pago test credentials (no real money moves)', 'stackless-lite'),
                'default' => 'yes',
            ],
            'live_section' => [
                'title' => __('Production', 'stackless-lite'),
                'type' => 'title',
                'description' => $this->connectionLine('live') . \Stackless\Core\WebhookSetup::keyButtons([
                    __('Open Your integrations', 'stackless-lite') => 'https://www.mercadopago.com/developers/panel/app',
                ]) . __('Open your application, then Production → Production credentials, and copy the access token.', 'stackless-lite'),
            ],
        ];
        foreach (['live', 'test'] as $mode) {
            if ($mode === 'test') {
                $fields['test_section'] = [
                    'title' => __('Test mode', 'stackless-lite'),
                    'type' => 'title',
                    'description' => $this->connectionLine('test') . __('Use the access token from Tests → Test credentials of the same application. Pay with a test buyer account and Mercado Pago’s test cards.', 'stackless-lite'),
                ];
            }
            if (!$this->isDefined($mode, 'access_token')) {
                $fields[$mode . '_access_token'] = [
                    'title' => $mode === 'test' ? __('Test access token', 'stackless-lite') : __('Production access token', 'stackless-lite'),
                    'type' => 'password',
                    'default' => '',
                ];
            }
            if (!$this->isDefined($mode, 'webhook_secret')) {
                $fields[$mode . '_webhook_secret'] = [
                    'title' => $mode === 'test' ? __('Test webhook secret', 'stackless-lite') : __('Production webhook secret', 'stackless-lite'),
                    'type' => 'password',
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
                'description' => __('Mercado Pago takes payments in the currency of the country your account belongs to, so it shows at checkout only when the store or the buyer’s selected currency matches. It takes one-time payments: subscription products can still be bought with it when they renew by pay link. Boleto, OXXO and other cash methods keep the order on hold until Mercado Pago approves the payment.', 'stackless-lite'),
            ],
            'debug' => [
                'title' => __('Debug log', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Log Mercado Pago activity to WooCommerce → Status → Logs (stackless-mercadopago_gateway). Tokens are never logged.', 'stackless-lite'),
                'default' => 'no',
            ],
        ];
        $this->form_fields = $fields;
    }

    private function connectionLine(string $mode): string
    {
        $account = $this->account($mode);
        $site = (string) ($account['site_id'] ?? '');
        if ($site === '' || !isset(Money::SITES[$site])) {
            return '';
        }
        /* translators: 1: Mercado Pago site id such as MLB, 2: currency code */
        return sprintf(__('Connected to a Mercado Pago account in %1$s (charges in %2$s).', 'stackless-lite'), '<strong>' . esc_html($site) . '</strong>', esc_html(Money::SITES[$site])) . ' ';
    }

    public static function webhookUrl(): string
    {
        return rest_url('stackless/v1/mercadopago/webhook');
    }

    private function webhookInstructions(): string
    {
        return sprintf(
            /* translators: %s: webhook URL */
            __('In Mercado Pago, open Your integrations → your application → Webhooks → Configure notifications. Paste this URL for both test mode and production mode, select the Payments event and save: %s Then copy the secret signature Mercado Pago shows into the webhook secret field for each mode. Webhooks confirm payments that settle later (boleto, OXXO, Pix) and payments whose buyer never came back, and bring refunds and chargebacks into WooCommerce. Mercado Pago does not let plugins set this up for you.', 'stackless-lite'),
            '<br><code>' . esc_html(self::webhookUrl()) . '</code><br>'
        );
    }

    public function saveSettings(): void
    {
        $this->process_admin_options();
    }

    public function process_admin_options()
    {
        $previous = ['live' => $this->accessToken('live'), 'test' => $this->accessToken('test')];
        $saved = parent::process_admin_options();
        $this->init_settings();

        foreach (['live', 'test'] as $mode) {
            $token = $this->accessToken($mode);
            if ($token === '') {
                delete_option($this->accountOption($mode));
                continue;
            }
            $account = $this->account($mode);
            if ($token !== $previous[$mode] || empty($account['site_id'])) {
                $account = $this->fetchAccount($mode);
                if (is_wp_error($account)) {
                    \WC_Admin_Settings::add_error($account->get_error_message());
                    continue;
                }
            }
            $isTest = EventMap::isTestToken($token, $account);
            if ($mode === 'live' && $isTest) {
                \WC_Admin_Settings::add_error(__('The Mercado Pago production access token belongs to a test account. Paste the token from Production → Production credentials.', 'stackless-lite'));
            } elseif ($mode === 'test' && !$isTest) {
                \WC_Admin_Settings::add_error(__('The Mercado Pago test access token belongs to a real seller account. Paste the token from Tests → Test credentials, so test orders never move real money.', 'stackless-lite'));
            }
        }

        if ($this->get_option('enabled') === 'yes') {
            $mode = $this->mode();
            if ($this->accessToken($mode) === '') {
                \WC_Admin_Settings::add_error(__('Mercado Pago is enabled but has no access token for the selected mode.', 'stackless-lite'));
            }
            if ($this->webhookSecret($mode) === '') {
                \WC_Admin_Settings::add_error(__('Add the Mercado Pago webhook secret. Payments that settle later, refunds and chargebacks stay in sync through the webhook.', 'stackless-lite'));
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

    /** Whether a value is pinned in wp-config.php, e.g. STACKLESS_MERCADOPAGO_LIVE_ACCESS_TOKEN. */
    public function isDefined(string $mode, string $field): bool
    {
        $constant = 'STACKLESS_MERCADOPAGO_' . strtoupper($mode === 'test' ? 'test' : 'live') . '_' . strtoupper($field);
        return defined($constant) && constant($constant);
    }

    private function setting(string $mode, string $field): string
    {
        $mode = $mode === 'test' ? 'test' : 'live';
        if ($this->isDefined($mode, $field)) {
            return trim((string) constant('STACKLESS_MERCADOPAGO_' . strtoupper($mode) . '_' . strtoupper($field)));
        }
        return trim((string) $this->get_option($mode . '_' . $field, ''));
    }

    public function accessToken(string $mode = ''): string
    {
        return $this->setting($mode !== '' ? $mode : $this->mode(), 'access_token');
    }

    public function webhookSecret(string $mode = ''): string
    {
        return $this->setting($mode !== '' ? $mode : $this->mode(), 'webhook_secret');
    }

    public function api(string $mode = ''): Api
    {
        $mode = $mode === 'live' || $mode === 'test' ? $mode : $this->mode();
        return new Api($mode, $this->accessToken($mode));
    }

    /** Mode an order was paid in (falls back to the current setting). */
    public function orderMode(\WC_Order $order): string
    {
        $mode = (string) $order->get_meta(self::META_MODE);
        return in_array($mode, ['live', 'test'], true) ? $mode : $this->mode();
    }

    private function accountOption(string $mode): string
    {
        return 'stackless_mercadopago_gateway_account_' . ($mode === 'test' ? 'test' : 'live');
    }

    /** @return array{site_id?:string,test_user?:bool} The account the token belongs to (no personal data). */
    public function account(string $mode): array
    {
        $account = get_option($this->accountOption($mode), []);
        return is_array($account) ? $account : [];
    }

    /** @return array{site_id:string,test_user:bool}|\WP_Error */
    public function fetchAccount(string $mode)
    {
        $me = $this->api($mode)->request('GET', 'users/me');
        if (is_wp_error($me)) {
            return $me;
        }
        $site = strtoupper((string) ($me['site_id'] ?? ''));
        if ($site === '') {
            return new \WP_Error('stackless_mercadopago_account', __('Mercado Pago did not say which country this account belongs to.', 'stackless-lite'));
        }
        $account = ['site_id' => $site, 'test_user' => in_array('test_user', (array) ($me['tags'] ?? []), true)];
        update_option($this->accountOption($mode), $account, false);
        return $account;
    }

    /** The country site of the account in a mode, '' until its token was saved. */
    public function siteId(string $mode = ''): string
    {
        return strtoupper((string) ($this->account($mode !== '' ? $mode : $this->mode())['site_id'] ?? ''));
    }

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        if (($level === 'info' || $level === 'debug') && $this->get_option('debug', 'no') !== 'yes') {
            return;
        }
        Logger::log('mercadopago_gateway', $message, $level, $context);
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || $this->accessToken() === '') {
            return false;
        }
        $order = $this->orderPayOrder();
        $currency = $order ? $order->get_currency() : stackless_current_currency();
        if (!Money::supported($currency, $this->siteId())) {
            return false;
        }
        return parent::is_available();
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Money::currencies($this->siteId());
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
        stackless_get_template('mercadopago_gateway/payment-fields.php', [
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
            $url = new \WP_Error('stackless_mercadopago_checkout', __('Mercado Pago could not start the payment. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Mercado Pago checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
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

    /**
     * Refund through Mercado Pago. The idempotency key comes from the order and its refund
     * count, so a retry after a timeout cannot pay the customer back twice.
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_mercadopago_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_remaining_refund_amount() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_mercadopago_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $paymentId = (string) $order->get_meta(self::META_PAYMENT_ID);
        if ($paymentId === '') {
            return new \WP_Error('stackless_mercadopago_refund', __('This order has no Mercado Pago payment to refund.', 'stackless-lite'));
        }
        $currency = strtoupper($order->get_currency());
        $refundAmount = Money::amount($amount, $currency);
        // The same refund retried after a timeout reuses the key; a different amount gets a new one.
        $key = 'wc' . $order->get_id() . '-r' . max(1, count($order->get_refunds())) . '-' . Money::toMinor($refundAmount, $currency) . '-' . substr(md5(Sync::siteHost()), 0, 8);
        // A full refund is sent without an amount, as Mercado Pago asks.
        $body = Money::equals($amount, $order->get_total(), $currency) ? [] : ['amount' => $refundAmount];
        $api = $this->api($this->orderMode($order));
        // Under the order's refund lock: Mercado Pago approves at once and notifies, and that
        // notification must not mirror this refund before its id is stored.
        $refund = Lock::run('refund-' . $order->get_id(), static function () use ($api, $paymentId, $body, $key, $order) {
            $refund = $api->request('POST', 'v1/payments/' . rawurlencode($paymentId) . '/refunds', $body, $key);
            if (is_wp_error($refund)) {
                return $refund;
            }
            $refundId = (string) ($refund['id'] ?? '');
            if ($refundId === '' || in_array((string) ($refund['status'] ?? ''), ['rejected', 'cancelled'], true)) {
                return new \WP_Error('stackless_mercadopago_refund', __('Mercado Pago could not process this refund. Check that your account balance covers it and that the payment is under 180 days old.', 'stackless-lite'));
            }
            Sync::recordRefundId($order, $refundId);
            return $refund;
        }, null);
        if ($refund === null) {
            return new \WP_Error('stackless_mercadopago_refund', __('This order is being updated by another request. Try the refund again in a moment.', 'stackless-lite'));
        }
        if (is_wp_error($refund)) {
            $this->log('Refund on order ' . $order->get_id() . ' failed: ' . $refund->get_error_message(), 'error');
            return $refund;
        }
        $refundId = (string) $refund['id'];
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Mercado Pago refund id, 3: status */
            __('Refunded %1$s through Mercado Pago (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $currency])),
            $refundId,
            (string) ($refund['status'] ?? 'approved')
        ));
        return true;
    }
}
