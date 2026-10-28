<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Off-session renewal charges for the Subscriptions module (Contracts\SubscriptionGateway).
 *
 * Outcomes follow the contract: success => payment_complete() already called; pending =>
 * Stripe is still processing (bank debit) or the call hit a transient error and a retry is
 * scheduled with the same idempotency key; failed => the renewal order is marked failed and
 * the Subscriptions module runs dunning. Authentication-required failures leave a pay link
 * in the order notes so the customer can confirm the payment on-session.
 */
final class Renewals
{
    public const RETRY_HOOK = 'stackless_stripe_gateway_retry_renewal';
    public const GROUP = 'stackless-stripe_gateway';
    public const META_ATTEMPT = '_stackless_stripe_renewal_attempt';
    public const META_TRANSIENT_RETRIES = '_stackless_stripe_transient_retries';
    public const META_NEEDS_AUTH = '_stackless_stripe_requires_action';

    private const MAX_TRANSIENT_RETRIES = 3;

    /**
     * @param \WC_Order|null $subscription SubscriptionData&WC_Order, used for token fallbacks.
     * @return array{result:string, message?:string}
     */
    public static function charge(\WC_Order $renewal, $subscription = null): array
    {
        if (!LiveStore::isLive()) {
            // A copy of the live store never uses a saved card, whatever mode the token is in (audit F04).
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $order_id = $renewal->get_id();

        if (!$renewal->needs_payment()) {
            return $renewal->is_paid() || $renewal->has_status('on-hold')
                ? ['result' => $renewal->is_paid() ? 'success' : 'pending']
                : ['result' => 'failed', 'message' => __('The renewal order cannot be paid in its current status.', 'stackless-lite')];
        }

        $currency = $renewal->get_currency();
        $total = (float) $renewal->get_total();
        if ($total <= 0) {
            $renewal->payment_complete();
            return ['result' => 'success'];
        }
        if (!Amount::isSupported($currency)) {
            /* translators: %s: currency code */
            return self::fail($renewal, sprintf(__('Stripe does not support %s.', 'stackless-lite'), $currency));
        }
        if (!Amount::meetsMinimum($renewal->get_total(), $currency)) {
            return self::fail($renewal, ErrorMapper::message('amount_too_small'));
        }

        $source = self::resolveSource($renewal, $subscription);
        if (!$source) {
            return self::fail($renewal, __('No saved Stripe payment method is attached to this subscription.', 'stackless-lite'));
        }

        // A previous attempt for this renewal may already have succeeded or still be processing.
        $previous = (string) $renewal->get_meta(Payments::META_INTENT);
        if ($previous !== '') {
            try {
                $intent = Settings::client($source['mode'])->get('payment_intents/' . rawurlencode($previous), ['expand' => ['latest_charge']]);
                $status = (string) ($intent['status'] ?? '');
                if ($status === 'succeeded') {
                    $outcome = Payments::applyPaymentIntent($renewal, $intent, 'renewal');
                    return ['result' => $outcome === Payments::OUTCOME_PAID ? 'success' : 'pending'];
                }
                if ($status === 'processing') {
                    return ['result' => 'pending'];
                }
            } catch (StripeApiException $e) {
                Settings::log('Could not re-check previous renewal intent: ' . $e->logSummary(), 'warning');
            }
        }

        // Carry the token onto the renewal order so later webhooks and refunds find it.
        $renewal->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $source['token_id']);
        $renewal->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $source['customer']);
        $renewal->update_meta_data(Payments::META_PM, $source['payment_method']);
        $renewal->save();

        $attempt = (int) $renewal->get_meta(self::META_ATTEMPT);
        $metadata = ['stackless_renewal' => '1'];
        if ($subscription instanceof \WC_Order) {
            $metadata['subscription_id'] = (string) $subscription->get_id();
        }

        try {
            $intent = Payments::createPaymentIntent($renewal, [
                'payment_method' => $source['payment_method'],
                'payment_method_type' => $source['type'],
                'customer' => $source['customer'],
            ], [
                'off_session' => true,
                'capture' => true,
                'flow' => 'renewal',
                'mode' => $source['mode'],
                'metadata' => $metadata,
                'idempotency_key' => IdempotencyKey::renewal($order_id, $attempt),
            ]);
        } catch (StripeApiException $e) {
            return self::handleException($renewal, $e);
        }

        $renewal->delete_meta_data(self::META_TRANSIENT_RETRIES);
        $renewal->save();

