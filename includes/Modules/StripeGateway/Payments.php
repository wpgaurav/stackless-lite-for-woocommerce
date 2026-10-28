<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * PaymentIntent / SetupIntent engine shared by checkout, order-pay, express checkout,
 * renewals, the verify endpoint, redirect returns and webhooks.
 *
 * Every state change goes through applyPaymentIntent()/applySetupIntent(), which re-read
 * the order under a lock, so the browser confirmation and the webhook can race safely.
 */
final class Payments
{
    public const META_INTENT = '_stackless_stripe_intent_id';
    public const META_INTENTS = '_stackless_stripe_intent_ids';
    public const META_SETUP_INTENT = '_stackless_stripe_setup_intent_id';
    public const META_PM = '_stackless_stripe_payment_method';
    public const META_MODE = '_stackless_stripe_mode';
    public const META_CHARGE = '_stackless_stripe_charge_id';
    public const META_CAPTURED = '_stackless_stripe_captured';
    public const META_SAVE = '_stackless_stripe_save_method';
    public const META_OFF_SESSION = '_stackless_stripe_off_session';
    public const META_REFUNDS = '_stackless_stripe_refund_ids';
    public const META_DISPUTE = '_stackless_stripe_dispute_id';
    public const META_FLOW = '_stackless_stripe_flow';
    public const META_AMOUNT_MISMATCH = '_stackless_stripe_amount_mismatch';

    public const OUTCOME_PAID = 'paid';
    public const OUTCOME_AUTHORIZED = 'authorized';
    public const OUTCOME_PROCESSING = 'processing';
    public const OUTCOME_SAVED = 'saved';
    public const OUTCOME_ACTION = 'requires_action';
    public const OUTCOME_FAILED = 'failed';
    /** Another request held the order lock the whole time; nothing was applied. */
    public const OUTCOME_BUSY = 'busy';
    /** The intent's amount or currency does not match the order; the order was put on hold. */
    public const OUTCOME_HELD = 'held';

    /**
     * Creates and confirms a PaymentIntent for an order.
     *
     * @param array{confirmation_token?:string, payment_method?:string, payment_method_type?:string, customer?:string} $source
     * @param array{save?:bool, off_session?:bool, capture?:bool, flow?:string, idempotency_key?:string, mode?:string, metadata?:array<string,string>} $options
     * @return array<string,mixed> PaymentIntent
     * @throws StripeApiException
     */
    public static function createPaymentIntent(\WC_Order $order, array $source, array $options = []): array
    {
        $client = Settings::client($options['mode'] ?? null);
        $save = !empty($options['save']);
        $off_session = !empty($options['off_session']);
        $capture = array_key_exists('capture', $options) ? (bool) $options['capture'] : true;
        $flow = (string) ($options['flow'] ?? 'checkout');
        $currency = $order->get_currency();

        if (!$off_session) {
            $previous = self::settlePreviousIntent($order, $client);
            if ($previous !== null) {
                return $previous;
            }
        }

        $params = [
            'amount' => Amount::toMinor($order->get_total(), $currency),
            'currency' => strtolower($currency),
            'confirm' => true,
            'capture_method' => $capture ? 'automatic' : 'manual',
            'description' => self::description($order),
            'metadata' => array_merge(self::metadata($order), $options['metadata'] ?? []),
            'expand' => ['payment_method', 'latest_charge'],
        ];
        $suffix = Settings::statementDescriptorSuffix();
        if ($suffix !== '') {
            $params['statement_descriptor_suffix'] = $suffix;
        }

        $customer = (string) ($source['customer'] ?? '');
        if ($customer === '' && ($save || $order->get_customer_id() > 0)) {
            $customer = Customers::forOrder($order, $client, true);
        }
        if ($customer !== '') {
            $params['customer'] = $customer;
        }

        if (!empty($source['confirmation_token'])) {
            $params['confirmation_token'] = $source['confirmation_token'];
            $params['automatic_payment_methods'] = ['enabled' => true];
            $params['return_url'] = self::returnUrl($order, $flow);
        } elseif (!empty($source['payment_method'])) {
            $params['payment_method'] = $source['payment_method'];
            $params['payment_method_types'] = [$source['payment_method_type'] ?? 'card'];
            if (!$off_session) {
                $params['return_url'] = self::returnUrl($order, $flow);
            }
        } else {
            throw new StripeApiException(esc_html__('No payment method was provided.', 'stackless-lite'), 400, ['type' => 'invalid_request_error', 'code' => 'payment_method_missing']);
        }

        if ($off_session) {
            $params['off_session'] = true;
        } elseif ($save) {
            $params['setup_future_usage'] = 'off_session';
        }

        $order->update_meta_data(self::META_MODE, $client->mode());
        $order->update_meta_data(self::META_SAVE, $save ? 'yes' : 'no');
        $order->update_meta_data(self::META_FLOW, $flow);
        if ($off_session) {
            $order->update_meta_data(self::META_OFF_SESSION, 'yes');
        } else {
            // The customer is paying now (a failed renewal's pay link): this attempt is not the earlier off-session one.
            $order->delete_meta_data(self::META_OFF_SESSION);
        }
        if ($customer !== '') {
            $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $order->save();

        $key = $options['idempotency_key'] ?? IdempotencyKey::order($order->get_id(), self::sourceFingerprint($source) . '|' . $params['amount'] . '|' . $params['currency']);

        try {
            $intent = $client->post('payment_intents', $params, $key);
        } catch (StripeApiException $e) {
            if ($e->isResourceMissing() && $e->getParam() === 'customer' && empty($source['customer'])) {
                // Customer deleted in the Stripe Dashboard: forget it and try once with a new one.
                Customers::forget((int) $order->get_customer_id(), $client->mode(), $customer);
                $order->delete_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER);
                $order->save();
                $params['customer'] = Customers::forOrder($order, $client, true);
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $params['customer']);
                $order->save();
                $intent = $client->post('payment_intents', $params, $key . '-c');
            } else {
                $failed = $e->getPaymentIntent();
                if ($failed) {
                    self::rememberIntent($order, $failed, $client->mode());
                }
                throw $e;
            }
        }

