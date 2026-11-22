<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Merchant-initiated charges on a saved PaymentConsent (Contracts\SubscriptionGateway): renewals
 * and subscription switches.
 *
 * Each attempt is one PaymentIntent, created without a payment method and then confirmed with
 * triggered_by merchant. Intent and request ids are saved before each call, so a retry after a
 * timeout repeats the same confirm. A new attempt only starts once the last one is paid, declined
 * or cancelled at Airwallex.
 *
 * Outcomes follow the contract: success = the order is completed; pending = Airwallex is still
 * processing or its answer is unknown (a retry is scheduled); failed = decline details written,
 * then the order failed.
 */
final class Renewals
{
    public const RETRY_HOOK = 'stackless_airwallex_gateway_retry';
    public const GROUP = 'stackless-airwallex_gateway';

    /** Retry delays in seconds; the last one repeats until MAX_TRIES. */
    private const DELAYS = [900, 1800, 3600, 7200, 14400, 28800, 43200, 86400];
    private const MAX_TRIES = 30;
    /** After this many retries an unknown charge is cancelled, which makes it known unpaid. */
    private const GIVE_UP_AFTER = 7;

    /** @var Sync */
    private $sync;

    public function __construct(?Sync $sync = null)
    {
        $this->sync = $sync ?: new Sync();
    }