        $outcome = Payments::applyPaymentIntent($renewal, $intent, 'renewal');
        switch ($outcome) {
            case Payments::OUTCOME_PAID:
                return ['result' => 'success'];
            case Payments::OUTCOME_PROCESSING:
            case Payments::OUTCOME_HELD:
            case Payments::OUTCOME_BUSY:
                // Busy: the webhook is applying this intent. Held: charged, but on hold for the merchant.
                return ['result' => 'pending'];
            case Payments::OUTCOME_ACTION:
                return self::failNeedsAuthentication($renewal);
            default:
                $error = isset($intent['last_payment_error']) && is_array($intent['last_payment_error']) ? $intent['last_payment_error'] : [];
                return self::fail($renewal, $error ? ErrorMapper::fromIntentError($error) : ErrorMapper::generic());
        }
    }

    /** Action Scheduler callback for transient-error retries. */
    public static function retry(int $order_id): void
    {
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $renewal = wc_get_order($order_id);
        if (!$renewal instanceof \WC_Order || $renewal->get_payment_method() !== Settings::GATEWAY_ID || !$renewal->needs_payment()) {
            return;
        }
        $subscriptions = stackless_get_order_subscriptions($renewal);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        self::charge($renewal, $subscription instanceof \WC_Order ? $subscription : null);
    }

    /**
     * @return array{token_id:int, payment_method:string, customer:string, type:string, mode:string}|null
     */
    public static function resolveSource(\WC_Order $renewal, $subscription = null): ?array
    {
        $read = function (string $key) use ($renewal, $subscription) {
            $value = $renewal->get_meta($key);
            if (($value === '' || $value === null || $value === false) && $subscription instanceof \WC_Order) {
                $value = $subscription->get_meta($key);
            }
            return $value;
        };

        $token_id = (int) $read(SubscriptionData::META_TOKEN_ID);
        $token = $token_id > 0 ? \WC_Payment_Tokens::get($token_id) : null;
        if ($token && $token->get_gateway_id() !== Settings::GATEWAY_ID) {
            $token = null;
        }

        $payment_method = $token ? $token->get_token() : (string) $read(Payments::META_PM);
        $customer = (string) $read(SubscriptionData::META_GATEWAY_CUSTOMER);
        if ($token && Tokens::customer($token) !== '') {
            $customer = Tokens::customer($token);
        }
        if ($payment_method === '' || $customer === '') {
            return null;
        }
        $mode = $token ? Tokens::mode($token) : (string) $read(Payments::META_MODE);
        return [
            'token_id' => $token ? (int) $token->get_id() : 0,
            'payment_method' => $payment_method,
            'customer' => $customer,
            'type' => $token ? Tokens::methodType($token) : 'card',
            'mode' => $mode === 'live' ? 'live' : ($mode === 'test' ? 'test' : Settings::mode()),
        ];
    }

    /** @return array{result:string, message?:string} */
    private static function handleException(\WC_Order $renewal, StripeApiException $e): array
    {
        $intent = $e->getPaymentIntent();

        if ($e->isRetryable() && !$intent) {
            $retries = (int) $renewal->get_meta(self::META_TRANSIENT_RETRIES);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                $renewal->update_meta_data(self::META_TRANSIENT_RETRIES, (string) ($retries + 1));
                $renewal->save();
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $renewal->get_id()], self::GROUP);
                /* translators: %s: error summary */
                $renewal->add_order_note(sprintf(__('Stripe could not be reached for the renewal charge (%s). Retrying automatically with the same idempotency key.', 'stackless-lite'), $e->getMessage()));
                return ['result' => 'pending'];
            }
        }

        $status = $intent ? (string) ($intent['status'] ?? '') : '';
        if (ErrorMapper::isAuthenticationRequired($e->getStripeCode(), $e->getDeclineCode(), $status)) {
            return self::failNeedsAuthentication($renewal);
        }
        return self::fail($renewal, ErrorMapper::fromException($e), $e->getDeclineCode() ?: $e->getStripeCode());
    }

    /** @return array{result:string, message:string} */
    private static function failNeedsAuthentication(\WC_Order $renewal): array
    {
        $renewal->update_meta_data(self::META_NEEDS_AUTH, 'yes');
        $renewal->save();
        $message = ErrorMapper::message('authentication_required');
        $note = sprintf(
            /* translators: %s: order payment URL */
            __('The bank requires the customer to authenticate this renewal (3D Secure). The customer can confirm and pay at: %s', 'stackless-lite'),
            $renewal->get_checkout_payment_url()
        );
        return self::fail($renewal, $message, 'authentication_required', $note);
    }

    /** @return array{result:string, message:string} */
    private static function fail(\WC_Order $renewal, string $message, string $code = '', string $note = ''): array
    {
        $renewal->update_meta_data(self::META_ATTEMPT, (string) ((int) $renewal->get_meta(self::META_ATTEMPT) + 1));
        $renewal->delete_meta_data(self::META_TRANSIENT_RETRIES);
        $renewal->save();
        if ($note === '') {
            /* translators: %s: reason */
            $note = sprintf(__('Stripe renewal charge failed: %s', 'stackless-lite'), $message);
            if ($code !== '') {
                $note .= ' (' . $code . ')';
            }
        }
        if (!$renewal->has_status('failed')) {
            $renewal->update_status('failed', $note);
        } else {
            $renewal->add_order_note($note);
        }
        Settings::log(sprintf('Renewal %d failed: %s', $renewal->get_id(), $code ?: $message), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }
}
