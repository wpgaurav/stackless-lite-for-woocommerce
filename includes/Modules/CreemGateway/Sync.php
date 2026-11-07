<?php

namespace Stackless\Modules\CreemGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Applies Creem checkout, payment, renewal, refund and subscription state to WooCommerce.
 *
 * Every entry point (browser return, webhook) funnels through these methods and each is safe
 * to repeat: orders are settled under a lock and only while unpaid, renewals are keyed by
 * Creem transaction id, refunds by Creem refund id. Creem sends checkout.completed and
 * subscription.paid for a subscription's first payment; whichever lands first records it.
 */
class Sync
{
    /** Every subscription status: wc_get_orders() leaves wc-sub-* out of its default (and "any") status list. */
    private const SUBSCRIPTION_STATUSES = [
        'wc-' . SubscriptionData::STATUS_PENDING,
        'wc-' . SubscriptionData::STATUS_TRIAL,
        'wc-' . SubscriptionData::STATUS_ACTIVE,
        'wc-' . SubscriptionData::STATUS_ON_HOLD,
        'wc-' . SubscriptionData::STATUS_PENDING_CANCEL,
        'wc-' . SubscriptionData::STATUS_CANCELLED,
        'wc-' . SubscriptionData::STATUS_EXPIRED,
    ];

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /* ---- Lookups ------------------------------------------------------------------ */

    /** A shop order of this gateway (original or copy) with a meta value. */
    public function findOrder(string $key, string $value): ?\WC_Order
    {
        if ($value === '') {
            return null;
        }
        $order = OrderQuery::findByMeta($key, $value);
        return $order && $order->get_payment_method() === $this->gateway->id ? $order : null;
    }

    /** WooCommerce subscription linked to a Creem subscription (by stored id, else by checkout metadata). */
    public function findSubscription(string $creemId, array $metadata): ?\WC_Order
    {
        if ($creemId !== '') {
            $first = OrderQuery::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId, ['type' => 'stackless_sub', 'status' => self::SUBSCRIPTION_STATUSES]);
            if ($first instanceof \WC_Order) {
                $subscription = stackless_get_subscription($first->get_id());
                return $subscription instanceof \WC_Order ? $subscription : $first;
            }
        }
        if (!self::forThisSite($metadata) || (string) ($metadata['wc_gateway'] ?? $this->gateway->id) !== $this->gateway->id) {
            return null;
        }
        $wcId = absint($metadata['wc_subscription_id'] ?? 0);
        $subscription = $wcId ? stackless_get_subscription($wcId) : null;
        if (!$subscription instanceof \WC_Order) {
            return null;
        }
        $parent = absint($metadata['wc_order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_id() !== $parent) || ($linked !== '' && $creemId !== '' && $linked !== $creemId)) {
            return null;
        }
        return $subscription;
    }

    private static function forThisSite(array $metadata): bool
    {
        $site = (string) ($metadata['wc_site'] ?? '');
        return $site === '' || $site === Catalog::siteHost();
    }

    /** @return array|\WP_Error */
    public function subscription(string $creemId, string $mode)
    {
        return $this->gateway->api($mode)->request('GET', 'v1/subscriptions', ['subscription_id' => $creemId]);
    }

    /* ---- First payment ------------------------------------------------------------- */

    /**
     * Read the order's checkout session back from Creem and apply it.
     *
     * @return true|\WP_Error stackless_creem_expired when the session ran out unpaid.
     */
    public function confirmCheckout(\WC_Order $order)
    {
        $checkoutId = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        if ($checkoutId === '') {
            return new \WP_Error('stackless_creem_no_session', __('No Creem checkout was started for this order.', 'stackless-lite'));
        }
        $mode = $this->gateway->orderMode($order);
        $session = $this->gateway->api($mode)->request('GET', 'v1/checkouts', ['checkout_id' => $checkoutId]);
        if (is_wp_error($session)) {
            return $session;
        }
        $status = (string) ($session['status'] ?? '');
        if ($status === 'expired') {
            return new \WP_Error('stackless_creem_expired', __('This Creem checkout expired before it was paid.', 'stackless-lite'));
        }
        if ($status !== 'completed') {
            return new \WP_Error('stackless_creem_pending', __('Creem has not completed this checkout yet. It will be confirmed automatically.', 'stackless-lite'));
        }
        $outcome = $this->applyCheckout($session, $mode, $order);
        return in_array($outcome, ['paid', 'trial', 'already paid'], true)
            ? true
            : new \WP_Error('stackless_creem_' . str_replace(' ', '_', $outcome), $outcome);
    }

