<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies what the Airwallex API says about an order, and is the only place an order becomes paid.
 * The browser return, webhooks, checkout, renewals and retries all come through reconcile() while
 * holding the order's lock, so an order is settled once.
 *
 * A Checkout Session has no PaymentIntent until the buyer completes it, and an order can see
 * several sessions and charges. They all carry the order's merchant_order_id, so every read looks
 * at the session, the intents the order knows and every intent Airwallex lists for that reference.
 *
 * Outcomes: paid, pending (money may be moving), held (money arrived and a person must look),
 * failed, open (nothing paid, a payment may be started), none (nothing started), error (Airwallex
 * could not be read, so nothing is known).
 */
final class Sync
{
    /** A paid order waits this long for the consent of the card it saved, then completes without it. */
    public const CONSENT_GRACE = 1800;

    private const FAILED_ATTEMPT = ['FAILED', 'EXPIRED', 'CANCELLED'];
    private const OPEN_INTENT = ['REQUIRES_PAYMENT_METHOD', 'REQUIRES_CUSTOMER_ACTION'];
    private const WORKING_ATTEMPT = ['RECEIVED', 'PENDING_AUTHORIZATION', 'AUTHORIZED', 'CAPTURE_REQUESTED'];

    /**
     * The client for the mode and account an order's Airwallex ids belong to.
     *
     * @return Api|\WP_Error
     */
    public static function apiFor(Sale $sale)
    {
        $mode = $sale->text(Meta::MODE);
        if ($mode !== 'live' && $mode !== 'test') {
            return new \WP_Error('stackless_airwallex_mode', __('This order has no Airwallex mode.', 'stackless-lite'), ['status' => 0, 'sent' => false]);
        }
        $api = Settings::api($mode);
        if (!$api->configured() || $api->account() !== $sale->text(Meta::ACCOUNT)) {
            return new \WP_Error('stackless_airwallex_account', __('The Airwallex account this order used is not connected any more.', 'stackless-lite'), ['status' => 0, 'sent' => false]);
        }
        return $api;
    }

    /**
     * Read and apply an order's payments (browser return, webhook, scheduled check).
     *
     * @param string[] $hints Intent ids a verified event named.
     * @return string An outcome, or "busy" when another request holds the order.
     */
    public function refresh(int $orderId, array $hints = []): string
    {
        return (string) Lock::run('order-' . $orderId, function () use ($orderId, $hints) {
            $sale = Sale::get($orderId);
            if (!$sale) {
                return 'none';
            }
            $outcome = $this->reconcile($sale, $hints)['outcome'];
            if (($outcome === 'pending' || $outcome === 'error') && !$sale->data(Meta::CHARGE)) {
                Renewals::scheduleCheck($sale);
            }
            return $outcome;
        }, 'busy');
    }

