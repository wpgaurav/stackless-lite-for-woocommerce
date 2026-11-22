<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies what Revolut says to a store order: payment, saved card, declines, refunds made in
 * Revolut and disputes. The browser return, the webhook, scheduled checks and charges all come
 * through here under one lock per order, and only ever act on an order read from the Revolut API.
 */
final class Sync
{
    /** A card that cannot be read back is retried this often before a paid order completes without it. */
    private const SAVE_RETRIES = 3;

    /**
     * Read a Revolut order and apply it.
     *
     * @return string paid|waiting|open|failed|mismatch|duplicate|nosave|retry|busy, or "ignored: …"
     */
    public function apply(int $orderId, string $id, string $mode): string
    {
        return (string) Lock::order($orderId, function () use ($orderId, $id, $mode) {
            return $this->applyLocked($orderId, $id, $mode);
        }, 'busy');
    }

    private function applyLocked(int $orderId, string $id, string $mode): string
    {
        if (!Orders::exists($orderId)) {
            return 'ignored: no order';
        }
        $remote = Remote::read(Settings::api($mode), $id);
        if (is_wp_error($remote)) {
            return Api::status($remote) === 404 ? 'ignored: unknown to Revolut' : 'retry';
        }
        $attempt = Attempts::match($orderId, $remote, $mode);
        if (!$attempt) {
            return 'ignored: not a payment of this order';
        }
        if ((string) $attempt['id'] === '') {
            $attempt = Attempts::update($orderId, $attempt, ['id' => $id]);
        }

        $class = Remote::classify($remote);
        if ($class === Remote::PAID) {
            return $this->settle($orderId, $attempt, $remote);
        }
        if (!empty($attempt['closed'])) {
            return $class === Remote::DEAD ? 'failed' : 'ignored: closed attempt';
        }
        if (Orders::settled($orderId)) {
            return 'ignored: order already settled';
        }

        if ($attempt['flow'] === Attempts::FLOW_CHARGE) {
            if (in_array($class, [Remote::CHALLENGE, Remote::DECLINED, Remote::DEAD], true)) {
                $resolved = Remote::resolve($orderId, $attempt);
                if ($resolved['state'] === 'paid') {
                    return $this->settle($orderId, $attempt, $resolved['remote']);
                }
                if ($resolved['state'] === 'closed') {
                    (new Charges())->fail($orderId, $resolved['decline'] ?? Declines::fromOrder($remote));
                    return 'failed';
                }
            }
            self::waiting($orderId);
            return 'waiting';
        }

        if ($class === Remote::PROCESSING || $class === Remote::ACTIVE) {
            self::waiting($orderId);
            return 'waiting';
        }
        if (Orders::meta($orderId, Keys::WAITING) !== '') {
            Orders::deleteMeta($orderId, Keys::WAITING);
        }
        if ($class === Remote::DEAD) {
            Attempts::close($orderId, $attempt);
            return 'failed';
        }
        // The same Revolut order can take another try, so the store order stays payable.
        return $class === Remote::DECLINED ? 'failed' : 'open';
    }

    public static function waiting(int $orderId): void
    {
        if (Orders::meta($orderId, Keys::WAITING) !== 'yes') {
            Orders::setMeta($orderId, [Keys::WAITING => 'yes']);
        }
        Jobs::later($orderId);
    }

