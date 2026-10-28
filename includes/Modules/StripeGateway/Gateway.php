<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Admin\SettingsPage;
use Stackless\Contracts\SubscriptionData;
use Stackless\Contracts\SubscriptionGateway;

defined('ABSPATH') || exit;

/**
 * Stripe payment gateway for core WooCommerce: Payment Element, saved methods, SCA,
 * refunds, authorize-and-capture and off-session subscription renewals.
 *
 * Card data never reaches the server. The browser turns the Payment Element into a
 * ConfirmationToken (ctoken_...), and the server creates and confirms the PaymentIntent or
 * SetupIntent with it ("finalize payments on the server"). When the bank asks for 3D Secure
 * or a redirect, process_payment() returns a #stackless-stripe-confirm/... fragment that the
 * checkout scripts pick up, hand to stripe.handleNextAction(), and then verify server-side.
 */
class Gateway extends \WC_Payment_Gateway implements SubscriptionGateway
{
    /** Contract features this gateway supports (everything except gateway_managed). */
    public const STACKLESS_FEATURES = ['auto_renewal', 'multiple_subscriptions', 'zero_total_setup', 'payment_method_change', 'amount_change', 'date_change'];

    public const FIELD_CONFIRMATION_TOKEN = 'stackless_stripe_confirmation_token';
    public const SUBSCRIPTION_ORDER_TYPE = 'stackless_sub';
    public const USER_META_PENDING_SETUP = '_stackless_stripe_pending_setup_intent';