    /** The order a checkout was started for: by metadata, then by session id. */
    private function orderFor(array $checkout): ?\WC_Order
    {
        $metadata = is_array($checkout['metadata'] ?? null) ? $checkout['metadata'] : [];
        if (!empty($metadata['wc_order_id'])) {
            if (!self::forThisSite($metadata)) {
                return null;
            }
            $order = wc_get_order(absint($metadata['wc_order_id']));
            return $order instanceof \WC_Order ? $order : null;
        }
        return $this->findOrder(Gateway::META_CHECKOUT_ID, (string) ($checkout['id'] ?? ''));
    }

    /** A completed checkout (from the return, a repeated checkout or checkout.completed). */
    public function applyCheckout(array $checkout, string $mode, ?\WC_Order $order = null): string
    {
        if ((string) ($checkout['status'] ?? '') !== 'completed') {
            return 'not completed';
        }
        $order = $order ?: $this->orderFor($checkout);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->gateway->id) {
            return 'not ours';
        }
        $metadata = is_array($checkout['metadata'] ?? null) ? $checkout['metadata'] : [];
        $sameSession = (string) ($checkout['id'] ?? '') === (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        if (!$sameSession && (string) ($metadata['wc_order_id'] ?? '') !== (string) $order->get_id()) {
            return 'not this order';
        }

        $creemOrder = is_array($checkout['order'] ?? null) ? $checkout['order'] : [];
        $subscriptionId = EventMap::id($checkout['subscription'] ?? '');
        $customer = EventMap::id($checkout['customer'] ?? '') ?: EventMap::id($creemOrder['customer'] ?? '');

        // A free trial saves the card without charging.
        if ((float) $order->get_total() <= 0) {
            if ($subscriptionId === '') {
                return 'no subscription';
            }
            $remote = is_array($checkout['subscription'] ?? null) && !empty($checkout['subscription']['status']) ? $checkout['subscription'] : $this->subscription($subscriptionId, $mode);
            if (is_wp_error($remote)) {
                throw new \RuntimeException(esc_html($remote->get_error_message()));
            }
            return $this->confirmTrial($order, $remote, $mode);
        }
        if (!$creemOrder) {
            return 'no order yet';
        }
        if ((string) ($creemOrder['status'] ?? '') !== 'paid') {
            return 'not paid';
        }
        return $this->settle($order, $creemOrder, $mode, $subscriptionId, $customer);
    }