    /**
     * A paid Revolut order. The caller holds the order lock.
     *
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $remote
     */
    public function settle(int $orderId, array $attempt, array $remote): string
    {
        $id = (string) ($remote['id'] ?? '');
        $mode = (string) $attempt['mode'];
        if ($id === '' || ((string) $attempt['id'] !== '' && (string) $attempt['id'] !== $id) || (isset($remote['type']) && $remote['type'] !== 'payment')) {
            return 'ignored: not a payment of this order';
        }
        $currency = Orders::currency($orderId);
        if (Orders::settled($orderId)) {
            Attempts::close($orderId, $attempt);
            if (Orders::meta($orderId, Keys::PAID) === $id) {
                return 'paid';
            }
            if ((int) ($remote['amount'] ?? 0) > 0 && self::reportOnce($orderId, 'duplicate:' . $id)) {
                self::review($orderId);
                Orders::note($orderId, sprintf(
                    /* translators: 1: amount, 2: Revolut order id */
                    __('A second payment of %1$s arrived through Revolut (order %2$s) for this already paid order. It did not complete the order again. Refund it in Revolut Business.', 'stackless-lite'),
                    Money::display((int) ($remote['amount'] ?? 0), (string) ($remote['currency'] ?? $currency)),
                    $id
                ));
            }
            return 'duplicate';
        }
        if ($mode === 'test' && Settings::mode() === 'live') {
            Attempts::close($orderId, $attempt);
            if (self::reportOnce($orderId, 'sandbox:' . $id)) {
                Orders::note($orderId, __('A Revolut sandbox payment was ignored: the store takes live payments.', 'stackless-lite'));
            }
            return 'ignored: sandbox payment on a live store';
        }

        $expected = Money::toMinor(Orders::total($orderId), $currency);
        $outstanding = isset($remote['outstanding_amount']) && ($remote['state'] ?? '') === 'completed' ? (int) $remote['outstanding_amount'] : 0;
        if (!Money::matches($remote, max(0, $expected), $currency) || $outstanding !== 0) {
            Attempts::close($orderId, $attempt);
            if (self::reportOnce($orderId, 'mismatch:' . $id)) {
                Orders::setMeta($orderId, [Keys::MODE => $mode]);
                self::review($orderId);
                Orders::hold($orderId, sprintf(
                    /* translators: 1: Revolut order id, 2: amount paid, 3: amount expected */
                    __('Revolut payment %1$s was for %2$s, but this order is for %3$s. The order was not completed. Check the payment before fulfilling it.', 'stackless-lite'),
                    $id,
                    Money::display((int) ($remote['amount'] ?? 0), (string) ($remote['currency'] ?? '')),
                    Money::display($expected, $currency)
                ));
            }
            return 'mismatch';
        }
        // A refunded order stays "completed" at Revolut; refunded_amount is all that shows it.
        $refunded = (int) ($remote['refunded_amount'] ?? 0);
        if ($refunded > 0) {
            Attempts::close($orderId, $attempt);
            if (self::reportOnce($orderId, 'refunded:' . $id)) {
                Orders::setMeta($orderId, [Keys::MODE => $mode]);
                self::review($orderId);
                Orders::hold($orderId, sprintf(
                    /* translators: 1: amount refunded, 2: Revolut order id */
                    __('%1$s of Revolut payment %2$s was already refunded, so the order was not completed. Check the payment before fulfilling it.', 'stackless-lite'),
                    Money::display($refunded, $currency),
                    $id
                ));
            }
            return 'mismatch';
        }

        if ($attempt['flow'] === Attempts::FLOW_SAVE) {
            // The card goes to the account that started the payment, if the order is still that account's.
            $user = (int) $attempt['user'] === Orders::user($orderId) ? (int) $attempt['user'] : 0;
            $saved = $this->saveCard($remote, $user, (string) $attempt['customer'], $mode);
            if ($saved['state'] === 'retry' && ($expected <= 0 || Jobs::count($orderId) < self::SAVE_RETRIES)) {
                self::waiting($orderId);
                return 'retry';
            }
            if ($saved['state'] === 'saved') {
                // Before the order completes: Subscriptions copies these at that moment.
                Orders::setMeta($orderId, [
                    SubscriptionData::META_TOKEN_ID => (string) $saved['token'],
                    SubscriptionData::META_GATEWAY_CUSTOMER => (string) $attempt['customer'],
                ]);
                /* translators: %s: saved card, e.g. "Visa ending in 4242" */
                Orders::note($orderId, sprintf(__('Saved %s for future payments.', 'stackless-lite'), Tokens::label($saved['token'])));
            } elseif ($expected <= 0) {
                // Nothing was paid and nothing was saved: a trial with no way to renew does not start.
                Attempts::close($orderId, $attempt);
                Orders::deleteMeta($orderId, Keys::WAITING);
                if (self::reportOnce($orderId, 'nosave:' . $id)) {
                    Orders::note($orderId, __('Revolut did not save the card for merchant use, so this free order was not completed. The customer can try again.', 'stackless-lite'));
                }
                return 'nosave';
            } elseif (self::reportOnce($orderId, 'nosave:' . $id)) {
                Orders::note($orderId, __('Revolut did not save the card for merchant use, so renewals will be invoiced.', 'stackless-lite'));
            }
        }

        Orders::setMeta($orderId, [Keys::PAID => $id, Keys::MODE => $mode]);
        if (!Orders::complete($orderId, $id)) {
            // A hook failed, or the order's status cannot be completed: try again, and tell the owner.
            if (self::reportOnce($orderId, 'incomplete:' . $id)) {
                self::review($orderId);
                /* translators: %s: Revolut order id */
                Orders::note($orderId, sprintf(__('Revolut payment %s arrived, but the order could not be completed yet.', 'stackless-lite'), $id));
            }
            self::waiting($orderId);
            return 'retry';
        }
        Attempts::close($orderId, $attempt);
        foreach ([Keys::WAITING, Keys::CHARGE, Keys::CHECKS, Keys::REVIEW] as $key) {
            if (Orders::meta($orderId, $key) !== '') {
                Orders::deleteMeta($orderId, $key);
            }
        }
        Settings::log(sprintf('Revolut order %s paid order %d (%s).', $id, $orderId, $mode));
        return 'paid';
    }