    /**
     * Call only while holding the order's lock, with a freshly loaded order.
     *
     * @param string[] $hints
     * @return array{outcome:string, session:array|null, intents:array<string,array>}
     */
    public function reconcile(Sale $sale, array $hints = []): array
    {
        $state = ['outcome' => $sale->paid() ? 'paid' : 'none', 'session' => null, 'intents' => []];
        $reference = $sale->reference();
        if ($reference === '') {
            return $state;
        }
        $api = self::apiFor($sale);
        if (is_wp_error($api)) {
            return $this->unreadable($sale, $api, $state);
        }

        $sessionId = Api::id($sale->text(Meta::SESSION));
        if ($sessionId !== '') {
            $session = $api->request('GET', 'checkout/checkout_sessions/' . rawurlencode($sessionId));
            if (is_wp_error($session)) {
                if (!Api::notFound($session)) {
                    return $this->unreadable($sale, $session, $state);
                }
            } elseif ((string) ($session['id'] ?? '') !== $sessionId) {
                return $this->unreadable($sale, null, $state);
            } else {
                $state['session'] = $session;
            }
        }

        $list = $api->request('GET', 'pa/payment_intents', ['merchant_order_id' => $reference, 'page_size' => 100]);
        if (is_wp_error($list) || !is_array($list['items'] ?? null)) {
            return $this->unreadable($sale, is_wp_error($list) ? $list : null, $state);
        }
        $ids = array_merge(
            [$sale->text(Meta::INTENT), $sale->text(Meta::CHARGE_INTENT), $state['session']['payment_intent_id'] ?? ''],
            $hints
        );
        foreach ($list['items'] as $item) {
            if (is_array($item) && (string) ($item['merchant_order_id'] ?? $reference) === $reference && ($item['status'] ?? '') !== 'CANCELLED') {
                $ids[] = $item['id'] ?? '';
            }
        }
        foreach (array_unique(array_filter(array_map([Api::class, 'id'], $ids))) as $id) {
            // The list is a summary. Every decision is made on the intent itself.
            $intent = $api->request('GET', 'pa/payment_intents/' . rawurlencode($id));
            if (is_wp_error($intent)) {
                if (Api::notFound($intent)) {
                    continue;
                }
                return $this->unreadable($sale, $intent, $state);
            }
            if ((string) ($intent['id'] ?? '') === $id && (string) ($intent['merchant_order_id'] ?? '') === $reference) {
                $state['intents'][$id] = $intent;
            }
        }

        $state['outcome'] = $this->apply($sale, $api, $state);
        if ($state['outcome'] === 'paid') {
            if ($sale->text(Meta::CHECKS) !== '' || $sale->text(Meta::RETRIES) !== '') {
                $sale->put([Meta::CHECKS => null, Meta::RETRIES => null]);
            }
        }
        return $state;
    }

    /**
     * @param array{outcome:string, session:array|null, intents:array<string,array>} $state
     * @return array{outcome:string, session:array|null, intents:array<string,array>}
     */
    private function unreadable(Sale $sale, ?\WP_Error $error, array $state): array
    {
        Settings::log(sprintf('Could not read the payments of order %d: %s', $sale->id(), $error ? $error->get_error_message() : 'unexpected response'), 'warning');
        $state['outcome'] = 'error';
        return $state;
    }

    /** @param array{outcome:string, session:array|null, intents:array<string,array>} $state */
    private function apply(Sale $sale, Api $api, array $state): string
    {
        $session = $state['session'];
        $succeeded = [];
        $paidBy = $sale->text(Meta::INTENT);
        foreach ($state['intents'] as $id => $intent) {
            if (($intent['status'] ?? '') === 'SUCCEEDED') {
                if ($id === $paidBy) {
                    array_unshift($succeeded, $intent);
                } else {
                    $succeeded[] = $intent;
                }
            }
        }
        $settled = '';
        foreach ($succeeded as $intent) {
            $result = $this->settle($sale, $api, $intent, $session);
            if ($result !== '' && $settled !== 'paid') {
                $settled = $result;
            }
        }

        $setupDone = $session && ($session['mode'] ?? '') === 'SETUP' && ($session['status'] ?? '') === 'COMPLETED';
        if ($setupDone && $settled === '' && !$sale->paid()) {
            $settled = $this->settleSetup($sale, $api, (array) $session);
        }

        if ($sale->data(Meta::CHARGE)) {
            $charge = $this->resolveCharge($sale, $api, $state['intents']);
            if (!$sale->paid() && $settled !== 'held') {
                return $charge;
            }
        }
        if ($sale->paid()) {
            return 'paid';
        }
        if ($settled !== '') {
            return $settled;
        }

        $busy = false;
        foreach ($state['intents'] as $intent) {
            $busy = $busy || self::isBusy($intent);
        }
        if ($session && ($session['mode'] ?? '') === 'PAYMENT' && ($session['status'] ?? '') === 'COMPLETED') {
            $intent = $state['intents'][(string) ($session['payment_intent_id'] ?? '')] ?? null;
            if (!$intent) {
                return 'pending'; // Completed, and its payment is not readable yet.
            }
            // Vouchers and transfers: checkout is over, the buyer still has to send the money.
            $busy = $busy || ($intent['status'] ?? '') === 'REQUIRES_CUSTOMER_ACTION';
        }
        if ($busy) {
            if ($sale->payable() && $sale->isOurs() && $sale->text(Meta::OFF_SESSION) !== 'yes' && !$sale->isRenewal()) {
                $sale->wait(__('Airwallex is processing the payment. The order updates when Airwallex confirms it.', 'stackless-lite'));
            }
            return 'pending';
        }

        if ($sale->waiting() && $state['intents']) {
            $last = [];
            foreach ($state['intents'] as $intent) {
                if (!in_array($intent['status'] ?? '', ['REQUIRES_PAYMENT_METHOD', 'CANCELLED'], true)) {
                    return 'pending';
                }
                $last = $intent;
            }
            $decline = Declines::fromIntent($last);
            Declines::record($sale, $decline);
            /* translators: %s: reason code */
            $sale->fail(sprintf(__('The Airwallex payment did not go through (%s).', 'stackless-lite'), sanitize_text_field($decline['code'])));
            $sale->put([Meta::WAITING => null]);
            return 'failed';
        }
        return 'open';
    }

