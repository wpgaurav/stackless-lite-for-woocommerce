<?php

namespace Stackless\Modules\MollieGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Recurring payments on a saved mandate: subscription renewals (Contracts\SubscriptionGateway)
 * and charges of a saved method for a new order (Contracts\SavedMethodCharger).
 *
 * Outcomes follow the contracts: success = payment_complete() already called; pending = Mollie
 * is still processing (SEPA Direct Debit settles days later) or Mollie could not be reached and
 * a retry is scheduled; failed = decline details written, then the order set to failed.
 */
final class Renewals
{
    public const RETRY_HOOK = 'stackless_mollie_gateway_retry_charge';
    public const GROUP = 'stackless-mollie_gateway';
    public const META_ATTEMPT = '_stackless_mollie_attempt';
    public const META_TRANSIENT = '_stackless_mollie_transient_retries';

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
            return $this->fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'no_mandate'], __('No saved Mollie payment method is attached to this subscription.', 'stackless-lite'));
        }
        return $this->chargeToken($renewal, $token, 'renewal', $subscription instanceof \WC_Order ? $subscription : null);
    }

    /** @return array{result:string, message?:string} */
    public function chargeSaved(\WC_Order $order, \WC_Payment_Token $token): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        if ($token->get_gateway_id() !== Gateway::ID || (int) $token->get_user_id() !== (int) $order->get_customer_id() || (int) $order->get_customer_id() <= 0) {
            return ['result' => 'failed', 'message' => __('That saved payment method does not belong to this customer.', 'stackless-lite')];
        }
        if ($order->get_payment_method() !== Gateway::ID) {
            return ['result' => 'failed', 'message' => __('The order is not set to pay with Mollie.', 'stackless-lite')];
        }
        return $this->chargeToken($order, $token, 'saved');
    }

    /**
     * Create (once) and apply a recurring payment for an order on a saved mandate.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeToken(\WC_Order $order, \WC_Payment_Token $token, string $flow, ?\WC_Order $subscription = null): array
    {
        if ($order->is_paid()) {
            return ['result' => 'success'];
        }
        if (!$order->needs_payment()) {
            return $order->has_status('on-hold') ? ['result' => 'pending'] : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite')];
        }
        $currency = strtoupper($order->get_currency());
        $total = (float) $order->get_total();
        if ($total <= 0) {
            $order->payment_complete();
            return ['result' => 'success'];
        }
        $mode = Tokens::mode($token);
        $customerId = Tokens::customer($token);
        $method = Tokens::method($token);
        if (!Money::supported($currency) || !Methods::mandateTakes($method, $currency)) {
            /* translators: 1: payment method, 2: currency code */
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'currency'], sprintf(__('%1$s through Mollie cannot be charged in %2$s.', 'stackless-lite'), Methods::label($method), $currency));
        }

        $api = $this->gateway->api($mode);
        // A previous attempt for this order may already be paid or still settling.
        $previous = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
        if ($previous !== '' && $order->get_meta(Gateway::META_SEQUENCE) === 'recurring') {
            $existing = $api->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && in_array($existing['status'] ?? '', ['paid', 'pending', 'open', 'authorized'], true)) {
                return $this->outcome($order, $existing, $mode);
            }
        }

        $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
        $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customerId);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->save();

        $metadata = Sync::metadata($order, $flow);
        if ($subscription) {
            $metadata['subscription_id'] = (string) $subscription->get_id();
        }
        $attempt = (int) $order->get_meta(self::META_ATTEMPT);
        $payment = $api->request('POST', 'payments', [
            'amount' => Money::amount($total, $currency),
            /* translators: 1: order number, 2: site name */
            'description' => substr(sprintf(__('Order %1$s – %2$s', 'stackless-lite'), $order->get_order_number(), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 255),
            'sequenceType' => 'recurring',
            'customerId' => $customerId,
            'mandateId' => $token->get_token(),
            'webhookUrl' => $this->gateway->webhookUrl(),
            'metadata' => $metadata,
        ], self::idempotencyKey($order->get_id(), $attempt));

        if (is_wp_error($payment)) {
            return $this->apiError($order, $payment, $flow);
        }
        $order->delete_meta_data(self::META_TRANSIENT);
        $order->update_meta_data(Gateway::META_PAYMENT_ID, (string) ($payment['id'] ?? ''));
        $order->update_meta_data(Gateway::META_SEQUENCE, 'recurring');
        $order->update_meta_data(Gateway::META_METHOD, (string) ($payment['method'] ?? $method));
        $order->save();
        return $this->outcome($order, $payment, $mode);
    }

    /** Idempotency key: one per order and attempt (Mollie keeps keys for an hour). */
    public static function idempotencyKey(int $orderId, int $attempt): string
    {
        return 'stackless-mollie-charge-' . $orderId . ($attempt > 0 ? '-retry-' . $attempt : '');
    }

    /** @return array{result:string, message?:string} */
    private function outcome(\WC_Order $order, array $payment, string $mode): array
    {
        $applied = (new Sync($this->gateway))->applyToOrder($order, $payment, $mode);
        if ($applied === 'paid') {
            return ['result' => 'success'];
        }
        if ($applied === 'failed') {
            $order = wc_get_order($order->get_id());
            $this->bumpAttempt($order instanceof \WC_Order ? $order : null);
            return ['result' => 'failed', 'message' => __('Mollie declined the payment.', 'stackless-lite')];
        }
        if ($applied === 'pending' || $applied === 'open' || $applied === 'busy') {
            if (in_array(Methods::MANDATE_OF[(string) ($payment['method'] ?? '')] ?? (string) ($payment['method'] ?? ''), Methods::SLOW_MANDATES, true)) {
                $order->add_order_note(__('SEPA Direct Debit submitted through Mollie. It settles in a few days; the order updates when Mollie confirms it.', 'stackless-lite'));
            }
            return ['result' => 'pending'];
        }
        return ['result' => 'failed', 'message' => __('Mollie could not charge the saved payment method.', 'stackless-lite')];
    }

    /** @return array{result:string, message?:string} */
    private function apiError(\WC_Order $order, \WP_Error $error, string $flow): array
    {
        if (Api::isTransient($error) && $flow !== 'checkout') {
            $retries = (int) $order->get_meta(self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                $order->update_meta_data(self::META_TRANSIENT, (string) ($retries + 1));
                $order->save();
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $order->get_id()], self::GROUP);
                /* translators: %s: error */
                $order->add_order_note(sprintf(__('Mollie could not be reached for this charge (%s). Retrying automatically.', 'stackless-lite'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        $this->bumpAttempt($order);
        return $this->fail($order, Declines::fromError($error), $error->get_error_message());
    }

    private function bumpAttempt(?\WC_Order $order): void
    {
        if (!$order) {
            return;
        }
        $order->update_meta_data(self::META_ATTEMPT, (string) ((int) $order->get_meta(self::META_ATTEMPT) + 1));
        $order->delete_meta_data(self::META_TRANSIENT);
        $order->save();
    }

    /**
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private function fail(\WC_Order $order, array $decline, string $message): array
    {
        Declines::record($order, $decline);
        /* translators: 1: reason, 2: reason code */
        $note = sprintf(__('Mollie charge failed: %1$s (%2$s)', 'stackless-lite'), $message, $decline['code']);
        if ($order->has_status('pending')) {
            $order->update_status('failed', $note);
        } else {
            $order->add_order_note($note);
        }
        $this->gateway->log(sprintf('Charge for order %d failed: %s', $order->get_id(), $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /** Action Scheduler callback: retry a charge that could not reach Mollie. */
    public static function retry(int $order_id): void
    {
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Gateway::ID || !$order->needs_payment()) {
            return;
        }
        $gateway = Gateway::instance();
        $subscriptions = stackless_get_order_subscriptions($order);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $token = self::resolveToken($order, $subscription instanceof \WC_Order ? $subscription : null);
        if ($token) {
            (new self($gateway))->chargeToken($order, $token, stackless_is_renewal_order($order) ? 'renewal' : 'saved', $subscription instanceof \WC_Order ? $subscription : null);
        }
    }

    /** The saved Mollie token a renewal (or its subscription) points at. */
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