    /**
     * Mark the order paid from a paid Creem order or transaction, once.
     *
     * @param array $paid A Creem order (checkout.completed) or transaction (subscription.paid).
     */
    public function settle(\WC_Order $order, array $paid, string $mode, string $subscriptionId = '', string $customer = ''): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $paid, $mode, $subscriptionId, $customer) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $isTransaction = (string) ($paid['object'] ?? '') === 'transaction';
            $transactionId = $isTransaction ? EventMap::id($paid['id'] ?? '') : EventMap::id($paid['transaction'] ?? '');
            $creemOrderId = $isTransaction ? EventMap::id($paid['order'] ?? '') : EventMap::id($paid['id'] ?? '');
            $orderMode = (string) $order->get_meta(Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                $this->gateway->log('Ignored a ' . $mode . '-mode Creem payment for ' . $orderMode . '-mode order ' . $order->get_id() . '.', 'warning');
                return 'other mode';
            }
            if ($order->is_paid()) {
                if ((string) $order->get_meta(Gateway::META_TRANSACTION_ID) === '' && $transactionId !== '') {
                    $order->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
                    $order->save();
                }
                return 'already paid';
            }

            $currency = strtoupper((string) ($paid['currency'] ?? ''));
            if ($currency !== '' && $currency !== strtoupper($order->get_currency())) {
                $order->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Creem charged in %2$s but the order is in %1$s. Check the payment in Creem before fulfilling.', 'stackless-lite'),
                    strtoupper($order->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $currency !== '' ? $currency : strtoupper($order->get_currency());
            $this->storeAmounts($order, $paid, $transactionId, $creemOrderId, $mode);

            $expected = (int) $order->get_meta(Gateway::META_AMOUNT) ?: Money::toMinor($order->get_total());
            if (!Money::paidMatches($paid, $expected)) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Creem charged, 2: order total */
                    __('Creem charged %1$s but the order total is %2$s. Check the payment in Creem before fulfilling.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor(Money::paidTotal($paid)), ['currency' => $currency])),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()]))
                ));
                return 'amount mismatch';
            }

            if ($subscriptionId !== '') {
                $this->linkSubscription($order, $subscriptionId, $customer, $mode);
            } elseif ($customer !== '') {
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $order->save();

            $order->add_order_note(sprintf(
                /* translators: 1: Creem transaction id, 2: total charged including tax */
                __('Creem payment %1$s succeeded (%2$s charged including tax).', 'stackless-lite'),
                $transactionId !== '' ? $transactionId : $creemOrderId,
                wp_strip_all_tags(wc_price(Money::fromMinor(Money::paidTotal($paid)), ['currency' => $currency]))
            ));
            $order->payment_complete($transactionId !== '' ? $transactionId : $creemOrderId);
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial: no charge, so the zero-total order is confirmed from the Creem subscription. */
    public function confirmTrial(\WC_Order $order, array $remote, string $mode): string
    {
        if (!in_array((string) ($remote['status'] ?? ''), ['trialing', 'active'], true)) {
            return 'trial not active';
        }
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $remote, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || $order->is_paid()) {
                return 'already paid';
            }
            if ((float) $order->get_total() > 0) {
                return 'not a trial';
            }
            $orderMode = (string) $order->get_meta(Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                return 'other mode';
            }
            $creemId = EventMap::id($remote['id'] ?? '');
            $this->linkSubscription($order, $creemId, EventMap::id($remote['customer'] ?? ''), $mode);
            $order->update_meta_data(Gateway::META_MODE, $mode);
            $order->save();
            /* translators: %s: Creem subscription id */
            $order->add_order_note(sprintf(__('Creem subscription %s started its free trial.', 'stackless-lite'), $creemId));
            $order->payment_complete();
            return 'trial';
        }, 'busy');
    }

    /** Write the Creem subscription on the parent order and on the subscription created from it. */
    public function linkSubscription(\WC_Order $order, string $creemSubscription, string $customer, string $mode): void
    {
        if ($creemSubscription === '') {
            return;
        }
        $write = static function (\WC_Order $target) use ($creemSubscription, $customer, $mode) {
            $target->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemSubscription);
            if ($customer !== '') {
                $target->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $target->update_meta_data(Gateway::META_MODE, $mode);
        };
        $write($order);
        foreach (stackless_get_order_subscriptions($order) as $subscription) {
            if ($subscription instanceof \WC_Order && $subscription->get_id() !== $order->get_id()) {
                $write($subscription);
                $subscription->save();
            }
        }
    }

    private function storeAmounts(\WC_Order $order, array $paid, string $transactionId, string $creemOrderId, string $mode): void
    {
        $order->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
        if ($creemOrderId !== '') {
            $order->update_meta_data(Gateway::META_ORDER_ID, $creemOrderId);
        }
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_TAX, wc_format_decimal(Money::fromMinor((int) ($paid['tax_amount'] ?? 0)), 2));
        $order->update_meta_data(Gateway::META_TOTAL, (string) Money::paidTotal($paid));
    }

    /* ---- Renewals ------------------------------------------------------------------ */

    /**
     * subscription.paid: the first payment (when it beats checkout.completed) or a renewal
     * Creem charged, recorded once per Creem transaction; then the subscription state.
     */
    public function subscriptionPaid(array $remote, string $mode, string $event): string
    {
        $creemId = EventMap::id($remote['id'] ?? '');
        $transaction = is_array($remote['last_transaction'] ?? null) ? $remote['last_transaction'] : [];
        $transactionId = EventMap::id($transaction['id'] ?? '') ?: (string) ($remote['last_transaction_id'] ?? '');
        if ($transactionId !== '' && !$transaction) {
            $fetched = $this->gateway->api($mode)->request('GET', 'v1/transactions', ['transaction_id' => $transactionId]);
            if (is_wp_error($fetched)) {
                throw new \RuntimeException(esc_html($fetched->get_error_message()));
            }
            $transaction = $fetched;
        }
        $transaction['object'] = 'transaction';
        $outcome = 'no transaction';
        if ($transactionId !== '' && (string) ($transaction['status'] ?? 'paid') === 'paid') {
            $outcome = $this->recordPaid($remote, $transaction, $mode);
            if ($outcome === 'busy') {
                return 'busy';
            }
        }
        return $outcome . '; ' . $this->syncSubscription($remote, $mode, $event);
    }

    private function recordPaid(array $remote, array $transaction, string $mode): string
    {
        $transactionId = EventMap::id($transaction['id'] ?? '');
        if ($this->findOrder(Gateway::META_TRANSACTION_ID, $transactionId)) {
            return 'duplicate';
        }
        $subscription = $this->findSubscription(EventMap::id($remote['id'] ?? ''), (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            $this->gateway->log('Payment ' . $transactionId . ' arrived for unknown Creem subscription ' . EventMap::id($remote['id'] ?? '') . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }
        $parent = $subscription->get_parent_id() ? wc_get_order($subscription->get_parent_id()) : false;
        if ($parent instanceof \WC_Order && !$parent->is_paid() && $parent->get_payment_method() === $this->gateway->id && (float) $parent->get_total() > 0) {
            // The first payment, before checkout.completed arrived.
            return $this->settle($parent, $transaction, $mode, EventMap::id($remote['id'] ?? ''), EventMap::id($remote['customer'] ?? ''));
        }
        return $this->recordRenewal($remote, $transaction, $subscription, $mode);
    }

    /** A renewal Creem charged becomes a paid WooCommerce renewal order, once per Creem transaction. */
    public function recordRenewal(array $remote, array $transaction, \WC_Order $subscription, string $mode): string
    {
        $transactionId = EventMap::id($transaction['id'] ?? '');
        $creemId = EventMap::id($remote['id'] ?? '');
        return (string) Lock::run('renewal-' . $transactionId, function () use ($transaction, $transactionId, $creemId, $subscription, $mode) {
            if ($this->findOrder(Gateway::META_TRANSACTION_ID, $transactionId)) {
                return 'duplicate';
            }
            $renewal = null;
            $awaiting = absint($subscription->get_meta(Gateway::META_AWAITING));
            if ($awaiting) {
                $candidate = wc_get_order($awaiting);
                if ($candidate instanceof \WC_Order && !$candidate->is_paid() && $candidate->get_payment_method() === $this->gateway->id) {
                    $renewal = $candidate;
                }
            }
            if (!$renewal) {
                if (!$subscription instanceof SubscriptionData) {
                    // Thrown so Creem retries once the Subscriptions module is active again.
                    throw new \RuntimeException('Subscriptions module is not active; cannot record the renewal.');
                }
                $created = stackless_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    // Thrown so the webhook answers 500 and Creem retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = $created;
            }

            $currency = strtoupper((string) ($transaction['currency'] ?? $renewal->get_currency()));
            $renewal->set_payment_method($this->gateway->id);
            $renewal->set_payment_method_title($this->gateway->get_title());
            $renewal->delete_meta_data(Gateway::META_AWAITING);
            $this->storeAmounts($renewal, $transaction, $transactionId, EventMap::id($transaction['order'] ?? ''), $mode);
            $renewal->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
            $product = (string) $subscription->get_meta(Gateway::META_PRODUCT_ID);
            if ($product !== '') {
                $renewal->update_meta_data(Gateway::META_PRODUCT_ID, $product);
            }
            $renewal->save();

            $subscription->delete_meta_data(Gateway::META_AWAITING);
            $subscription->save();

            if ($currency !== strtoupper($renewal->get_currency())) {
                $renewal->update_status('on-hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Creem charged this renewal in %2$s but the subscription bills in %1$s. Check it in Creem.', 'stackless-lite'),
                    strtoupper($renewal->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($transaction, Money::toMinor($renewal->get_total()))) {
                // Creem already took the money; record it and flag the difference.
                $renewal->add_order_note(sprintf(
                    /* translators: 1: amount Creem charged, 2: renewal total */
                    __('Creem charged %1$s including tax for this renewal; the WooCommerce renewal total is %2$s.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor(Money::paidTotal($transaction)), ['currency' => $currency])),
                    wp_strip_all_tags(wc_price($renewal->get_total(), ['currency' => $renewal->get_currency()]))
                ));
            }
            /* translators: %s: Creem transaction id */
            $renewal->add_order_note(sprintf(__('Creem charged this renewal (transaction %s).', 'stackless-lite'), $transactionId));
            $renewal->payment_complete($transactionId);
            return 'renewed';
        }, 'busy');
    }

    /**
     * subscription.past_due / subscription.unpaid: Creem could not take a renewal.
     *
     * Subscriptions creates no renewal orders for provider-billed subscriptions, so the failed
     * charge gets one here (or reuses the one still waiting). The decline category is written
     * on it before it is failed, so dunning sees it; Creem's later subscription.paid pays that
     * same order. A renewal that already failed is left as it is.
     */
    public function renewalFailed(array $remote, string $mode, string $event): string
    {
        $creemId = EventMap::id($remote['id'] ?? '');
        $subscription = $this->findSubscription($creemId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }
        // Deliveries can arrive late or out of order: act only on what Creem says now.
        $current = $this->subscription($creemId, $mode);
        if (is_wp_error($current)) {
            throw new \RuntimeException(esc_html($current->get_error_message()));
        }
        $status = (string) ($current['status'] ?? '');
        if (!in_array($status, ['past_due', 'unpaid'], true)) {
            return 'stale (' . $status . '); ' . $this->syncSubscription($current, $mode, 'subscription.update');
        }
        [$category, $code] = EventMap::decline('subscription.' . $status);
        $subscriptionId = $subscription->get_id();
        $outcome = (string) Lock::run('decline-' . $subscriptionId, function () use ($subscriptionId, $creemId, $category, $code) {
            $subscription = \Stackless\Core\FreshOrder::get($subscriptionId);
            if (!$subscription instanceof \WC_Order) {
                return 'gone';
            }
            $awaiting = absint($subscription->get_meta(Gateway::META_AWAITING));
            $renewal = $awaiting ? wc_get_order($awaiting) : false;
            if (!$renewal instanceof \WC_Order || $renewal->is_paid() || $renewal->get_payment_method() !== $this->gateway->id) {
                if (!$subscription instanceof SubscriptionData) {
                    // Thrown so Creem retries once the Subscriptions module is active again.
                    throw new \RuntimeException('Subscriptions module is not active; cannot record the failed renewal.');
                }
                $created = stackless_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = $created;
                $renewal->set_payment_method($this->gateway->id);
                $renewal->set_payment_method_title($this->gateway->get_title());
                $renewal->update_meta_data(Gateway::META_AWAITING, 'yes');
                $renewal->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
                $renewal->save();
                $subscription->update_meta_data(Gateway::META_AWAITING, (string) $renewal->get_id());
                $subscription->save();
            }
            if ($renewal->has_status('failed')) {
                return 'already failed';
            }
            $renewal->update_meta_data(SubscriptionData::META_DECLINE, $category);
            $renewal->update_meta_data(SubscriptionData::META_DECLINE_CODE, $code);
            $renewal->save();
            /* translators: %s: Creem status such as past_due */
            $renewal->update_status('failed', sprintf(__('Creem could not take this renewal payment (%s). Creem retries the card on its own schedule; this order is paid when a retry succeeds.', 'stackless-lite'), $code));
            return 'renewal failed';
        }, 'busy');
        if ($outcome === 'busy') {
            return 'busy';
        }
        return $outcome . '; ' . $this->syncSubscription($current, $mode, $event);
    }

    /* ---- Refunds and disputes ------------------------------------------------------ */

    public static function recordRefundId(\WC_Order $order, string $refundId): void
    {
        if ($refundId === '') {
            return;
        }
        $ids = array_filter(explode(',', (string) $order->get_meta(Gateway::META_REFUND_IDS)));
        if (!in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data(Gateway::META_REFUND_IDS, implode(',', $ids));
            $order->save();
        }
    }

    public static function hasRefundId(\WC_Order $order, string $refundId): bool
    {
        return in_array($refundId, array_filter(explode(',', (string) $order->get_meta(Gateway::META_REFUND_IDS))), true);
    }

    /** The order a Creem transaction or order belongs to. @param mixed $transaction @param mixed $creemOrder */
    private function orderForPayment($transaction, $creemOrder): ?\WC_Order
    {
        return $this->findOrder(Gateway::META_TRANSACTION_ID, EventMap::id($transaction))
            ?: $this->findOrder(Gateway::META_ORDER_ID, EventMap::id($creemOrder));
    }

    /**
     * refund.created (sent once the refund succeeded): mirror it once. A full refund made from
     * WooCommerce is already recorded, so nothing is left to refund and only its id is kept.
     */
    public function recordRefund(array $refund): string
    {
        $order = $this->orderForPayment($refund['transaction'] ?? '', $refund['order'] ?? '');
        if (!$order) {
            return 'not ours';
        }
        $refundId = EventMap::id($refund['id'] ?? '');
        if (!in_array((string) ($refund['status'] ?? 'succeeded'), ['succeeded', ''], true)) {
            return 'not succeeded';
        }
        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refund, $refundId) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || self::hasRefundId($order, $refundId)) {
                return 'duplicate';
            }
            $currency = strtoupper((string) ($refund['refund_currency'] ?? $order->get_currency()));
            if ($currency !== strtoupper($order->get_currency())) {
                self::recordRefundId($order, $refundId);
                $order->add_order_note(__('Creem reported a refund in a different currency. Record it manually.', 'stackless-lite'));
                return 'currency mismatch';
            }
            $minor = Money::orderRefund((int) ($refund['refund_amount'] ?? 0), Money::toMinor($order->get_total()), (int) $order->get_meta(Gateway::META_TOTAL));
            $amount = min(Money::fromMinor($minor), (float) $order->get_remaining_refund_amount());
            self::recordRefundId($order, $refundId);
            if ($amount <= 0) {
                return 'nothing left';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, 2),
                /* translators: %s: Creem refund id */
                'reason' => sprintf(__('Refunded in Creem (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            return 'refunded';
        }, 'busy');
    }

    public function noteDispute(array $dispute): string
    {
        $order = $this->orderForPayment($dispute['transaction'] ?? '', $dispute['order'] ?? '');
        if (!$order) {
            return 'not ours';
        }
        /* translators: %s: Creem dispute id */
        $order->add_order_note(sprintf(__('Creem reported a dispute (%s). Review it in the Creem dashboard.', 'stackless-lite'), EventMap::id($dispute['id'] ?? '')));
        return 'noted';
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a WooCommerce subscription in line with a Creem subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $creemId = EventMap::id($remote['id'] ?? '');
        if ($creemId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($creemId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        $status = (string) ($remote['status'] ?? '');
        $cancelAtEnd = $status === 'scheduled_cancel';
        $subscription->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $creemId);
        $customer = EventMap::id($remote['customer'] ?? '');
        if ($customer !== '') {
            $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }
        $product = EventMap::id($remote['product'] ?? '');
        if ($product !== '') {
            $subscription->update_meta_data(Gateway::META_PRODUCT_ID, $product);
        }
        $subscription->update_meta_data(Gateway::META_MODE, $mode);
        $subscription->update_meta_data(Gateway::META_CANCEL_AT_END, $cancelAtEnd ? 'yes' : 'no');
        $next = (string) ($remote['current_period_end_date'] ?? ($remote['next_transaction_date'] ?? ''));
        if ($next !== '') {
            $subscription->update_meta_data(Gateway::META_NEXT_BILLING, $next);
            // Creem runs the schedule; let the Subscriptions module show Creem's next charge date.
            if (method_exists($subscription, 'set_next_payment_date') && in_array($status, ['active', 'trialing'], true)) {
                $subscription->set_next_payment_date($next);
            }
        }
        $subscription->save();

        $target = EventMap::status($remote, $event);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: checkout.completed (or the trial path) activates it.
        $parent = $subscription->get_parent_id() ? wc_get_order($subscription->get_parent_id()) : false;
        if ($target !== null && in_array($target, $active, true) && $parent instanceof \WC_Order && !$parent->is_paid()) {
            if ((float) $parent->get_total() <= 0 && in_array($status, ['trialing', 'active'], true) && $parent->get_payment_method() === $this->gateway->id) {
                return $this->confirmTrial($parent, $remote, $mode);
            }
            return 'awaiting first payment';
        }

        if ($target === null) {
            return 'no status change';
        }
        $current = (string) $subscription->get_status();
        if (EventMap::isTerminal($current)) {
            return 'terminal';
        }
        if ($current === $target) {
            return 'unchanged';
        }
        /* translators: %s: Creem subscription status */
        $subscription->update_status($target, sprintf(__('Creem subscription is now %s.', 'stackless-lite'), $event === 'subscription.expired' ? 'expired' : $status));
        do_action('stackless_creem_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }
}
