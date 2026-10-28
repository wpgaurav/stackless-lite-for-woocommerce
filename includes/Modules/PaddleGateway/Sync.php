<?php

namespace Stackless\Modules\PaddleGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Applies Paddle payment, renewal and subscription state to WooCommerce.
 *
 * Every entry point (browser return, webhook) funnels through these methods and
 * each is safe to repeat: orders are settled under a lock and only while
 * unpaid, renewals are keyed by Paddle transaction id.
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

    /** True when custom data names another site sharing this Paddle account. */
    public static function otherSite(array $customData): bool
    {
        $site = (string) ($customData['wc_site'] ?? '');
        return $site !== '' && $site !== Checkout::siteHost();
    }

    /** WooCommerce subscription linked to a Paddle subscription (by stored id, else by custom data). */
    public function findSubscription(string $paddleId, array $customData): ?\WC_Order
    {
        if ($paddleId !== '') {
            $first = OrderQuery::findByMeta(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleId, ['type' => 'stackless_sub', 'status' => self::SUBSCRIPTION_STATUSES]);
            if ($first instanceof \WC_Order) {
                $subscription = stackless_get_subscription($first->get_id());
                return $subscription instanceof \WC_Order ? $subscription : $first;
            }
        }

        if (self::otherSite($customData)) {
            return null;
        }
        $wcId = absint($customData['wc_subscription_id'] ?? 0);
        $subscription = $wcId ? stackless_get_subscription($wcId) : null;
        if (!$subscription instanceof \WC_Order) {
            return null;
        }
        $parent = absint($customData['wc_order_id'] ?? 0);
        $linked = (string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION);
        if (($parent && $subscription->get_parent_id() !== $parent) || ($linked !== '' && $paddleId !== '' && $linked !== $paddleId)) {
            return null;
        }
        return $subscription;
    }

    /* ---- First payments ---------------------------------------------------------- */

    /**
     * Look up a transaction and confirm the order when it is paid (browser return).
     *
     * @return true|\WP_Error
     */
    public function confirmById(\WC_Order $order, string $transactionId)
    {
        $transaction = $this->gateway->api($this->gateway->orderMode($order))->request('GET', 'transactions/' . rawurlencode($transactionId));
        if (is_wp_error($transaction)) {
            return $transaction;
        }
        if ((string) ($transaction['custom_data']['wc_order_id'] ?? '') !== (string) $order->get_id()) {
            return new \WP_Error('stackless_paddle_mismatch', __('This Paddle transaction belongs to a different order.', 'stackless-lite'));
        }
        if (!EventMap::isPaid($transaction)) {
            /* translators: %s: Paddle transaction status */
            return new \WP_Error('stackless_paddle_unpaid', sprintf(__('The Paddle transaction is %s, not paid yet. It will be confirmed automatically.', 'stackless-lite'), (string) ($transaction['status'] ?? 'unknown')));
        }
        $this->confirmPayment($order, $transaction, $this->gateway->orderMode($order));
        return true;
    }

    /** Route a paid Paddle transaction to the first payment or a renewal (webhook). */
    public function confirmTransaction(array $transaction, string $mode): string
    {
        if (!EventMap::isPaid($transaction)) {
            return 'unpaid';
        }
        $kind = EventMap::transactionKind($transaction);
        if ($kind === 'renewal') {
            return $this->recordRenewal($transaction, $mode);
        }
        if ($kind !== 'first') {
            return 'ignored'; // Plan changes, one-off charges and card updates are started in Paddle, not here.
        }

        $customData = (array) ($transaction['custom_data'] ?? []);
        if (self::otherSite($customData)) {
            return 'other site';
        }
        $order = wc_get_order(absint($customData['wc_order_id'] ?? 0));
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->gateway->id) {
            return 'not ours';
        }
        $stored = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if ($stored !== '' && $stored !== (string) ($transaction['id'] ?? '')) {
            return 'transaction mismatch';
        }
        return $this->confirmPayment($order, $transaction, $mode);
    }

    public function confirmPayment(\WC_Order $order, array $transaction, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $transaction, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $transactionId = (string) ($transaction['id'] ?? '');
            $paddleSubscription = (string) ($transaction['subscription_id'] ?? '');
            $customer = (string) ($transaction['customer_id'] ?? '');

            if ($order->is_paid()) {
                // transaction.completed after transaction.paid: the subscription id arrives now.
                if ($paddleSubscription !== '' && (string) $order->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION) === '') {
                    $this->linkSubscription($order, $paddleSubscription, $customer, $mode);
                    $order->save();
                    return 'linked subscription';
                }
                return 'already paid';
            }

            $totals = (array) ($transaction['details']['totals'] ?? []);
            $currency = strtoupper((string) ($transaction['currency_code'] ?? ($totals['currency_code'] ?? '')));
            $order->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
            $order->update_meta_data(Gateway::META_MODE, $mode);

            if ($currency !== '' && $currency !== strtoupper($order->get_currency())) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: expected currency, 2: charged currency */
                    __('Paddle charged in %2$s but the order is in %1$s. Check the payment in Paddle.', 'stackless-lite'),
                    strtoupper($order->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            $currency = $currency !== '' ? $currency : strtoupper($order->get_currency());
            self::storeAmounts($order, $totals, $currency);

            if (!Money::paidMatches($totals, Money::toMinor($order->get_total(), $currency))) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Paddle charged, 2: order total */
                    __('Paddle charged %1$s but the order total is %2$s. Check the payment in Paddle before fulfilling.', 'stackless-lite'),
                    self::price((int) ($totals['grand_total'] ?? 0), $currency),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $order->get_currency()]))
                ));
                return 'amount mismatch';
            }

            if ($paddleSubscription !== '') {
                $this->linkSubscription($order, $paddleSubscription, $customer, $mode);
            } elseif ($customer !== '') {
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
            }
            $order->save();

            $order->add_order_note(sprintf(
                /* translators: 1: Paddle transaction id, 2: total charged including tax */
                __('Paddle transaction %1$s paid (%2$s charged including tax).', 'stackless-lite'),
                $transactionId,
                self::price((int) ($totals['grand_total'] ?? 0), $currency)
            ));
            $order->payment_complete($transactionId);
            return 'paid';
        }, 'busy');
    }

    /** Start a free trial when the subscription shows up before its zero-amount transaction is reported. */
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
            /* translators: %s: Paddle subscription id */
            $order->add_order_note(sprintf(__('Paddle subscription %s started its free trial.', 'stackless-lite'), (string) ($remote['id'] ?? '')));
            $order->payment_complete((string) $order->get_meta(Gateway::META_TRANSACTION_ID));
            return 'trial';
        }, 'busy');
    }

    /** Write the Paddle subscription on the parent order and on the subscription created from it. */
    public function linkSubscription(\WC_Order $order, string $paddleSubscription, string $customer, string $mode): void
    {
        if ($paddleSubscription === '') {
            return;
        }
        $write = static function (\WC_Order $target) use ($paddleSubscription, $customer, $mode) {
            $target->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleSubscription);
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

    private static function storeAmounts(\WC_Order $order, array $totals, string $currency): void
    {
        $decimals = Money::decimals($currency);
        $order->update_meta_data(Gateway::META_TAX, wc_format_decimal(Money::fromMinor((int) ($totals['tax'] ?? 0), $currency), $decimals));
        $order->update_meta_data(Gateway::META_TOTAL, wc_format_decimal(Money::fromMinor((int) ($totals['grand_total'] ?? ($totals['total'] ?? 0)), $currency), $decimals));
    }

    private static function price(int $minor, string $currency): string
    {
        return wp_strip_all_tags(wc_price(Money::fromMinor($minor, $currency), ['currency' => strtoupper($currency)]));
    }

    /* ---- Failed payments ---------------------------------------------------------- */

    /** Note a failed attempt; Paddle retries renewals itself and reports past_due through the subscription. */
    public function paymentFailed(array $transaction, string $mode): string
    {
        $customData = (array) ($transaction['custom_data'] ?? []);
        if (self::otherSite($customData)) {
            return 'other site';
        }
        $error = '';
        foreach ((array) ($transaction['payments'] ?? []) as $payment) {
            if (is_array($payment) && !empty($payment['error_code'])) {
                $error = (string) $payment['error_code'];
                break;
            }
        }
        $target = null;
        if (EventMap::transactionKind($transaction) === 'renewal') {
            $subscription = $this->findSubscription((string) ($transaction['subscription_id'] ?? ''), $customData);
            $awaiting = $subscription ? absint($subscription->get_meta(Gateway::META_AWAITING)) : 0;
            $target = $awaiting ? wc_get_order($awaiting) : $subscription;
        } else {
            $order = wc_get_order(absint($customData['wc_order_id'] ?? 0));
            $target = $order instanceof \WC_Order && $order->get_payment_method() === $this->gateway->id ? $order : null;
        }
        if (!$target instanceof \WC_Order) {
            return 'not ours';
        }
        $target->add_order_note(sprintf(
            /* translators: 1: Paddle transaction id, 2: decline reason code */
            __('Paddle could not take a payment for transaction %1$s (%2$s).', 'stackless-lite'),
            (string) ($transaction['id'] ?? ''),
            $error !== '' ? $error : __('no reason given', 'stackless-lite')
        ));
        return 'noted';
    }

    /* ---- Renewals ---------------------------------------------------------------- */

    /** A renewal Paddle charged becomes a paid WooCommerce renewal order. */
    public function recordRenewal(array $transaction, string $mode): string
    {
        $transactionId = (string) ($transaction['id'] ?? '');
        $paddleSubscription = (string) ($transaction['subscription_id'] ?? '');
        if ($transactionId === '' || $paddleSubscription === '') {
            return 'no subscription';
        }
        if (self::findOrderByMeta(Gateway::META_TRANSACTION_ID, $transactionId)) {
            return 'duplicate';
        }
        $subscription = $this->findSubscription($paddleSubscription, (array) ($transaction['custom_data'] ?? []));
        if (!$subscription) {
            $this->gateway->log('Renewal ' . $transactionId . ' arrived for unknown Paddle subscription ' . $paddleSubscription . '.', 'warning');
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        return (string) Lock::run('renewal-' . $transactionId, function () use ($transaction, $transactionId, $paddleSubscription, $subscription, $mode) {
            if (self::findOrderByMeta(Gateway::META_TRANSACTION_ID, $transactionId)) {
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
                    // Thrown so Paddle retries once the Subscriptions module is active again.
                    throw new \RuntimeException('Subscriptions module is not active; cannot record the renewal.');
                }
                $created = stackless_create_renewal_order($subscription);
                if (is_wp_error($created)) {
                    // Thrown so the webhook answers 500 and Paddle retries later.
                    throw new \RuntimeException(esc_html('Could not create renewal order: ' . $created->get_error_message()));
                }
                $renewal = $created;
            }

            $totals = (array) ($transaction['details']['totals'] ?? []);
            $currency = strtoupper((string) ($transaction['currency_code'] ?? $renewal->get_currency()));
            $renewal->set_payment_method($this->gateway->id);
            $renewal->set_payment_method_title($this->gateway->get_title());
            $renewal->delete_meta_data(Gateway::META_AWAITING);
            $renewal->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
            $renewal->update_meta_data(Gateway::META_MODE, $mode);
            $renewal->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleSubscription);
            self::storeAmounts($renewal, $totals, $currency);
            $renewal->save();

            $subscription->delete_meta_data(Gateway::META_AWAITING);
            $end = (string) ($transaction['billing_period']['ends_at'] ?? '');
            if ($end !== '') {
                $subscription->update_meta_data(Gateway::META_PERIOD_END, $end);
            }
            $subscription->save();

            if ($currency !== strtoupper($renewal->get_currency())) {
                $renewal->update_status('on-hold', sprintf(
                    /* translators: 1: renewal currency, 2: charged currency */
                    __('Paddle charged this renewal in %2$s but the subscription bills in %1$s. Check it in Paddle.', 'stackless-lite'),
                    strtoupper($renewal->get_currency()),
                    $currency
                ));
                return 'currency mismatch';
            }
            if (!Money::paidMatches($totals, Money::toMinor($renewal->get_total(), $currency))) {
                // Paddle already took the money; record it and flag the difference.
                $renewal->add_order_note(sprintf(
                    /* translators: 1: amount Paddle charged, 2: renewal total */
                    __('Paddle charged %1$s for this renewal; the WooCommerce renewal total is %2$s.', 'stackless-lite'),
                    self::price((int) ($totals['grand_total'] ?? 0), $currency),
                    wp_strip_all_tags(wc_price($renewal->get_total(), ['currency' => $renewal->get_currency()]))
                ));
            }
            /* translators: %s: Paddle transaction id */
            $renewal->add_order_note(sprintf(__('Paddle charged this renewal (transaction %s).', 'stackless-lite'), $transactionId));
            $renewal->payment_complete($transactionId);
            return 'renewed';
        }, 'busy');
    }

    /* ---- Subscription state -------------------------------------------------------- */

    /** Bring a WooCommerce subscription in line with a Paddle subscription object. */
    public function syncSubscription(array $remote, string $mode, string $event): string
    {
        $paddleId = (string) ($remote['id'] ?? '');
        if ($paddleId === '') {
            return 'no id';
        }
        $subscription = $this->findSubscription($paddleId, (array) ($remote['custom_data'] ?? []));
        if (!$subscription) {
            return 'unknown subscription';
        }
        if (!in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
            return 'other gateway';
        }

        // Webhooks can arrive out of order; ignore one older than what was applied.
        $updated = strtotime((string) ($remote['updated_at'] ?? '')) ?: 0;
        $applied = (int) $subscription->get_meta('_stackless_paddle_synced_at');
        if ($updated && $applied && $updated < $applied) {
            return 'stale';
        }

        $subscription->update_meta_data(SubscriptionData::META_GATEWAY_SUBSCRIPTION, $paddleId);
        if (!empty($remote['customer_id'])) {
            $subscription->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, (string) $remote['customer_id']);
        }
        $subscription->update_meta_data(Gateway::META_MODE, $mode);
        $subscription->update_meta_data(Gateway::META_CANCEL_AT_END, EventMap::cancelScheduled($remote) ? 'yes' : 'no');
        if ($updated) {
            $subscription->update_meta_data('_stackless_paddle_synced_at', (string) $updated);
        }
        $end = (string) ($remote['current_billing_period']['ends_at'] ?? '');
        if ($end !== '') {
            $subscription->update_meta_data(Gateway::META_PERIOD_END, $end);
        }
        $next = EventMap::nextCharge($remote);
        // Paddle runs the schedule; let the Subscriptions module show Paddle's next charge date.
        if ($next !== '' && method_exists($subscription, 'set_next_payment_date')) {
            $subscription->set_next_payment_date($next);
        }
        $subscription->save();

        $target = EventMap::status($remote);
        $active = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_PENDING_CANCEL];

        // The first payment isn't confirmed yet: the transaction webhook (or the trial path) activates it.
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

        /* translators: %s: Paddle subscription status */
        $note = sprintf(__('Paddle subscription is now %s.', 'stackless-lite'), (string) ($remote['status'] ?? $event));
        if (EventMap::cancelScheduled($remote) && $target === SubscriptionData::STATUS_PENDING_CANCEL) {
            $note = __('Paddle subscription is set to end at the close of the paid period.', 'stackless-lite');
        }
        $subscription->update_status($target, $note);
        do_action('stackless_paddle_gateway_subscription_synced', $subscription, $remote, $event);
        return 'status ' . $target;
    }
}