    /** Money may be moving: pending, under review, waiting for capture, or a status added later. */
    public static function isBusy(array $intent): bool
    {
        $status = (string) ($intent['status'] ?? '');
        if ($status === 'SUCCEEDED' || $status === 'CANCELLED') {
            return false;
        }
        if (!in_array($status, self::OPEN_INTENT, true)) {
            return true;
        }
        return $status === 'REQUIRES_PAYMENT_METHOD' && in_array(self::attemptStatus($intent), self::WORKING_ATTEMPT, true);
    }

    public static function attemptStatus(array $intent): string
    {
        $attempt = $intent['latest_payment_attempt'] ?? null;
        return is_array($attempt) ? (string) ($attempt['status'] ?? '') : '';
    }

    /* ---- Settlement ------------------------------------------------------------- */

    private function settle(Sale $sale, Api $api, array $intent, ?array $session): string
    {
        $id = (string) $intent['id'];
        if ($sale->paid()) {
            if ($sale->text(Meta::INTENT) !== $id) {
                /* translators: %s: Airwallex payment id */
                $this->flag($sale, $id, 'duplicate', __('Airwallex took payment %s for this order, which was already paid. It did not complete the order again: refund it in Airwallex if the customer paid twice.', 'stackless-lite'));
            }
            return 'paid';
        }
        $reason = $sale->data(Meta::FLAGS)[$id] ?? '';
        if ($reason === 'returned') {
            return ''; // Reviewed and refunded in full: as good as never paid.
        }
        if ($reason === 'mismatch' || $reason === 'refunded' || ($reason !== '' && !$sale->canSettle())) {
            return 'held';
        }

        $currency = $sale->currency();
        $attempt = is_array($intent['latest_payment_attempt'] ?? null) ? $intent['latest_payment_attempt'] : [];
        $captured = $intent['captured_amount'] ?? 0;
        $matches = !$sale->isZero()
            && Money::matches($intent['amount'] ?? null, $intent['currency'] ?? null, $sale->total(), $currency)
            && (!is_numeric($captured) || (float) $captured <= 0 || Money::matches($captured, $currency, $sale->total(), $currency));
        if (!$matches) {
            /* translators: %s: Airwallex payment id */
            return $this->holdPayment($sale, $id, 'mismatch', __('Airwallex payment %s does not match the order’s amount and currency, so it did not complete the order. Check it in Airwallex. Automatic Currency Conversion must be off for this gateway.', 'stackless-lite'));
        }
        if ((float) ($attempt['refunded_amount'] ?? 0) > 0) {
            /* translators: %s: Airwallex payment id */
            return $this->holdPayment($sale, $id, 'refunded', __('Airwallex payment %s was refunded before it could complete the order.', 'stackless-lite'));
        }
        if (!$sale->isOurs()) {
            /* translators: %s: Airwallex payment id */
            return $this->holdPayment($sale, $id, 'gateway', __('Airwallex payment %s arrived after the order moved to another payment method. Refund it in Airwallex, or check that no other payment was taken and complete the order.', 'stackless-lite'));
        }
        if (!$sale->canSettle()) {
            /* translators: %s: Airwallex payment id */
            return $this->holdPayment($sale, $id, 'closed', __('Airwallex took payment %s for an order that is no longer waiting for payment. Refund it in Airwallex or complete the order.', 'stackless-lite'));
        }

        $values = [Meta::INTENT => $id, Meta::WAITING => null];
        if ($sale->text(Meta::SAVE) === 'yes') {
            $sessionIntent = (string) ($session['payment_intent_id'] ?? '');
            $ofSession = $sessionIntent === '' || $sessionIntent === $id;
            $consent = Api::id(($ofSession ? ($session['payment_consent_id'] ?? null) : null) ?? ($intent['payment_consent_id'] ?? ($attempt['payment_consent_id'] ?? null)));
            if ($consent !== '') {
                $saved = $this->saveConsent($api, $consent, $this->ownerId($sale), $sale->text(Meta::CUSTOMER));
            } else {
                // A payment webhook can beat the session's completion, which carries the consent.
                $saved = $session && ($session['status'] ?? '') === 'ACTIVE' ? new \WP_Error('stackless_airwallex_consent', 'The consent is not ready.') : null;
            }
            if (is_wp_error($saved)) {
                $since = (int) $sale->text(Meta::CONSENT_WAIT);
                if ($since === 0 || $since > time() - self::CONSENT_GRACE) {
                    if ($since === 0) {
                        $sale->put([Meta::CONSENT_WAIT => (string) time()]);
                    }
                    return 'pending';
                }
                $saved = null;
            }
            if ($saved instanceof SavedMethod) {
                // Before the order completes: Subscriptions copies these at that moment.
                $values[SubscriptionData::META_TOKEN_ID] = (string) $saved->id;
                $values[SubscriptionData::META_GATEWAY_CUSTOMER] = $saved->customer;
                /* translators: %s: saved method, e.g. "Visa ending in 4242" */
                $sale->note(sprintf(__('Saved %s for automatic payments.', 'stackless-lite'), $saved->displayName()));
            } else {
                $sale->note(__('Airwallex did not leave a card that this store may charge later, so renewals need a new payment method.', 'stackless-lite'));
            }
            $values[Meta::CONSENT_WAIT] = null;
        }

        $flags = $sale->data(Meta::FLAGS);
        if (isset($flags[$id])) {
            unset($flags[$id]);
            $values[Meta::FLAGS] = $flags ?: null;
            $values[Meta::REVIEW] = array_diff($flags, ['returned']) ? 'yes' : null;
        }
        $sale->put($values);
        return $sale->complete($id) ? 'paid' : 'pending';
    }

