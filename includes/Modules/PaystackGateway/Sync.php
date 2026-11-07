<?php

namespace Stackless\Modules\PaystackGateway;

use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Applies verified Paystack payments, refunds and disputes to WooCommerce orders.
 *
 * Every entry point (browser return, webhook, a repeated checkout) verifies the transaction
 * with Paystack's API first and settles the order under a lock, only while it is unpaid
 * and only when the currency and amount match. Refunds are reconciled against Paystack's
 * refund list by refund id, so repeating any of this is harmless.
 */
class Sync
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function siteHost(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    private static function forThisSite(array $metadata): bool
    {
        $site = (string) ($metadata['wc_site'] ?? '');
        return $site === '' || $site === self::siteHost();
    }

    /** A shop order of this gateway with a meta value. */
    public function findOrder(string $key, string $value): ?\WC_Order
    {
        if ($value === '') {
            return null;
        }
        $order = OrderQuery::findByMeta($key, $value);
        return $order && $order->get_payment_method() === $this->gateway->id ? $order : null;
    }

    /** @return array|\WP_Error The verified transaction. */
    public function verify(string $reference, string $mode)
    {
        return $this->gateway->api($mode)->request('GET', 'transaction/verify/' . rawurlencode($reference));
    }

    /**
     * Verify one of the order's transactions with Paystack and settle the order.
     *
     * @return true|\WP_Error stackless_paystack_failed when the payment did not go through,
     *                        stackless_paystack_pending while it is still open.
     */
    public function confirm(\WC_Order $order, string $reference)
    {
        $mode = $this->gateway->orderMode($order);
        $transaction = $this->verify($reference, $mode);
        if (is_wp_error($transaction)) {
            return $transaction;
        }
        if (!$this->belongsTo($transaction, $order)) {
            return new \WP_Error('stackless_paystack_mismatch', __('This Paystack transaction belongs to a different order.', 'stackless-lite'));
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state === 'failed') {
            return new \WP_Error('stackless_paystack_failed', __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if ($state !== 'paid') {
            return new \WP_Error('stackless_paystack_pending', __('Paystack has not completed this payment yet. It will be confirmed automatically.', 'stackless-lite'));
        }
        $outcome = $this->settle($order, $transaction, $mode);
        return in_array($outcome, ['paid', 'already paid'], true)
            ? true
            : new \WP_Error('stackless_paystack_' . str_replace(' ', '_', $outcome), $outcome);
    }

    /** A verified transaction is this order's when its metadata or its reference says so. */
    public function belongsTo(array $transaction, \WC_Order $order): bool
    {
        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
        $orderId = (string) ($metadata['wc_order_id'] ?? '');
        if ($orderId !== '') {
            return $orderId === (string) $order->get_id() && self::forThisSite($metadata);
        }
        $reference = (string) ($transaction['reference'] ?? '');
        return $reference !== '' && ($reference === (string) $order->get_meta(Gateway::META_REFERENCE) || Checkout::referenceFor($reference, $order->get_id()));
    }

    /** The order a verified transaction was started for. */
    public function orderFor(array $transaction): ?\WC_Order
    {
        $metadata = is_array($transaction['metadata'] ?? null) ? $transaction['metadata'] : [];
        if (!empty($metadata['wc_order_id'])) {
            if (!self::forThisSite($metadata)) {
                return null;
            }
            $order = wc_get_order(absint($metadata['wc_order_id']));
            return $order instanceof \WC_Order && $order->get_payment_method() === $this->gateway->id ? $order : null;
        }
        return $this->findOrder(Gateway::META_REFERENCE, (string) ($transaction['reference'] ?? ''));
    }

    /** charge.success: verify the transaction again with the API, then settle its order. */
    public function confirmPayment(array $data, string $mode): string
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return 'no reference';
        }
        $transaction = $this->verify($reference, $mode);
        if (is_wp_error($transaction)) {
            // Thrown so the webhook answers 500 and Paystack retries.
            throw new \RuntimeException(esc_html($transaction->get_error_message()));
        }
        if (EventMap::transactionState((string) ($transaction['status'] ?? '')) !== 'paid') {
            return 'not paid';
        }
        $order = $this->orderFor($transaction);
        if (!$order) {
            return 'not ours';
        }
        return $this->settle($order, $transaction, $mode);
    }

    /** Mark the order paid from a verified, successful transaction, once. */
    public function settle(\WC_Order $order, array $transaction, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $transaction, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $transactionId = (string) ($transaction['id'] ?? '');
            // A test-mode payment never settles a live order (or the other way round): anyone can
            // start a test transaction with the public test key and name a live order in it.
            $orderMode = (string) $order->get_meta(Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                $this->gateway->log('Ignored a ' . $mode . '-mode Paystack payment for ' . $orderMode . '-mode order ' . $order->get_id() . '.', 'warning');
                return 'other mode';
            }
            if ($order->is_paid()) {
                if ((string) $order->get_meta(Gateway::META_TRANSACTION_ID) === '' && $transactionId !== '' && $this->belongsTo($transaction, $order)) {
                    $order->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
                    $order->save();
                }
                return 'already paid';
            }
            if (EventMap::transactionState((string) ($transaction['status'] ?? '')) !== 'paid' || !$this->belongsTo($transaction, $order)) {
                return 'not paid';
            }

            $currency = strtoupper($order->get_currency());
            $charged = strtoupper((string) ($transaction['currency'] ?? ''));
            $order->update_meta_data(Gateway::META_TRANSACTION_ID, $transactionId);
            $order->update_meta_data(Gateway::META_REFERENCE, (string) ($transaction['reference'] ?? $order->get_meta(Gateway::META_REFERENCE)));
            $order->update_meta_data(Gateway::META_MODE, $mode);
            if (!empty($transaction['channel'])) {
                $order->update_meta_data(Gateway::META_CHANNEL, sanitize_key((string) $transaction['channel']));
            }
            $expected = Money::toMinor($order->get_total(), $currency);
            if (!Money::paidMatches($transaction, $expected, $currency)) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Paystack charged, 2: order total */
                    __('Paystack charged %1$s but the order total is %2$s. Check the transaction in Paystack before fulfilling.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price(Money::fromMinor((int) ($transaction['amount'] ?? 0)), ['currency' => $charged !== '' ? $charged : $currency])),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $currency]))
                ));
                return $charged !== $currency ? 'currency mismatch' : 'amount mismatch';
            }
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: Paystack reference, 2: payment channel */
                __('Paystack payment %1$s verified (%2$s).', 'stackless-lite'),
                (string) ($transaction['reference'] ?? ''),
                (string) ($transaction['channel'] ?? __('unknown channel', 'stackless-lite'))
            ));
            $order->payment_complete($transactionId);
            return 'paid';
        }, 'busy');
    }

    /* ---- Refunds ------------------------------------------------------------------ */

    /** @return string[] */
    private static function ids(\WC_Order $order, string $key): array
    {
        return array_values(array_filter(explode(',', (string) $order->get_meta($key))));
    }

    public static function recordRefundId(\WC_Order $order, string $refundId, string $key = Gateway::META_REFUND_IDS): void
    {
        $ids = self::ids($order, $key);
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data($key, implode(',', $ids));
            $order->save();
        }
    }

    public static function hasRefundId(\WC_Order $order, string $refundId, string $key = Gateway::META_REFUND_IDS): bool
    {
        return in_array($refundId, self::ids($order, $key), true);
    }

    /**
     * A refund event: Paystack's refund events carry no refund id, so read the transaction's
     * refunds from the API and mirror each processed one (made in the Paystack dashboard)
     * once; refunds made here are already in WooCommerce. A failed refund made here is noted.
     */
    public function reconcileRefunds(array $data, string $mode): string
    {
        $order = $this->findOrder(Gateway::META_REFERENCE, (string) ($data['transaction_reference'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        $transactionId = (string) $order->get_meta(Gateway::META_TRANSACTION_ID);
        if ($transactionId === '') {
            return 'no transaction';
        }
        $refunds = $this->gateway->api($this->gateway->orderMode($order))->request('GET', 'refund', ['transaction' => $transactionId, 'perPage' => 100]);
        if (is_wp_error($refunds)) {
            throw new \RuntimeException(esc_html($refunds->get_error_message()));
        }
        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refunds) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $done = [];
            foreach ($refunds as $refund) {
                if (is_array($refund)) {
                    $done[] = $this->applyRefund($order, $refund);
                }
            }
            $done = array_filter($done);
            return $done ? implode(', ', array_unique($done)) : 'nothing new';
        }, 'busy');
    }

    /** Mirror one refund from Paystack's list. Returns what happened, '' when nothing did. */
    public function applyRefund(\WC_Order $order, array $refund): string
    {
        $refundId = (string) ($refund['id'] ?? '');
        if ($refundId === '') {
            return '';
        }
        $state = EventMap::refundState((string) ($refund['status'] ?? ''));
        if ($state === 'failed') {
            if (self::hasRefundId($order, $refundId) && !self::hasRefundId($order, $refundId, Gateway::META_REFUND_FAILED)) {
                self::recordRefundId($order, $refundId, Gateway::META_REFUND_FAILED);
                $order->add_order_note(sprintf(
                    /* translators: %s: Paystack refund id */
                    __('Paystack could not complete refund %s. The WooCommerce refund recorded for it did not reach the customer; refund another way or correct the order.', 'stackless-lite'),
                    $refundId
                ));
                return 'failed noted';
            }
            return '';
        }
        if ($state !== 'done' || self::hasRefundId($order, $refundId)) {
            return '';
        }
        $currency = strtoupper($order->get_currency());
        if (strtoupper((string) ($refund['currency'] ?? $currency)) !== $currency) {
            self::recordRefundId($order, $refundId);
            $order->add_order_note(__('Paystack reported a refund in a different currency. Record it manually.', 'stackless-lite'));
            return 'currency mismatch';
        }
        $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0)), (float) $order->get_remaining_refund_amount());
        self::recordRefundId($order, $refundId);
        if ($amount <= 0) {
            return 'nothing left';
        }
        $created = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount' => wc_format_decimal($amount, 2),
            /* translators: %s: Paystack refund id */
            'reason' => sprintf(__('Refunded in Paystack (%s)', 'stackless-lite'), $refundId),
            'refund_payment' => false,
            'restock_items' => false,
        ]);
        if (is_wp_error($created)) {
            throw new \RuntimeException(esc_html($created->get_error_message()));
        }
        return 'refunded';
    }

    /* ---- Disputes ------------------------------------------------------------------ */

    public function noteDispute(string $event, array $dispute): string
    {
        $transaction = is_array($dispute['transaction'] ?? null) ? $dispute['transaction'] : [];
        $order = $this->findOrder(Gateway::META_REFERENCE, (string) ($transaction['reference'] ?? ''))
            ?: $this->findOrder(Gateway::META_TRANSACTION_ID, (string) ($transaction['id'] ?? ''));
        if (!$order) {
            return 'not ours';
        }
        $order->add_order_note(sprintf(
            /* translators: 1: Paystack event, 2: dispute status */
            __('Paystack reported %1$s (status: %2$s). Respond to it in the Paystack dashboard.', 'stackless-lite'),
            $event,
            (string) ($dispute['status'] ?? '')
        ));
        return 'noted';
    }
}