        self::rememberIntent($order, $intent, $client->mode());
        return $intent;
    }

    /**
     * Before a new attempt on the same order: if an earlier PaymentIntent already took the
     * money (or is still processing it), return it instead of charging again; if it is still
     * waiting for the customer, cancel it so two attempts can never both succeed.
     *
     * @return array<string,mixed>|null The earlier intent when it must be used instead.
     */
    private static function settlePreviousIntent(\WC_Order $order, StripeClient $client): ?array
    {
        $previous = (string) $order->get_meta(self::META_INTENT);
        if ($previous === '' || (string) $order->get_meta(self::META_MODE) !== $client->mode()) {
            return null;
        }
        try {
            $intent = $client->get('payment_intents/' . rawurlencode($previous), ['expand' => ['payment_method', 'latest_charge']]);
        } catch (StripeApiException $e) {
            return null;
        }
        $status = (string) ($intent['status'] ?? '');
        if (in_array($status, ['succeeded', 'processing', 'requires_capture'], true)) {
            Settings::log(sprintf('Order %d already has %s in status %s; not charging again.', $order->get_id(), $previous, $status), 'warning');
            return $intent;
        }
        if (in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            try {
                $client->post('payment_intents/' . rawurlencode($previous) . '/cancel', ['cancellation_reason' => 'abandoned'], 'stackless-cancel-' . $previous);
            } catch (StripeApiException $e) {
                // Cancelling fails when the customer just completed it elsewhere: use that result.
                try {
                    $intent = $client->get('payment_intents/' . rawurlencode($previous), ['expand' => ['payment_method', 'latest_charge']]);
                    if (in_array((string) ($intent['status'] ?? ''), ['succeeded', 'processing', 'requires_capture'], true)) {
                        return $intent;
                    }
                } catch (StripeApiException $ignored) {
                    // Fall through to a fresh attempt.
                }
                Settings::log('Could not cancel superseded intent ' . $previous . ': ' . $e->logSummary(), 'warning');
            }
        }
        return null;
    }

    /**
     * Creates and confirms a SetupIntent that saves a method for later off-session use.
     *
     * @param array{confirmation_token:string} $source
     * @param array{customer:string, return_url:string, metadata?:array<string,string>, idempotency_key?:string, mode?:string} $options
     * @return array<string,mixed> SetupIntent
     * @throws StripeApiException
     */
    public static function createSetupIntent(array $source, array $options): array
    {
        $client = Settings::client($options['mode'] ?? null);
        $params = [
            'confirm' => true,
            'confirmation_token' => $source['confirmation_token'],
            'customer' => $options['customer'],
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
            'return_url' => $options['return_url'],
            'metadata' => array_merge(['stackless_site' => Settings::siteMarker()], $options['metadata'] ?? []),
            'expand' => ['payment_method'],
        ];
        $key = $options['idempotency_key'] ?? IdempotencyKey::setup('si', $source['confirmation_token']);
        return $client->post('setup_intents', $params, $key);
    }

    /**
     * Records the intent on the order (current id, history, mode) without changing status.
     *
     * @param array<string,mixed> $intent
     */
    public static function rememberIntent(\WC_Order $order, array $intent, string $mode): void
    {
        $id = (string) ($intent['id'] ?? '');
        if ($id === '') {
            return;
        }
        if (strpos($id, 'seti_') === 0) {
            $order->update_meta_data(self::META_SETUP_INTENT, $id);
        } else {
            $order->update_meta_data(self::META_INTENT, $id);
            $history = $order->get_meta(self::META_INTENTS);
            $history = is_array($history) ? $history : [];
            if (!in_array($id, $history, true)) {
                $history[] = $id;
                $order->update_meta_data(self::META_INTENTS, array_slice($history, -20));
            }
        }
        $order->update_meta_data(self::META_MODE, $mode);
        $order->save();
    }

    /** True when the intent id was created for this order. */
    public static function orderOwnsIntent(\WC_Order $order, string $intent_id): bool
    {
        if ($intent_id === '') {
            return false;
        }
        if ($order->get_meta(self::META_INTENT) === $intent_id || $order->get_meta(self::META_SETUP_INTENT) === $intent_id) {
            return true;
        }
        $history = $order->get_meta(self::META_INTENTS);
        return is_array($history) && in_array($intent_id, $history, true);
    }

    /**
     * Applies a PaymentIntent's current state to its order. Safe to call repeatedly.
     *
     * @param array<string,mixed> $intent
     */
    public static function applyPaymentIntent(\WC_Order $order, array $intent, string $via): string
    {
        $order_id = $order->get_id();
        $lock = self::lock($order_id);
        if ($lock === null) {
            Settings::log(sprintf('Order %d is locked by another request; %s via %s not applied.', $order_id, (string) ($intent['id'] ?? ''), $via), 'warning');
            return self::OUTCOME_BUSY;
        }
        try {
            $fresh = \Stackless\Core\FreshOrder::get($order_id);
            $order = $fresh ?: $order;
            $intent_id = (string) ($intent['id'] ?? '');
            $status = (string) ($intent['status'] ?? '');
            $mode = !empty($intent['livemode']) ? 'live' : 'test';

            switch ($status) {
                case 'succeeded':
                    if (!self::orderAwaitingPayment($order)) {
                        return self::OUTCOME_PAID;
                    }
                    if (!self::intentMatchesOrder($order, $intent)) {
                        return self::holdForMismatch($order, $intent, $via);
                    }
                    self::maybeSaveMethod($order, $intent, $mode);
                    self::recordCharge($order, $intent);
                    $order->update_meta_data(self::META_CAPTURED, 'yes');
                    $order->save();
                    /* translators: 1: PaymentIntent id, 2: how the result arrived (checkout, webhook, renewal...) */
                    $order->add_order_note(sprintf(__('Stripe payment succeeded (%1$s, via %2$s).', 'stackless-lite'), $intent_id, $via));
                    $order->payment_complete($intent_id);
                    Settings::log(sprintf('Order %d paid by %s via %s.', $order_id, $intent_id, $via), 'info');
                    return self::OUTCOME_PAID;

                case 'requires_capture':
                    if ($order->get_meta(self::META_CAPTURED) === 'no' && $order->has_status('on-hold')) {
                        return self::OUTCOME_AUTHORIZED;
                    }
                    if (!$order->needs_payment()) {
                        return self::OUTCOME_AUTHORIZED;
                    }
                    if (!self::intentMatchesOrder($order, $intent)) {
                        return self::holdForMismatch($order, $intent, $via);
                    }
                    self::maybeSaveMethod($order, $intent, $mode);
                    self::recordCharge($order, $intent);
                    $order->set_transaction_id($intent_id);
                    $order->update_meta_data(self::META_CAPTURED, 'no');
                    $order->save();
                    wc_maybe_reduce_stock_levels($order_id);
                    $order->update_status('on-hold', sprintf(
                        /* translators: %s: PaymentIntent id */
                        __('Stripe authorized the payment (%s). Change the order to Processing or Completed to capture it; authorizations expire after 7 days.', 'stackless-lite'),
                        $intent_id
                    ));
                    return self::OUTCOME_AUTHORIZED;

                case 'processing':
                    if (!$order->needs_payment()) {
                        return $order->has_status('on-hold') ? self::OUTCOME_PROCESSING : self::OUTCOME_PAID;
                    }
                    self::maybeSaveMethod($order, $intent, $mode);
                    $order->set_transaction_id($intent_id);
                    $order->save();
                    if ($order->get_meta(self::META_OFF_SESSION) === 'yes') {
                        // Renewals stay pending so the Subscriptions module keeps its own state; the webhook completes them.
                        /* translators: %s: PaymentIntent id */
                        $order->add_order_note(sprintf(__('Stripe is processing the renewal payment (%s). The order updates when the bank confirms.', 'stackless-lite'), $intent_id));
                    } else {
                        wc_maybe_reduce_stock_levels($order_id);
                        /* translators: %s: PaymentIntent id */
                        $order->update_status('on-hold', sprintf(__('Stripe is processing the payment (%s). The order updates when the bank confirms it.', 'stackless-lite'), $intent_id));
                    }
                    return self::OUTCOME_PROCESSING;

                case 'requires_action':
                case 'requires_confirmation':
                    return self::OUTCOME_ACTION;

                case 'canceled':
                    if ($order->needs_payment() || $order->has_status('on-hold')) {
                        /* translators: %s: PaymentIntent id */
                        $order->add_order_note(sprintf(__('Stripe PaymentIntent %s was canceled.', 'stackless-lite'), $intent_id));
                    }
                    return self::OUTCOME_FAILED;

                case 'requires_payment_method':
                default:
                    $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
                    if ($error && self::orderAwaitingPayment($order)) {
                        $message = ErrorMapper::fromIntentError($error);
                        /* translators: 1: PaymentIntent id, 2: customer-facing reason */
                        $note = sprintf(__('Stripe payment failed (%1$s): %2$s', 'stackless-lite'), $intent_id, $message);
                        if ($order->has_status('on-hold') || $order->get_meta(self::META_OFF_SESSION) === 'yes') {
                            $order->update_status('failed', $note);
                        } else {
                            $order->add_order_note($note);
                        }
                    }
                    return self::OUTCOME_FAILED;
            }
        } finally {
            self::unlock($order_id, $lock);
        }
    }

    /**
     * True when the intent charges the order's current total in the order's currency. The order
     * can change after the intent was created (an admin edit or a recalculation while the
     * customer is in 3D Secure), and the intent then pays for the old amount.
     *
     * @param array<string,mixed> $intent
     */
    public static function intentMatchesOrder(\WC_Order $order, array $intent): bool
    {
        $currency = $order->get_currency();
        if (strtolower((string) ($intent['currency'] ?? '')) !== strtolower($currency)) {
            return false;
        }
        try {
            return (int) ($intent['amount'] ?? -1) === Amount::toMinor($order->get_total(), $currency);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Leaves the order unpaid and on hold for a person to check, noting it once per intent.
     *
     * @param array<string,mixed> $intent
     */
    private static function holdForMismatch(\WC_Order $order, array $intent, string $via): string
    {
        $intent_id = (string) ($intent['id'] ?? '');
        if ((string) $order->get_meta(self::META_AMOUNT_MISMATCH) === $intent_id) {
            return self::OUTCOME_HELD;
        }
        $intent_currency = strtoupper((string) ($intent['currency'] ?? ''));
        $charged = $intent_currency !== ''
            ? Amount::fromMinor((int) ($intent['amount'] ?? 0), $intent_currency) . ' ' . $intent_currency
            : '?';
        $order->update_meta_data(self::META_AMOUNT_MISMATCH, $intent_id);
        $order->set_transaction_id($intent_id);
        $order->save();
        $order->update_status('on-hold', sprintf(
            /* translators: 1: PaymentIntent id, 2: amount on the intent, 3: order total */
            __('Stripe PaymentIntent %1$s is for %2$s but the order total is now %3$s. The order was not marked paid; check the payment in Stripe before fulfilling.', 'stackless-lite'),
            $intent_id,
            $charged,
            $order->get_total() . ' ' . strtoupper($order->get_currency())
        ));
        Settings::log(sprintf('Order %d: %s amount or currency does not match the order (via %s); put on hold.', $order->get_id(), $intent_id, $via), 'warning');
        return self::OUTCOME_HELD;
    }

    /**
     * Applies a SetupIntent to a zero-total order: saves the method, then completes the order.
     *
     * @param array<string,mixed> $setup_intent
     */
    public static function applySetupIntent(\WC_Order $order, array $setup_intent, string $via): string
    {
        $order_id = $order->get_id();
        $lock = self::lock($order_id);
        if ($lock === null) {
            Settings::log(sprintf('Order %d is locked by another request; %s via %s not applied.', $order_id, (string) ($setup_intent['id'] ?? ''), $via), 'warning');
            return self::OUTCOME_BUSY;
        }
        try {
            $fresh = \Stackless\Core\FreshOrder::get($order_id);
            $order = $fresh ?: $order;
            $status = (string) ($setup_intent['status'] ?? '');
            $id = (string) ($setup_intent['id'] ?? '');
            $mode = !empty($setup_intent['livemode']) ? 'live' : 'test';

            if ($status === 'succeeded' || $status === 'processing') {
                if (!self::orderAwaitingPayment($order)) {
                    return self::OUTCOME_SAVED;
                }
                $token = self::saveMethodForOrder($order, $setup_intent['payment_method'] ?? '', (string) ($setup_intent['customer'] ?? ''), $mode);
                if (!$token && !$order->get_meta(self::META_PM)) {
                    $order->add_order_note(__('Stripe confirmed the setup but the payment method could not be saved.', 'stackless-lite'));
                    return self::OUTCOME_FAILED;
                }
                /* translators: 1: SetupIntent id, 2: how the result arrived */
                $order->add_order_note(sprintf(__('Payment method saved for future payments (%1$s, via %2$s).', 'stackless-lite'), $id, $via));
                if ($status === 'processing') {
                    $order->update_status('on-hold', __('Stripe is verifying the saved payment method.', 'stackless-lite'));
                    return self::OUTCOME_PROCESSING;
                }
                $order->payment_complete();
                return self::OUTCOME_SAVED;
            }
            if ($status === 'requires_action' || $status === 'requires_confirmation') {
                return self::OUTCOME_ACTION;
            }
            $error = isset($setup_intent['last_setup_error']) && is_array($setup_intent['last_setup_error']) ? $setup_intent['last_setup_error'] : [];
            if ($error) {
                /* translators: 1: SetupIntent id, 2: reason */
                $order->add_order_note(sprintf(__('Saving the payment method failed (%1$s): %2$s', 'stackless-lite'), $id, ErrorMapper::fromIntentError($error)));
            }
            return self::OUTCOME_FAILED;
        } finally {
            self::unlock($order_id, $lock);
        }
    }

    /**
     * Saves the intent's payment method when the order asked for it (subscription or the
     * customer ticked "save"), and writes the token + customer meta the Subscriptions module reads.
     *
     * @param array<string,mixed> $intent
     */
    public static function maybeSaveMethod(\WC_Order $order, array $intent, string $mode): ?\WC_Payment_Token
    {
        $wanted = $order->get_meta(self::META_SAVE) === 'yes' || stackless_order_requires_saved_payment_method($order);
        if (!$wanted || $order->get_meta(self::META_OFF_SESSION) === 'yes') {
            return null;
        }
        $pm = $intent['payment_method'] ?? '';
        $pm_id = is_array($pm) ? (string) ($pm['id'] ?? '') : (string) $pm;
        $existing = (int) $order->get_meta(SubscriptionData::META_TOKEN_ID);
        $token = $existing > 0 ? \WC_Payment_Tokens::get($existing) : null;
        // A renewal carries the subscription's token; a different card paid now replaces it.
        if ($token && ($pm_id === '' || $token->get_token() === $pm_id)) {
            return $token;
        }
        return self::saveMethodForOrder($order, $intent['payment_method'] ?? '', (string) ($intent['customer'] ?? ''), $mode);
    }

    /**
     * @param array<string,mixed>|string $payment_method Expanded PaymentMethod or its id.
     */
    public static function saveMethodForOrder(\WC_Order $order, $payment_method, string $customer, string $mode): ?\WC_Payment_Token
    {
        $client = Settings::client($mode);
        if (is_string($payment_method)) {
            if ($payment_method === '') {
                return null;
            }
            try {
                $payment_method = $client->get('payment_methods/' . rawurlencode($payment_method));
            } catch (StripeApiException $e) {
                Settings::log('Could not load payment method to save it: ' . $e->logSummary(), 'warning');
                return null;
            }
        }
        if (!is_array($payment_method) || empty($payment_method['id'])) {
            return null;
        }
        if ($customer === '' && !empty($payment_method['customer'])) {
            $customer = is_array($payment_method['customer']) ? (string) ($payment_method['customer']['id'] ?? '') : (string) $payment_method['customer'];
        }

        $order->update_meta_data(self::META_PM, (string) $payment_method['id']);
        if ($customer !== '') {
            $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $token = Tokens::saveFromPaymentMethod($payment_method, (int) $order->get_customer_id(), $customer, $mode);
        if ($token) {
            $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
            $order->add_payment_token($token);
        }
        $order->save();
        return $token;
    }

    /**
     * Writes the charge id and fee information when the intent carries an expanded latest_charge.
     *
     * @param array<string,mixed> $intent
     */
    private static function recordCharge(\WC_Order $order, array $intent): void
    {
        $charge = $intent['latest_charge'] ?? '';
        $charge_id = is_array($charge) ? (string) ($charge['id'] ?? '') : (string) $charge;
        if ($charge_id !== '') {
            $order->update_meta_data(self::META_CHARGE, $charge_id);
        }
        $pm = $intent['payment_method'] ?? null;
        if (is_array($pm) && !empty($pm['id'])) {
            $order->update_meta_data(self::META_PM, (string) $pm['id']);
            $type = (string) ($pm['type'] ?? '');
            if ($type === 'card' && !empty($pm['card']['wallet']['type'])) {
                $type = (string) $pm['card']['wallet']['type'];
            }
            if ($type !== '') {
                $order->update_meta_data('_stackless_stripe_payment_method_type', $type);
            }
        }
    }

    /** Orders that can still take this payment: pending/failed/on-hold (async or authorized). */
    public static function orderAwaitingPayment(\WC_Order $order): bool
    {
        return $order->needs_payment() || ($order->has_status('on-hold') && !$order->get_date_paid());
    }

    /* ---------------------------------------------------------------------
     * Capture / void (authorize-only mode)
     * ------------------------------------------------------------------- */

    public static function capture(\WC_Order $order): bool
    {
        if ($order->get_payment_method() !== Settings::GATEWAY_ID || $order->get_meta(self::META_CAPTURED) !== 'no') {
            return false;
        }
        $intent_id = (string) $order->get_meta(self::META_INTENT);
        if ($intent_id === '') {
            return false;
        }
        $client = Settings::clientForOrder($order);
        try {
            $intent = $client->get('payment_intents/' . rawurlencode($intent_id));
            if (($intent['status'] ?? '') !== 'requires_capture') {
                $order->update_meta_data(self::META_CAPTURED, ($intent['status'] ?? '') === 'succeeded' ? 'yes' : 'no');
                $order->save();
                return ($intent['status'] ?? '') === 'succeeded';
            }
            $capturable = (int) ($intent['amount_capturable'] ?? 0);
            $amount = min($capturable, Amount::toMinor(max(0, (float) $order->get_total() - (float) $order->get_total_refunded()), $order->get_currency()));
            $params = $amount > 0 && $amount < $capturable ? ['amount_to_capture' => $amount] : [];
            $client->post('payment_intents/' . rawurlencode($intent_id) . '/capture', $params, 'stackless-capture-' . $order->get_id() . '-' . $intent_id);
            $order->update_meta_data(self::META_CAPTURED, 'yes');
            if (!$order->get_date_paid()) {
                $order->set_date_paid(time());
            }
            $order->save();
            /* translators: %s: formatted amount */
            $order->add_order_note(sprintf(__('Stripe charge captured: %s.', 'stackless-lite'), wc_price((float) Amount::fromMinor($amount > 0 ? $amount : $capturable, $order->get_currency()), ['currency' => $order->get_currency()])));
            return true;
        } catch (StripeApiException $e) {
            /* translators: %s: error message */
            $order->add_order_note(sprintf(__('Stripe capture failed: %s', 'stackless-lite'), $e->getMessage()));
            Settings::log('Capture failed for order ' . $order->get_id() . ': ' . $e->logSummary(), 'error');
            return false;
        }
    }

    public static function voidAuthorization(\WC_Order $order): bool
    {
        if ($order->get_payment_method() !== Settings::GATEWAY_ID || $order->get_meta(self::META_CAPTURED) !== 'no') {
            return false;
        }
        $intent_id = (string) $order->get_meta(self::META_INTENT);
        if ($intent_id === '') {
            return false;
        }
        try {
            Settings::clientForOrder($order)->post('payment_intents/' . rawurlencode($intent_id) . '/cancel', ['cancellation_reason' => 'requested_by_customer'], 'stackless-void-' . $intent_id);
            $order->update_meta_data(self::META_CAPTURED, 'void');
            $order->save();
            $order->add_order_note(__('Stripe authorization released.', 'stackless-lite'));
            return true;
        } catch (StripeApiException $e) {
            /* translators: %s: error message */
            $order->add_order_note(sprintf(__('Releasing the Stripe authorization failed: %s', 'stackless-lite'), $e->getMessage()));
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    public static function returnUrl(\WC_Order $order, string $flow): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
            'flow' => sanitize_key($flow),
        ], WC()->api_request_url('stackless_stripe_gateway_return'));
    }

    public static function description(\WC_Order $order): string
    {
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        /* translators: 1: site name, 2: order number */
        return substr(sprintf(__('%1$s - Order %2$s', 'stackless-lite'), $site, $order->get_order_number()), 0, 1000);
    }

    /** @return array<string,string> */
    public static function metadata(\WC_Order $order): array
    {
        return [
            'order_id' => (string) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'stackless_site' => Settings::siteMarker(),
            'site_url' => home_url('/'),
        ];
    }

    /** @param array<string,mixed> $source */
    private static function sourceFingerprint(array $source): string
    {
        return (string) ($source['confirmation_token'] ?? $source['payment_method'] ?? '');
    }

    /**
     * Per-order lock in the options table: an INSERT IGNORE on the unique option_name, so exactly
     * one request wins (add_option() is not atomic: it upserts). Waits up to ~5 seconds for a
     * concurrent holder and takes over locks older than 60 seconds.
     *
     * @return string|null Owner token for unlock(), or null when another request still holds it.
     */
    public static function lock(int $order_id): ?string
    {
        global $wpdb;
        $name = 'stackless_stripe_lock_' . $order_id;
        $value = time() . ':' . bin2hex(random_bytes(10));
        for ($i = 0; $i < 20; $i++) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value))) {
                return $value;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            $since = (int) $current;
            if ($since > 0 && time() - $since > 60) {
                // Abandoned by a request that died; take it over only if nobody else did first.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ($wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current))) {
                    return $value;
                }
            }
            usleep(250000);
        }
        return null;
    }

    /** Releases the order lock, but only while $token still owns it. */
    public static function unlock(int $order_id, string $token): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($wpdb->options, ['option_name' => 'stackless_stripe_lock_' . $order_id, 'option_value' => $token]);
    }
}
