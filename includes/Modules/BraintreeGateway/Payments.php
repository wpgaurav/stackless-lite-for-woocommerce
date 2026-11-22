<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Every Braintree charge: checkout, renewals, switch charges and saved-card charges.
 *
 * All of them, and the scheduled check, work under one lock per order and follow the same
 * steps: settle the order's open attempt first, record the new attempt, send it, and complete
 * the order only from Braintree's own answer after checking it belongs to this attempt with the
 * same amount, currency and merchant account.
 *
 * An answer that never arrives is unknown, not failed. The attempt stays open and is searched
 * by its reference; an empty search counts as absent only after ABSENT_AFTER and a second
 * empty search, because search can lag. An unattended charge is then repeated with the same
 * apiRequestKey, which makes Braintree return the first transaction if there was one.
 */
final class Payments
{
    public const CHECK_HOOK = 'stackless_braintree_gateway_check_charge';
    public const GROUP = 'stackless-braintree_gateway';

    /** An empty search proves absence only this long after the request was sent. */
    public const ABSENT_AFTER = 1800;
    /** And only when an earlier search, at least this long before, was empty too. */
    public const EMPTY_GAP = 300;
    /** Braintree keeps an apiRequestKey for 30 days. */
    private const KEY_LIFETIME = 29 * 86400;
    private const MAX_REPLAYS = 3;
    private const AUDIT_AFTER = 6 * 3600;
    private const FIRST_CHECK = 180;
    private const CHECK_DELAYS = [300, 900, 660, 3600, 21600, 86400];
    private const MAX_CHECKS = 60;

    public const CAPTURED = ['SUBMITTED_FOR_SETTLEMENT', 'SETTLING', 'SETTLED'];
    public const UNPAID = ['AUTHORIZATION_EXPIRED', 'FAILED', 'GATEWAY_REJECTED', 'PROCESSOR_DECLINED', 'SETTLEMENT_DECLINED', 'VOIDED'];

    /* ---- Entry points ----------------------------------------------------------- */

    /**
     * Checkout and pay pages.
     *
     * @param array{nonce?:string, token_id?:int, save?:bool, device_data?:string} $request
     * @return array<string,mixed> result success|pending|failed, plus message and busy
     */
    public function checkout(int $orderId, array $request): array
    {
        $order = Orders::summary($orderId);
        if (!$order) {
            return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
        }
        $card = null;
        $tokenId = (int) ($request['token_id'] ?? 0);
        if ($tokenId > 0) {
            $card = Tokens::get($tokenId);
            if (!$card || $order['user'] <= 0 || $card->user !== $order['user'] || $card->mode !== Config::mode()) {
                return ['result' => 'failed', 'message' => __('That saved card is not available. Choose another one.', 'stackless-lite')];
            }
        }
        $requiresSave = Orders::requiresSave($orderId);
        return $this->charge($orderId, [
            'flow' => Attempt::FLOW_CHECKOUT,
            'card' => $card,
            'nonce' => self::cleanNonce((string) ($request['nonce'] ?? '')),
            'device_data' => self::cleanDeviceData((string) ($request['device_data'] ?? '')),
            'save' => !$card && ($requiresSave || (!empty($request['save']) && Config::savedCards() && $order['user'] > 0)),
            'must_save' => $requiresSave,
            'present' => true,
            'initiator' => $requiresSave ? 'RECURRING_FIRST' : '',
        ]);
    }