    /**
     * @param string $tokenId The subscription's saved method, used when the order names none.
     * @return array{result:string, message?:string}
     */
    public function charge(int $orderId, string $tokenId = ''): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $result = Lock::run('order-' . $orderId, function () use ($orderId, $tokenId) {
            return $this->chargeLocked($orderId, $tokenId);
        });
        if (!is_array($result)) {
            self::schedule($orderId, self::DELAYS[0]);
            return ['result' => 'pending', 'message' => __('Another request is checking this payment.', 'stackless-lite')];
        }
        return $result;
    }

    /** @return array{result:string, message?:string} */
    private function chargeLocked(int $orderId, string $tokenId): array
    {
        $sale = Sale::get($orderId);
        if (!$sale) {
            return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
        }
        if ($sale->data(Meta::CHARGE)) {
            // Finish the attempt that is out there, with the card it started with.
            return $this->resume($sale);
        }
        if ($sale->paid()) {
            return ['result' => 'success'];
        }
        if (!$sale->isOurs()) {
            return ['result' => 'failed', 'message' => __('The order is not set to pay with Airwallex.', 'stackless-lite')];
        }
        if (!$sale->payable()) {
            return $sale->waiting() || $sale->text(Meta::REVIEW) === 'yes'
                ? ['result' => 'pending']
                : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite')];
        }
        if ($sale->isZero()) {
            $sale->complete();
            return ['result' => 'success'];
        }

        // An earlier hosted payment or charge may have paid it, or may still.
        $state = $this->sync->reconcile($sale);
        if ($sale->paid()) {
            return ['result' => 'success'];
        }
        if ($state['outcome'] === 'failed') {
            return $this->failed();
        }
        if (in_array($state['outcome'], ['pending', 'error', 'held'], true)) {
            return $this->later($sale);
        }

        $saved = self::resolveMethod($sale, $tokenId);
        if (!$saved) {
            $this->sync->failCharge($sale, Declines::decline('no_payment_method'));
            return $this->failed(__('No saved Airwallex card of this customer is attached to this subscription.', 'stackless-lite'));
        }
        if ($saved->mode !== Settings::mode() || !Settings::configured($saved->mode)) {
            return $this->later($sale, __('The saved card belongs to another Airwallex mode, or that mode is not connected. The charge waits until it is.', 'stackless-lite'));
        }
        if ($saved->account !== Settings::account($saved->mode)) {
            $this->sync->failCharge($sale, Declines::decline('account_changed'));
            return $this->failed(__('The saved card belongs to another Airwallex account.', 'stackless-lite'));
        }
        $currency = $sale->currency();
        if (!Money::supported($currency)) {
            $this->sync->failCharge($sale, Declines::decline('currency_not_supported'));
            /* translators: %s: currency code */
            return $this->failed(sprintf(__('Airwallex does not take payments in %s.', 'stackless-lite'), $currency));
        }

        $released = $this->sync->release($sale, $state);
        if ($released === 'paid') {
            return ['result' => 'success'];
        }
        if ($released !== 'free' || !$sale->payable()) {
            return $this->later($sale);
        }

        $sale->put([
            Meta::REFERENCE => $sale->reference() ?: $sale->newReference(),
            Meta::MODE => $saved->mode,
            Meta::ACCOUNT => $saved->account,
            Meta::OFF_SESSION => 'yes',
            Meta::SESSION => null,
            Meta::SESSION_HASH => null,
            Meta::SAVE => null,
            Meta::CHARGE_INTENT => null,
            Meta::RETRIES => null,
            SubscriptionData::META_TOKEN_ID => (string) $saved->id,
            SubscriptionData::META_GATEWAY_CUSTOMER => $saved->customer,
            Meta::CHARGE => [
                'key' => Api::requestId(),
                'intent' => '',
                'confirm' => false,
                'amount' => Money::value($sale->total(), $currency),
                'currency' => $currency,
                'consent' => $saved->consent,
                'customer' => $saved->customer,
                'method' => $saved->method,
            ],
        ]);
        return $this->resume($sale);
    }

    /** @return array{result:string, message?:string} */
    private function resume(Sale $sale): array
    {
        $api = Sync::apiFor($sale);
        if (is_wp_error($api)) {
            return $this->later($sale, $api->get_error_message());
        }
        $charge = $sale->data(Meta::CHARGE);
        $reference = $sale->reference();

        if ((string) $charge['intent'] === '') {
            if (!$sale->payable()) {
                // An intent without a payment method cannot take money.
                $sale->put([Meta::CHARGE => null, Meta::RETRIES => null]);
                return ['result' => $sale->paid() ? 'success' : 'pending'];
            }
            $created = $api->request('POST', 'pa/payment_intents/create', [
                'request_id' => Api::stableId($reference, (string) $charge['key'], 'create'),
                'amount' => (string) $charge['amount'],
                'currency' => (string) $charge['currency'],
                'merchant_order_id' => $reference,
                'customer_id' => (string) $charge['customer'],
                'metadata' => ['stackless_attempt' => (string) $charge['key']],
            ]);
            if (is_wp_error($created)) {
                if (Api::isRejected($created)) {
                    $this->sync->failCharge($sale, Declines::fromError($created));
                    return $this->failed($created->get_error_message());
                }
                $found = Api::wasSent($created) ? $this->findAttempt($api, $reference, (string) $charge['key']) : null;
                if (!$found) {
                    if ((int) $sale->text(Meta::RETRIES) >= self::GIVE_UP_AFTER) {
                        $this->sync->failCharge($sale, Declines::decline('no_response'));
                        return $this->failed($created->get_error_message());
                    }
                    return $this->later($sale, $created->get_error_message());
                }
                $created = $found;
            }
            $id = Api::id($created['id'] ?? null);
            if ($id === '') {
                return $this->later($sale);
            }
            $charge['intent'] = $id;
            $sale->put([Meta::CHARGE => $charge, Meta::CHARGE_INTENT => $id]);
        }
        $id = (string) $charge['intent'];

        if (!empty($charge['confirm']) || !$sale->payable() || !empty($charge['decline'])) {
            // Confirm may have gone out already: look before sending anything.
            $state = $this->sync->reconcile($sale, [$id]);
            if (!$sale->data(Meta::CHARGE)) {
                return $this->result($sale, $state['outcome']);
            }
            $intent = $state['intents'][$id] ?? null;
            if ($state['outcome'] === 'error' || !$intent || ($intent['status'] ?? '') !== 'REQUIRES_PAYMENT_METHOD' || Sync::isBusy($intent)) {
                return $sale->paid() ? ['result' => 'success'] : $this->later($sale);
            }
            if (!$sale->payable() || !empty($charge['decline'])) {
                return $this->abandon($sale, $api, $charge, $charge['decline'] ?? Declines::decline('order_closed'));
            }
            if ((int) $sale->text(Meta::RETRIES) >= self::GIVE_UP_AFTER) {
                return $this->abandon($sale, $api, $charge, Declines::decline('no_response'));
            }
        }

        $charge['confirm'] = true;
        $sale->put([Meta::CHARGE => $charge]);
        $confirmed = $api->request('POST', 'pa/payment_intents/' . rawurlencode($id) . '/confirm', [
            // The same id for every send of this attempt: Airwallex processes it once.
            'request_id' => Api::stableId($reference, (string) $charge['key'], 'confirm'),
            'customer_id' => (string) $charge['customer'],
            'payment_consent_id' => (string) $charge['consent'],
            'payment_method' => ['id' => (string) $charge['method'], 'type' => 'card'],
            'payment_method_options' => ['card' => ['auto_capture' => true]],
            'triggered_by' => 'merchant',
        ]);
        if (is_wp_error($confirmed) && Api::isRejected($confirmed)) {
            // Kept for the decline category: it can say more than the failed attempt does.
            $charge['answer'] = Declines::fromError($confirmed);
            $sale->put([Meta::CHARGE => $charge]);
        }
        // The answer to the confirm never settles anything; what Airwallex now holds does.
        $state = $this->sync->reconcile($sale, [$id]);
        if (!$sale->data(Meta::CHARGE)) {
            return $this->result($sale, $state['outcome']);
        }
        if (is_wp_error($confirmed) && Api::isRejected($confirmed)) {
            $intent = $state['intents'][$id] ?? null;
            if ($intent && ($intent['status'] ?? '') === 'REQUIRES_PAYMENT_METHOD' && !Sync::isBusy($intent)) {
                return $this->abandon($sale, $api, $charge, Declines::fromError($confirmed));
            }
        }
        return $sale->paid() ? ['result' => 'success'] : $this->later($sale, is_wp_error($confirmed) ? $confirmed->get_error_message() : '');
    }

    /**
     * Cancel an attempt, so it is known unpaid, before calling it failed.
     *
     * @param array<string,mixed> $charge
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message?:string}
     */
    private function abandon(Sale $sale, Api $api, array $charge, array $decline): array
    {
        $charge['decline'] = $decline;
        $sale->put([Meta::CHARGE => $charge]);
        $id = (string) $charge['intent'];
        if (!$this->sync->cancelIntent($api, $id)) {
            return $this->later($sale);
        }
        $state = $this->sync->reconcile($sale, [$id]);
        return $sale->data(Meta::CHARGE) ? $this->later($sale) : $this->result($sale, $state['outcome']);
    }

    /** An intent a create without an answer left behind. */
    private function findAttempt(Api $api, string $reference, string $key): ?array
    {
        $list = $api->request('GET', 'pa/payment_intents', ['merchant_order_id' => $reference, 'page_size' => 100]);
        if (is_wp_error($list)) {
            return null;
        }
        foreach ((array) ($list['items'] ?? []) as $intent) {
            if (is_array($intent) && (string) ($intent['merchant_order_id'] ?? '') === $reference && (string) ($intent['metadata']['stackless_attempt'] ?? '') === $key) {
                return $intent;
            }
        }
        return null;
    }

    /** @return array{result:string, message?:string} */
    private function result(Sale $sale, string $outcome): array
    {
        if ($sale->paid()) {
            return ['result' => 'success'];
        }
        return $outcome === 'failed' ? $this->failed() : ['result' => 'pending'];
    }

    /** @return array{result:string, message:string} */
    private function failed(string $message = ''): array
    {
        return ['result' => 'failed', 'message' => $message !== '' ? $message : __('Airwallex declined the payment.', 'stackless-lite')];
    }

    /** @return array{result:string, message?:string} */
    private function later(Sale $sale, string $message = ''): array
    {
        $tries = (int) $sale->text(Meta::RETRIES);
        if ($tries < self::MAX_TRIES) {
            $sale->put([Meta::RETRIES => (string) ($tries + 1), Meta::OFF_SESSION => 'yes']);
            self::schedule($sale->id(), self::DELAYS[min($tries, count(self::DELAYS) - 1)]);
        }
        if ($tries === 0) {
            $sale->note($message !== ''
                /* translators: %s: reason */
                ? sprintf(__('The Airwallex charge has no final result yet (%s). Checking again automatically; no second charge is started.', 'stackless-lite'), $message)
                : __('The Airwallex charge has no final result yet. Checking again automatically; no second charge is started.', 'stackless-lite'));
        }
        return $message !== '' ? ['result' => 'pending', 'message' => $message] : ['result' => 'pending'];
    }

    /** The next look at a hosted or asynchronous payment, in case no webhook arrives. */
    public static function scheduleCheck(Sale $sale): void
    {
        $checks = (int) $sale->text(Meta::CHECKS);
        if ($checks >= self::MAX_TRIES) {
            return;
        }
        $sale->put([Meta::CHECKS => (string) ($checks + 1)]);
        self::schedule($sale->id(), self::DELAYS[min($checks, count(self::DELAYS) - 1)]);
    }

    /** One scheduled look per order: a new one replaces the waiting one. */
    public static function schedule(int $orderId, int $delay): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        $args = ['order_id' => $orderId];
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::RETRY_HOOK, $args, self::GROUP);
        }
        as_schedule_single_action(time() + $delay, self::RETRY_HOOK, $args, self::GROUP);
    }

    /**
     * Action Scheduler callback: finish an unknown charge, or look at a payment again.
     *
     * @param mixed $order_id
     */
    public static function retry($order_id): void
    {
        $orderId = (int) $order_id;
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $orderId], self::GROUP)) {
            return;
        }
        $sale = Sale::get($orderId);
        if (!$sale) {
            return;
        }
        if ($sale->data(Meta::CHARGE) || ($sale->text(Meta::RETRIES) !== '' && $sale->payable() && $sale->isOurs())) {
            $ids = $sale->tokenIds();
            (new self())->charge($orderId, (string) end($ids));
            return;
        }
        $outcome = (new Sync())->refresh($orderId);
        if ($outcome === 'busy') {
            self::schedule($orderId, self::DELAYS[0]);
            return;
        }
        // A session lives for an hour: keep looking until it has surely ended.
        $sale = Sale::get($orderId);
        if ($sale && $outcome === 'open' && $sale->text(Meta::SESSION) !== '' && (int) $sale->text(Meta::CHECKS) < 4) {
            self::scheduleCheck($sale);
        }
    }

    /**
     * The order's saved card, then the subscription's, but only one of the order's own customer.
     * A guest's card also needs the Airwallex customer the subscription recorded.
     */
    public static function resolveMethod(Sale $sale, string $tokenId = ''): ?SavedMethod
    {
        foreach ([$sale->text(SubscriptionData::META_TOKEN_ID), $tokenId] as $candidate) {
            $saved = ctype_digit($candidate) ? Tokens::get((int) $candidate) : null;
            if (!$saved) {
                continue;
            }
            if ($saved->user_id > 0 && $saved->user_id === $sale->userId()) {
                return $saved;
            }
            if ($saved->user_id === 0 && $saved->customer === $sale->text(SubscriptionData::META_GATEWAY_CUSTOMER)) {
                return $saved;
            }
        }
        return null;
    }
}
