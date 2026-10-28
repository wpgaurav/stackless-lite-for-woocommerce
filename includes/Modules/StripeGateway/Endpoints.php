<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Browser-facing endpoints that finish a payment after Stripe asked for customer action.
 *
 * - wc-ajax=stackless_stripe_gateway_verify: called by the scripts after stripe.handleNextAction()
 *   (3D Secure in a modal). Returns JSON with a redirect or an error.
 * - ?wc-api=stackless_stripe_gateway_return: Stripe's return_url after a full-page redirect
 *   (bank redirects, some wallets). Redirects the shopper onward.
 *
 * Neither trusts an intent id from the browser: the order (checked by its order key) or the
 * signed-in user's pending setup is the source of truth, and the intent is re-read from Stripe.
 */
final class Endpoints
{
    public static function registerHooks(): void
    {
        add_action('wc_ajax_stackless_stripe_gateway_verify', [self::class, 'verify']);
        add_action('woocommerce_api_stackless_stripe_gateway_return', [self::class, 'handleReturn']);
    }

    public static function verify(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- authorised by the order key / signed-in user; the call only re-reads state from Stripe.
        $flow = isset($_POST['flow']) ? sanitize_key(wp_unslash((string) $_POST['flow'])) : '';
        $type = isset($_POST['type']) && $_POST['type'] === 'si' ? 'si' : 'pi';
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash((string) $_POST['order_key'])) : '';
        // phpcs:enable

