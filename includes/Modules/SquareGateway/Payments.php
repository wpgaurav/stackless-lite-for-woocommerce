<?php

namespace Stackless\Modules\SquareGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\FreshOrder;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Square payments: a new card or wallet token at checkout (saved on file when asked), a saved
 * card at checkout, renewals and saved-card charges without the buyer (merchant-initiated), and
 * webhook updates. Square answers card payments at once, so outcomes are success or failed,
 * except a transient error on a renewal, which is retried with the same idempotency key.
 *
 * Buyer-present charges of one order run one at a time (see CheckoutAttempt for their keys).
 */
final class Payments
{
    public const RETRY_HOOK = 'stackless_square_gateway_retry_charge';
    public const GROUP = 'stackless-square_gateway';
    public const META_ATTEMPT = '_stackless_square_attempt';
    public const META_TRANSIENT = '_stackless_square_transient_retries';

    private const MAX_TRANSIENT_RETRIES = 3;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function rememberRefund(\WC_Order $order, string $refundId): void
    {
        $ids = $order->get_meta(Gateway::META_REFUND_IDS);
        $ids = is_array($ids) ? $ids : [];
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data(Gateway::META_REFUND_IDS, array_values($ids));
            $order->save();
        }
    }

    /**
     * Pay an order with a token from the card form or a wallet; save the card on file when asked.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeNew(\WC_Order $order, string $source, bool $save): array
    {
        return $this->oneAtATime($order, function (\WC_Order $fresh) use ($source, $save) {
            return $this->payWithNewCard($fresh, $source, $save);
        });
    }

    /** @return array{result:string, message?:string} */
    private function payWithNewCard(\WC_Order $order, string $source, bool $save): array
    {
        $held = $this->whileHeld($order, null, '');
        if ($held) {
            return $held;
        }
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if ($this->gateway->currency($mode) !== '' && $currency !== $this->gateway->currency($mode)) {
            /* translators: %s: currency code */
            return ['result' => 'failed', 'message' => sprintf(__('Square takes payments in %s only.', 'stackless-lite'), $this->gateway->currency($mode))];
        }
        $userId = (int) $order->get_customer_id();
        $customer = '';
        if ($save) {
            $customer = $this->gateway->customerFor($userId, $mode, $order);
            if (is_wp_error($customer)) {
                return ['result' => 'failed', 'message' => $customer->get_error_message()];
            }
        }
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->save();

        if ((float) $order->get_total() <= 0) {
            if (!$save) {
                $order->payment_complete();
                return ['result' => 'success'];
            }
            $token = $this->createCard($source, $customer, $userId, $mode, $order);
            if (is_wp_error($token)) {
                $order->add_order_note(sprintf(
                    /* translators: %s: error */
                    __('Square could not save the card: %s', 'stackless-lite'),
                    $token->get_error_message()
                ));
                return ['result' => 'failed', 'message' => Declines::message(Api::code($token))];
            }
            Gateway::attachToken($order, $token);
            /* translators: %s: card, e.g. "Visa ending in 4242" */
            $order->add_order_note(sprintf(__('Saved %s with Square for future payments.', 'stackless-lite'), wp_strip_all_tags($token->get_display_name())));
            $order->payment_complete();
            return ['result' => 'success'];
        }

        $body = $this->paymentBody($order, $mode, [
            'source_id' => $source,
            'customer_id' => $customer !== '' ? $customer : null,
        ], Api::key('o' . $order->get_id(), substr(hash('sha256', $source), 0, 16)));
        $payment = $this->sendHeld($order, $mode, $body, CheckoutAttempt::KIND_NEW, false);
        if (is_wp_error($payment)) {
            $result = $this->checkoutError($order, $payment);
            // Held now means this request started the hold: whileHeld() refused before sending otherwise.
            return CheckoutAttempt::of($order)->hold ? ['result' => 'failed', 'message' => Hold::pendingMessage()] : $result;
        }
        // Save the card before the order is marked paid: Subscriptions copies the token from the
        // parent order when payment completes (Contracts\SubscriptionGateway).
        if ($save && in_array($payment['status'] ?? '', ['COMPLETED', 'APPROVED'], true)) {
            $token = $this->createCard((string) $payment['id'], $customer, $userId, $mode, $order);
            if ($token instanceof \WC_Payment_Token) {
                Gateway::attachToken($order, $token);
                /* translators: %s: card, e.g. "Visa ending in 4242" */
                $order->add_order_note(sprintf(__('Saved %s with Square for future payments.', 'stackless-lite'), wp_strip_all_tags($token->get_display_name())));
            } else {
                /* translators: %s: error */
                $order->add_order_note(sprintf(__('The payment went through, but Square could not save the card for renewals: %s Renewals will be invoiced.', 'stackless-lite'), is_wp_error($token) ? $token->get_error_message() : ''));
            }
        }
        return $this->checkoutResult($order, $payment, $mode);
    }

    /**
     * Pay with a saved card while the buyer is at checkout.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeCard(\WC_Order $order, \WC_Payment_Token $token, string $flow, bool $onSession = true): array
    {
        if ($order->is_paid()) {
            return ['result' => 'success'];
        }
        $mode = Tokens::mode($token);
        $currency = strtoupper($order->get_currency());
        if ($this->gateway->currency($mode) !== '' && $currency !== $this->gateway->currency($mode)) {
            /* translators: %s: currency code */
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'CURRENCY_MISMATCH'], sprintf(__('Square takes payments in %s only.', 'stackless-lite'), $this->gateway->currency($mode)), $flow);
        }
        if ($flow === 'checkout') {
            return $this->oneAtATime($order, function (\WC_Order $fresh) use ($token, $mode) {
                return $this->payWithSavedCard($fresh, $token, $mode);
            });
        }
        // Off session: never alongside a buyer's payment of the same order, nor while one is held.
        $id = (int) $order->get_id();
        $result = Lock::run('charge-' . $id, function () use ($id, $order, $token, $mode, $flow, $onSession) {
            $fresh = FreshOrder::get($id) ?: $order;
            if ($fresh->is_paid()) {
                return ['result' => 'success'];
            }
            $attempt = CheckoutAttempt::of($fresh);
            if ($attempt->hold) {
                $attempt->hold['renewal_waiting'] = true; // charged when the hold is released
                $attempt->write($fresh);
                $fresh->save();
                return ['result' => 'pending', 'message' => Hold::message()];
            }
            return $this->chargeOffSession($fresh, $token, $mode, $flow, $onSession);
        });
        if (is_array($result)) {
            return $result;
        }
        // The buyer is paying this order right now: try again shortly, as for a network error.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 300, self::RETRY_HOOK, ['order_id' => $id], self::GROUP);
        }
        return ['result' => 'pending'];
    }

    /** @return array{result:string, message?:string} */
    private function chargeOffSession(\WC_Order $order, \WC_Payment_Token $token, string $mode, string $flow, bool $onSession): array
    {
        Gateway::attachToken($order, $token);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->save();
        if ((float) $order->get_total() <= 0) {
            $order->payment_complete();
            return ['result' => 'success'];
        }

        // A charge that already went through for this order is reported, not repeated.
        $previous = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
        if ($previous !== '') {
            $existing = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && is_array($existing['payment'] ?? null) && in_array($existing['payment']['status'] ?? '', ['COMPLETED', 'APPROVED', 'PENDING'], true)) {
                return $this->applyPayment($order, $existing['payment'], $mode) === 'paid' ? ['result' => 'success'] : ['result' => 'pending'];
            }
        }

        $attempt = (int) $order->get_meta(self::META_ATTEMPT);
        $payment = $this->createPayment($order, $mode, [
            'source_id' => $token->get_token(),
            'customer_id' => Tokens::customer($token),
            // Without the buyer present, Square treats the charge as merchant-initiated (no 3-D Secure).
            'customer_details' => ['customer_initiated' => $onSession, 'seller_keyed_in' => false],
        ], Api::key(substr($flow, 0, 1) . $order->get_id(), (string) $attempt));
        if (is_wp_error($payment)) {
            return $this->offSessionError($order, $payment, $flow);
        }
        $outcome = $this->applyPayment($order, $payment, $mode);
        if ($outcome === 'paid') {
            $order->delete_meta_data(self::META_TRANSIENT);
            $order->save();
            return ['result' => 'success'];
        }
        if ($outcome === 'pending') {
            return ['result' => 'pending'];
        }
        $decline = Declines::fromPayment($payment);
        return $this->fail($order, $decline, Declines::message($decline['code']), $flow);
    }

    /**
     * A saved card at checkout or on the pay page. A definite decline moves on to a new key; an
     * unknown outcome holds the order (see Hold).
     *
     * @return array{result:string, message?:string}
     */
    private function payWithSavedCard(\WC_Order $order, \WC_Payment_Token $token, string $mode): array
    {
        $held = $this->whileHeld($order, $token, $mode);
        if ($held) {
            return $held;
        }
        if ((float) $order->get_total() <= 0) {
            $order->update_meta_data(Gateway::META_MODE, $mode);
            Gateway::attachToken($order, $token);
            $order->payment_complete();
            return ['result' => 'success'];
        }
        $attempt = CheckoutAttempt::of($order);

        // A charge that already went through for this order is reported, not repeated.
        $previous = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
        $previousUnknown = false;
        if ($previous !== '') {
            $existing = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode($previous));
            $status = is_array($existing) && is_array($existing['payment'] ?? null) ? (string) ($existing['payment']['status'] ?? '') : '';
            if (in_array($status, ['COMPLETED', 'APPROVED', 'PENDING'], true)) {
                return $this->checkoutResult($order, $existing['payment'], $mode);
            }
            $previousUnknown = !in_array($status, ['FAILED', 'CANCELED'], true);
        }
        // A second key is a second possible payment, so it needs everything before it to be settled.
        if ($attempt->declines > 0 && ($previousUnknown || (int) $order->get_meta(self::META_TRANSIENT) > 0)) {
            return ['result' => 'failed', 'message' => Hold::message()];
        }

        $body = $this->paymentBody($order, $mode, [
            'source_id' => $token->get_token(),
            'customer_id' => Tokens::customer($token),
            'customer_details' => ['customer_initiated' => true, 'seller_keyed_in' => false],
        ], $attempt->key($order));
        $order->update_meta_data(Gateway::META_MODE, $mode);
        Gateway::attachToken($order, $token); // saves the order
        return $this->savedCardResult($order, $mode, $this->sendHeld($order, $mode, $body, CheckoutAttempt::KIND_SAVED, false), false);
    }

    /**
     * @param array<string,mixed>|\WP_Error $payment
     * @return array{result:string, message?:string}
     */
    private function savedCardResult(\WC_Order $order, string $mode, $payment, bool $resent): array
    {
        if (CheckoutAttempt::declined($payment, $resent) && !stackless_is_renewal_order($order)) {
            // Not for a renewal order: an automatic retry can charge it meanwhile, with a key of its own.
            $attempt = CheckoutAttempt::of($order);
            $attempt->declines++;
            $attempt->write($order);
            $order->save();
        }
        if (!is_wp_error($payment)) {
            return $this->checkoutResult($order, $payment, $mode);
        }
        $result = $this->checkoutError($order, $payment);
        if (CheckoutAttempt::of($order)->hold || Api::code($payment) === 'IDEMPOTENCY_KEY_REUSED') {
            // A first send with no answer may have charged; a resend under the same key charges nothing new.
            $result['message'] = !$resent && CheckoutAttempt::of($order)->hold ? Hold::pendingMessage() : Hold::message();
        }
        return $result;
    }

    /**
     * Send a buyer-present request with the order held until Square answers: the hold is saved
     * first, so a request that dies with this process still holds the order.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>|\WP_Error
     */
    private function sendHeld(\WC_Order $order, string $mode, array $body, string $kind, bool $resent)
    {
        $attempt = CheckoutAttempt::of($order);
        if (!$resent) {
            $attempt->hold = CheckoutAttempt::holdFor($body, $kind, $mode);
            $attempt->write($order);
            $order->save();
        }
        $payment = $this->sendPayment($order, $mode, $body);
        $attempt = CheckoutAttempt::of($order);
        if (CheckoutAttempt::answered($payment, $resent)) {
            $attempt->hold = [];
            $attempt->write($order);
            $order->save();
        } else {
            Hold::started($order);
        }
        return $payment;
    }

    /**
     * The order is held: ask Square first. Settled paid, the buyer goes to the receipt; released,
     * the payment goes ahead (null). Still held, only the same saved card may resend the very same
     * request; anything else is refused.
     *
     * @return array{result:string, message?:string}|null
     */
    private function whileHeld(\WC_Order $order, ?\WC_Payment_Token $token, string $mode): ?array
    {
        if (!CheckoutAttempt::of($order)->hold) {
            return null;
        }
        $state = $this->settleHold($order);
        if ($state === 'paid' || $state === 'pending') {
            return ['result' => 'success'];
        }
        $order = FreshOrder::get((int) $order->get_id()) ?: $order;
        $hold = CheckoutAttempt::of($order)->hold;
        if (!$hold) {
            return null; // released: Square shows no payment
        }
        if ($token && ($hold['kind'] ?? '') === CheckoutAttempt::KIND_SAVED && ($hold['source'] ?? '') === $token->get_token()
            && (int) ($hold['minor'] ?? -1) === Money::toMinor($order->get_total(), $order->get_currency())) {
            $body = $this->paymentBody($order, $mode, [
                'source_id' => $token->get_token(),
                'customer_id' => Tokens::customer($token),
                'customer_details' => ['customer_initiated' => true, 'seller_keyed_in' => false],
            ], (string) $hold['key']);
            if (($hold['hash'] ?? '') !== '' && hash_equals((string) $hold['hash'], CheckoutAttempt::hash($body))) {
                return $this->savedCardResult($order, $mode, $this->sendHeld($order, $mode, $body, CheckoutAttempt::KIND_SAVED, true), true);
            }
        }
        return ['result' => 'failed', 'message' => Hold::message()];
    }

    /**
     * Settle the order's hold from what Square shows. Call while holding the order's charge lock.
     *
     * @return string paid|pending|released|held|none
     */
    public function settleHold(\WC_Order $order): string
    {
        $attempt = CheckoutAttempt::of($order);
        $hold = $attempt->hold;
        if (!$hold) {
            return 'none';
        }
        $found = $this->findHeldPayment($order, $hold);
        if ($found['state'] === 'paid') {
            return $this->settleWith($order, $found['payment'], (string) $hold['mode']);
        }
        if ($found['state'] === 'unclear') {
            if (empty($hold['unclear'])) {
                $attempt->hold['unclear'] = $found['ids'];
                $attempt->write($order);
                $order->save();
                /* translators: %s: Square payment ids */
                $order->add_order_note(sprintf(__('Square shows a payment for this order number that this store can\'t confirm as its own (%s). The order stays held: check the Square Dashboard, then mark the order paid or release the Square hold.', 'stackless-lite'), implode(', ', $found['ids'])));
            }
            return 'held';
        }
        if ($found['state'] !== 'none') {
            return 'held'; // Square could not be asked: try later
        }
        $now = time();
        if ($now - (int) $hold['sent'] >= Hold::ABSENT_AFTER && (int) $hold['empty_since'] > 0 && $now - (int) $hold['empty_since'] >= Hold::EMPTY_GAP) {
            Hold::release($order, sprintf(
                /* translators: %d: minutes */
                __('Square shows no payment for the held request after %d minutes, so the hold is released and the order can be paid.', 'stackless-lite'),
                (int) floor(($now - (int) $hold['sent']) / 60)
            ));
            return 'released';
        }
        if ((int) $hold['empty_since'] === 0) {
            $attempt->hold['empty_since'] = $now;
            $attempt->write($order);
            $order->save();
        }
        return 'held';
    }

    /**
     * The held request's payment as Square lists it: the order's reference, this location, the
     * held amount, sent no earlier than the request, and the same note (which names this store).
     *
     * @param array<string,mixed> $hold
     * @return array{state:string, payment?:array<string,mixed>, ids?:string[]}
     */
    private function findHeldPayment(\WC_Order $order, array $hold): array
    {
        $mode = (string) $hold['mode'];
        $location = (string) $hold['location'] !== '' ? (string) $hold['location'] : $this->gateway->locationId($mode);
        $query = [
            'begin_time' => gmdate('Y-m-d\TH:i:s\Z', (int) $hold['sent'] - 600),
            'end_time' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
            'location_id' => $location,
            'total' => (int) $hold['minor'],
            'sort_order' => 'ASC',
            'limit' => 100,
        ];
        $mine = [];
        $others = [];
        for ($page = 0; $page < 5; $page++) {
            $list = $this->gateway->api($mode)->request('GET', 'payments', $query);
            if (is_wp_error($list)) {
                return ['state' => 'error'];
            }
            foreach ((array) ($list['payments'] ?? []) as $payment) {
                if (!is_array($payment) || !self::isHeldPayment($order, $hold, $payment, $location)) {
                    continue;
                }
                if ((string) $hold['note'] === '' || hash_equals((string) $hold['note'], hash('sha256', (string) ($payment['note'] ?? '')))) {
                    $mine[] = $payment;
                } else {
                    $others[] = (string) ($payment['id'] ?? '');
                }
            }
            $cursor = (string) ($list['cursor'] ?? '');
            if ($cursor === '') {
                if ($mine) {
                    return ['state' => 'paid', 'payment' => $mine[0]];
                }
                return $others ? ['state' => 'unclear', 'ids' => $others] : ['state' => 'none'];
            }
            $query = ['cursor' => $cursor];
        }
        return $mine ? ['state' => 'paid', 'payment' => $mine[0]] : ['state' => 'error'];
    }

    /**
     * @param array<string,mixed> $hold
     * @param array<string,mixed> $payment
     */
    private static function isHeldPayment(\WC_Order $order, array $hold, array $payment, string $location): bool
    {
        $created = strtotime((string) ($payment['created_at'] ?? '')) ?: 0;
        return (string) ($payment['reference_id'] ?? '') === substr((string) $order->get_id(), 0, 40)
            && ($location === '' || (string) ($payment['location_id'] ?? '') === $location)
            && (int) ($payment['amount_money']['amount'] ?? -1) === (int) $hold['minor']
            && strtoupper((string) ($payment['amount_money']['currency'] ?? '')) === strtoupper((string) $hold['currency'])
            && in_array($payment['status'] ?? '', ['COMPLETED', 'APPROVED', 'PENDING'], true)
            && $created >= (int) $hold['sent'] - 600;
    }

    /**
     * Record Square's payment for the held request and complete the order with it.
     *
     * @param array<string,mixed> $payment
     */
    private function settleWith(\WC_Order $order, array $payment, string $mode): string
    {
        $attempt = CheckoutAttempt::of($order);
        $attempt->hold = [];
        $attempt->write($order);
        $order->update_meta_data(Gateway::META_PAYMENT_ID, (string) $payment['id']);
        $order->save();
        Hold::forget($order);
        /* translators: %s: Square payment id */
        $order->add_order_note(sprintf(__('Square shows payment %s for the held request, so the hold is settled.', 'stackless-lite'), (string) $payment['id']));
        $outcome = $this->applyPayment($order, $payment, $mode);
        return $outcome === 'paid' || $outcome === 'pending' ? $outcome : 'held';
    }

    /**
     * One buyer-present charge per order at a time, each starting from what the last one saved, and
     * one per shopper at a time: a second tab or a changed cart can't pay while a payment is held,
     * nor pay again for a cart another tab just paid.
     *
     * @param callable(\WC_Order): array{result:string, message?:string} $charge
     * @return array{result:string, message?:string}
     */
    private function oneAtATime(\WC_Order $order, callable $charge): array
    {
        $id = (int) $order->get_id();
        $run = static function () use ($id, $charge) {
            return Lock::run('charge-' . $id, static function () use ($id, $charge) {
                $fresh = FreshOrder::get($id);
                if (!$fresh) {
                    return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
                }
                if ($fresh->is_paid() || ($fresh->has_status('on-hold') && (string) $fresh->get_meta(Gateway::META_PAYMENT_ID) !== '')) {
                    return ['result' => 'success']; // the submit before this one paid
                }
                // Another order of this shopper is held, or an automatic charge of this one is waiting to be sent again.
                if (Hold::heldFor($fresh) || ((int) $fresh->get_meta(self::META_TRANSIENT) > 0 && !CheckoutAttempt::of($fresh)->hold)) {
                    return ['result' => 'failed', 'message' => Hold::message()];
                }
                if (Hold::paidElsewhere($fresh)) {
                    return ['result' => 'failed', 'message' => Hold::paidElsewhereMessage()];
                }
                $result = $charge($fresh);
                if (($result['result'] ?? '') === 'success') {
                    Hold::rememberPaid($fresh);
                }
                return $result;
            });
        };
        $purchase = Hold::purchaseKey();
        $result = $purchase !== '' ? Lock::run('purchase-' . $purchase, $run) : $run();
        return is_array($result) ? $result : ['result' => 'failed', 'message' => __('A payment for this order is already being processed. Wait a moment and check your orders before you pay again.', 'stackless-lite')];
    }

    /** @return array{result:string, message?:string} */
    private function checkoutResult(\WC_Order $order, array $payment, string $mode): array
    {
        $outcome = $this->applyPayment($order, $payment, $mode);
        if ($outcome === 'busy' && ($payment['status'] ?? '') === 'COMPLETED') {
            $outcome = 'paid'; // The webhook is recording this same payment right now.
        }
        if ($outcome !== 'paid' && $outcome !== 'pending') {
            return ['result' => 'failed', 'message' => Declines::message((string) (Declines::fromPayment($payment)['code']))];
        }
        return ['result' => 'success'];
    }

    /**
     * Renewal (Contracts\SubscriptionGateway).
     *
     * @return array{result:string, message?:string}
     */
    public function renew(\WC_Order $renewal, ?\WC_Order $subscription): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $token = self::resolveToken($renewal, $subscription);
        if (!$token) {
            return $this->fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'NO_CARD_ON_FILE'], __('No saved Square card is attached to this subscription.', 'stackless-lite'), 'renewal');
        }
        if (!$renewal->needs_payment()) {
            return $renewal->is_paid() ? ['result' => 'success'] : ['result' => 'failed', 'message' => __('The renewal order cannot be paid in its current status.', 'stackless-lite')];
        }
        return $this->chargeCard($renewal, $token, 'renewal', false);
    }

    /**
     * Charge a saved card for a new order (Contracts\SavedMethodCharger).
     *
     * @return array{result:string, message?:string}
     */
    public function chargeSaved(\WC_Order $order, \WC_Payment_Token $token, bool $onSession): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        if ($token->get_gateway_id() !== Gateway::ID || (int) $order->get_customer_id() <= 0 || (int) $token->get_user_id() !== (int) $order->get_customer_id()) {
            return ['result' => 'failed', 'message' => __('That saved card does not belong to this customer.', 'stackless-lite')];
        }
        if ($order->get_payment_method() !== Gateway::ID || !$order->needs_payment()) {
            return ['result' => 'failed', 'message' => __('The order is not waiting for a Square payment.', 'stackless-lite')];
        }
        $result = $this->chargeCard($order, $token, 'saved', $onSession);
        if ($result['result'] === 'failed' && $onSession && (string) $order->get_meta(SubscriptionData::META_DECLINE) === SubscriptionData::DECLINE_AUTH) {
            // The bank wants the buyer to verify: let them enter the card on the order's pay page.
            return ['result' => 'action_required', 'message' => $result['message'] ?? '', 'redirect' => $order->get_checkout_payment_url()];
        }
        return $result;
    }

    /**
     * My Account → Add payment method: store a card from a STORE-intent token.
     *
     * @return \WC_Payment_Token|\WP_Error
     */
    public function storeCard(string $source, int $userId, string $mode)
    {
        $customer = $this->gateway->customerFor($userId, $mode);
        if (is_wp_error($customer)) {
            return $customer;
        }
        return $this->createCard($source, $customer, $userId, $mode);
    }

    /**
     * POST /v2/cards from a token (STORE intent) or a payment id (charged within 24 hours).
     *
     * @return \WC_Payment_Token|\WP_Error
     */
    private function createCard(string $source, string $customer, int $userId, string $mode, ?\WC_Order $order = null)
    {
        $card = array_filter([
            'customer_id' => $customer,
            'cardholder_name' => $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '',
            'reference_id' => $order ? 'wc_order_' . $order->get_id() : 'wp_user_' . $userId,
            'billing_address' => $order ? self::address($order) : null,
        ]);
        $response = $this->gateway->api($mode)->request('POST', 'cards', [
            'idempotency_key' => Api::key('card', substr(hash('sha256', $source . '|' . $customer), 0, 20)),
            'source_id' => $source,
            'card' => $card,
        ]);
        if (is_wp_error($response)) {
            $this->gateway->log('Saving a card failed: ' . Api::code($response) . ' ' . $response->get_error_message(), 'warning');
            return $response;
        }
        $token = Tokens::saveFromCard(is_array($response['card'] ?? null) ? $response['card'] : [], $userId, $mode);
        return $token ?: new \WP_Error('stackless_square_card', __('Square saved the card, but it could not be stored here.', 'stackless-lite'));
    }

    /** @return array|\WP_Error The payment object. */
    private function createPayment(\WC_Order $order, string $mode, array $source, string $idempotencyKey)
    {
        return $this->sendPayment($order, $mode, $this->paymentBody($order, $mode, $source, $idempotencyKey));
    }

    /** @return array<string,mixed> */
    private function paymentBody(\WC_Order $order, string $mode, array $source, string $idempotencyKey): array
    {
        return array_filter(array_merge($source, [
            'idempotency_key' => $idempotencyKey,
            'amount_money' => Money::money($order->get_total(), $order->get_currency()),
            'location_id' => $this->gateway->locationId($mode),
            'autocomplete' => true,
            'reference_id' => substr((string) $order->get_id(), 0, 40),
            /* translators: 1: order number, 2: site name */
            'note' => substr(sprintf(__('Order %1$s – %2$s', 'stackless-lite'), $order->get_order_number(), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 500),
            'buyer_email_address' => is_email($order->get_billing_email()) ? $order->get_billing_email() : null,
            'billing_address' => self::address($order) ?: null,
        ]), static function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /** @return array|\WP_Error The payment object. */
    private function sendPayment(\WC_Order $order, string $mode, array $body)
    {
        $response = $this->gateway->api($mode)->request('POST', 'payments', $body);
        if (is_wp_error($response)) {
            return $response;
        }
        $payment = is_array($response['payment'] ?? null) ? $response['payment'] : [];
        if (empty($payment['id'])) {
            return new \WP_Error('stackless_square_payment', __('Square did not return a payment.', 'stackless-lite'), ['status' => 502]);
        }
        $order->update_meta_data(Gateway::META_PAYMENT_ID, (string) $payment['id']);
        $order->save();
        return $payment;
    }

    /**
     * Apply a Square payment object to its order (checkout, renewal or webhook).
     *
     * @return string paid|pending|failed|mismatch|busy|ignored…
     */
    public function applyPayment(\WC_Order $order, array $payment, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            $paymentId = (string) ($payment['id'] ?? '');
            $current = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
            if ($current !== '' && $current !== $paymentId) {
                return 'ignored: another payment';
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'COMPLETED' || $status === 'APPROVED') {
                if ($order->is_paid() || ($order->get_date_paid() && $order->get_transaction_id() === $paymentId)) {
                    return 'paid';
                }
                if (!Money::matches($payment['amount_money'] ?? null, $order->get_total(), $order->get_currency())) {
                    if (!$order->has_status('on-hold')) {
                        /* translators: %s: Square payment id */
                        $order->update_status('on-hold', sprintf(__('Square payment %s does not match the order total. Check it before shipping.', 'stackless-lite'), $paymentId));
                    }
                    return 'mismatch';
                }
                $order->payment_complete($paymentId);
                return 'paid';
            }
            if ($status === 'PENDING') {
                if ($order->needs_payment() && !stackless_is_renewal_order($order)) {
                    $order->set_transaction_id($paymentId);
                    /* translators: %s: Square payment id */
                    $order->update_status('on-hold', sprintf(__('Square is processing payment %s.', 'stackless-lite'), $paymentId));
                }
                return 'pending';
            }
            if ($status === 'FAILED' || $status === 'CANCELED') {
                if ($order->needs_payment() && stackless_is_renewal_order($order)) {
                    $decline = Declines::fromPayment($payment);
                    Declines::record($order, $decline);
                    if (!$order->has_status('failed')) {
                        /* translators: 1: Square payment id, 2: error code */
                        $order->update_status('failed', sprintf(__('Square payment %1$s failed (%2$s).', 'stackless-lite'), $paymentId, $decline['code']));
                    }
                }
                return 'failed';
            }
            return 'pending';
        }, 'busy');
    }

    /** @return array{result:string, message:string} */
    private function checkoutError(\WC_Order $order, \WP_Error $error): array
    {
        $code = Api::code($error);
        /* translators: 1: error code, 2: message */
        $order->add_order_note(sprintf(__('Square payment attempt failed: %1$s (%2$s)', 'stackless-lite'), $error->get_error_message(), $code !== '' ? $code : 'HTTP ' . Api::status($error)));
        $this->gateway->log('Checkout payment for order ' . $order->get_id() . ' failed: ' . $code, Api::isTransient($error) ? 'error' : 'info');
        return ['result' => 'failed', 'message' => $code !== '' ? Declines::message($code) : $error->get_error_message()];
    }

    /** @return array{result:string, message?:string} */
    private function offSessionError(\WC_Order $order, \WP_Error $error, string $flow): array
    {
        if (Api::isTransient($error)) {
            $retries = (int) $order->get_meta(self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                $order->update_meta_data(self::META_TRANSIENT, (string) ($retries + 1));
                $order->save();
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $order->get_id()], self::GROUP);
                /* translators: %s: error */
                $order->add_order_note(sprintf(__('Square could not be reached for this charge (%s). Retrying automatically with the same idempotency key.', 'stackless-lite'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        $decline = Declines::fromError($error);
        return $this->fail($order, $decline, Declines::message($decline['code']), $flow);
    }

    /**
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private function fail(\WC_Order $order, array $decline, string $message, string $flow): array
    {
        if ($flow === 'checkout') {
            return ['result' => 'failed', 'message' => $message];
        }
        $order->update_meta_data(self::META_ATTEMPT, (string) ((int) $order->get_meta(self::META_ATTEMPT) + 1));
        $order->delete_meta_data(self::META_TRANSIENT);
        $order->delete_meta_data(Gateway::META_PAYMENT_ID);
        $order->save();
        Declines::record($order, $decline);
        /* translators: 1: reason, 2: error code */
        $note = sprintf(__('Square charge failed: %1$s (%2$s)', 'stackless-lite'), $message, $decline['code']);
        if ($order->has_status('pending')) {
            $order->update_status('failed', $note);
        } else {
            $order->add_order_note($note);
        }
        $this->gateway->log(sprintf('Charge for order %d failed: %s', $order->get_id(), $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /** Action Scheduler callback: retry a charge that could not reach Square. */
    public static function retry(int $order_id): void
    {
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Gateway::ID || !$order->needs_payment()) {
            return;
        }
        $subscriptions = stackless_get_order_subscriptions($order);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $token = self::resolveToken($order, $subscription instanceof \WC_Order ? $subscription : null);
        if ($token) {
            (new self(Gateway::instance()))->chargeCard($order, $token, stackless_is_renewal_order($order) ? 'renewal' : 'saved', false);
        }
    }

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

    /** @return array<string,string> Square address (empty values left out). */
    public static function address(\WC_Order $order): array
    {
        return array_filter([
            'address_line_1' => $order->get_billing_address_1(),
            'address_line_2' => $order->get_billing_address_2(),
            'locality' => $order->get_billing_city(),
            'administrative_district_level_1' => $order->get_billing_state(),
            'postal_code' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
        ], static function ($value) {
            return (string) $value !== '';
        });
    }

    /* ---- Webhook ----------------------------------------------------------------------- */

    /** payment.created / payment.updated */
    public function webhookPayment(array $payment, string $mode): string
    {
        $order = $this->findOrder((string) ($payment['id'] ?? ''));
        if (!$order) {
            return $this->webhookForHold($payment);
        }
        return $this->applyPayment($order, $payment, $mode);
    }

    /**
     * A payment for a held request: Square answered nobody, so its id was never stored. It settles
     * the hold only if it matches the held request (see findHeldPayment).
     *
     * @param array<string,mixed> $payment
     */
    private function webhookForHold(array $payment): string
    {
        $id = absint((string) ($payment['reference_id'] ?? ''));
        $order = $id > 0 ? wc_get_order($id) : null;
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Gateway::ID || !CheckoutAttempt::of($order)->hold) {
            return 'ignored: no matching order';
        }
        $settled = Lock::run('charge-' . $id, function () use ($id, $payment) {
            $fresh = FreshOrder::get($id);
            $hold = $fresh ? CheckoutAttempt::of($fresh)->hold : [];
            if (!$fresh || !$hold) {
                return 'ignored: no held request';
            }
            $location = (string) $hold['location'] !== '' ? (string) $hold['location'] : $this->gateway->locationId((string) $hold['mode']);
            if (!self::isHeldPayment($fresh, $hold, $payment, $location)
                || ((string) $hold['note'] !== '' && !hash_equals((string) $hold['note'], hash('sha256', (string) ($payment['note'] ?? ''))))) {
                return 'ignored: not the held request';
            }
            return 'held request ' . $this->settleWith($fresh, $payment, (string) $hold['mode']);
        }, 'busy');
        return (string) $settled;
    }

    /** refund.created / refund.updated: refunds made in Square, and failures of ours. */
    public function webhookRefund(array $refund): string
    {
        $order = $this->findOrder((string) ($refund['payment_id'] ?? ''));
        if (!$order) {
            return 'ignored: no matching order';
        }
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $refund) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            $refundId = (string) ($refund['id'] ?? '');
            $status = (string) ($refund['status'] ?? '');
            $known = $order->get_meta(Gateway::META_REFUND_IDS);
            $known = is_array($known) ? $known : [];
            if (in_array($status, ['REJECTED', 'FAILED'], true)) {
                if (in_array($refundId, $known, true) && !in_array($refundId . ':failed', $known, true)) {
                    /* translators: %s: Square refund id */
                    $order->add_order_note(sprintf(__('Square could not complete refund %s. Check it in the Square Dashboard.', 'stackless-lite'), $refundId));
                    self::rememberRefund($order, $refundId . ':failed');
                }
                return 'refund failed noted';
            }
            if ($refundId === '' || in_array($refundId, $known, true)) {
                return 'refund already recorded';
            }
            $amount = min(Money::fromMinor((int) ($refund['amount_money']['amount'] ?? 0), $order->get_currency()), (float) $order->get_remaining_refund_amount());
            self::rememberRefund($order, $refundId);
            if ($amount <= 0) {
                return 'nothing left to refund';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                /* translators: %s: Square refund id */
                'reason' => sprintf(__('Refunded in Square (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            return is_wp_error($created) ? 'refund not recorded: ' . $created->get_error_message() : 'refund recorded';
        }, 'busy');
    }

    /**
     * The order a Square payment belongs to, by the payment id stored when it was created.
     * reference_id alone is not trusted: another store on the same Square account can use the
     * same order numbers.
     */
    private function findOrder(string $paymentId): ?\WC_Order
    {
        if ($paymentId === '') {
            return null;
        }
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Gateway::META_PAYMENT_ID, 'meta_value' => $paymentId, 'type' => 'shop_order']); // phpcs:ignore WordPress.DB.SlowDBQuery
        return $orders && $orders[0] instanceof \WC_Order ? $orders[0] : null;
    }
}