    /** A SETUP session saved a card for an order with nothing to pay (a free trial). */
    private function settleSetup(Sale $sale, Api $api, array $session): string
    {
        $customer = $sale->text(Meta::CUSTOMER);
        if (!$sale->isZero() || !$sale->isOurs() || !$sale->canSettle() || $customer === ''
            || (string) ($session['id'] ?? '') !== $sale->text(Meta::SESSION)
            || (string) ($session['customer_id'] ?? '') !== $customer
            || (string) ($session['account_id'] ?? '') !== $sale->text(Meta::ACCOUNT)) {
            return 'open';
        }
        $consent = Api::id($session['payment_consent_id'] ?? null);
        if ($consent === '') {
            return 'failed';
        }
        $saved = $this->saveConsent($api, $consent, $this->ownerId($sale), $customer);
        if (is_wp_error($saved)) {
            $since = (int) $sale->text(Meta::CONSENT_WAIT);
            if ($since === 0) {
                $sale->put([Meta::CONSENT_WAIT => (string) time()]);
            }
            // Nothing was charged, so after the wait the buyer may simply save a card again.
            return $since === 0 || $since > time() - self::CONSENT_GRACE ? 'pending' : 'failed';
        }
        if (!$saved) {
            return 'failed';
        }
        $sale->put([
            SubscriptionData::META_TOKEN_ID => (string) $saved->id,
            SubscriptionData::META_GATEWAY_CUSTOMER => $saved->customer,
            Meta::WAITING => null,
        ]);
        /* translators: %s: saved method, e.g. "Visa ending in 4242" */
        $sale->note(sprintf(__('Saved %s for automatic payments.', 'stackless-lite'), $saved->displayName()));
        return $sale->complete() ? 'paid' : 'pending';
    }