        wc_nocache_headers();
        $result = self::finish($flow, $type, $order_id, $key);
        if ($result['result'] === 'success') {
            wp_send_json($result);
        }
        wp_send_json($result, 200);
    }

    public static function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorised by the order key / signed-in user.
        $flow = isset($_GET['flow']) ? sanitize_key(wp_unslash((string) $_GET['flow'])) : 'checkout';
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        $type = isset($_GET['setup_intent']) ? 'si' : 'pi';
        // phpcs:enable

        if ($flow !== 'add' && $order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order && $order->get_meta(Payments::META_SETUP_INTENT) && !$order->get_meta(Payments::META_INTENT)) {
                $type = 'si';
            }
        }

        $result = self::finish($flow, $type, $order_id, $key);
        if ($result['result'] !== 'success') {
            wc_add_notice($result['message'] ?? ErrorMapper::generic(), 'error');
        }
        wp_safe_redirect($result['redirect'] ?? wc_get_checkout_url());
        exit;
    }

    /**
     * @return array{result:string, redirect?:string, message?:string}
     */
    public static function finish(string $flow, string $type, int $order_id, string $key): array
    {
        if ($flow === 'add') {
            return self::finishAddPaymentMethod();
        }

        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || ($order->get_payment_method() !== Settings::GATEWAY_ID && $order->get_type() !== Gateway::SUBSCRIPTION_ORDER_TYPE)) {
            return ['result' => 'failure', 'message' => __('We could not find that order.', 'stackless-lite'), 'redirect' => wc_get_checkout_url()];
        }

        $gateway = Module::gateway();
        $failure_redirect = self::failureRedirect($order, $flow);
        $client = Settings::clientForOrder($order);

        try {
            if ($type === 'si') {
                $setup_intent_id = (string) $order->get_meta(Payments::META_SETUP_INTENT);
                if ($setup_intent_id === '') {
                    return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $failure_redirect];
                }
                $setup_intent = $client->get('setup_intents/' . rawurlencode($setup_intent_id), ['expand' => ['payment_method']]);
                $status = (string) ($setup_intent['status'] ?? '');

                if ($order->get_type() === Gateway::SUBSCRIPTION_ORDER_TYPE) {
                    if (($status === 'succeeded' || $status === 'processing') && $gateway->completeSubscriptionSetup($order, $setup_intent)) {
                        wc_add_notice(__('Payment method updated.', 'stackless-lite'));
                        return ['result' => 'success', 'redirect' => (string) apply_filters('stackless_stripe_gateway_change_payment_method_redirect', $order->get_view_order_url(), $order)];
                    }
                    return ['result' => 'failure', 'message' => self::intentErrorMessage($setup_intent, 'last_setup_error'), 'redirect' => $failure_redirect];
                }

                $outcome = Payments::applySetupIntent($order, $setup_intent, 'verify');
                if ($outcome === Payments::OUTCOME_BUSY && ($status === 'succeeded' || $status === 'processing')) {
                    // The webhook is applying it right now.
                    return ['result' => 'success', 'redirect' => $gateway->get_return_url($order)];
                }
                if ($outcome === Payments::OUTCOME_SAVED || $outcome === Payments::OUTCOME_PROCESSING) {
                    return ['result' => 'success', 'redirect' => $gateway->get_return_url($order)];
                }
                return ['result' => 'failure', 'message' => self::intentErrorMessage($setup_intent, 'last_setup_error'), 'redirect' => $failure_redirect];
            }

            $intent_id = (string) $order->get_meta(Payments::META_INTENT);
            if ($intent_id === '') {
                return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $failure_redirect];
            }
            $intent = $client->get('payment_intents/' . rawurlencode($intent_id), ['expand' => ['payment_method', 'latest_charge']]);
            $outcome = Payments::applyPaymentIntent($order, $intent, 'verify');
            if (in_array($outcome, [Payments::OUTCOME_PAID, Payments::OUTCOME_AUTHORIZED, Payments::OUTCOME_PROCESSING], true)) {
                $gateway->syncRenewalToken($order);
                return ['result' => 'success', 'redirect' => $gateway->get_return_url($order)];
            }
            $settled = in_array((string) ($intent['status'] ?? ''), ['succeeded', 'processing', 'requires_capture'], true);
            if ($outcome === Payments::OUTCOME_HELD || ($outcome === Payments::OUTCOME_BUSY && $settled)) {
                // Money taken: held for the merchant, or the webhook is applying it right now.
                return ['result' => 'success', 'redirect' => $gateway->get_return_url($order)];
            }
            return ['result' => 'failure', 'message' => self::intentErrorMessage($intent, 'last_payment_error'), 'redirect' => $failure_redirect];
        } catch (StripeApiException $e) {
            Settings::log('Verification failed for order ' . $order->get_id() . ': ' . $e->logSummary(), 'error');
            return ['result' => 'failure', 'message' => ErrorMapper::fromException($e), 'redirect' => $failure_redirect];
        }
    }

    /**
     * @return array{result:string, redirect?:string, message?:string}
     */
    private static function finishAddPaymentMethod(): array
    {
        $add_url = wc_get_endpoint_url('add-payment-method', '', wc_get_page_permalink('myaccount'));
        $user_id = get_current_user_id();
        $setup_intent_id = $user_id ? (string) get_user_meta($user_id, Gateway::USER_META_PENDING_SETUP, true) : '';
        if ($setup_intent_id === '') {
            return ['result' => 'failure', 'message' => __('Your session expired. Add the payment method again.', 'stackless-lite'), 'redirect' => $add_url];
        }
        $client = Settings::client();
        try {
            $setup_intent = $client->get('setup_intents/' . rawurlencode($setup_intent_id), ['expand' => ['payment_method']]);
            $customer = Customers::forUser($user_id, $client, false);
            if ($customer === '' || (string) ($setup_intent['customer'] ?? '') !== $customer) {
                return ['result' => 'failure', 'message' => ErrorMapper::generic(), 'redirect' => $add_url];
            }
            $status = (string) ($setup_intent['status'] ?? '');
            if (($status === 'succeeded' || $status === 'processing') && is_array($setup_intent['payment_method'] ?? null)) {
                delete_user_meta($user_id, Gateway::USER_META_PENDING_SETUP);
                if (Tokens::saveFromPaymentMethod($setup_intent['payment_method'], $user_id, $customer, $client->mode())) {
                    wc_add_notice(__('Payment method successfully added.', 'stackless-lite'));
                    return ['result' => 'success', 'redirect' => wc_get_account_endpoint_url('payment-methods')];
                }
            }
            return ['result' => 'failure', 'message' => self::intentErrorMessage($setup_intent, 'last_setup_error'), 'redirect' => $add_url];
        } catch (StripeApiException $e) {
            return ['result' => 'failure', 'message' => ErrorMapper::fromException($e), 'redirect' => $add_url];
        }
    }

    private static function failureRedirect(\WC_Order $order, string $flow): string
    {
        if ($order->get_type() === Gateway::SUBSCRIPTION_ORDER_TYPE) {
            return $order->get_view_order_url();
        }
        if ($flow === 'pay' || $flow === 'renewal') {
            return $order->get_checkout_payment_url();
        }
        return wc_get_checkout_url();
    }

    /** @param array<string,mixed> $intent */
    private static function intentErrorMessage(array $intent, string $field): string
    {
        $error = isset($intent[$field]) && is_array($intent[$field]) ? $intent[$field] : [];
        if ($error) {
            return ErrorMapper::fromIntentError($error);
        }
        if (($intent['status'] ?? '') === 'requires_action' || ($intent['status'] ?? '') === 'requires_payment_method') {
            return ErrorMapper::message('payment_intent_authentication_failure');
        }
        return ErrorMapper::generic();
    }
}
