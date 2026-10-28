<?php

namespace Stackless\Modules\PolarGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Applies Polar payment, renewal, refund and subscription state to WooCommerce.
 *
 * Every entry point (browser return, webhook) funnels through these methods and
 * each is safe to repeat: orders are settled under a lock and only while
 * unpaid, renewals are keyed by Polar order id, refunds by Polar refund id.
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

    public static function findOrderByMeta(string $key, string $value): ?\WC_Order
    {
        return OrderQuery::findByMeta($key, $value);
    }

    /** WooCommerce subscription linked to a Polar subscription (by stored id, else by checkout metadata). */
    public function findSubscription(string $polarId, array $metadata): ?\WC_Order
    {
        if ($polarId !== '') {
            $first = OrderQuery::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarId, ['type' => 'stackless_sub', 'status' => self::SUBSCRIPTION_STATUSES]);
            if ($first instanceof \WC_Order) {
                $subscription = stackless_get_subscription($first->get_id());
                return $subscription instanceof \WC_Order ? $subscription : $first;
            }
        }

        $site = (string) ($metadata['wc_site'] ?? '');
        if ($site !== '' && $site !== Catalog::siteHost()) {
            return null;
        }
        $wcId = absint($metadata['wc_subscription_id'] ?? 0);
        if (!$wcId) {
            return null;
        }
        $subscription = stackless_get_subscription($wcId);
        if (!$subscription instanceof \WC_Order) {
            return null;
        }
        $parent = absint($metadata['wc_order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_id() !== $parent) || ($linked !== '' && $polarId !== '' && $linked !== $polarId)) {
            return null;
        }
        return $subscription;
    }

    /* ---- Checkout confirmation ---------------------------------------------------- */

    /**
     * Look up a checkout and confirm its paid order (or its free trial).
     *
     * @return true|\WP_Error
     */
    public function confirmCheckout(\WC_Order $order, string $checkoutId)
    {
        $mode = $this->gateway->orderMode($order);
        $api = $this->gateway->api($mode);
        $checkout = $api->request('GET', 'checkouts/' . rawurlencode($checkoutId));
        if (is_wp_error($checkout)) {
            return $checkout;
        }
        if ((string) ($checkout['metadata']['wc_order_id'] ?? '') !== (string) $order->get_id()) {
            return new \WP_Error('stackless_polar_mismatch', __('This Polar checkout belongs to a different order.', 'stackless-lite'));
        }
        $status = (string) ($checkout['status'] ?? '');
        if ($status !== 'succeeded') {
            return new \WP_Error('stackless_polar_checkout_' . ($status ?: 'unknown'), $status === 'confirmed'
                ? __('Polar is still processing this payment. It will be confirmed automatically.', 'stackless-lite')
                /* translators: %s: Polar checkout status */
                : sprintf(__('The Polar checkout is %s, not paid.', 'stackless-lite'), $status ?: 'unknown'));
        }

        $orders = $api->request('GET', 'orders/', ['checkout_id' => $checkoutId, 'limit' => 10]);
        if (is_wp_error($orders)) {
            return $orders;
        }
        foreach ((array) ($orders['items'] ?? []) as $polarOrder) {
            if (is_array($polarOrder) && EventMap::isPaid($polarOrder) && EventMap::orderKind($polarOrder) === 'first') {
                $this->confirmPayment($order, $polarOrder, $mode);
                return true;
            }
        }

        // A free trial starts a subscription without charging, so there may be no paid order.
        $polarSubscription = (string) ($checkout['subscription_id'] ?? '');
        if ($polarSubscription !== '' && (float) $order->get_total() <= 0) {
            $remote = $api->request('GET', 'subscriptions/' . rawurlencode($polarSubscription));
            if (!is_wp_error($remote) && in_array($remote['status'] ?? '', ['trialing', 'active'], true)) {
                $this->confirmTrial($order, $remote, $mode);
                return true;
            }
        }
        return new \WP_Error('stackless_polar_pending', __('Polar has not created the order for this checkout yet. It will be confirmed automatically.', 'stackless-lite'));
    }

    /** Route a Polar order to the first payment or a renewal. */
    public function confirmOrder(array $polarOrder, string $mode): string
    {
        if (!EventMap::isPaid($polarOrder)) {
            return 'unpaid';
        }
        $kind = EventMap::orderKind($polarOrder);
        if ($kind === 'renewal') {
            return $this->recordRenewal($polarOrder, $mode);
        }
        if ($kind !== 'first') {
            return 'ignored'; // Plan changes and metered cycles are started in Polar, not here.
        }

        $metadata = (array) ($polarOrder['metadata'] ?? []);
        $site = (string) ($metadata['wc_site'] ?? '');
        if ($site !== '' && $site !== Catalog::siteHost()) {
            return 'other site';
        }
        $order = wc_get_order(absint($metadata['wc_order_id'] ?? 0));
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->gateway->id) {
            return 'not ours';
        }
        $checkoutId = (string) ($polarOrder['checkout_id'] ?? '');
        $stored = (string) $order->get_meta(Gateway::META_CHECKOUT_ID);
        if ($checkoutId !== '' && $stored !== '' && $checkoutId !== $stored) {
            return 'checkout mismatch';
        }
        return $this->confirmPayment($order, $polarOrder, $mode);
    }

    public function confirmPayment(\WC_Order $order, array $polarOrder, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $polarOrder, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $polarId = (string) ($polarOrder['id'] ?? '');
            if ($order->is_paid()) {
                if ((string) $order->get_meta(Gateway::META_ORDER_ID) === '' && $polarId !== '') {
                    $order->update_meta_data(Gateway::META_ORDER_ID, $polarId);
                    $order->save();
                }
                return 'already paid';
            }

            $currency = strtolower((string) ($polarOrder['currency'] ?? ''));
            if ($currency !== '' && $currency !== strtolower($order->get_currency())) {
                $order->update_meta_data(Gateway::META_ORDER_ID, $polarId);
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Polar charged in %2$s but the order is in %1$s. Check the payment in Polar.', 'stackless-lite'),
                    strtoupper($order->get_currency()),
                    strtoupper($currency)
                ));
                return 'currency mismatch';
            }

            $currency = $currency !== '' ? $currency : strtolower($order->get_currency());
            $this->storeAmounts($order, $polarOrder, $currency, $mode);

            if (!Money::paidMatches($polarOrder, Money::toMinor($order->get_total(), $currency))) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Polar charged, 2: order total */
                    __('Polar charged %1$s but the order total is %2$s. Check the payment in Polar before fulfilling.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor((int) ($polarOrder['net_amount'] ?? 0), $currency), ['currency' => strtoupper($currency)])),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()]))
                ));
                return 'amount mismatch';
            }

            $polarSubscription = (string) ($polarOrder['subscription_id'] ?? '');
            $customer = (string) ($polarOrder['customer_id'] ?? '');
            if ($polarSubscription !== '') {
                $this->linkSubscription($order, $polarSubscription, $customer, $mode);
            } elseif ($customer !== '') {
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $order->save();

            $order->add_order_note(sprintf(
                /* translators: 1: Polar order id, 2: total charged including tax */
                __('Polar order %1$s paid (%2$s charged including tax).', 'stackless-lite'),
                $polarId,
                wp_strip_all_tags(wc_price(Money::fromMinor((int) ($polarOrder['total_amount'] ?? 0), $currency), ['currency' => strtoupper($currency)]))
            ));
            $order->payment_complete($polarId);
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial: no charge, so the zero-total order is confirmed from the Polar subscription. */
    public function confirmTrial(\WC_Order $order, array $remote, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $remote, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || $order->is_paid()) {
                return 'already paid';
            }
            if ((float) $order->get_total() > 0) {
                return 'not a trial';
            }
            $this->linkSubscription($order, (string) ($remote['id'] ?? ''), (string) ($remote['customer_id'] ?? ''), $mode);
            $order->update_meta_data(Gateway::META_MODE, $mode);
            $order->save();
            /* translators: %s: Polar subscription id */
            $order->add_order_note(sprintf(__('Polar subscription %s started its free trial.', 'stackless-lite'), (string) ($remote['id'] ?? '')));
            $order->payment_complete();
            return 'trial';
        }, 'busy');
    }

    /** Write the Polar subscription on the parent order and on the subscription created from it. */
    public function linkSubscription(\WC_Order $order, string $polarSubscription, string $customer, string $mode): void
    {
        if ($polarSubscription === '') {
            return;
        }
        $write = static function (\WC_Order $target) use ($polarSubscription, $customer, $mode) {
            $target->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarSubscription);
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

    private function storeAmounts(\WC_Order $order, array $polarOrder, string $currency, string $mode): void
    {
        $order->update_meta_data(Gateway::META_ORDER_ID, (string) ($polarOrder['id'] ?? ''));
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_TAX, wc_format_decimal(Money::fromMinor((int) ($polarOrder['tax_amount'] ?? 0), $currency), Money::decimals($currency)));
        $order->update_meta_data(Gateway::META_TOTAL, wc_format_decimal(Money::fromMinor((int) ($polarOrder['total_amount'] ?? 0), $currency), Money::decimals($currency)));
    }

    /* ---- Renewals ---------------------------------------------------------------- */

    /** A renewal Polar charged becomes a paid WooCommerce renewal order. */
    public function recordRenewal(array $polarOrder, string $mode): string
    {
        $polarId = (string) ($polarOrder['id'] ?? '');
        $polarSubscription = (string) ($polarOrder['subscription_id'] ?? '');
        if ($polarId === '' || $polarSubscription === '') {
            return 'no subscription';
        }
        if (self::findOrderByMeta(Gateway::META_ORDER_ID, $polarId)) {
            return 'duplicate';
        }
        $metadata = (array) ($polarOrder['subscription']['metadata'] ?? ($polarOrder['metadata'] ?? []));
        $subscription = $this->findSubscription($polarSubscription, $metadata);
        if (!$subscription) {
            $this->gateway->log('Renewal ' . $polarId . ' arrived for unknown Polar subscription ' . $polarSubscription . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        return (string) Lock::run('renewal-' . $polarId, function () use ($polarOrder, $polarId, $subscription, $mode) {
            if (self::findOrderByMeta(Gateway::META_ORDER_ID, $polarId)) {
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
                    // Thrown so Polar retries once the Subscriptions module is active again.
                    throw new \RuntimeException('Subscriptions module is not active; cannot record the renewal.');
                }
                $created = stackless_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    // Thrown so the webhook answers 500 and Polar retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = $created;
            }

            $currency = strtolower((string) ($polarOrder['currency'] ?? $renewal->get_currency()));
            $renewal->set_payment_method($this->gateway->id);
            $renewal->set_payment_method_title($this->gateway->get_title());
            $renewal->delete_meta_data(Gateway::META_AWAITING);
            $this->storeAmounts($renewal, $polarOrder, $currency, $mode);
            $renewal->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, (string) ($polarOrder['subscription_id'] ?? ''));
            $renewal->save();

            $subscription->delete_meta_data(Gateway::META_AWAITING);
            $end = (string) ($polarOrder['subscription']['current_period_end'] ?? '');
            if ($end !== '') {
                $subscription->update_meta_data(Gateway::META_PERIOD_END, $end);
            }
            $subscription->save();

            if ($currency !== strtolower($renewal->get_currency())) {
                $renewal->update_status('on-hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Polar charged this renewal in %2$s but the subscription bills in %1$s. Check it in Polar.', 'stackless-lite'),
                    strtoupper($renewal->get_currency()),
                    strtoupper($currency)
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($polarOrder, Money::toMinor($renewal->get_total(), $currency))) {
                // Polar already took the money; record it and flag the difference.
                $renewal->add_order_note(sprintf(
                    /* translators: 1: amount Polar charged, 2: renewal total */
                    __('Polar charged %1$s for this renewal; the WooCommerce renewal total is %2$s.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor((int) ($polarOrder['net_amount'] ?? 0), $currency), ['currency' => strtoupper($currency)])),
                    wp_strip_all_tags(wc_price($renewal->get_total(), ['currency' => $renewal->get_currency()]))
                ));
            }
            /* translators: %s: Polar order id */
            $renewal->add_order_note(sprintf(__('Polar charged this renewal (order %s).', 'stackless-lite'), $polarId));
            $renewal->payment_complete($polarId);
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

    /** Mirror a Polar refund (made here or in the Polar dashboard) once. */
    public function recordRefund(array $refund, string $mode): string
    {
        if (($refund['status'] ?? '') !== 'succeeded') {
            return 'not succeeded';
        }
        $order = self::findOrderByMeta(Gateway::META_ORDER_ID, (string) ($refund['order_id'] ?? ''));
        if (!$order || $order->get_payment_method() !== $this->gateway->id) {
            return 'not ours';
        }
        $refundId = (string) ($refund['id'] ?? '');

        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refund, $refundId) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || self::hasRefundId($order, $refundId)) {
                return 'duplicate';
            }
            $currency = strtolower((string) ($refund['currency'] ?? $order->get_currency()));
            if ($currency !== strtolower($order->get_currency())) {
                self::recordRefundId($order, $refundId);
                $order->add_order_note(__('Polar reported a refund in a different currency. Record it manually.', 'stackless-lite'));
                return 'currency mismatch';
            }
            $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0), $currency), (float) $order->get_remaining_refund_amount());
            if ($amount <= 0) {
                self::recordRefundId($order, $refundId);
                return 'nothing left';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                /* translators: %s: Polar refund id */
                'reason' => sprintf(__('Refunded in Polar (%s)', 'stackless-lite'), $refundId),
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

    /** order.refunded carries the order; its refunds come from the refunds list. */
    public function refundsForOrder(array $polarOrder, string $mode): string
    {
        $refunds = $this->gateway->api($mode)->request('GET', 'refunds/', ['order_id' => (string) ($polarOrder['id'] ?? ''), 'limit' => 100]);
        if (is_wp_error($refunds)) {
            throw new \RuntimeException(esc_html($refunds->get_error_message()));
        }
        $done = 0;
        $busy = false;
        foreach ((array) ($refunds['items'] ?? []) as $refund) {
            $outcome = is_array($refund) ? $this->recordRefund($refund, $mode) : '';
            if ($outcome === 'refunded') {
                $done++;
            } elseif ($outcome === 'busy') {
                $busy = true;
            }
        }
        // A refund skipped behind the order lock must be retried; recorded ones are skipped next time.
        return $busy ? 'busy' : 'refunds recorded: ' . $done;
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a WooCommerce subscription in line with a Polar subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $polarId = (string) ($remote['id'] ?? '');
        if ($polarId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($polarId, (array) ($remote['metadata'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        $subscription->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $polarId);
        if (!empty($remote['customer_id'])) {
            $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, (string) $remote['customer_id']);
        }
        $subscription->update_meta_data(Gateway::META_MODE, $mode);
        $subscription->update_meta_data(Gateway::META_CANCEL_AT_END, !empty($remote['cancel_at_period_end']) ? 'yes' : 'no');
        $end = (string) (($remote['status'] ?? '') === 'trialing' && !empty($remote['trial_end']) ? $remote['trial_end'] : ($remote['current_period_end'] ?? ''));
        if ($end !== '') {
            $subscription->update_meta_data(Gateway::META_PERIOD_END, $end);
            // Polar runs the schedule; let the Subscriptions module show Polar's next charge date when it can.
            if (method_exists($subscription, 'set_next_payment_date') && !in_array($remote['status'] ?? '', ['canceled'], true) && empty($remote['cancel_at_period_end'])) {
                $subscription->set_next_payment_date($end);
            }
        }
        $subscription->save();

        $target = EventMap::status($remote, $event);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: order.paid (or the trial path) activates it.
        $parent = $subscription->get_parent_id() ? wc_get_order($subscription->get_parent_id()) : false;
        if ($target !== null && in_array($target, $active, true) && $parent instanceof \WC_Order && !$parent->is_paid()) {
            if ((float) $parent->get_total() <= 0 && in_array($remote['status'] ?? '', ['trialing', 'active'], true) && $parent->get_payment_method() === $this->gateway->id) {
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

        $note = $target === SubscriptionData::STATUS_CANCELLED && !empty($remote['customer_cancellation_reason'])
            /* translators: %s: reason given in Polar */
            ? sprintf(__('Canceled in Polar: %s', 'stackless-lite'), (string) $remote['customer_cancellation_reason'])
            /* translators: %s: Polar subscription status */
            : sprintf(__('Polar subscription is now %s.', 'stackless-lite'), (string) ($remote['status'] ?? $event));
        $subscription->update_status($target, $note);
        do_action('stackless_polar_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }
}