    private function ownerId(Sale $sale): int
    {
        $owner = $sale->text(Meta::OWNER);
        return strpos($owner, 'u:') === 0 ? (int) substr($owner, 2) : 0;
    }

    /**
     * Save a consent only when Airwallex says the merchant may charge it, for this customer.
     *
     * @return SavedMethod|\WP_Error|null null = never usable; an error = not known yet.
     */
    public function saveConsent(Api $api, string $consentId, int $userId, string $customerId)
    {
        if ($customerId === '') {
            return null;
        }
        $consent = $api->request('GET', 'pa/payment_consents/' . rawurlencode($consentId));
        if (is_wp_error($consent)) {
            return Api::notFound($consent) ? null : $consent;
        }
        if ((string) ($consent['id'] ?? '') !== $consentId
            || (string) ($consent['customer_id'] ?? '') !== $customerId
            || strtolower((string) ($consent['next_triggered_by'] ?? '')) !== 'merchant'
            || ($consent['payment_method']['type'] ?? '') !== 'card') {
            Settings::log(sprintf('Consent %s was not saved: not a merchant-triggered card consent of the expected customer.', $consentId), 'warning');
            return null;
        }
        $status = (string) ($consent['status'] ?? '');
        if ($status === 'DISABLED') {
            return null;
        }
        if ($status !== 'VERIFIED') {
            return new \WP_Error('stackless_airwallex_consent', 'The consent is not verified yet.');
        }
        return Tokens::save($consent, $userId, $customerId, $api->mode(), $api->account());
    }

    private function holdPayment(Sale $sale, string $id, string $reason, string $template): string
    {
        $note = sprintf($template, $id);
        if ($this->flag($sale, $id, $reason, $template, !$sale->canSettle())) {
            if ($sale->canSettle()) {
                $sale->hold($note);
                $sale->put([Meta::WAITING => null]);
            }
        }
        return 'held';
    }

    /** @return bool false when the payment was already flagged */
    private function flag(Sale $sale, string $id, string $reason, string $template, bool $note = true): bool
    {
        $flags = $sale->data(Meta::FLAGS);
        if (isset($flags[$id])) {
            return false;
        }
        $flags[$id] = $reason;
        $sale->put([Meta::FLAGS => $flags, Meta::REVIEW => 'yes']);
        if ($note) {
            $sale->note(sprintf($template, $id));
        }
        Settings::log(sprintf('Payment %s on order %d needs review (%s).', $id, $sale->id(), $reason), 'warning');
        return true;
    }

    /* ---- Off-session charges and replacement ------------------------------------ */

    /**
     * A charge is only failed once Airwallex says it is unpaid.
     *
     * @param array<string,array> $intents
     */
    private function resolveCharge(Sale $sale, Api $api, array $intents): string
    {
        $charge = $sale->data(Meta::CHARGE);
        $id = (string) ($charge['intent'] ?? '');
        $intent = $intents[$id] ?? null;
        if ($sale->paid()) {
            if ($intent && in_array($intent['status'] ?? '', self::OPEN_INTENT, true) && !self::isBusy($intent) && !$this->cancelIntent($api, $id)) {
                return 'pending'; // Keep it until it cannot take a second payment.
            }
            if ($intent && self::isBusy($intent)) {
                return 'pending';
            }
            $sale->put([Meta::CHARGE => null, Meta::RETRIES => null]);
            return 'paid';
        }
        if (!$intent) {
            return 'pending';
        }
        $status = (string) ($intent['status'] ?? '');
        if ($status === 'SUCCEEDED') {
            if (isset($sale->data(Meta::FLAGS)[$id])) {
                $sale->put([Meta::CHARGE => null, Meta::RETRIES => null]);
                return 'held';
            }
            return 'pending';
        }
        if ($status === 'CANCELLED') {
            $decline = is_array($charge['decline'] ?? null) ? $charge['decline'] : Declines::fromIntent($intent);
            return $this->failCharge($sale, $decline);
        }
        if ($status === 'REQUIRES_CUSTOMER_ACTION') {
            // Nobody is there to authenticate. Cancel it, then the customer gets a pay link.
            return $this->cancelIntent($api, $id) ? $this->failCharge($sale, Declines::decline('authentication_required')) : 'pending';
        }
        if ($status === 'REQUIRES_PAYMENT_METHOD' && in_array(self::attemptStatus($intent), self::FAILED_ATTEMPT, true)) {
            $decline = Declines::fromIntent($intent);
            if ($decline['category'] === SubscriptionData::DECLINE_SOFT && is_array($charge['answer'] ?? null)) {
                $decline = $charge['answer'];
            }
            return $this->failCharge($sale, $decline);
        }
        return 'pending';
    }