    /**
     * Save the card of a paid widget order, only when Revolut says it may be charged by the merchant.
     *
     * @param array<string,mixed> $remote
     * @return array{state:string, token:int} state: saved | none | retry
     */
    public function saveCard(array $remote, int $userId, string $customer, string $mode): array
    {
        $none = ['state' => 'none', 'token' => 0];
        if ($customer === '' || Remote::customerId($remote) !== $customer) {
            return $none;
        }
        $methodId = Remote::savedMethodId($remote);
        if ($methodId === '') {
            return $none;
        }
        $method = Settings::api($mode)->request('GET', 'customers/' . rawurlencode($customer) . '/payment-methods/' . rawurlencode($methodId));
        if (is_wp_error($method)) {
            return Api::isTransient($method) || Api::status($method) === 404 ? ['state' => 'retry', 'token' => 0] : $none;
        }
        if ((string) ($method['id'] ?? '') !== $methodId || ($method['type'] ?? '') !== 'card' || ($method['saved_for'] ?? '') !== 'merchant') {
            return $none;
        }
        $token = Tokens::save($method, $userId, $customer, $mode);
        return $token > 0 ? ['state' => 'saved', 'token' => $token] : ['state' => 'retry', 'token' => 0];
    }

    /**
     * Account page → add a card: save it once the zero-amount order is complete.
     *
     * @return array{state:string, token:int} state: saved | none | retry | open
     */
    public function applyAdd(int $userId, string $id, string $mode): array
    {
        $none = ['state' => 'none', 'token' => 0];
        $result = Lock::run('add-' . $id, function () use ($userId, $id, $mode, $none) {
            $session = Adds::byId($userId, $id, $mode);
            if (!$session) {
                return $none;
            }
            if ((int) $session['token'] > 0) {
                return ['state' => 'saved', 'token' => (int) $session['token']];
            }
            $remote = Remote::read(Settings::api($mode), $id);
            if (is_wp_error($remote)) {
                return Api::status($remote) === 404 ? $none : ['state' => 'retry', 'token' => 0];
            }
            $class = Remote::classify($remote);
            if ($class === Remote::DEAD) {
                return $none;
            }
            if ($class !== Remote::PAID) {
                return ['state' => 'open', 'token' => 0];
            }
            if (($remote['amount'] ?? null) !== 0 || (string) ($remote['currency'] ?? '') !== (string) $session['currency']) {
                return $none;
            }
            $saved = $this->saveCard($remote, $userId, (string) $session['customer'], $mode);
            if ($saved['state'] === 'saved') {
                Adds::update($userId, $session, ['token' => $saved['token']]);
            }
            return $saved;
        }, ['state' => 'retry', 'token' => 0]);
        return is_array($result) ? $result : $none;
    }

    /**
     * A Revolut refund order for one of this order's payments.
     *
     * @param array<string,mixed> $refund
     */
    public function applyRefund(int $orderId, array $refund, string $relatedId, string $mode): string
    {
        return (string) Lock::order($orderId, function () use ($orderId, $refund, $relatedId, $mode) {
            return $this->refundLocked($orderId, $refund, $relatedId, $mode);
        }, 'busy');
    }

