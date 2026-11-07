<?php

namespace Stackless\Modules\StripeGateway\Managed;

use Stackless\Admin\SettingsPage;
use Stackless\Contracts\PausableGateway;
use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;
use Stackless\Core\LiveStore;
use Stackless\Modules\StripeGateway\Amount;
use Stackless\Modules\StripeGateway\IdempotencyKey;
use Stackless\Modules\StripeGateway\Payments;
use Stackless\Modules\StripeGateway\Settings;
use Stackless\Modules\StripeGateway\StripeApiException;
use Stackless\Modules\StripeGateway\StripeClient;

defined('ABSPATH') || exit;

/**
 * Stripe Managed Payments (2.0.0): Stripe as merchant of record for digital products.
 *
 * Stripe calculates, collects and remits sales tax and VAT, handles disputes and fraud, and
 * bills subscriptions on Stripe Billing. After the order is placed the buyer pays in an
 * embedded Checkout Session on this store's own pay page; the browser return and signed
 * webhooks (the Stripe module's endpoint) keep orders, renewals, refunds, pauses and
 * cancellations in step. Reuses the Stripe module's keys, client, idempotency keys and
 * event log.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway, PausableGateway
{
    public const ID = 'stripe_managed';

    /** Managed Payments needs 2025-03-31.basil or later (docs.stripe.com/payments/managed-payments/set-up). */
    public const API_VERSION = '2025-03-31.basil';

    public const META_SESSION = '_stackless_stripe_managed_session_id';
    public const META_FINGERPRINT = '_stackless_stripe_managed_fingerprint';
    public const META_TAX = '_stackless_stripe_managed_tax';
    public const META_TOTAL = '_stackless_stripe_managed_total';
    public const META_INVOICE = '_stackless_stripe_managed_invoice_id';
    public const META_AWAITING = '_stackless_stripe_managed_awaiting_renewal';
    public const META_PERIOD_END = '_stackless_stripe_managed_period_end';
    public const META_CANCEL_AT_END = '_stackless_stripe_managed_cancel_at_period_end';

    public const STACKLESS_FEATURES = ['gateway_managed', 'auto_renewal', 'zero_total_setup'];

    /** Default tax code: General - Electronically Supplied Services, eligible for Managed Payments. */
    public const DEFAULT_TAX_CODE = 'txcd_10000000';

    /** A few eligible tax codes (docs.stripe.com/payments/managed-payments/eligibility). */
    public const TAX_CODES = [
        'txcd_10000000', 'txcd_10103000', 'txcd_10103001', 'txcd_10103100', 'txcd_10103101',
        'txcd_10202000', 'txcd_10202003', 'txcd_10701100', 'txcd_20060058',
    ];

    /** @var Gateway|null */
    private static $instance;

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
        $this->has_fields = true;
        $this->method_title = __('Stripe Managed Payments (Stackless)', 'stackless-lite');
        $this->method_description = __('Stripe as merchant of record for digital products and subscriptions: Stripe calculates and remits sales tax and VAT, handles disputes, and bills subscriptions on Stripe Billing. Buyers pay in Stripe Checkout, embedded on your store.', 'stackless-lite');
        $this->supports = ['products', 'refunds', 'stackless_subscriptions'];

        $this->init_form_fields();
        $this->init_settings();
        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, function (): void {
            $this->process_admin_options();
        });
    }

    /* ---- Settings -------------------------------------------------------------- */

    public function init_form_fields()
    {
        $codes = [
            'txcd_10000000' => __('txcd_10000000 · Electronically supplied services (general)', 'stackless-lite'),
            'txcd_10103000' => __('txcd_10103000 · SaaS, personal use', 'stackless-lite'),
            'txcd_10103001' => __('txcd_10103001 · SaaS, business use', 'stackless-lite'),
            'txcd_10103100' => __('txcd_10103100 · SaaS, electronic download, personal use', 'stackless-lite'),
            'txcd_10103101' => __('txcd_10103101 · SaaS, electronic download, business use', 'stackless-lite'),
            'txcd_10202000' => __('txcd_10202000 · Downloadable software, personal use', 'stackless-lite'),
            'txcd_10202003' => __('txcd_10202003 · Downloadable software, business use', 'stackless-lite'),
            'txcd_10701100' => __('txcd_10701100 · Website hosting', 'stackless-lite'),
            'txcd_20060058' => __('txcd_20060058 · Training, self-study web-based', 'stackless-lite'),
        ];
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'stackless-lite'),
                'type' => 'checkbox',
                'label' => __('Accept payments with Stripe Managed Payments', 'stackless-lite'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'stackless-lite'),
                'type' => 'text',
                'default' => __('Card, Apple Pay, Google Pay or Link', 'stackless-lite'),
            ],
            'description' => [
                'title' => __('Description', 'stackless-lite'),
                'type' => 'textarea',
                'default' => __('You’ll pay securely with Stripe on the next step. Sales tax or VAT for your location is added before you pay.', 'stackless-lite'),
            ],
            'tax_code' => [
                'title' => __('Stripe tax code', 'stackless-lite'),
                'type' => 'select',
                'default' => self::DEFAULT_TAX_CODE,
                'options' => $codes,
                'description' => __('Managed Payments needs an eligible tax code on every product. Pick the one that describes what you sell; the stackless_stripe_managed_tax_code filter can set one per order.', 'stackless-lite'),
            ],
            'tax_behavior' => [
                'title' => __('Prices and tax', 'stackless-lite'),
                'type' => 'select',
                'default' => 'exclusive',
                'options' => [
                    'exclusive' => __('Add tax on top of my prices', 'stackless-lite'),
                    'inclusive' => __('My prices already include tax', 'stackless-lite'),
                ],
                'description' => __('Stripe is the merchant of record and calculates sales tax and VAT itself. Turn off WooCommerce tax for products sold this way so buyers are not taxed twice.', 'stackless-lite'),
            ],
            'limits_section' => [
                'title' => __('Good to know', 'stackless-lite'),
                'type' => 'title',
                'description' => self::limitsText(),
            ],
        ];
    }

    public static function limitsText(): string
    {
        return implode(' ', [
            sprintf(
                /* translators: %s: link to Stripe Dashboard settings */
                __('Turn on Managed Payments in your Stripe Dashboard first (%s); Stripe reviews each account. API keys and the webhook are shared with the Stripe module.', 'stackless-lite'),
                '<a href="https://dashboard.stripe.com/settings/managed-payments" target="_blank" rel="noopener">' . esc_html__('Settings → Managed Payments', 'stackless-lite') . '</a>'
            ),
            __('Digital goods only: software, digital media, online courses and other electronically supplied services. Physical products, professional services and in-person events are not eligible, so the method hides when the cart holds them.', 'stackless-lite'),
            __('One subscription schedule per checkout; one-time items and signup fees are added to the first invoice. Klarna, Afterpay and Bancontact take one-time payments only.', 'stackless-lite'),
            __('Only new subscriptions are covered: subscriptions started with another method keep renewing there. Stripe bills renewals itself; renewal orders are marked paid when Stripe reports them. Customers update their card in the Stripe customer portal.', 'stackless-lite'),
        ]);
    }

    public function admin_options()
    {
        parent::admin_options();
        printf(
            '<p>%s <a class="button" href="%s">%s</a></p>',
            esc_html__('API keys, the webhook and the debug log are shared with the Stripe module.', 'stackless-lite'),
            esc_url(SettingsPage::url(Settings::MODULE_ID)),
            esc_html__('Open Stripe settings', 'stackless-lite')
        );
    }

    public function taxCode(?\WC_Order $order = null): string
    {
        $code = (string) $this->get_option('tax_code', self::DEFAULT_TAX_CODE);
        $code = preg_match('/^txcd_\d{8}$/', $code) ? $code : self::DEFAULT_TAX_CODE;
        $filtered = (string) apply_filters('stackless_stripe_managed_tax_code', $code, $order);
        return preg_match('/^txcd_\d{8}$/', $filtered) ? $filtered : $code;
    }

    public function taxBehavior(): string
    {
        return $this->get_option('tax_behavior', 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
    }

    public static function apiVersion(): string
    {
        $version = (string) apply_filters('stackless_stripe_managed_api_version', self::API_VERSION);
        return preg_match('/^\d{4}-\d{2}-\d{2}(\.[a-z]+)?$/', $version) ? $version : self::API_VERSION;
    }

    /** Client in a mode, pinned to the Managed Payments API version. */
    public static function client(?string $mode = null): StripeClient
    {
        return Settings::versionedClient(self::apiVersion(), $mode);
    }

    /** Client for the mode an order or subscription was paid in. */
    public static function clientFor(\WC_Order $order): StripeClient
    {
        $mode = (string) $order->get_meta(Payments::META_MODE);
        return self::client($mode !== '' ? $mode : null);
    }

    public function get_transaction_url($order)
    {
        if (!$order instanceof \WC_Order || $order->get_transaction_id() === '') {
            return '';
        }
        $mode = (string) $order->get_meta(Payments::META_MODE);
        $base = $mode === 'live' || ($mode === '' && !Settings::isTestMode()) ? 'https://dashboard.stripe.com/payments/' : 'https://dashboard.stripe.com/test/payments/';
        return $base . rawurlencode($order->get_transaction_id());
    }

    /** @return string[] */
    public function supported_currencies(): array
    {
        return Amount::SUPPORTED;
    }

    /* ---- Availability ---------------------------------------------------------- */

    public function is_available()
    {
        if ($this->enabled !== 'yes' || !Settings::isConfigured()) {
            return false;
        }
        $order = $this->payPageOrder();
        if ($order) {
            // Stripe charges renewals itself; a renewal order is never paid here a second time.
            if (stackless_is_renewal_order($order) || !Amount::isSupported($order->get_currency()) || Eligibility::orderReason($order) !== Eligibility::OK) {
                return false;
            }
        } else {
            if (!Amount::isSupported(stackless_current_currency())) {
                return false;
            }
            if (function_exists('WC') && WC()->cart && did_action('wp_loaded')) {
                if (Eligibility::cartReason() !== Eligibility::OK) {
                    return false;
                }
                if ((float) WC()->cart->get_total('edit') <= 0 && !stackless_cart_contains_subscription()) {
                    return false;
                }
            }
        }
        return parent::is_available();
    }

    public function payPageOrder(): ?\WC_Order
    {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return null;
        }
        $order_id = absint(get_query_var('order-pay'));
        $order = $order_id ? wc_get_order($order_id) : false;
        return $order instanceof \WC_Order ? $order : null;
    }

    public function payment_fields()
    {
        $description = $this->get_description();
        if ($description !== '') {
            echo wp_kses_post(wpautop(wptexturize($description)));
        }
        if (Settings::isTestMode()) {
            echo '<p class="stackless-stripe-test-notice">' . esc_html__('Test mode: no real payment will be taken.', 'stackless-lite') . '</p>';
        }
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
        } catch (StripeApiException $e) {
            Settings::log('Managed Payments checkout for order ' . $order->get_id() . ' failed: ' . $e->logSummary(), 'error');
            $url = new \WP_Error('stackless_stripe_managed', self::apiErrorMessage($e));
        } catch (\Throwable $e) {
            Settings::log('Managed Payments checkout for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error');
            $url = new \WP_Error('stackless_stripe_managed', __('Stripe could not start the checkout. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if (is_wp_error($url)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Stripe Managed Payments checkout could not start: %s', 'stackless-lite'), $url->get_error_message()));
            return $this->failure($url->get_error_message());
        }
        return ['result' => 'success', 'redirect' => $url];
    }

    public static function apiErrorMessage(StripeApiException $e): string
    {
        if ($e->getStripeType() === 'invalid_request_error' && stripos($e->getMessage(), 'managed') !== false) {
            return __('Stripe Managed Payments is not available on this Stripe account yet. Choose another payment method.', 'stackless-lite');
        }
        return __('Stripe could not start the checkout. Please try again or choose another payment method.', 'stackless-lite');
    }

    /** @return array{result:string, message:string} */
    public function failure(string $message): array
    {
        if (!(defined('REST_REQUEST') && REST_REQUEST) && function_exists('WC') && WC()->session) {
            wc_add_notice($message, 'error');
        }
        return ['result' => 'failure', 'message' => $message];
    }

    public function can_refund_order($order)
    {
        return $order instanceof \WC_Order && (string) $order->get_meta(Payments::META_INTENT) !== '' && $order->is_paid();
    }

    /**
     * Refunds go through the Refunds API on the PaymentIntent; the refund includes the tax
     * Stripe collected as merchant of record.
     *
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return new \WP_Error('stackless_stripe_managed_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_total() - (float) $order->get_total_refunded() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_stripe_managed_refund', __('Refund amount must be greater than zero.', 'stackless-lite'));
        }
        $intent = (string) $order->get_meta(Payments::META_INTENT);
        if ($intent === '') {
            return new \WP_Error('stackless_stripe_managed_refund', __('No Stripe payment was found for this order.', 'stackless-lite'));
        }
        $currency = $order->get_currency();
        $minor = Amount::toMinor($amount, $currency);
        $refunds = $order->get_refunds();
        $latest = $refunds ? reset($refunds) : null;
        $refund_id = $latest instanceof \WC_Order_Refund ? $latest->get_id() : count($refunds);

        set_transient('stackless_stripe_refunding_' . $order->get_id(), 1, 120);
        try {
            $refund = self::clientFor($order)->post('refunds', [
                'payment_intent' => $intent,
                'amount' => $minor,
                'reason' => 'requested_by_customer',
                'metadata' => array_filter([
                    'order_id' => (string) $order->get_id(),
                    'wc_refund_id' => (string) $refund_id,
                    'stackless_site' => Settings::siteMarker(),
                    'reason' => substr(wp_strip_all_tags((string) $reason), 0, 500),
                ]),
            ], IdempotencyKey::refund($order->get_id(), (int) $refund_id, $minor));
        } catch (StripeApiException $e) {
            Settings::log('Managed Payments refund failed for order ' . $order->get_id() . ': ' . $e->logSummary(), 'error');
            return new \WP_Error('stackless_stripe_managed_refund', $e->getMessage());
        } finally {
            delete_transient('stackless_stripe_refunding_' . $order->get_id());
        }
        $status = (string) ($refund['status'] ?? '');
        if ($status === 'failed' || $status === 'canceled') {
            return new \WP_Error('stackless_stripe_managed_refund', __('Stripe declined the refund.', 'stackless-lite'));
        }
        $ids = $order->get_meta(Payments::META_REFUNDS);
        $ids = is_array($ids) ? $ids : [];
        $ids[] = (string) ($refund['id'] ?? '');
        $order->update_meta_data(Payments::META_REFUNDS, array_values(array_filter(array_unique($ids))));
        $order->save();
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: Stripe refund id, 3: refund status */
            __('Refunded %1$s through Stripe Managed Payments (%2$s, %3$s). Stripe refunds the tax it collected with it.', 'stackless-lite'),
            wc_price($amount, ['currency' => $currency]),
            (string) ($refund['id'] ?? ''),
            $status
        ));
        return true;
    }

    /* ---- Contracts\SubscriptionGateway ------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * Stripe Billing charges renewals on its own schedule, so nothing is charged here. The
     * renewal order is parked and completed when Stripe's invoice.paid webhook arrives.
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
        $renewal_order->add_order_note(__('Stripe bills this subscription itself. This order is marked paid when Stripe reports the renewal charge.', 'stackless-lite'));
        return ['result' => 'pending', 'message' => __('Waiting for Stripe to charge the renewal.', 'stackless-lite')];
    }

    /** Cancel at Stripe: at the end of the billing period, or immediately. */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        if (!$subscription instanceof \WC_Order) {
            return false;
        }
        $remote_id = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($remote_id === '') {
            return true; // Never reached Stripe, nothing to stop there.
        }
        if (!LiveStore::isLive()) {
            return false;
        }
        $client = self::clientFor($subscription);
        try {
            $remote = $client->get('subscriptions/' . rawurlencode($remote_id));
            $status = (string) ($remote['status'] ?? '');
            if (in_array($status, ['canceled', 'incomplete_expired'], true)) {
                return true;
            }
            $deferred = $at_period_end && in_array($status, ['active', 'trialing'], true);
            if ($deferred) {
                if (empty($remote['cancel_at_period_end'])) {
                    $client->post('subscriptions/' . rawurlencode($remote_id), ['cancel_at_period_end' => true], 'stackless-managed-cancel-end-' . $remote_id);
                }
            } else {
                $client->delete('subscriptions/' . rawurlencode($remote_id));
            }
        } catch (StripeApiException $e) {
            Settings::log('Cancel of ' . $remote_id . ' failed: ' . $e->logSummary(), 'error');
            return false;
        }
        $subscription->update_meta_data(self::META_CANCEL_AT_END, $deferred ? 'yes' : 'no');
        $subscription->save();
        $subscription->add_order_note($deferred
            ? __('Stripe subscription set to end at the close of the paid period.', 'stackless-lite')
            : __('Stripe subscription canceled immediately.', 'stackless-lite'));
        return true;
    }

    /* ---- Contracts\PausableGateway ----------------------------------------------- */

    /**
     * Pauses collection at Stripe: invoices Stripe would create while paused are voided, so the
     * customer is not charged, and collection resumes on $resume_at (or when resumed by hand).
     */
    public function stackless_pause_subscription($subscription, ?int $resume_at): bool
    {
        if (!$subscription instanceof \WC_Order || !LiveStore::isLive()) {
            return false;
        }
        $remote_id = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($remote_id === '') {
            return false;
        }
        $pause = ['behavior' => 'void'];
        if ($resume_at !== null && $resume_at > time()) {
            $pause['resumes_at'] = $resume_at;
        }
        try {
            self::clientFor($subscription)->post('subscriptions/' . rawurlencode($remote_id), ['pause_collection' => $pause], 'stackless-managed-pause-' . $remote_id . '-' . ($resume_at ?? 0) . '-' . gmdate('YmdH'));
        } catch (StripeApiException $e) {
            Settings::log('Pause of ' . $remote_id . ' failed: ' . $e->logSummary(), 'error');
            return false;
        }
        $subscription->add_order_note($resume_at
            /* translators: %s: date */
            ? sprintf(__('Stripe paused collection until %s.', 'stackless-lite'), wp_date(get_option('date_format'), $resume_at))
            : __('Stripe paused collection until the subscription is resumed.', 'stackless-lite'));
        return true;
    }

    public function stackless_resume_subscription($subscription): bool
    {
        if (!$subscription instanceof \WC_Order || !LiveStore::isLive()) {
            return false;
        }
        $remote_id = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if ($remote_id === '') {
            return false;
        }
        try {
            // An empty pause_collection clears it; Stripe bills again from the next period.
            self::clientFor($subscription)->post('subscriptions/' . rawurlencode($remote_id), ['pause_collection' => []], 'stackless-managed-resume-' . $remote_id . '-' . gmdate('YmdH'));
        } catch (StripeApiException $e) {
            Settings::log('Resume of ' . $remote_id . ' failed: ' . $e->logSummary(), 'error');
            return false;
        }
        $subscription->add_order_note(__('Stripe resumed collection.', 'stackless-lite'));
        return true;
    }
}