    /** @param array{category:string, code:string} $decline */
    public function failCharge(Sale $sale, array $decline): string
    {
        // Decline details first: Dunning reacts to the status change and reads them then.
        Declines::record($sale, $decline);
        $sale->put([Meta::CHARGE => null, Meta::RETRIES => null]);
        /* translators: %s: reason code */
        $sale->fail(sprintf(__('Airwallex charge failed (%s).', 'stackless-lite'), sanitize_text_field($decline['code'])));
        Settings::log(sprintf('Charge for order %d failed: %s', $sale->id(), $decline['code']), 'warning');
        return 'failed';
    }

    /** @return bool true only when Airwallex says the intent is cancelled */
    public function cancelIntent(Api $api, string $id): bool
    {
        $result = $api->request('POST', 'pa/payment_intents/' . rawurlencode($id) . '/cancel', ['request_id' => Api::requestId()]);
        return is_array($result) && (string) ($result['id'] ?? '') === $id && ($result['status'] ?? '') === 'CANCELLED';
    }

    /**
     * Before any new payment for an order: close the session and every intent that could still
     * be paid, and confirm it.
     *
     * @param array{outcome:string, session:array|null, intents:array<string,array>} $state
     * @return string free|paid|held|busy
     */
    public function release(Sale $sale, array $state): string
    {
        $blocked = $this->blocked($sale, $state);
        if ($blocked !== '') {
            return $blocked;
        }
        if ($sale->reference() === '') {
            return 'free';
        }
        $api = self::apiFor($sale);
        if (is_wp_error($api)) {
            return 'busy';
        }
        $session = $state['session'];
        if ($session && !in_array($session['status'] ?? '', ['ACTIVE', 'COMPLETED', 'CANCELLED', 'EXPIRED'], true)) {
            return 'busy'; // A status this module does not know may still take money.
        }
        if ($session && ($session['status'] ?? '') === 'ACTIVE') {
            $path = 'checkout/checkout_sessions/' . rawurlencode((string) $session['id']);
            $closed = $api->request('POST', $path . '/cancel');
            if (is_wp_error($closed)) {
                $closed = $api->request('GET', $path); // It may have expired or completed meanwhile.
            }
            if (is_wp_error($closed) || !in_array($closed['status'] ?? '', ['CANCELLED', 'EXPIRED'], true)) {
                return 'busy';
            }
            // The buyer may have paid just before it closed.
            $state = $this->reconcile($sale);
            $blocked = $this->blocked($sale, $state);
            if ($blocked !== '') {
                return $blocked;
            }
            if ($state['session'] && ($state['session']['status'] ?? '') === 'ACTIVE') {
                return 'busy';
            }
        }
        foreach ($state['intents'] as $id => $intent) {
            if (!in_array($intent['status'] ?? '', ['CANCELLED', 'SUCCEEDED'], true) && !$this->cancelIntent($api, (string) $id)) {
                return 'busy';
            }
        }
        return 'free';
    }

    /** @param array{outcome:string, session:array|null, intents:array<string,array>} $state */
    private function blocked(Sale $sale, array $state): string
    {
        if ($sale->paid() || $state['outcome'] === 'paid') {
            return 'paid';
        }
        if ($state['outcome'] === 'held') {
            return 'held';
        }
        return in_array($state['outcome'], ['pending', 'error'], true) || $sale->data(Meta::CHARGE) ? 'busy' : '';
    }
}
