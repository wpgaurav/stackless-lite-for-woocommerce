<?php

namespace Stackless\Modules\RazorpayGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Recurring debits on a saved Razorpay token: subscription renewals, charges of a saved method
 * for a new order, and the first eNACH debit of an order.
 *
 * Each debit is a Razorpay order plus POST payments/create/recurring. Razorpay sends the
 * customer the pre-debit notice itself and debits a day or more later, so every debit returns
 * "pending" and settles through the payment.captured or payment.failed webhook. A debit is
 * created at most once per attempt: a repeat call finds the stored Razorpay order or payment
 * and reports its state instead of debiting again.
 */
final class Renewals
{
    /** Order flows that are debits without the customer present. */
    public const FLOWS = ['renewal', 'saved', 'first'];

    public const RETRY_HOOK = 'stackless_razorpay_gateway_retry_charge';
    public const GROUP = 'stackless-razorpay_gateway';
    public const META_ATTEMPT = '_stackless_razorpay_attempt';
    public const META_TRANSIENT = '_stackless_razorpay_transient_retries';

    private const MAX_TRANSIENT_RETRIES = 3;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @param \WC_Order|null $subscription SubscriptionData&WC_Order
     * @return array{result:string, message?:string}
     */
    public function charge(\WC_Order $renewal, $subscription = null): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $token = self::resolveToken($renewal, $subscription);
        if (!$token) {
            return $this->fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'no_mandate'], __('No saved Razorpay payment method is attached to this subscription.', 'stackless-lite'));
        }
        return $this->chargeToken($renewal, $token, 'renewal');
    }

    /** @return array{result:string, message?:string} */
    public function chargeSaved(\WC_Order $order, \WC_Payment_Token $token): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        if ($token->get_gateway_id() !== Gateway::ID || (int) $order->get_customer_id() <= 0 || (int) $token->get_user_id() !== (int) $order->get_customer_id()) {
            return ['result' => 'failed', 'message' => __('That saved payment method does not belong to this customer.', 'stackless-lite')];
        }
        if ($order->get_payment_method() !== Gateway::ID) {
            return ['result' => 'failed', 'message' => __('The order is not set to pay with Razorpay.', 'stackless-lite')];
        }
        return $this->chargeToken($order, $token, 'saved');
    }

    /**
     * @return array{result:string, message?:string}
     */
    public function chargeToken(\WC_Order $order, \WC_Payment_Token $token, string $flow): array
    {
        if ($flow !== 'first' && !LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $result = Lock::run('charge-' . $order->get_id(), function () use ($order, $token, $flow) {
            $order = wc_get_order($order->get_id());
            return $order instanceof \WC_Order ? $this->debit($order, $token, $flow) : ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
        });
        return is_array($result) ? $result : ['result' => 'pending'];
    }

    /** @return array{result:string, message?:string} */
    private function debit(\WC_Order $order, \WC_Payment_Token $token, string $flow): array
    {
        if ($order->is_paid()) {
            return ['result' => 'success'];
        }
        if (!$order->needs_payment() && !($flow === 'first' && $order->has_status('on-hold'))) {
            return $order->has_status('on-hold') ? ['result' => 'pending'] : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite')];
        }
        $currency = strtoupper($order->get_currency());
        $minor = Money::toMinor($order->get_total(), $currency);
        if ($minor <= 0) {
            $order->payment_complete();
            return ['result' => 'success'];
        }
        if (!Money::recurringSupported($currency)) {
            /* translators: %s: currency code */
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'currency'], sprintf(__('Razorpay recurring payments are in Indian rupees only, not %s.', 'stackless-lite'), $currency));
        }
        $status = Tokens::status($token);
        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'mandate_' . $status], __('The customer’s Razorpay mandate is no longer active.', 'stackless-lite'));
        }
        if ($status === 'paused') {
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_AUTH, 'code' => 'mandate_paused'], __('The customer paused their Razorpay mandate.', 'stackless-lite'));
        }
        if ($status === 'initiated') {
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_SOFT, 'code' => 'mandate_not_confirmed'], __('The bank has not confirmed the customer’s mandate yet.', 'stackless-lite'));
        }
        $max = Tokens::maxMinor($token);
        if ($max > 0 && $minor > $max) {
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_AUTH, 'code' => 'amount_exceeds_mandate'], __('The amount is above what the customer’s mandate allows. The customer needs to pay this one by hand or set up a new mandate.', 'stackless-lite'));
        }

        $mode = Tokens::mode($token);
        $api = $this->gateway->api($mode);

        // A debit already created for this order is reported, never repeated.
        $existing = $this->existingDebit($order, $api, $flow);
        if ($existing !== null) {
            return $existing;
        }

        $attempt = (int) $order->get_meta(self::META_ATTEMPT);
        $notes = Sync::notes($order, $flow);
        $created = $api->request('POST', 'orders', [
            'amount' => $minor,
            'currency' => Money::RECURRING,
            'payment_capture' => true,
            'receipt' => substr('wc_' . $flow[0] . '_' . $order->get_id() . '_' . $attempt . '_' . base_convert((string) time(), 10, 36), 0, 40),
            'notes' => $notes,
        ]);
        if (is_wp_error($created)) {
            return $this->apiError($order, $created);
        }
        $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
        $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
        $order->update_meta_data(Gateway::META_ORDER_ID, (string) ($created['id'] ?? ''));
        $order->update_meta_data(Gateway::META_AMOUNT, (string) $minor);
        $order->update_meta_data(Gateway::META_FLOW, $flow);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_ORDER_AT, (string) time());
        $order->delete_meta_data(Gateway::META_PAYMENT_ID);
        $order->save();

        $email = (string) $token->get_meta(Tokens::META_EMAIL);
        $contact = (string) $token->get_meta(Tokens::META_CONTACT);
        $payment = $api->request('POST', 'payments/create/recurring', [
            'email' => is_email($email) ? $email : (string) $order->get_billing_email(),
            'contact' => $contact !== '' ? $contact : Gateway::contact((string) $order->get_billing_phone()),
            'amount' => $minor,
            'currency' => Money::RECURRING,
            'order_id' => (string) ($created['id'] ?? ''),
            'customer_id' => Tokens::customer($token),
            'token' => $token->get_token(),
            'recurring' => true,
            /* translators: %s: order number */
            'description' => substr(sprintf(__('Order %s', 'stackless-lite'), $order->get_order_number()), 0, 50),
            'notes' => $notes,
        ]);
        if (is_wp_error($payment)) {
            return $this->apiError($order, $payment);
        }
        $paymentId = (string) ($payment['razorpay_payment_id'] ?? ($payment['id'] ?? ''));
        $order->update_meta_data(Gateway::META_PAYMENT_ID, $paymentId);
        $order->delete_meta_data(self::META_TRANSIENT);
        $order->save();
        $order->add_order_note(sprintf(
            /* translators: 1: method, 2: Razorpay payment id */
            __('Razorpay debit requested on the customer’s %1$s (%2$s). Razorpay notifies the customer first and debits a day or two later; the order updates when Razorpay confirms it.', 'stackless-lite'),
            wp_strip_all_tags($token->get_display_name()),
            $paymentId
        ));
        return ['result' => 'pending'];
    }

    /**
     * The state of a debit already created for this order, or null when there is none.
     *
     * @return array{result:string, message?:string}|null
     */
    private function existingDebit(\WC_Order $order, Api $api, string $flow): ?array
    {
        if (!in_array((string) $order->get_meta(Gateway::META_FLOW), self::FLOWS, true)) {
            return null;
        }
        $rzpOrder = (string) $order->get_meta(Gateway::META_ORDER_ID);
        if ($rzpOrder === '') {
            return null;
        }
        $payments = $api->request('GET', 'orders/' . rawurlencode($rzpOrder) . '/payments');
        if (is_wp_error($payments)) {
            // Unknown: don't risk a second debit; report pending and let the webhook settle it.
            return Api::isTransient($payments) ? ['result' => 'pending'] : null;
        }
        foreach ((array) ($payments['items'] ?? []) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'captured' || $status === 'authorized') {
                $outcome = (new Sync($this->gateway))->applyPayment($order, $payment, $api->mode());
                return $outcome === 'paid' ? ['result' => 'success'] : ['result' => 'pending'];
            }
            if ($status === 'created' || $status === 'pending') {
                return ['result' => 'pending'];
            }
        }
        return null; // Only failed attempts: a new debit may be created.
    }

    /** @return array{result:string, message?:string} */
    private function apiError(\WC_Order $order, \WP_Error $error): array
    {
        if (Api::isTransient($error)) {
            $retries = (int) $order->get_meta(self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                $order->update_meta_data(self::META_TRANSIENT, (string) ($retries + 1));
                $order->save();
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $order->get_id()], self::GROUP);
                /* translators: %s: error */
                $order->add_order_note(sprintf(__('Razorpay could not be reached for this debit (%s). Retrying automatically.', 'stackless-lite'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        return $this->fail($order, Declines::fromError($error), $error->get_error_message());
    }

    /**
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private function fail(\WC_Order $order, array $decline, string $message): array
    {
        $order->update_meta_data(self::META_ATTEMPT, (string) ((int) $order->get_meta(self::META_ATTEMPT) + 1));
        $order->delete_meta_data(self::META_TRANSIENT);
        $order->save();
        Declines::record($order, $decline);
        /* translators: 1: reason, 2: reason code */
        $note = sprintf(__('Razorpay debit failed: %1$s (%2$s)', 'stackless-lite'), $message, $decline['code']);
        if ($order->has_status(['pending', 'on-hold'])) {
            $order->update_status('failed', $note);
        } else {
            $order->add_order_note($note);
        }
        $this->gateway->log(sprintf('Debit for order %d failed: %s', $order->get_id(), $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /** Action Scheduler callback: retry a debit that could not reach Razorpay. */
    public static function retry(int $order_id): void
    {
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Gateway::ID || $order->is_paid()) {
            return;
        }
        $subscriptions = stackless_get_order_subscriptions($order);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $token = self::resolveToken($order, $subscription instanceof \WC_Order ? $subscription : null);
        if ($token) {
            $flow = (string) $order->get_meta(Gateway::META_FLOW);
            (new self(Gateway::instance()))->chargeToken($order, $token, in_array($flow, self::FLOWS, true) ? $flow : 'renewal');
        }
    }

    /** The saved Razorpay token an order (or its subscription) points at. */
    public static function resolveToken(\WC_Order $order, $subscription = null): ?\WC_Payment_Token
    {
        foreach ([$order, $subscription] as $source) {
            if (!$source instanceof \WC_Order) {
                continue;
            }
            $tokenId = (int) $source->get_meta(SubscriptionData::META_TOKEN_ID);
            $token = $tokenId > 0 ? \WC_Payment_Tokens::get($tokenId) : null;
            if ($token && $token->get_gateway_id() === Gateway::ID && Tokens::customer($token) !== '') {
                return $token;
            }
        }
        return null;
    }
}
