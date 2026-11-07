<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Contracts\SavedMethodCharger for Stripe (2.0.0): charges a customer's saved payment
 * method for a new order outside the renewal schedule (post-purchase offers).
 *
 * The caller created the order and owns the customer's consent. This re-checks that the
 * token belongs to the order's customer and to this gateway, refuses on a copy of the live
 * store, and charges with one PaymentIntent keyed by the order. When the bank wants the
 * customer to authenticate, the order stays pending and the result points at a page where
 * the customer confirms (the order-pay page, which runs 3-D Secure in place).
 */
final class SavedCharges
{
    /**
     * @return array{result:string, message?:string, redirect?:string}
     */
    public static function charge(\WC_Order $order, \WC_Payment_Token $token, bool $on_session): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $refusal = self::refusal($order, $token);
        if ($refusal !== '') {
            Settings::log(sprintf('Saved-method charge for order %d refused: %s', $order->get_id(), $refusal), 'warning');
            return ['result' => 'failed', 'message' => $refusal];
        }
        if ($order->is_paid()) {
            return ['result' => 'success'];
        }

        $mode = Tokens::mode($token);
        $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
        $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
        $order->update_meta_data(Payments::META_PM, $token->get_token());
        $order->save();

        try {
            $intent = Payments::createPaymentIntent($order, [
                'payment_method' => $token->get_token(),
                'payment_method_type' => Tokens::methodType($token),
                'customer' => Tokens::customer($token),
            ], [
                'off_session' => !$on_session,
                'capture' => true,
                'flow' => 'pay',
                'mode' => $mode,
                'metadata' => ['stackless_saved_charge' => '1'],
                'idempotency_key' => self::idempotencyKey($order->get_id(), (int) $token->get_id(), $on_session),
            ]);
        } catch (StripeApiException $e) {
            return self::fromException($order, $e);
        }

        $outcome = Payments::applyPaymentIntent($order, $intent, 'saved_method');
        switch ($outcome) {
            case Payments::OUTCOME_PAID:
                return ['result' => 'success'];
            case Payments::OUTCOME_PROCESSING:
            case Payments::OUTCOME_HELD:
            case Payments::OUTCOME_BUSY:
            case Payments::OUTCOME_AUTHORIZED:
                return ['result' => 'pending'];
            case Payments::OUTCOME_ACTION:
                return self::actionRequired($order, (string) ($intent['client_secret'] ?? ''));
        }
        $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
        if ($error) {
            Declines::fromIntentError($order, $error);
            $order->save();
        }
        return ['result' => 'failed', 'message' => $error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic()];
    }

    /** Why the token may not pay this order, or '' when it may. */
    public static function refusal(\WC_Order $order, \WC_Payment_Token $token): string
    {
        $customer = (int) $order->get_customer_id();
        if ($order->get_payment_method() !== Settings::GATEWAY_ID) {
            return __('The order is not set to pay with Stripe.', 'stackless-lite');
        }
        if ($token->get_gateway_id() !== Settings::GATEWAY_ID || $customer <= 0 || (int) $token->get_user_id() !== $customer) {
            return __('That saved payment method does not belong to this customer.', 'stackless-lite');
        }
        if (Tokens::customer($token) === '' || $token->get_token() === '') {
            return __('That saved payment method is incomplete. Ask the customer to pay with a new method.', 'stackless-lite');
        }
        if (Tokens::mode($token) !== Settings::mode()) {
            return __('That saved payment method belongs to the other Stripe mode (test or live).', 'stackless-lite');
        }
        if (!$order->is_paid() && !$order->needs_payment()) {
            return __('The order cannot be paid in its current status.', 'stackless-lite');
        }
        $currency = $order->get_currency();
        if ((float) $order->get_total() <= 0 || !Amount::isSupported($currency) || !Amount::meetsMinimum($order->get_total(), $currency)) {
            return ErrorMapper::message('amount_too_small');
        }
        return '';
    }

    /** One key per order, token and session type, so a double click never charges twice. */
    public static function idempotencyKey(int $order_id, int $token_id, bool $on_session): string
    {
        return 'stackless-saved-' . $order_id . '-' . $token_id . ($on_session ? '-on' : '-off');
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    private static function fromException(\WC_Order $order, StripeApiException $e): array
    {
        $intent = $e->getPaymentIntent();
        $status = $intent ? (string) ($intent['status'] ?? '') : '';
        Declines::fromException($order, $e);
        $order->save();
        /* translators: %s: error message */
        $order->add_order_note(sprintf(__('Charging the saved Stripe payment method failed: %s', 'stackless-lite'), ErrorMapper::fromException($e) . ($e->getDeclineCode() ? ' (' . $e->getDeclineCode() . ')' : '')));
        Settings::log('Saved-method charge for order ' . $order->get_id() . ' failed: ' . $e->logSummary(), $e->getStripeType() === 'card_error' ? 'info' : 'error');
        if (ErrorMapper::isAuthenticationRequired($e->getStripeCode(), $e->getDeclineCode(), $status)) {
            // Off-session attempt the bank wants confirmed: the customer pays it on the order-pay page.
            return ['result' => 'action_required', 'message' => ErrorMapper::message('authentication_required'), 'redirect' => $order->get_checkout_payment_url()];
        }
        return ['result' => 'failed', 'message' => ErrorMapper::fromException($e)];
    }

    /** @return array{result:string, message:string, redirect:string} */
    private static function actionRequired(\WC_Order $order, string $client_secret): array
    {
        $redirect = $order->get_checkout_payment_url();
        if ($client_secret !== '') {
            // The order-pay page picks up the fragment and runs stripe.handleNextAction() in place.
            $redirect .= Gateway::confirmFragment('pay', 'pi', $order->get_id(), $order->get_order_key(), $client_secret);
        }
        return ['result' => 'action_required', 'message' => ErrorMapper::message('authentication_required'), 'redirect' => $redirect];
    }
}
