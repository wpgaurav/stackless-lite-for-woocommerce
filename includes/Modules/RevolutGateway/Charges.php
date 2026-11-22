<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Merchant-initiated charges of a saved card: renewals and switch payments through
 * Contracts\SubscriptionGateway.
 *
 * Paying a Revolut order has no Idempotency-Key, so there is one Revolut order per attempt and
 * the next attempt starts only once the last is paid, or read back and cancelled. An answer that
 * never came leaves the order pending; it is never called failed.
 */
final class Charges
{
    /** A charge not yet sent is retried this often before it fails as a network problem. */
    private const UNSENT_RETRIES = 3;
    /** Charges that Revolut has no trace of are sent again until this many checks were used. */
    private const RESEND_LIMIT = 4;

    /**
     * @param mixed $subscription The subscription being renewed, when known.
     * @return array{result:string, message?:string}
     */
    public function charge(int $orderId, $subscription = null): array
    {
        if (!Settings::liveStore()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $result = Lock::order($orderId, function () use ($orderId, $subscription) {
            return $this->chargeLocked($orderId, $subscription);
        }, null);
        if (!is_array($result)) {
            // The check that follows makes the charge, unless whoever holds the lock settled the order.
            Orders::setMeta($orderId, [Keys::CHARGE => 'yes']);
            Jobs::at($orderId, 120);
            return ['result' => 'pending'];
        }
        return $result;
    }

    /**
     * @param mixed $subscription
     * @return array{result:string, message?:string}
     */
    private function chargeLocked(int $orderId, $subscription): array
    {
        if (!Orders::exists($orderId)) {
            return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
        }
        if (Orders::settled($orderId)) {
            return ['result' => 'success'];
        }
        if (!Orders::needsPayment($orderId)) {
            return Orders::underReview($orderId)
                ? ['result' => 'pending']
                : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite')];
        }

        if (Orders::meta($orderId, Keys::CHARGE) !== 'yes') {
            // Before anything can leave it pending: the scheduled check then finishes the charge.
            Orders::setMeta($orderId, [Keys::CHARGE => 'yes']);
        }
        $sync = new Sync();
        $absent = false;
        foreach (Attempts::open($orderId) as $attempt) {
            $resolved = Remote::resolve($orderId, $attempt);
            if ($resolved['state'] === 'paid') {
                $settled = $sync->settle($orderId, $attempt, $resolved['remote']);
                if ($settled === 'paid' || $settled === 'duplicate') {
                    return ['result' => 'success'];
                }
                if ($settled === 'nosave') {
                    continue;
                }
                return ['result' => 'pending'];
            }
            if ($resolved['state'] !== 'closed') {
                Sync::waiting($orderId);
                return ['result' => 'pending'];
            }
            if ($resolved['decline']) {
                // The earlier charge was declined: report it. Dunning decides about another one.
                return $this->fail($orderId, $resolved['decline']);
            }
            $absent = $absent || ($attempt['flow'] === Attempts::FLOW_CHARGE && (int) $attempt['sent'] > 0);
        }
        if ($absent && Jobs::count($orderId) >= self::RESEND_LIMIT) {
            return $this->fail($orderId, ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => 'no_payment_created']);
        }

        $currency = Orders::currency($orderId);
        $amount = Money::toMinor(Orders::total($orderId), $currency);
        if ($amount <= 0) {
            Orders::complete($orderId, '');
            return ['result' => 'success'];
        }
        $token = self::tokenFor($orderId, $subscription);
        if (!$token) {
            return $this->fail($orderId, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'no_saved_card'], __('No saved Revolut card of this customer is attached to this subscription.', 'stackless-lite'));
        }
        if ($token['mode'] === 'test' && Settings::mode() === 'live') {
            return $this->fail($orderId, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'sandbox_card'], __('The card was saved in the Revolut sandbox and cannot pay on a live store.', 'stackless-lite'));
        }
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return $this->fail($orderId, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'currency_not_supported'], sprintf(__('Saved Revolut cards cannot be charged in %s.', 'stackless-lite'), $currency));
        }

        $mode = $token['mode'];
        $api = Settings::api($mode);
        Orders::setMeta($orderId, [
            Keys::MODE => $mode,
            SubscriptionData::META_TOKEN_ID => (string) $token['id'],
            SubscriptionData::META_GATEWAY_CUSTOMER => $token['customer'],
        ]);

        // Binds the card to this customer, mode and Revolut account, and to merchant use.
        $method = $api->request('GET', 'customers/' . rawurlencode($token['customer']) . '/payment-methods/' . rawurlencode($token['method']));
        if (is_wp_error($method)) {
            return Api::status($method) === 404
                ? $this->fail($orderId, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'card_not_found'], __('Revolut has no such saved card for this customer in the connected account.', 'stackless-lite'))
                : $this->unsent($orderId, $method);
        }
        if ((string) ($method['id'] ?? '') !== $token['method'] || ($method['type'] ?? '') !== 'card' || ($method['saved_for'] ?? '') !== 'merchant') {
            return $this->fail($orderId, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'not_saved_for_merchant'], __('The saved card may no longer be charged without the customer.', 'stackless-lite'));
        }

        $attempt = Attempts::begin($orderId, [
            'mode' => $mode,
            'flow' => Attempts::FLOW_CHARGE,
            'amount' => $amount,
            'currency' => $currency,
            'customer' => $token['customer'],
        ]);
        $created = Checkout::createOrder($api, $orderId, $attempt);
        if (is_wp_error($created)) {
            if (!Api::isTransient($created)) {
                Attempts::close($orderId, $attempt);
            }
            return $this->unsent($orderId, $created);
        }

        // Saved before the request: from here the charge may have happened.
        $attempt = Attempts::update($orderId, $attempt, ['id' => (string) $created['id'], 'sent' => time()]);
        $payment = $api->request('POST', 'orders/' . rawurlencode((string) $attempt['id']) . '/payments', [
            'saved_payment_method' => ['type' => 'card', 'id' => $token['method'], 'initiator' => 'merchant'],
        ]);
        $refused = is_wp_error($payment) && !Api::isTransient($payment);

        $resolved = Remote::resolve($orderId, $attempt, $refused);
        if ($resolved['state'] === 'paid') {
            $settled = $sync->settle($orderId, $attempt, $resolved['remote']);
            return $settled === 'paid' || $settled === 'duplicate' ? ['result' => 'success'] : ['result' => 'pending'];
        }
        if ($resolved['state'] === 'closed') {
            if ($resolved['decline']) {
                return $this->fail($orderId, $resolved['decline']);
            }
            if (is_wp_error($payment) && $refused) {
                return $this->fail($orderId, Declines::fromError($payment), $payment->get_error_message());
            }
        }
        if (is_wp_error($payment)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('The outcome of the Revolut charge is not known yet (%s). It is checked again before anything else is charged.', 'stackless-lite'), $payment->get_error_message()));
        }
        Sync::waiting($orderId);
        return ['result' => 'pending'];
    }

    /**
     * Nothing was charged because Revolut could not be reached, or refused to create the order.
     *
     * @return array{result:string, message?:string}
     */
    private function unsent(int $orderId, \WP_Error $error): array
    {
        if (Api::isTransient($error) && Jobs::count($orderId) < self::UNSENT_RETRIES && Jobs::later($orderId)) {
            /* translators: %s: error */
            Orders::note($orderId, sprintf(__('Revolut could not take this charge yet (%s). Nothing was charged. Retrying automatically.', 'stackless-lite'), $error->get_error_message()));
            return ['result' => 'pending'];
        }
        return $this->fail($orderId, Declines::fromError($error), $error->get_error_message());
    }

    /**
     * Decline details first, then the status: Dunning reads them when the order turns failed.
     *
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    public function fail(int $orderId, array $decline, string $message = ''): array
    {
        $message = $message !== '' ? $message : __('Revolut declined the payment.', 'stackless-lite');
        Declines::record($orderId, $decline);
        foreach ([Keys::CHARGE, Keys::WAITING, Keys::CHECKS] as $key) {
            if (Orders::meta($orderId, $key) !== '') {
                Orders::deleteMeta($orderId, $key);
            }
        }
        /* translators: 1: reason, 2: reason code */
        $note = sprintf(__('Revolut charge failed: %1$s (%2$s)', 'stackless-lite'), $message, $decline['code']);
        // An order the customer moved to another gateway is that gateway's to fail.
        if (Orders::isPending($orderId) && Orders::isOurs($orderId)) {
            Orders::fail($orderId, $note);
        } else {
            Orders::note($orderId, $note);
        }
        Settings::log(sprintf('Charge for order %d failed: %s', $orderId, $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /**
     * The saved card the order (or its subscription) points at, when it is this customer's.
     *
     * An account's card must belong to the order's account. A card saved by a guest must also
     * belong to the Revolut customer recorded on the order or subscription.
     *
     * @param mixed $subscription
     * @return array{id:int, user:int, method:string, customer:string, mode:string}|null
     */
    public static function tokenFor(int $orderId, $subscription = null): ?array
    {
        $user = Orders::user($orderId);
        $customers = array_filter([
            Orders::meta($orderId, SubscriptionData::META_GATEWAY_CUSTOMER),
            Orders::subscriptionValue($subscription, SubscriptionData::META_GATEWAY_CUSTOMER),
        ]);
        $candidates = [
            Orders::meta($orderId, SubscriptionData::META_TOKEN_ID),
            Orders::subscriptionValue($subscription, SubscriptionData::META_TOKEN_ID),
        ];
        foreach ($candidates as $candidate) {
            $token = ctype_digit($candidate) ? Tokens::get((int) $candidate) : null;
            if (!$token || $token['method'] === '' || $token['customer'] === '') {
                continue;
            }
            if ($token['user'] > 0 ? $token['user'] === $user : in_array($token['customer'], $customers, true)) {
                return $token;
            }
        }
        return null;
    }
}
