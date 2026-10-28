<?php

namespace Stackless\Modules\DodoGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Applies Dodo Payments payment, renewal, refund and subscription state to WooCommerce.
 *
 * Every entry point (browser return, webhook) funnels through these methods and
 * each is safe to repeat: orders are settled under a lock and only while unpaid,
 * renewals are keyed by Dodo payment id, refunds by Dodo refund id. Dodo sends
 * payment.succeeded and subscription.renewed for every renewal; whichever lands
 * first records it.
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
        $order = OrderQuery::findByMeta($key, $value);
        return $order && $order->get_payment_method() === $this->gateway->id ? $order : null;
    }

    /** WooCommerce subscription linked to a Dodo subscription (by stored id, else by checkout metadata). */
    public function findSubscription(string $dodoId, array $metadata): ?\WC_Order
    {
        if ($dodoId !== '') {
            $first = OrderQuery::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoId, ['type' => 'stackless_sub', 'status' => self::SUBSCRIPTION_STATUSES]);
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
        if (($parent && $subscription->get_parent_id() !== $parent) || ($linked !== '' && $dodoId !== '' && $linked !== $dodoId)) {
            return null;
        }
        return $subscription;
    }

    private static function forThisSite(array $metadata): bool
    {
        $site = (string) ($metadata['wc_site'] ?? '');
        return $site === '' || $site === Catalog::siteHost();
    }

    /* ---- First payment ------------------------------------------------------------- */

    /**
     * Look up the order's checkout session and confirm its payment (or its free trial).
     *
     * @param string $subscriptionHint Dodo subscription id from the return URL, used for free trials.
     * @return true|\WP_Error
     */
    public function confirmCheckout(\WC_Order $order, string $subscriptionHint = '')
    {
        $mode = $this->gateway->orderMode($order);
        $api = $this->gateway->api($mode);
        $sessionId = (string) $order->get_meta(Gateway::META_SESSION_ID);
        if ($sessionId === '') {
            return new \WP_Error('stackless_dodo_no_session', __('No Dodo Payments checkout was started for this order.', 'stackless-lite'));
        }
        $session = $api->request('GET', 'checkouts/' . rawurlencode($sessionId));
        if (is_wp_error($session)) {
            return $session;
        }

        $paymentId = (string) ($session['payment_id'] ?? '');
        if ($paymentId !== '') {
            $payment = $api->request('GET', 'payments/' . rawurlencode($paymentId));
            if (is_wp_error($payment)) {
                return $payment;
            }
            if (!$this->belongsTo($payment, $order, $sessionId)) {
                return new \WP_Error('stackless_dodo_mismatch', __('This Dodo Payments payment belongs to a different order.', 'stackless-lite'));
            }
            $status = (string) ($payment['status'] ?? '');
            if (in_array($status, ['failed', 'cancelled'], true)) {
                return new \WP_Error('stackless_dodo_failed', __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'));
            }
            if ($status !== 'succeeded') {
                return new \WP_Error('stackless_dodo_pending', __('Dodo Payments has not completed this payment yet. It will be confirmed automatically.', 'stackless-lite'));
            }
            $this->confirmPayment($payment, $mode, $order);
            return true;
        }

        // A free trial saves the card without charging, so there may be no payment.
        if ($subscriptionHint !== '' && (float) $order->get_total() <= 0) {
            $remote = $api->request('GET', 'subscriptions/' . rawurlencode($subscriptionHint));
            if (!is_wp_error($remote) && (string) ($remote['metadata']['wc_order_id'] ?? '') === (string) $order->get_id() && ($remote['status'] ?? '') === 'active') {
                $this->confirmTrial($order, $remote, $mode);
                return true;
            }
        }
        return new \WP_Error('stackless_dodo_pending', __('Dodo Payments has not completed this checkout yet. It will be confirmed automatically.', 'stackless-lite'));
    }

    private function belongsTo(array $payment, \WC_Order $order, string $sessionId): bool
    {
        $orderId = (string) ($payment['metadata']['wc_order_id'] ?? '');
        if ($orderId !== '') {
            return $orderId === (string) $order->get_id() && self::forThisSite((array) $payment['metadata']);
        }
        return (string) ($payment['checkout_session_id'] ?? '') === $sessionId;
    }

    /** Route a succeeded Dodo payment to the first payment or a renewal. */
    public function confirmPayment(array $payment, string $mode, ?\WC_Order $order = null): string
    {
        if ((string) ($payment['status'] ?? '') !== 'succeeded') {
            return 'not succeeded';
        }
        $kind = EventMap::paymentKind($payment);
        if ($kind === 'renewal') {
            return $this->recordRenewal($payment, $mode);
        }
        if ($kind !== 'first') {
            return 'ignored';
        }

        if (!$order) {
            $order = $this->orderFor($payment);
        }
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->gateway->id) {
            return 'not ours';
        }

        // A free trial's first "payment" is the zero-amount card authorization.
        $dodoSubscription = (string) ($payment['subscription_id'] ?? '');
        if ((float) $order->get_total() <= 0 && $dodoSubscription !== '') {
            $remote = $this->gateway->api($mode)->request('GET', 'subscriptions/' . rawurlencode($dodoSubscription));
            if (is_wp_error($remote)) {
                throw new \RuntimeException(esc_html($remote->get_error_message()));
            }
            return $this->confirmTrial($order, $remote, $mode);
        }
        return $this->settle($order, $payment, $mode);
    }

    /** The order a first payment was started for: by metadata, then by checkout session. */
    private function orderFor(array $payment): ?\WC_Order
    {
        $metadata = (array) ($payment['metadata'] ?? []);
        if (!empty($metadata['wc_order_id'])) {
            if (!self::forThisSite($metadata)) {
                return null;
            }
            $order = wc_get_order(absint($metadata['wc_order_id']));
            return $order instanceof \WC_Order ? $order : null;
        }
        $sessionId = (string) ($payment['checkout_session_id'] ?? '');
        return $sessionId !== '' ? $this->findOrder(Gateway::META_SESSION_ID, $sessionId) : null;
    }

    public function settle(\WC_Order $order, array $payment, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $paymentId = (string) ($payment['payment_id'] ?? '');
            if ($order->is_paid()) {
                if ((string) $order->get_meta(Gateway::META_PAYMENT_ID) === '' && $paymentId !== '') {
                    $order->update_meta_data(Gateway::META_PAYMENT_ID, $paymentId);
                    $order->save();
                }
                return 'already paid';
            }

            $currency = strtoupper((string) ($payment['currency'] ?? ''));
            if ($currency !== '' && $currency !== strtoupper($order->get_currency())) {
                $order->update_meta_data(Gateway::META_PAYMENT_ID, $paymentId);
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Dodo Payments charged in %2$s but the order is in %1$s. Check the payment in Dodo before fulfilling.', 'stackless-lite'),
                    strtoupper($order->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $currency !== '' ? $currency : strtoupper($order->get_currency());
            $this->storeAmounts($order, $payment, $currency, $mode);

            $expected = (int) $order->get_meta(Gateway::META_AMOUNT) ?: Money::toMinor($order->get_total(), $currency);
            if (!Money::paidMatches($payment, $expected)) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Dodo charged, 2: order total */
                    __('Dodo Payments charged %1$s but the order total is %2$s. Check the payment in Dodo before fulfilling.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor((int) ($payment['total_amount'] ?? 0), $currency), ['currency' => $currency])),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()]))
                ));
                return 'amount mismatch';
            }

            $dodoSubscription = (string) ($payment['subscription_id'] ?? '');
            $customer = (string) ($payment['customer']['customer_id'] ?? '');
            if ($dodoSubscription !== '') {
                $this->linkSubscription($order, $dodoSubscription, $customer, $mode);
            } elseif ($customer !== '') {
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $order->save();

            $order->add_order_note(sprintf(
                /* translators: 1: Dodo payment id, 2: total charged including tax */
                __('Dodo Payments payment %1$s succeeded (%2$s charged including tax).', 'stackless-lite'),
                $paymentId,
                wp_strip_all_tags(wc_price(Money::fromMinor((int) ($payment['total_amount'] ?? 0), $currency), ['currency' => $currency]))
            ));
            $order->payment_complete($paymentId);
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial: no charge, so the zero-total order is confirmed from the Dodo subscription. */
    public function confirmTrial(\WC_Order $order, array $remote, string $mode): string
    {
        // Dodo activates a trial subscription once the card is saved.
        if (($remote['status'] ?? '') !== 'active') {
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
            $dodoId = (string) ($remote['subscription_id'] ?? '');
            $this->linkSubscription($order, $dodoId, (string) ($remote['customer']['customer_id'] ?? ''), $mode);
            $order->update_meta_data(Gateway::META_MODE, $mode);
            $order->save();
            /* translators: %s: Dodo subscription id */
            $order->add_order_note(sprintf(__('Dodo Payments subscription %s started its free trial.', 'stackless-lite'), $dodoId));
            $order->payment_complete();
            return 'trial';
        }, 'busy');
    }

    /** Write the Dodo subscription on the parent order and on the subscription created from it. */
    public function linkSubscription(\WC_Order $order, string $dodoSubscription, string $customer, string $mode): void
    {
        if ($dodoSubscription === '') {
            return;
        }
        $write = static function (\WC_Order $target) use ($dodoSubscription, $customer, $mode) {
            $target->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoSubscription);
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

    private function storeAmounts(\WC_Order $order, array $payment, string $currency, string $mode): void
    {
        $order->update_meta_data(Gateway::META_PAYMENT_ID, (string) ($payment['payment_id'] ?? ''));
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_TAX, wc_format_decimal(Money::fromMinor((int) ($payment['tax'] ?? 0), $currency), Money::decimals($currency)));
        $order->update_meta_data(Gateway::META_TOTAL, (string) (int) ($payment['total_amount'] ?? 0));
    }

    /* ---- Renewals ---------------------------------------------------------------- */

    /** A renewal Dodo charged becomes a paid WooCommerce renewal order, once per Dodo payment. */
    public function recordRenewal(array $payment, string $mode): string
    {
        $paymentId = (string) ($payment['payment_id'] ?? '');
        $dodoSubscription = (string) ($payment['subscription_id'] ?? '');
        if ($paymentId === '' || $dodoSubscription === '') {
            return 'no subscription';
        }
        if ($this->findOrder(Gateway::META_PAYMENT_ID, $paymentId)) {
            return 'duplicate';
        }
        // Usage settlements that do not pay for a billing period are not renewals.
        if (!empty($payment['includes_usage']) && empty($payment['service_periods'])) {
            return 'usage charge';
        }
        $subscription = $this->findSubscription($dodoSubscription, (array) ($payment['metadata'] ?? []));
        if (!$subscription) {
            $this->gateway->log('Renewal ' . $paymentId . ' arrived for unknown Dodo subscription ' . $dodoSubscription . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        return (string) Lock::run('renewal-' . $paymentId, function () use ($payment, $paymentId, $dodoSubscription, $subscription, $mode) {
            if ($this->findOrder(Gateway::META_PAYMENT_ID, $paymentId)) {
                return 'duplicate';
            }
            $renewal = null;
            $awaiting = absint($subscription->get_meta(Gateway::META_AWAITING));
            if ($awaiting) {
                $candidate = wc_get_order($awaiting);
                if ($candidate instanceof \WC_Order && !$candidate->is_paid() && $candidate->get_meta(Gateway::META_AWAITING) === 'yes') {
                    $renewal = $candidate;
                }
            }
            if (!$renewal) {
                if (!$subscription instanceof SubscriptionData) {
                    // Thrown so Dodo retries once the Subscriptions module is active again.
                    throw new \RuntimeException('Subscriptions module is not active; cannot record the renewal.');
                }
                $created = stackless_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    // Thrown so the webhook answers 500 and Dodo retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = $created;
            }

            $currency = strtoupper((string) ($payment['currency'] ?? $renewal->get_currency()));
            $renewal->set_payment_method($this->gateway->id);
            $renewal->set_payment_method_title($this->gateway->get_title());
            $renewal->delete_meta_data(Gateway::META_AWAITING);
            $this->storeAmounts($renewal, $payment, $currency, $mode);
            $renewal->update_meta_data(Gateway::META_TAX_INCLUSIVE, $this->gateway->taxInclusive() ? 'yes' : 'no');
            $renewal->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoSubscription);
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
                    __('Dodo Payments charged this renewal in %2$s but the subscription bills in %1$s. Check it in Dodo.', 'stackless-lite'),
                    strtoupper($renewal->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($payment, Money::toMinor($renewal->get_total(), $currency))) {
                // Dodo already took the money; record it and flag the difference.
                $renewal->add_order_note(sprintf(
                    /* translators: 1: amount Dodo charged, 2: renewal total */
                    __('Dodo Payments charged %1$s including tax for this renewal; the WooCommerce renewal total is %2$s.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor((int) ($payment['total_amount'] ?? 0), $currency), ['currency' => $currency])),
                    wp_strip_all_tags(wc_price($renewal->get_total(), ['currency' => $renewal->get_currency()]))
                ));
            }
            /* translators: %s: Dodo payment id */
            $renewal->add_order_note(sprintf(__('Dodo Payments charged this renewal (payment %s).', 'stackless-lite'), $paymentId));
            $renewal->payment_complete($paymentId);
            return 'renewed';
        }, 'busy');
    }

    /* ---- Refunds ------------------------------------------------------------------ */

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

    /** Mirror a Dodo refund (made here or in the Dodo dashboard) once. */
    public function recordRefund(array $refund): string
    {
        $order = $this->findOrder(Gateway::META_PAYMENT_ID, (string) ($refund['payment_id'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        $refundId = (string) ($refund['refund_id'] ?? '');
        $status = (string) ($refund['status'] ?? '');

        if ($status === 'failed') {
            if (self::hasRefundId($order, $refundId)) {
                $order->add_order_note(sprintf(
                    /* translators: %s: Dodo refund id */
                    __('Dodo Payments could not complete refund %s. The WooCommerce refund recorded for it did not reach the customer; refund another way or correct the order.', 'stackless-lite'),
                    $refundId
                ));
                return 'failed noted';
            }
            return 'failed';
        }
        if ($status !== 'succeeded') {
            return 'not succeeded';
        }

        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refund, $refundId) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || self::hasRefundId($order, $refundId)) {
                return 'duplicate';
            }
            $currency = strtoupper((string) ($refund['currency'] ?? $order->get_currency()));
            if ($currency !== strtoupper($order->get_currency())) {
                self::recordRefundId($order, $refundId);
                $order->add_order_note(__('Dodo Payments reported a refund in a different currency. Record it manually.', 'stackless-lite'));
                return 'currency mismatch';
            }
            $minor = Money::orderRefund((int) ($refund['amount'] ?? 0), Money::toMinor($order->get_total(), $currency), (int) $order->get_meta(Gateway::META_TOTAL));
            $amount = min(Money::fromMinor($minor, $currency), (float) $order->get_remaining_refund_amount());
            if ($amount <= 0) {
                self::recordRefundId($order, $refundId);
                return 'nothing left';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, Money::decimals($currency)),
                /* translators: %s: Dodo refund id */
                'reason' => sprintf(__('Refunded in Dodo Payments (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            self::recordRefundId($order, $refundId);
            return 'refunded';
        }, 'busy');
    }

    /* ---- Disputes ------------------------------------------------------------------ */

    public function noteDispute(string $type, array $dispute): string
    {
        $order = $this->findOrder(Gateway::META_PAYMENT_ID, (string) ($dispute['payment_id'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        $order->add_order_note(sprintf(
            /* translators: 1: dispute event, 2: Dodo dispute id */
            __('Dodo Payments reported %1$s (dispute %2$s). Review it in the Dodo Payments dashboard.', 'stackless-lite'),
            $type,
            (string) ($dispute['dispute_id'] ?? '')
        ));
        return 'noted';
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a WooCommerce subscription in line with a Dodo subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $dodoId = (string) ($remote['subscription_id'] ?? '');
        if ($dodoId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($dodoId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        $cancelAtEnd = !empty($remote['cancel_at_next_billing_date']);
        $subscription->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $dodoId);
        if (!empty($remote['customer']['customer_id'])) {
            $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, (string) $remote['customer']['customer_id']);
        }
        if (!empty($remote['product_id'])) {
            $subscription->update_meta_data(Gateway::META_PRODUCT_ID, (string) $remote['product_id']);
        }
        $subscription->update_meta_data(Gateway::META_MODE, $mode);
        $subscription->update_meta_data(Gateway::META_CANCEL_AT_END, $cancelAtEnd ? 'yes' : 'no');
        $next = (string) ($remote['next_billing_date'] ?? '');
        if ($next !== '') {
            $subscription->update_meta_data(Gateway::META_NEXT_BILLING, $next);
            // Dodo runs the schedule; let the Subscriptions module show Dodo's next charge date.
            if (method_exists($subscription, 'set_next_payment_date') && ($remote['status'] ?? '') === 'active' && !$cancelAtEnd) {
                $subscription->set_next_payment_date($next);
            }
        }
        $subscription->save();

        $target = EventMap::status($remote);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: payment.succeeded (or the trial path) activates it.
        $parent = $subscription->get_parent_id() ? wc_get_order($subscription->get_parent_id()) : false;
        if ($target !== null && in_array($target, $active, true) && $parent instanceof \WC_Order && !$parent->is_paid()) {
            if ((float) $parent->get_total() <= 0 && ($remote['status'] ?? '') === 'active' && $parent->get_payment_method() === $this->gateway->id) {
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

        $note = $target === SubscriptionData::STATUS_CANCELLED && !empty($remote['cancellation_comment'])
            /* translators: %s: reason given in Dodo */
            ? sprintf(__('Cancelled in Dodo Payments: %s', 'stackless-lite'), (string) $remote['cancellation_comment'])
            /* translators: %s: Dodo subscription status */
            : sprintf(__('Dodo Payments subscription is now %s.', 'stackless-lite'), (string) ($remote['status'] ?? $event));
        $subscription->update_status($target, $note);
        do_action('stackless_dodo_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }
}