    /**
     * A renewal or switch charge (Contracts\SubscriptionGateway).
     *
     * @param int    $fallbackToken    The subscription's token, when the order names none.
     * @param string $fallbackCustomer The subscription's Braintree customer.
     * @return array<string,mixed>
     */
    public function renew(int $orderId, int $fallbackToken = 0, string $fallbackCustomer = ''): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $order = Orders::summary($orderId);
        if (!$order) {
            return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
        }
        return $this->charge($orderId, [
            'flow' => Attempt::FLOW_RENEWAL,
            'card' => $this->renewalCard($order, $fallbackToken, $fallbackCustomer),
            'initiator' => Orders::isRenewal($orderId) ? 'RECURRING' : 'UNSCHEDULED',
        ]);
    }

    /**
     * A saved card for a new order (Contracts\SavedMethodCharger). Refusals make no API call.
     *
     * @return array<string,mixed>
     */
    public function chargeSaved(int $orderId, int $tokenId, bool $present): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $order = Orders::summary($orderId);
        if (!$order || $order['gateway'] !== Gateway::ID) {
            return ['result' => 'failed', 'message' => __('The order is not set to pay with Braintree.', 'stackless-lite')];
        }
        $card = Tokens::get($tokenId);
        if (!$card || $order['user'] <= 0 || $card->user !== $order['user']) {
            return ['result' => 'failed', 'message' => __('That saved card does not belong to this customer.', 'stackless-lite')];
        }
        if ($card->mode !== Config::mode()) {
            return ['result' => 'failed', 'message' => __('That saved card was saved in the other Braintree mode.', 'stackless-lite')];
        }
        return $this->charge($orderId, [
            'flow' => Attempt::FLOW_SAVED,
            'card' => $card,
            'present' => $present,
            'initiator' => $present ? '' : 'UNSCHEDULED',
        ]);
    }

    /**
     * The card a renewal names, if it is this customer's. A guest's card has no user to compare,
     * so it must be the card of the Braintree customer recorded for this subscription.
     *
     * @param array<string,mixed> $order
     */
    private function renewalCard(array $order, int $fallbackToken, string $fallbackCustomer): ?Card
    {
        $recorded = [(string) Orders::meta($order['id'], SubscriptionData::META_GATEWAY_CUSTOMER), $fallbackCustomer];
        foreach ([(int) Orders::meta($order['id'], SubscriptionData::META_TOKEN_ID), $fallbackToken] as $tokenId) {
            $card = $tokenId > 0 ? Tokens::get($tokenId) : null;
            if (!$card || $card->remote === '') {
                continue;
            }
            if ($card->user > 0 ? $card->user === $order['user'] : ($card->customer !== '' && in_array($card->customer, $recorded, true))) {
                return $card;
            }
        }
        return null;
    }

    /* ---- The charge ------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function charge(int $orderId, array $spec): array
    {
        $spec += ['flow' => Attempt::FLOW_CHECKOUT, 'card' => null, 'nonce' => '', 'device_data' => '', 'save' => false, 'must_save' => false, 'present' => false, 'initiator' => ''];
        $result = Lock::run('order-' . $orderId, function () use ($orderId, $spec) {
            return $this->chargeLocked($orderId, $spec);
        }, null);
        return is_array($result) ? $result : ['result' => 'pending', 'busy' => true, 'message' => __('This payment is already being processed. Wait a moment before trying again.', 'stackless-lite')];
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function chargeLocked(int $orderId, array $spec): array
    {
        $order = Orders::summary($orderId);
        if (!$order) {
            return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
        }
        if ($order['paid']) {
            return ['result' => 'success'];
        }
        $checkout = $spec['flow'] === Attempt::FLOW_CHECKOUT;

        if (Attempt::current($orderId)) {
            $settled = $this->resolveLocked($order, false, !$checkout);
            if ($settled['state'] === 'paid') {
                return ['result' => 'success'];
            }
            if ($settled['state'] === 'pending') {
                return $this->waiting($orderId, $checkout);
            }
            if ($settled['state'] === 'failed' && !$checkout) {
                return $this->failedResult($orderId, $settled['decline'], $spec);
            }
            // Known unpaid or absent: this request may charge.
            $order = Orders::summary($orderId);
            if (!$order || $order['paid']) {
                return $order ? ['result' => 'success'] : ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
            }
        }
        if (!$order['payable']) {
            return $order['completable'] ? ['result' => 'pending'] : ['result' => 'failed', 'message' => __('The order cannot be paid in its current status.', 'stackless-lite')];
        }

        $currency = $order['currency'];
        $minor = Money::minor($order['total'], $currency);
        if ($minor === null || $minor < 0) {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_SOFT, 'amount_precision');
        }
        /** @var Card|null $card */
        $card = $spec['card'];
        $policy = Config::threeDSecure();

        if ($minor === 0 && !$card && !($checkout && $spec['save'])) {
            if ($checkout && $spec['must_save']) {
                return $this->refuse($order, $spec, SubscriptionData::DECLINE_SOFT, 'card_required');
            }
            Orders::complete($orderId, '');
            return ['result' => 'success'];
        }
        if (!$card && !$checkout) {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_HARD, 'no_saved_card');
        }
        if ($checkout && !$card && $spec['nonce'] === '') {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_SOFT, 'card_required');
        }

        $mode = $card ? $card->mode : Config::mode();
        if ($mode === 'test' && Config::mode() === 'live') {
            // A sandbox card would mark a real order paid without moving money.
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_HARD, 'test_card');
        }
        $connection = Connection::ensure($mode, DAY_IN_SECONDS);
        if (!$connection) {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_NETWORK, 'not_connected');
        }
        $account = (string) $connection['account'];
        if ($card && $card->account !== $account) {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_HARD, 'card_unavailable');
        }
        if ($card && $minor === 0) {
            Orders::setMeta($orderId, self::cardMeta($card) + [Meta::MODE => $mode, Meta::ACCOUNT => $account]);
            /* translators: %s: card, e.g. "Visa ending in 1111" */
            Orders::note($orderId, sprintf(__('%s attached for future Braintree payments.', 'stackless-lite'), $card->label()));
            Orders::complete($orderId, '');
            return ['result' => 'success'];
        }

        $merchantAccount = Connection::merchantAccount($mode, $currency, $connection);
        if (!$merchantAccount) {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_SOFT, 'no_merchant_account');
        }
        if ($card && $spec['present'] && $spec['flow'] === Attempt::FLOW_SAVED && $policy !== ThreeDSecure::OFF) {
            // The bank check needs the card form, which only checkout and the pay page have.
            return ['result' => 'action_required', 'message' => __('Confirm this payment with your bank to finish it.', 'stackless-lite'), 'redirect' => Orders::payUrl($orderId)];
        }

        $api = Config::api($mode);
        $nonce = $checkout ? (string) $spec['nonce'] : '';
        if ($card && $policy === ThreeDSecure::OFF) {
            $nonce = '';
        }
        if ($checkout && $card && $policy !== ThreeDSecure::OFF && $nonce === '') {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_AUTH, 'three_d_secure_missing');
        }
        if ($nonce !== '') {
            $read = $api->query(Queries::PAYMENT_METHOD, ['id' => $nonce]);
            if (is_wp_error($read)) {
                return $this->refuseWith($order, $spec, Declines::fromError($read));
            }
            $refusal = ThreeDSecure::refusal($read['node'] ?? null, $policy, $card);
            if ($refusal) {
                return $this->refuseWith($order, $spec, $refusal);
            }
        }

        $save = !$card && $spec['save'];
        $customer = '';
        if ($save) {
            $customer = Vault::customerForOrder($order, $mode, $account);
            if (is_wp_error($customer)) {
                return $this->refuseWith($order, $spec, Declines::fromError($customer));
            }
        }

        if ($minor === 0) {
            $saved = Vault::store($mode, $account, $nonce, $customer, $merchantAccount['id'], $order['user'], Orders::billing($orderId));
            if (is_array($saved)) {
                return $this->refuseWith($order, $spec, $saved);
            }
            Orders::setMeta($orderId, self::cardMeta($saved) + [Meta::MODE => $mode, Meta::ACCOUNT => $account, Meta::MERCHANT_ACCOUNT => $merchantAccount['id']]);
            /* translators: %s: card, e.g. "Visa ending in 1111" */
            Orders::note($orderId, sprintf(__('Saved %s with Braintree for future payments.', 'stackless-lite'), $saved->label()));
            Orders::complete($orderId, '');
            return ['result' => 'success'];
        }

        $attempt = Attempt::create($orderId, [
            'flow' => $spec['flow'],
            'mode' => $mode,
            'account' => $account,
            'merchant_account' => $merchantAccount['id'],
            'minor' => $minor,
            'currency' => $currency,
            'token' => $card ? $card->id : 0,
            'save' => $save,
            'user' => $order['user'],
            'present' => $spec['present'],
            'initiator' => $spec['initiator'],
        ]);
        $meta = [Meta::MODE => $mode, Meta::ACCOUNT => $account, Meta::MERCHANT_ACCOUNT => $merchantAccount['id']];
        if ($card) {
            $meta += self::cardMeta($card);
        }
        Orders::setMeta($orderId, $meta);
        if (!Attempt::save($orderId, $attempt)) {
            return $this->refuse($order, $spec, SubscriptionData::DECLINE_NETWORK, 'not_recorded');
        }
        // Before the call, so a request that dies half way is still reconciled.
        $this->schedule($orderId, self::FIRST_CHECK);

        $extra = [];
        if ($checkout) {
            if ($spec['device_data'] !== '' && Config::deviceData()) {
                $extra['device_data'] = $spec['device_data'];
            }
            if ($nonce !== '' && $policy !== ThreeDSecure::OFF) {
                $extra['require_3ds'] = true;
            }
            if (!$card) {
                $extra['billing'] = Orders::billing($orderId);
                if ($customer !== '') {
                    $extra['customer'] = $customer;
                } elseif ($order['email'] !== '') {
                    $extra['email'] = $order['email'];
                }
            }
        }
        $sent = $this->send($order, $attempt, $nonce !== '' ? $nonce : ($card ? $card->remote : ''), $extra, false);
        if ($sent['state'] === 'paid') {
            return ['result' => 'success'];
        }
        if ($sent['state'] === 'pending') {
            return $this->waiting($orderId, $checkout);
        }
        return $this->failedResult($orderId, $sent['decline'], $spec);
    }

    /**
     * The charge request. A pure function of the attempt for unattended charges, so a repeat
     * sends Braintree exactly the input it saw first.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $extra device_data, require_3ds, billing, customer, email
     * @return array<string,mixed>
     */
    public static function chargeInput(array $attempt, string $paymentMethod, array $extra = []): array
    {
        $transaction = [
            'amount' => Money::value((int) $attempt['minor'], (string) $attempt['currency']),
            'merchantAccountId' => (string) $attempt['merchant_account'],
            'orderId' => (string) $attempt['ref'],
        ];
        if (!empty($attempt['initiator'])) {
            $transaction['paymentInitiator'] = (string) $attempt['initiator'];
        }
        if (!empty($attempt['save']) && !empty($extra['customer'])) {
            $transaction['customerId'] = (string) $extra['customer'];
            $transaction['vaultPaymentMethodAfterTransacting'] = ['when' => 'ON_SUCCESSFUL_TRANSACTION'];
        } elseif (!empty($extra['email'])) {
            $transaction['customerDetails'] = ['email' => (string) $extra['email']];
        }
        if (!empty($extra['device_data'])) {
            $transaction['riskData'] = ['deviceData' => (string) $extra['device_data']];
        }
        $options = [];
        if (!empty($extra['billing'])) {
            $options['billingAddress'] = $extra['billing'];
        }
        if (!empty($extra['require_3ds'])) {
            $options['threeDSecureAuthentication'] = ['required' => true];
        }
        $input = ['apiRequestKey' => (string) $attempt['key'], 'paymentMethodId' => $paymentMethod, 'transaction' => $transaction];
        if ($options) {
            $input['options'] = $options;
        }
        return $input;
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $extra
     * @return array{state:string, decline:array{category:string, code:string}}
     */
    private function send(array $order, array $attempt, string $paymentMethod, array $extra, bool $late): array
    {
        $orderId = $order['id'];
        $result = Config::api($attempt['mode'])->request(Queries::CHARGE, ['input' => self::chargeInput($attempt, $paymentMethod, $extra)], Api::WRITE_TIMEOUT);
        $transaction = $result['data']['chargeCreditCard']['transaction'] ?? null;
        if (is_array($transaction) && !empty($transaction['id'])) {
            if (!empty($transaction['status']) && isset($transaction['amount'], $transaction['orderId'])) {
                return $this->apply($order, $attempt, $transaction, $late);
            }
            $attempt['transaction'] = (string) $transaction['id'];
            Attempt::save($orderId, $attempt);
            return $this->resolveLocked($order, $late, false);
        }

        $error = $result['error'];
        if ($error && Api::isDefinite($error)) {
            if ((int) $attempt['replays'] > 0) {
                // A refused repeat says nothing about the first request unless it was a validation.
                if (in_array(Api::errorClass($error), ['AUTHENTICATION', 'AUTHORIZATION'], true)) {
                    return self::state('pending');
                }
                Attempt::closeAbsent($orderId, $attempt);
                $this->schedule($orderId, self::AUDIT_AFTER);
                $decline = Api::code($error) === '915234' ? ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => 'not_found'] : Declines::fromError($error);
            } else {
                Attempt::close($orderId, $attempt);
                if (Api::inputField($error) === 'customerId') {
                    Vault::forgetCustomer($order, $attempt['mode'], $attempt['account']);
                }
                $decline = Declines::fromError($error);
            }
            $this->decline($order, $attempt, $decline, $late);
            return self::state('failed', $decline);
        }

        Config::log(sprintf('Charge %s for order %d has no known result yet (%s). Searching by reference.', $attempt['ref'], $orderId, $error ? $error->get_error_code() : 'empty response'), 'warning');
        return $this->resolveLocked($order, $late, false);
    }

    /* ---- Settling an attempt ---------------------------------------------------- */

    /**
     * @param array<string,mixed> $order
     * @return array{state:string, decline:array{category:string, code:string}} state paid|pending|failed|absent|none
     */
    private function resolveLocked(array $order, bool $late, bool $mayRepeat): array
    {
        $orderId = $order['id'];
        $attempt = Attempt::current($orderId);
        if (!$attempt) {
            return self::state('none');
        }
        if (Connection::account($attempt['mode']) !== $attempt['account']) {
            // Other credentials cannot see this charge, so they cannot call it absent either.
            return self::state('pending');
        }
        $api = Config::api($attempt['mode']);
        if ($attempt['transaction'] !== '') {
            $read = $api->query(Queries::READ, ['id' => $attempt['transaction']]);
            if (is_wp_error($read) || !is_array($read['node'] ?? null) || empty($read['node']['id'])) {
                return self::state('pending');
            }
            return $this->apply($order, $attempt, $read['node'], $late);
        }

        $found = $this->search($api, $attempt['ref']);
        if (is_wp_error($found)) {
            return self::state('pending');
        }
        if ($found) {
            foreach (array_slice($found, 1) as $extra) {
                if (in_array($extra['status'] ?? '', self::CAPTURED, true)) {
                    $this->flag($orderId, $extra);
                }
            }
            return $this->apply($order, $attempt, $found[0], $late);
        }

        $now = time();
        if (empty($attempt['empty_since'])) {
            $attempt['empty_since'] = $now;
            Attempt::save($orderId, $attempt);
            return self::state('pending');
        }
        if ($now - (int) $attempt['sent'] < self::ABSENT_AFTER || $now - (int) $attempt['empty_since'] < self::EMPTY_GAP) {
            return self::state('pending');
        }

        if ($mayRepeat) {
            $card = $this->repeatableWith($order, $attempt);
            if ($card) {
                $attempt['replays'] = (int) $attempt['replays'] + 1;
                $attempt['sent'] = $now;
                $attempt['empty_since'] = 0;
                if (!Attempt::save($orderId, $attempt)) {
                    return self::state('pending');
                }
                return $this->send($order, $attempt, $card->remote, [], $late);
            }
        }

        Attempt::closeAbsent($orderId, $attempt);
        $this->schedule($orderId, self::AUDIT_AFTER);
        $decline = ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => 'not_found'];
        if ($late || (string) Orders::meta($orderId, Meta::CONFIRMING) !== '') {
            $this->decline($order, $attempt, $decline, true);
            return self::state('failed', $decline);
        }
        return self::state('absent', $decline);
    }

    /**
     * The card to repeat an unattended charge with, when repeating it is still the same request.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $attempt
     */
    private function repeatableWith(array $order, array $attempt): ?Card
    {
        if (
            !in_array($attempt['flow'], [Attempt::FLOW_RENEWAL, Attempt::FLOW_SAVED], true)
            || (int) $attempt['replays'] >= self::MAX_REPLAYS
            || time() - (int) $attempt['started'] >= self::KEY_LIFETIME
            || !$order['payable']
            || $order['currency'] !== $attempt['currency']
            || Money::minor($order['total'], $order['currency']) !== (int) $attempt['minor']
            || !LiveStore::isLive()
        ) {
            return null;
        }
        $card = (int) $attempt['token'] > 0 ? Tokens::get((int) $attempt['token']) : null;
        return $card && $card->account === $attempt['account'] && $card->mode === $attempt['mode'] ? $card : null;
    }

    /**
     * Transactions carrying a reference, the one that matters first.
     *
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    private function search(Api $api, string $reference)
    {
        $data = $api->query(Queries::FIND, ['input' => ['orderId' => ['is' => $reference]], 'first' => 10]);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!is_array($data['transactions']['edges'] ?? null)) {
            return new \WP_Error('stackless_braintree_search', 'The transaction search returned no list.');
        }
        $captured = [];
        $waiting = [];
        $unpaid = [];
        foreach ($data['transactions']['edges'] as $edge) {
            $node = is_array($edge) ? ($edge['node'] ?? null) : null;
            if (!is_array($node) || empty($node['id'])) {
                // An unreadable hit is still a hit: never call it absent.
                return new \WP_Error('stackless_braintree_search', 'The transaction search returned an incomplete result.');
            }
            if ((string) ($node['orderId'] ?? '') !== $reference) {
                continue;
            }
            if (in_array($node['status'] ?? '', self::CAPTURED, true)) {
                $captured[] = $node;
            } elseif (in_array($node['status'] ?? '', self::UNPAID, true)) {
                $unpaid[] = $node;
            } else {
                $waiting[] = $node;
            }
        }
        return array_merge($captured, $waiting, $unpaid);
    }

    /**
     * Apply a transaction read from Braintree to its attempt.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $transaction
     * @return array{state:string, decline:array{category:string, code:string}}
     */
    private function apply(array $order, array $attempt, array $transaction, bool $late): array
    {
        $orderId = $order['id'];
        if ((string) ($transaction['orderId'] ?? '') !== (string) $attempt['ref']) {
            Config::log(sprintf('A Braintree transaction did not carry the reference of order %d. It was not applied.', $orderId), 'error');
            return self::state('pending');
        }
        $status = strtoupper((string) ($transaction['status'] ?? ''));
        $attempt['transaction'] = (string) $transaction['id'];

        if (in_array($status, self::CAPTURED, true)) {
            return $this->captured($order, $attempt, $transaction);
        }
        if (in_array($status, self::UNPAID, true)) {
            // Braintree can retry some declines itself; then this is not known unpaid yet.
            foreach ((array) ($transaction['retriedTransactions'] ?? []) as $retry) {
                if (!is_array($retry) || empty($retry['id']) || $retry['id'] === $transaction['id'] || !in_array($retry['status'] ?? '', self::CAPTURED, true)) {
                    continue;
                }
                $attempt['transaction'] = (string) $retry['id'];
                Attempt::save($orderId, $attempt);
                $read = Config::api($attempt['mode'])->query(Queries::READ, ['id' => $attempt['transaction']]);
                if (is_wp_error($read) || !is_array($read['node'] ?? null) || !in_array($read['node']['status'] ?? '', self::CAPTURED, true)) {
                    return self::state('pending');
                }
                return $this->apply($order, $attempt, $read['node'], $late);
            }
            if (empty($transaction['upcomingRetryDate'])) {
                Attempt::close($orderId, $attempt);
                $decline = Declines::fromTransaction($transaction);
                $this->decline($order, $attempt, $decline, $late, (string) ($transaction['legacyId'] ?? ''));
                return self::state('failed', $decline);
            }
        }
        // Authorizing, settlement pending, or a status newer than this code: pending is not failed.
        Attempt::save($orderId, $attempt);
        $this->schedule($orderId, self::CHECK_DELAYS[0]);
        return self::state('pending');
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $transaction
     * @return array{state:string, decline:array{category:string, code:string}}
     */
    private function captured(array $order, array $attempt, array $transaction): array
    {
        $orderId = $order['id'];
        $order = Orders::summary($orderId);
        if (!$order) {
            return self::state('pending');
        }
        $id = (string) $transaction['id'];
        $legacy = (string) ($transaction['legacyId'] ?? '');

        if ($order['paid']) {
            $ours = (string) Orders::meta($orderId, Meta::TRANSACTION) === $id && ($order['transaction'] === '' || $order['transaction'] === $legacy);
            if (!$ours) {
                $this->flag($orderId, $transaction);
            }
            Attempt::close($orderId, $attempt);
            return self::state('paid');
        }
        if (($attempt['state'] ?? '') === Attempt::HELD) {
            // Left to the owner: it may be refunded by now, which a transaction's status does not show.
            return self::state('pending');
        }

        $currency = (string) $attempt['currency'];
        $paidMinor = Money::read($transaction['amount'] ?? null, $currency);
        $matches = $paidMinor !== null
            && $paidMinor === (int) $attempt['minor']
            && $order['currency'] === $currency
            && Money::minor($order['total'], $currency) === $paidMinor
            && (string) ($transaction['merchantAccountId'] ?? '') === (string) $attempt['merchant_account'];

        $payment = [
            Meta::TRANSACTION => $id,
            Meta::LEGACY_ID => $legacy,
            Meta::MODE => $attempt['mode'],
            Meta::ACCOUNT => $attempt['account'],
            Meta::MERCHANT_ACCOUNT => $attempt['merchant_account'],
        ];

        // Refunds leave a transaction's own status alone.
        $returned = false;
        foreach ((array) ($transaction['refunds'] ?? []) as $refund) {
            $returned = $returned || (is_array($refund) && !in_array($refund['status'] ?? '', self::UNPAID, true));
        }

        if (!$matches || $returned || !$order['completable']) {
            // Kept open: the money is at Braintree, so this order must not be charged again.
            $attempt['state'] = Attempt::HELD;
            Orders::setMeta($orderId, $payment + [Meta::ATTEMPT => $attempt]);
            if ($order['completable']) {
                $note = $returned
                    /* translators: 1: Braintree transaction id, 2: amount and currency */
                    ? __('Braintree transaction %1$s (%2$s) was already refunded, in whole or in part, so it did not complete this order. Check it before fulfilling the order. It will not be charged again.', 'stackless-lite')
                    /* translators: 1: Braintree transaction id, 2: amount and currency */
                    : __('Braintree transaction %1$s took %2$s, which does not match this order. Check it, then refund it or complete the order. It will not be charged again.', 'stackless-lite');
                Orders::holdForReview($orderId, sprintf($note, $legacy, Money::describe($transaction['amount'] ?? null)));
            } else {
                $this->flag($orderId, $transaction);
            }
            Sync::ensureScheduled();
            return self::state('pending');
        }

        if (!empty($attempt['save'])) {
            $card = Card::fromPaymentMethod($transaction['paymentMethod'] ?? null, (int) $attempt['user'], $attempt['mode'], $attempt['account']);
            $card = $card ? Tokens::save($card) : null;
            if ($card) {
                // Before completion: Subscriptions copies the token from the parent order then.
                $payment += self::cardMeta($card);
                /* translators: %s: card, e.g. "Visa ending in 1111" */
                Orders::note($orderId, sprintf(__('Saved %s with Braintree for future payments.', 'stackless-lite'), $card->label()));
            } else {
                Orders::note($orderId, __('The payment went through, but Braintree returned no saved card, so renewals will be invoiced.', 'stackless-lite'));
            }
        }
        // The attempt stays open until the order is really paid: a hook that breaks completion
        // must not leave a captured order open to another charge.
        Orders::setMeta($orderId, $payment + [Meta::ATTEMPT => $attempt]);
        Orders::complete($orderId, $legacy !== '' ? $legacy : $id);
        Sync::ensureScheduled();
        $completed = Orders::summary($orderId);
        if (!$completed || !$completed['paid']) {
            $this->schedule($orderId, self::CHECK_DELAYS[0]);
            return self::state('pending');
        }
        Attempt::close($orderId, $attempt);
        return self::state('paid');
    }

    /**
     * Write the decline, then fail the order where nobody is there to try again.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $attempt
     * @param array{category:string, code:string} $decline
     */
    private function decline(array $order, array $attempt, array $decline, bool $late, string $transaction = ''): void
    {
        $orderId = $order['id'];
        Orders::setMeta($orderId, Declines::meta($decline));
        $note = $transaction !== ''
            /* translators: 1: Braintree transaction id, 2: reason code */
            ? sprintf(__('Braintree transaction %1$s was not paid (%2$s).', 'stackless-lite'), $transaction, $decline['code'])
            /* translators: %s: reason code */
            : sprintf(__('The Braintree charge did not go through (%s).', 'stackless-lite'), $decline['code']);
        $present = $attempt['flow'] === Attempt::FLOW_CHECKOUT
            || (!empty($attempt['present']) && $decline['category'] === SubscriptionData::DECLINE_AUTH);
        if (!$present || $late || (string) Orders::meta($orderId, Meta::CONFIRMING) !== '') {
            Orders::fail($orderId, $note);
        } else {
            Orders::note($orderId, $note);
        }
        Config::log(sprintf('Charge for order %d was not paid: %s (%s).', $orderId, $decline['code'], $decline['category']));
    }

    /**
     * A second payment, or one nobody can use: never completes anything.
     *
     * @param array<string,mixed> $transaction
     */
    private function flag(int $orderId, array $transaction): void
    {
        $id = (string) ($transaction['legacyId'] ?? '') !== '' ? (string) $transaction['legacyId'] : (string) $transaction['id'];
        $flagged = Orders::meta($orderId, Meta::FLAGGED);
        $flagged = is_array($flagged) ? $flagged : [];
        if (in_array($id, $flagged, true)) {
            return;
        }
        $flagged[] = $id;
        Orders::setMeta($orderId, [Meta::FLAGGED => $flagged]);
        /* translators: 1: Braintree transaction id, 2: amount and currency */
        Orders::note($orderId, sprintf(__('Braintree transaction %1$s (%2$s) is an extra payment for this order or arrived too late for it. It did not complete the order: refund it in the Braintree Control Panel.', 'stackless-lite'), $id, Money::describe($transaction['amount'] ?? null)));
        Config::log(sprintf('Order %d has a payment that must be refunded: %s.', $orderId, $id), 'error');
    }

    /* ---- Results ---------------------------------------------------------------- */

    /** @return array<string,mixed> */
    private function waiting(int $orderId, bool $checkout): array
    {
        if ($checkout) {
            Orders::awaitProvider($orderId, __('Braintree has not confirmed this payment yet. Stackless checks it automatically and will not charge the card again meanwhile.', 'stackless-lite'));
        }
        return ['result' => 'pending', 'message' => __('Braintree has not confirmed this payment yet.', 'stackless-lite')];
    }

    /**
     * @param array{category:string, code:string} $decline
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function failedResult(int $orderId, array $decline, array $spec): array
    {
        if ($spec['flow'] === Attempt::FLOW_SAVED && $spec['present'] && $decline['category'] === SubscriptionData::DECLINE_AUTH) {
            $order = Orders::summary($orderId);
            if ($order && $order['payable']) {
                return ['result' => 'action_required', 'message' => Declines::message($decline), 'redirect' => Orders::payUrl($orderId)];
            }
        }
        return ['result' => 'failed', 'message' => Declines::message($decline), 'code' => $decline['code']];
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function refuse(array $order, array $spec, string $category, string $code): array
    {
        return $this->refuseWith($order, $spec, ['category' => $category, 'code' => $code]);
    }

    /**
     * A charge that was never sent.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $spec
     * @param array{category:string, code:string} $decline
     * @return array<string,mixed>
     */
    private function refuseWith(array $order, array $spec, array $decline): array
    {
        $this->decline($order, ['flow' => $spec['flow'], 'present' => $spec['present']], $decline, false);
        return $this->failedResult($order['id'], $decline, $spec);
    }

    /**
     * @param array{category:string, code:string}|null $decline
     * @return array{state:string, decline:array{category:string, code:string}}
     */
    private static function state(string $state, ?array $decline = null): array
    {
        return ['state' => $state, 'decline' => $decline ?? ['category' => SubscriptionData::DECLINE_SOFT, 'code' => 'declined']];
    }

    /** @return array<string,string> */
    private static function cardMeta(Card $card): array
    {
        return [SubscriptionData::META_TOKEN_ID => (string) $card->id, SubscriptionData::META_GATEWAY_CUSTOMER => $card->customer];
    }

    /* ---- Scheduled check -------------------------------------------------------- */

    public function schedule(int $orderId, int $delay): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        $args = ['order_id' => $orderId];
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CHECK_HOOK, $args, self::GROUP);
        }
        as_schedule_single_action(time() + max(60, $delay), self::CHECK_HOOK, $args, self::GROUP);
    }

    /**
     * Action Scheduler callback.
     *
     * @param mixed $orderId
     */
    public static function check($orderId): void
    {
        $orderId = (int) $orderId;
        if (LiveStore::hold(self::CHECK_HOOK, ['order_id' => $orderId], self::GROUP)) {
            return;
        }
        (new self())->checkOrder($orderId);
    }

    public function checkOrder(int $orderId): void
    {
        $ran = Lock::run('order-' . $orderId, function () use ($orderId) {
            $order = Orders::summary($orderId);
            if (!$order) {
                return true;
            }
            if (Attempt::current($orderId)) {
                $this->resolveLocked($order, true, true);
            }
            $wait = $this->auditAbsent($orderId);
            $attempt = Attempt::current($orderId);
            if ($attempt && (int) $attempt['checks'] < self::MAX_CHECKS) {
                $next = self::CHECK_DELAYS[min((int) $attempt['checks'], count(self::CHECK_DELAYS) - 1)];
                $attempt['checks'] = (int) $attempt['checks'] + 1;
                Attempt::save($orderId, $attempt);
                $wait = $wait === null ? $next : min($wait, $next);
            }
            if ($wait !== null) {
                $this->schedule($orderId, $wait);
            }
            return true;
        }, false);
        if ($ran === false) {
            $this->schedule($orderId, self::CHECK_DELAYS[0]);
        }
    }

    /**
     * An absent charge is searched once more, hours later: search lag or a processor can make
     * one appear late.
     *
     * @return int|null Seconds until the next audit.
     */
    private function auditAbsent(int $orderId): ?int
    {
        $list = Attempt::absent($orderId);
        if (!$list) {
            return null;
        }
        $keep = [];
        $next = null;
        foreach ($list as $attempt) {
            $due = (int) ($attempt['closed'] ?? 0) + self::AUDIT_AFTER - time();
            if ($due > 0) {
                $keep[] = $attempt;
                $next = $next === null ? $due : min($next, $due);
                continue;
            }
            if (Connection::account($attempt['mode']) !== $attempt['account']) {
                if ($due > -3 * DAY_IN_SECONDS) {
                    $keep[] = $attempt;
                    $next = self::AUDIT_AFTER;
                }
                continue;
            }
            $found = $this->search(Config::api($attempt['mode']), (string) $attempt['ref']);
            if (is_wp_error($found)) {
                $keep[] = $attempt;
                $next = self::AUDIT_AFTER;
                continue;
            }
            if (!$found || in_array($found[0]['status'] ?? '', self::UNPAID, true)) {
                continue;
            }
            if (!in_array($found[0]['status'] ?? '', self::CAPTURED, true)) {
                // Pending is not absent: look again.
                $keep[] = $attempt;
                $next = self::AUDIT_AFTER;
                continue;
            }
            $order = Orders::summary($orderId);
            if (!$order || Attempt::current($orderId)) {
                $this->flag($orderId, $found[0]);
            } else {
                $this->apply($order, $attempt, $found[0], true);
            }
        }
        Orders::setMeta($orderId, [Meta::ABSENT => $keep ?: null]);
        return $next;
    }

    /* ---- Posted values ---------------------------------------------------------- */

    /** A payment method nonce, or ''. */
    public static function cleanNonce(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_:-]{6,255}$/', $value) ? $value : '';
    }

    /** The data collector's JSON, or ''. */
    public static function cleanDeviceData(string $value): string
    {
        return $value !== '' && strlen($value) <= 4000 && is_array(json_decode($value, true)) ? $value : '';
    }
}