    public function __construct()
    {
        $this->id = Settings::GATEWAY_ID;
        $this->method_title = __('Stripe (Stackless)', 'stackless-lite');
        $this->method_description = __('Cards, wallets and local payment methods through the Stripe Payment Element, with saved methods and automatic subscription renewals.', 'stackless-lite');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method', 'stackless_subscriptions'];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = Settings::title();
        $this->description = Settings::description();
        $this->new_method_label = __('Use a new payment method', 'stackless-lite');
        $this->view_transaction_url = Settings::isTestMode() ? 'https://dashboard.stripe.com/test/payments/%s' : 'https://dashboard.stripe.com/payments/%s';

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, function (): void {
            $this->process_admin_options();
        });
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'stackless-lite'),
                'label' => __('Enable Stripe', 'stackless-lite'),
                'type' => 'checkbox',
                'default' => 'no',
            ],
        ];
    }

    public function admin_options()
    {
        parent::admin_options();
        printf(
            '<p>%s <a class="button" href="%s">%s</a></p>',
            esc_html__('API keys, webhooks, checkout appearance and express buttons live in the Stackless settings.', 'stackless-lite'),
            esc_url(SettingsPage::url(Settings::MODULE_ID)),
            esc_html__('Open Stripe settings', 'stackless-lite')
        );
    }

    /**
     * @param \WC_Order|null $order
     * @return string
     */
    public function get_transaction_url($order)
    {
        if ($order instanceof \WC_Order) {
            $mode = (string) $order->get_meta(Payments::META_MODE);
            $this->view_transaction_url = $mode === 'live' || ($mode === '' && !Settings::isTestMode())
                ? 'https://dashboard.stripe.com/payments/%s'
                : 'https://dashboard.stripe.com/test/payments/%s';
        }
        return parent::get_transaction_url($order);
    }

    public function is_available()
    {
        if (!parent::is_available() || !Settings::isConfigured()) {
            return false;
        }
        $context = $this->amountContext();
        if (!Amount::isSupported($context['currency'])) {
            return false;
        }
        if ($context['total'] > 0 && !Amount::meetsMinimum($context['total'], $context['currency'])) {
            return false;
        }
        return true;
    }

    /** @return string[] ISO codes, read by Currency-Aware Payment Gateways. */
    public function supported_currencies(): array
    {
        return Amount::SUPPORTED;
    }

    /* ---------------------------------------------------------------------
     * Checkout fields
     * ------------------------------------------------------------------- */

    public function payment_fields()
    {
        $context = $this->fieldContext();
        $show_tokens = is_user_logged_in() && $context['flow'] !== 'add' && (Settings::savedCardsEnabled() || $context['requires_save']);
        if ($show_tokens) {
            $this->tokenization_script();
        }
        stackless_get_template('stripe_gateway/payment-fields.php', [
            'gateway' => $this,
            'context' => $context,
            'description' => $this->get_description(),
            'test_mode' => Settings::isTestMode(),
            'show_tokens' => $show_tokens,
            'show_save' => !$context['requires_save'] && Settings::savedCardsEnabled() && is_user_logged_in() && in_array($context['flow'], ['checkout', 'pay'], true),
        ]);
    }

    /**
     * What the Payment Element needs on this page: flow, intent mode, amount and currency.
     *
     * @return array{flow:string, mode:string, amount:int, currency:string, requires_save:bool, capture:string}
     */
    public function fieldContext(): array
    {
        $flow = 'checkout';
        $order = $this->payPageOrder();
        if (function_exists('is_add_payment_method_page') && is_add_payment_method_page()) {
            $flow = 'add';
        } elseif ($order) {
            $flow = $order->get_type() === self::SUBSCRIPTION_ORDER_TYPE ? 'change' : 'pay';
        }

        if ($flow === 'add' || $flow === 'change') {
            return [
                'flow' => $flow,
                'mode' => 'setup',
                'amount' => 0,
                'currency' => $order ? $order->get_currency() : get_woocommerce_currency(),
                'requires_save' => true,
                'capture' => 'automatic',
            ];
        }

        if ($order) {
            $currency = $order->get_currency();
            $total = (float) $order->get_total();
            $requires_save = stackless_order_requires_saved_payment_method($order);
        } else {
            $currency = get_woocommerce_currency();
            $total = WC()->cart ? (float) WC()->cart->get_total('edit') : 0.0;
            $requires_save = stackless_cart_requires_saved_payment_method();
        }

        return [
            'flow' => $flow,
            'mode' => $total > 0 ? 'payment' : 'setup',
            'amount' => $total > 0 ? Amount::toMinor($total, $currency) : 0,
            'currency' => $currency,
            'requires_save' => $requires_save,
            'capture' => ($requires_save || Settings::captureNow()) ? 'automatic' : 'manual',
        ];
    }

    public function validate_fields()
    {
        if ($this->postedTokenId() > 0 || $this->postedConfirmationToken() !== '') {
            return true;
        }
        wc_add_notice(__('Enter your payment details to continue.', 'stackless-lite'), 'error');
        return false;
    }

    /* ---------------------------------------------------------------------
     * Payments
     * ------------------------------------------------------------------- */

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
        if ($order->get_type() === self::SUBSCRIPTION_ORDER_TYPE) {
            return $this->changeSubscriptionMethod($order);
        }

        $flow = $this->requestFlow();
        $requires_save = stackless_order_requires_saved_payment_method($order);
        $save = $requires_save || ($this->postedSaveRequest() && Settings::savedCardsEnabled() && $order->get_customer_id() > 0);
        $token_id = $this->postedTokenId();
        $confirmation_token = $this->postedConfirmationToken();
        $total = (float) $order->get_total();
        $capture = $requires_save || Settings::captureNow();

        try {
            if ($token_id > 0) {
                $token = Tokens::getOwned($token_id, (int) $order->get_customer_id());
                if (!$token || Tokens::mode($token) !== Settings::mode()) {
                    return $this->failure(__('That saved payment method is not available. Choose another one.', 'stackless-lite'));
                }
                $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
                $order->update_meta_data(Payments::META_PM, $token->get_token());
                $order->update_meta_data(Payments::META_MODE, Tokens::mode($token));
                $order->save();

                if ($total <= 0) {
                    $order->add_order_note(__('Saved Stripe payment method attached for future payments.', 'stackless-lite'));
                    $order->payment_complete();
                    return $this->success($order);
                }

                $intent = Payments::createPaymentIntent($order, [
                    'payment_method' => $token->get_token(),
                    'payment_method_type' => Tokens::methodType($token),
                    'customer' => Tokens::customer($token),
                ], ['save' => false, 'capture' => $capture, 'flow' => $flow, 'mode' => Tokens::mode($token)]);
                return $this->handlePaymentIntent($order, $intent, $flow);
            }

            if ($total <= 0 && !$requires_save && !$save) {
                $order->payment_complete();
                return $this->success($order);
            }

            if ($confirmation_token === '') {
                return $this->failure(__('Enter your payment details to continue.', 'stackless-lite'));
            }

            if ($total <= 0) {
                $client = Settings::client();
                $customer = Customers::forOrder($order, $client, true);
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
                $order->update_meta_data(Payments::META_MODE, $client->mode());
                $order->update_meta_data(Payments::META_SAVE, 'yes');
                $order->update_meta_data(Payments::META_FLOW, $flow);
                $order->save();
                $setup_intent = Payments::createSetupIntent(['confirmation_token' => $confirmation_token], [
                    'customer' => $customer,
                    'return_url' => Payments::returnUrl($order, $flow),
                    'metadata' => array_merge(Payments::metadata($order), ['stackless_flow' => 'order']),
                    'idempotency_key' => IdempotencyKey::setup('order-' . $order->get_id(), $confirmation_token),
                ]);
                Payments::rememberIntent($order, $setup_intent, $client->mode());
                return $this->handleSetupIntent($order, $setup_intent, $flow);
            }

            if (!Amount::meetsMinimum($order->get_total(), $order->get_currency())) {
                return $this->failure(ErrorMapper::message('amount_too_small'));
            }

            $intent = Payments::createPaymentIntent($order, ['confirmation_token' => $confirmation_token], [
                'save' => $save,
                'capture' => $capture,
                'flow' => $flow,
            ]);
            return $this->handlePaymentIntent($order, $intent, $flow);
        } catch (StripeApiException $e) {
            $message = ErrorMapper::fromException($e);
            /* translators: %s: error message */
            $order->add_order_note(sprintf(__('Stripe payment attempt failed: %s', 'stackless-lite'), $message . ($e->getDeclineCode() ? ' (' . $e->getDeclineCode() . ')' : '')));
            Settings::log('Checkout payment failed for order ' . $order->get_id() . ': ' . $e->logSummary(), $e->getStripeType() === 'card_error' ? 'info' : 'error');
            return $this->failure($message);
        } catch (\InvalidArgumentException $e) {
            return $this->failure(ErrorMapper::generic());
        }
    }

    /**
     * @param array<string,mixed> $intent
     * @return array<string,string>
     */
    private function handlePaymentIntent(\WC_Order $order, array $intent, string $flow): array
    {
        $outcome = Payments::applyPaymentIntent($order, $intent, $flow);
        switch ($outcome) {
            case Payments::OUTCOME_PAID:
            case Payments::OUTCOME_AUTHORIZED:
            case Payments::OUTCOME_PROCESSING:
                $this->syncRenewalToken($order);
                return $this->success($order);
            case Payments::OUTCOME_HELD:
                // Stripe took the money; the order waits on hold for the merchant.
                return $this->success($order);
            case Payments::OUTCOME_ACTION:
                return $this->actionResult($order, 'pi', (string) ($intent['client_secret'] ?? ''), $flow);
            case Payments::OUTCOME_BUSY:
                // The webhook is applying this intent right now; a settled one lands the customer on the thank-you page.
                if (in_array((string) ($intent['status'] ?? ''), ['succeeded', 'processing', 'requires_capture'], true)) {
                    return $this->success($order);
                }
                break;
        }
        $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
        return $this->failure($error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
    }

    /**
     * @param array<string,mixed> $setup_intent
     * @return array<string,string>
     */
    private function handleSetupIntent(\WC_Order $order, array $setup_intent, string $flow): array
    {
        $outcome = Payments::applySetupIntent($order, $setup_intent, $flow);
        if ($outcome === Payments::OUTCOME_SAVED || $outcome === Payments::OUTCOME_PROCESSING) {
            return $this->success($order);
        }
        if ($outcome === Payments::OUTCOME_BUSY && in_array((string) ($setup_intent['status'] ?? ''), ['succeeded', 'processing'], true)) {
            return $this->success($order);
        }
        if ($outcome === Payments::OUTCOME_ACTION) {
            return $this->actionResult($order, 'si', (string) ($setup_intent['client_secret'] ?? ''), $flow);
        }
        $error = isset($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error']) ? $setup_intent['last_setup_error'] : [];
        return $this->failure($error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
    }

    /* ---------------------------------------------------------------------
     * Saved methods: My Account → Add payment method, and subscription method changes
     * ------------------------------------------------------------------- */

    /** @return array<string,string> */
    public function add_payment_method()
    {
        $user_id = get_current_user_id();
        $confirmation_token = $this->postedConfirmationToken();
        if ($user_id <= 0 || $confirmation_token === '') {
            wc_add_notice(__('Enter your payment details to continue.', 'stackless-lite'), 'error');
            return ['result' => 'failure', 'redirect' => ''];
        }
        try {
            $client = Settings::client();
            $customer = Customers::forUser($user_id, $client, true);
            $setup_intent = Payments::createSetupIntent(['confirmation_token' => $confirmation_token], [
                'customer' => $customer,
                'return_url' => add_query_arg(['flow' => 'add'], WC()->api_request_url('stackless_stripe_gateway_return')),
                'metadata' => ['stackless_flow' => 'add', 'wp_user_id' => (string) $user_id],
                'idempotency_key' => IdempotencyKey::setup('user-' . $user_id, $confirmation_token),
            ]);
            $status = (string) ($setup_intent['status'] ?? '');
            if ($status === 'succeeded' || $status === 'processing') {
                $pm = is_array($setup_intent['payment_method'] ?? null) ? $setup_intent['payment_method'] : $client->get('payment_methods/' . rawurlencode((string) $setup_intent['payment_method']));
                $token = Tokens::saveFromPaymentMethod($pm, $user_id, $customer, $client->mode());
                if ($token) {
                    return ['result' => 'success', 'redirect' => wc_get_account_endpoint_url('payment-methods')];
                }
            } elseif ($status === 'requires_action') {
                update_user_meta($user_id, self::USER_META_PENDING_SETUP, (string) $setup_intent['id']);
                $url = wc_get_endpoint_url('add-payment-method', '', wc_get_page_permalink('myaccount'));
                return ['result' => 'pending', 'redirect' => $url . self::confirmFragment('add', 'si', 0, 'add', (string) $setup_intent['client_secret'])];
            } elseif (!empty($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error'])) {
                wc_add_notice(ErrorMapper::fromIntentError($setup_intent['last_setup_error']), 'error');
            }
        } catch (StripeApiException $e) {
            Settings::log('Add payment method failed: ' . $e->logSummary(), 'warning');
            wc_add_notice(ErrorMapper::fromException($e), 'error');
        }
        return ['result' => 'failure', 'redirect' => ''];
    }

    /**
     * process_payment() called with a subscription object: the customer is changing the
     * payment method a subscription renews with.
     *
     * @return array<string,string>
     */
    private function changeSubscriptionMethod(\WC_Order $subscription): array
    {
        $user_id = (int) $subscription->get_customer_id();
        $token_id = $this->postedTokenId();
        try {
            if ($token_id > 0) {
                $token = Tokens::getOwned($token_id, $user_id);
                if (!$token || Tokens::mode($token) !== Settings::mode()) {
                    return $this->failure(__('That saved payment method is not available. Choose another one.', 'stackless-lite'));
                }
                $this->updateSubscriptionMethod($subscription, $token);
                return ['result' => 'success', 'redirect' => $this->changeRedirect($subscription)];
            }

            $confirmation_token = $this->postedConfirmationToken();
            if ($confirmation_token === '') {
                return $this->failure(__('Enter your payment details to continue.', 'stackless-lite'));
            }
            $client = Settings::client();
            $customer = Customers::forOrder($subscription, $client, true);
            $setup_intent = Payments::createSetupIntent(['confirmation_token' => $confirmation_token], [
                'customer' => $customer,
                'return_url' => Payments::returnUrl($subscription, 'change'),
                'metadata' => ['stackless_flow' => 'change', 'subscription_id' => (string) $subscription->get_id(), 'wp_user_id' => (string) $user_id],
                'idempotency_key' => IdempotencyKey::setup('sub-' . $subscription->get_id(), $confirmation_token),
            ]);
            Payments::rememberIntent($subscription, $setup_intent, $client->mode());
            $status = (string) ($setup_intent['status'] ?? '');
            if ($status === 'succeeded' || $status === 'processing') {
                $this->completeSubscriptionSetup($subscription, $setup_intent);
                return ['result' => 'success', 'redirect' => $this->changeRedirect($subscription)];
            }
            if ($status === 'requires_action') {
                return $this->actionResult($subscription, 'si', (string) $setup_intent['client_secret'], 'change');
            }
            $error = isset($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error']) ? $setup_intent['last_setup_error'] : [];
            return $this->failure($error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
        } catch (StripeApiException $e) {
            Settings::log('Change payment method failed: ' . $e->logSummary(), 'warning');
            return $this->failure(ErrorMapper::fromException($e));
        }
    }

    /**
     * Saves the SetupIntent's method and points the subscription at it.
     *
     * @param array<string,mixed> $setup_intent
     */
    public function completeSubscriptionSetup(\WC_Order $subscription, array $setup_intent): bool
    {
        $mode = !empty($setup_intent['livemode']) ? 'live' : 'test';
        $pm = $setup_intent['payment_method'] ?? '';
        if (is_string($pm) && $pm !== '') {
            $pm = Settings::client($mode)->get('payment_methods/' . rawurlencode($pm));
        }
        if (!is_array($pm)) {
            return false;
        }
        $token = Tokens::saveFromPaymentMethod($pm, (int) $subscription->get_customer_id(), (string) ($setup_intent['customer'] ?? ''), $mode);
        if (!$token) {
            return false;
        }
        $this->updateSubscriptionMethod($subscription, $token);
        return true;
    }

    /** Points a subscription at a saved token. Public so the Subscriptions module or admin tools can call it. */
    public function updateSubscriptionMethod(\WC_Order $subscription, \WC_Payment_Token $token): void
    {
        $subscription->set_payment_method($this->id);
        $subscription->set_payment_method_title($this->get_title());
        $subscription->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
        $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
        $subscription->update_meta_data(Payments::META_PM, $token->get_token());
        $subscription->update_meta_data(Payments::META_MODE, Tokens::mode($token));
        $subscription->save();
        /* translators: %s: payment method name, e.g. "Visa ending in 4242" */
        $subscription->add_order_note(sprintf(__('Renewals now charge %s.', 'stackless-lite'), $token->get_display_name()));
        do_action('stackless_stripe_gateway_subscription_payment_method_updated', $subscription, $token);
    }

    /**
     * A customer who pays a failed renewal from the pay link with a different saved or new
     * method expects future renewals to use it: point the renewed subscriptions at that token.
     */
    public function syncRenewalToken(\WC_Order $order): void
    {
        if (!stackless_is_renewal_order($order)) {
            return;
        }
        $token_id = (int) $order->get_meta(SubscriptionData::META_TOKEN_ID);
        $token = $token_id > 0 ? \WC_Payment_Tokens::get($token_id) : null;
        if (!$token || $token->get_gateway_id() !== $this->id) {
            return;
        }
        foreach (stackless_get_order_subscriptions($order) as $subscription) {
            if ($subscription instanceof \WC_Order && (int) $subscription->get_meta(SubscriptionData::META_TOKEN_ID) !== $token_id) {
                $this->updateSubscriptionMethod($subscription, $token);
            }
        }
    }

    private function changeRedirect(\WC_Order $subscription): string
    {
        return (string) apply_filters('stackless_stripe_gateway_change_payment_method_redirect', $subscription->get_view_order_url(), $subscription);
    }

    /* ---------------------------------------------------------------------
     * Refunds
     * ------------------------------------------------------------------- */

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
            return new \WP_Error('stackless_stripe_refund', __('Order not found.', 'stackless-lite'));
        }
        $amount = $amount === null ? (float) $order->get_total() - (float) $order->get_total_refunded() : (float) $amount;
        if ($amount <= 0) {
            return new \WP_Error('stackless_stripe_refund', __('Refund amount must be greater than zero.', 'stackless-lite'));
        }

        $intent_id = (string) $order->get_meta(Payments::META_INTENT);
        if ($intent_id === '' && strpos((string) $order->get_transaction_id(), 'pi_') === 0) {
            $intent_id = (string) $order->get_transaction_id();
        }
        if ($intent_id === '') {
            return new \WP_Error('stackless_stripe_refund', __('No Stripe payment was found for this order.', 'stackless-lite'));
        }

        if ($order->get_meta(Payments::META_CAPTURED) === 'no') {
            if (abs($amount - (float) $order->get_total()) < 0.005) {
                return Payments::voidAuthorization($order)
                    ? true
                    : new \WP_Error('stackless_stripe_refund', __('The Stripe authorization could not be released.', 'stackless-lite'));
            }
            return new \WP_Error('stackless_stripe_refund', __('This payment is only authorized. Capture it first (set the order to Processing), then refund part of it.', 'stackless-lite'));
        }

        $currency = $order->get_currency();
        $minor = Amount::toMinor($amount, $currency);
        $refunds = $order->get_refunds();
        $latest = $refunds ? reset($refunds) : null;
        $refund_id = $latest instanceof \WC_Order_Refund ? $latest->get_id() : count($refunds);

        $metadata = array_filter([
            'order_id' => (string) $order->get_id(),
            'wc_refund_id' => (string) $refund_id,
            'stackless_site' => Settings::siteMarker(),
            'reason' => substr(wp_strip_all_tags((string) $reason), 0, 500),
        ]);

        set_transient('stackless_stripe_refunding_' . $order->get_id(), 1, 120);
        try {
            $refund = Settings::clientForOrder($order)->post('refunds', [
                'payment_intent' => $intent_id,
                'amount' => $minor,
                'reason' => 'requested_by_customer',
                'metadata' => $metadata,
            ], IdempotencyKey::refund($order->get_id(), (int) $refund_id, $minor));

            $status = (string) ($refund['status'] ?? '');
            if ($status === 'failed' || $status === 'canceled') {
                return new \WP_Error('stackless_stripe_refund', __('Stripe declined the refund.', 'stackless-lite'));
            }
            $ids = $order->get_meta(Payments::META_REFUNDS);
            $ids = is_array($ids) ? $ids : [];
            $ids[] = (string) ($refund['id'] ?? '');
            $order->update_meta_data(Payments::META_REFUNDS, array_values(array_filter(array_unique($ids))));
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: amount, 2: Stripe refund id, 3: refund status */
                __('Refunded %1$s through Stripe (%2$s, %3$s).', 'stackless-lite'),
                wc_price($amount, ['currency' => $currency]),
                (string) ($refund['id'] ?? ''),
                $status
            ));
            return true;
        } catch (StripeApiException $e) {
            Settings::log('Refund failed for order ' . $order->get_id() . ': ' . $e->logSummary(), 'error');
            return new \WP_Error('stackless_stripe_refund', $e->getMessage());
        } finally {
            delete_transient('stackless_stripe_refunding_' . $order->get_id());
        }
    }

    /* ---------------------------------------------------------------------
     * Contracts\SubscriptionGateway
     * ------------------------------------------------------------------- */

    public function stackless_supports(string $feature): bool
    {
        return in_array($feature, self::STACKLESS_FEATURES, true);
    }

    /**
     * @param \WC_Order $subscription SubscriptionData&WC_Order
     * @return array{result:string, message?:string}
     */
    public function stackless_charge_renewal(\WC_Order $renewal_order, $subscription): array
    {
        return Renewals::charge($renewal_order, $subscription instanceof \WC_Order ? $subscription : null);
    }

    /**
     * Stripe does not run the schedule (the Subscriptions module does), so nothing to cancel remotely.
     *
     * @param \WC_Order $subscription
     */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool
    {
        return true;
    }

    /* ---------------------------------------------------------------------
     * Request helpers
     * ------------------------------------------------------------------- */

    /** Fragment the front-end scripts turn into stripe.handleNextAction() + server verification. */
    public static function confirmFragment(string $flow, string $type, int $order_id, string $key, string $client_secret): string
    {
        return '#stackless-stripe-confirm/' . rawurlencode($flow) . '/' . rawurlencode($type) . '/' . $order_id . '/' . rawurlencode($key) . '/' . rawurlencode($client_secret);
    }

    /** @return array<string,string> */
    private function actionResult(\WC_Order $order, string $type, string $client_secret, string $flow): array
    {
        if ($client_secret === '') {
            return $this->failure(ErrorMapper::generic());
        }
        $fragment = self::confirmFragment($flow, $type, $order->get_id(), $order->get_order_key(), $client_secret);
        if ($flow === 'pay') {
            $redirect = $order->get_checkout_payment_url() . $fragment;
        } elseif ($flow === 'change') {
            $referer = wp_get_referer();
            $base = $referer ? strtok($referer, '#') : $order->get_checkout_payment_url();
            $redirect = $base . $fragment;
        } else {
            $redirect = $fragment;
        }
        return [
            'result' => 'success',
            'redirect' => $redirect,
            'stackless_stripe_action' => 'confirm',
            'stackless_stripe_flow' => $flow,
            'stackless_stripe_intent_type' => $type,
            'stackless_stripe_client_secret' => $client_secret,
            'stackless_stripe_order_id' => (string) $order->get_id(),
            'stackless_stripe_order_key' => $order->get_order_key(),
        ];
    }

    /** @return array<string,string> */
    private function success(\WC_Order $order): array
    {
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    /** @return array<string,string> */
    private function failure(string $message): array
    {
        // The blocks checkout reads "message"; the classic checkout and pay page read notices.
        if (!$this->isRestRequest() && function_exists('WC') && WC()->session) {
            wc_add_notice($message, 'error');
        }
        return ['result' => 'failure', 'message' => $message];
    }

    private function isRestRequest(): bool
    {
        return (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request());
    }

    private function requestFlow(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout/pay nonce before calling the gateway.
        if (!empty($_POST['stackless_stripe_express'])) {
            return 'express';
        }
        if ($this->isRestRequest()) {
            return 'blocks';
        }
        if (isset($_POST['woocommerce_pay']) || (function_exists('is_checkout_pay_page') && is_checkout_pay_page())) {
            return 'pay';
        }
        // phpcs:enable
        return 'checkout';
    }

    private function postedTokenId(): int
    {
        $key = 'wc-' . $this->id . '-payment-token';
        $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return ($value !== '' && $value !== 'new') ? absint($value) : 0;
    }

    private function postedConfirmationToken(): string
    {
        $value = isset($_POST[self::FIELD_CONFIRMATION_TOKEN]) ? sanitize_text_field(wp_unslash((string) $_POST[self::FIELD_CONFIRMATION_TOKEN])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return preg_match('/^ctoken_[A-Za-z0-9]+$/', $value) ? $value : '';
    }

    private function postedSaveRequest(): bool
    {
        $key = 'wc-' . $this->id . '-new-payment-method';
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }
        $value = strtolower(sanitize_text_field(wp_unslash((string) $_POST[$key]))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** The order on an order-pay page, validated against its key. */
    private function payPageOrder(): ?\WC_Order
    {
        if (!function_exists('is_checkout_pay_page') || !is_checkout_pay_page()) {
            return null;
        }
        global $wp;
        $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key)) {
            return null;
        }
        return $order;
    }

    /** @return array{currency:string, total:float} */
    private function amountContext(): array
    {
        $order = $this->payPageOrder();
        if ($order) {
            return ['currency' => $order->get_currency(), 'total' => (float) $order->get_total()];
        }
        $total = 0.0;
        if (function_exists('WC') && WC()->cart && did_action('wp_loaded')) {
            $total = (float) WC()->cart->get_total('edit');
        }
        return ['currency' => get_woocommerce_currency(), 'total' => $total];
    }
}