    /** @param array<string,mixed> $refund */
    private function refundLocked(int $orderId, array $refund, string $relatedId, string $mode): string
    {
        $id = (string) ($refund['id'] ?? '');
        if ($id === '') {
            return 'ignored: no refund id';
        }
        $minor = (int) ($refund['amount'] ?? 0);
        $currency = (string) ($refund['currency'] ?? '');
        $failed = RefundService::failed($refund);
        $known = in_array($id, RefundService::known($orderId), true);

        if ($relatedId !== Orders::meta($orderId, Keys::PAID) || $mode !== Orders::mode($orderId)) {
            // Refund of a second payment: it changes nothing on the order it did not pay.
            if (!$failed && ($refund['state'] ?? '') === 'completed' && self::reportOnce($orderId, 'refund:' . $id)) {
                /* translators: 1: amount, 2: Revolut order id */
                Orders::note($orderId, sprintf(__('Revolut refunded %1$s of the extra payment %2$s.', 'stackless-lite'), Money::display($minor, $currency), $relatedId));
                foreach (Attempts::all($orderId) as $attempt) {
                    // Part of it back still leaves money to look at.
                    if ((string) $attempt['id'] === $relatedId && $minor >= (int) $attempt['amount']) {
                        Orders::deleteMeta($orderId, Keys::REVIEW);
                    }
                }
            }
            return 'ignored: refund of another payment';
        }
        if ($failed) {
            if ($known && self::reportOnce($orderId, 'refund-failed:' . $id)) {
                self::review($orderId);
                /* translators: 1: amount, 2: Revolut refund id */
                Orders::note($orderId, sprintf(__('Revolut could not complete the refund of %1$s (%2$s). The customer has not received it. Check it in Revolut Business.', 'stackless-lite'), Money::display($minor, $currency), $id));
            }
            return 'refund failed';
        }
        if ($known) {
            return 'refund known';
        }
        if (($refund['state'] ?? '') !== 'completed') {
            return 'refund pending';
        }
        if (strtoupper($currency) !== Orders::currency($orderId) || $minor <= 0) {
            return 'ignored: refund currency';
        }
        RefundService::remember($orderId, $id);
        $amount = min(Money::fromMinor($minor, $currency), Orders::remainingRefund($orderId));
        if ($amount <= 0) {
            return 'refund already covered';
        }
        /* translators: %s: Revolut refund id */
        if (!Orders::recordRefund($orderId, $amount, $id, sprintf(__('Refunded in Revolut (%s).', 'stackless-lite'), $id))) {
            RefundService::forget($orderId, $id);
            return 'retry';
        }
        return 'refund recorded';
    }

    /**
     * A dispute as read from Revolut.
     *
     * @param array<string,mixed> $dispute
     */
    public function applyDispute(int $orderId, array $dispute): string
    {
        return (string) Lock::order($orderId, static function () use ($orderId, $dispute) {
            $id = (string) ($dispute['id'] ?? '');
            $state = (string) ($dispute['state'] ?? '');
            if ($id === '' || !in_array($state, ['needs_response', 'under_review', 'won', 'lost'], true)) {
                return 'ignored: dispute state';
            }
            if (!self::reportOnce($orderId, 'dispute:' . $id . ':' . $state)) {
                return 'dispute known';
            }
            $amount = Money::display((int) ($dispute['amount'] ?? 0), (string) ($dispute['currency'] ?? Orders::currency($orderId)));
            if ($state === 'won') {
                /* translators: %s: amount */
                Orders::note($orderId, sprintf(__('The Revolut dispute of %s was won.', 'stackless-lite'), $amount));
            } else {
                $note = $state === 'lost'
                    /* translators: %s: amount */
                    ? sprintf(__('The Revolut dispute of %s was lost.', 'stackless-lite'), $amount)
                    /* translators: %s: amount */
                    : sprintf(__('Revolut reports a dispute of %s on this payment. Answer it in Revolut Business.', 'stackless-lite'), $amount);
                // Held once, so an owner who releases the order is not overruled by the next update.
                if (self::reportOnce($orderId, 'dispute-hold:' . $id)) {
                    Orders::dispute($orderId, $id, $note);
                } else {
                    Orders::note($orderId, $note);
                }
            }
            return 'dispute ' . $state;
        }, 'busy');
    }

    /** Flag money the store must look at, for Billing Health. */
    public static function review(int $orderId): void
    {
        Orders::setMeta($orderId, [Keys::REVIEW => 'yes', Keys::REVIEW_AT => (string) time()]);
    }

    /** True the first time a thing is reported on an order. */
    public static function reportOnce(int $orderId, string $what): bool
    {
        $reported = Orders::metaList($orderId, Keys::REPORTED);
        if (in_array($what, $reported, true)) {
            return false;
        }
        $reported[] = $what;
        Orders::setMeta($orderId, [Keys::REPORTED => array_values($reported)]);
        return true;
    }
}
