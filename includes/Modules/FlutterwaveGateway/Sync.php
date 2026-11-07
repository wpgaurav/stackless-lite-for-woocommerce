<?php

namespace Stackless\Modules\FlutterwaveGateway;

use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * Applies verified Flutterwave payments and refunds to WooCommerce orders.
 *
 * Every entry point verifies the transaction with Flutterwave's API first (as Flutterwave
 * asks: reference, status, currency and amount) and settles the order under a lock, only
 * while it is unpaid. Refunds are keyed by Flutterwave refund id. Repeating any of this is
 * harmless.
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

    /** A shop order of this gateway with a meta value. */
    public function findOrder(string $key, string $value): ?\WC_Order
    {
        if ($value === '') {
            return null;
        }
        $order = OrderQuery::findByMeta($key, $value);
        return $order && $order->get_payment_method() === $this->gateway->id ? $order : null;
    }

    /** The order a tx_ref was made for, when it is one of this gateway's. */
    public function orderForReference(string $txRef): ?\WC_Order
    {
        $orderId = Checkout::orderIdFrom($txRef);
        $order = $orderId ? wc_get_order($orderId) : false;
        return $order instanceof \WC_Order && $order->get_payment_method() === $this->gateway->id ? $order : null;
    }

    /**
     * @param string $transactionId Flutterwave's id when known (faster and exact), else the tx_ref is looked up.
     * @return array|\WP_Error The verified transaction.
     */
    public function verify(string $txRef, string $transactionId, string $mode)
    {
        $api = $this->gateway->api($mode);
        if ($transactionId !== '' && ctype_digit($transactionId)) {
            return $api->request('GET', 'transactions/' . rawurlencode($transactionId) . '/verify');
        }
        return $api->request('GET', 'transactions/verify_by_reference', ['tx_ref' => $txRef]);
    }

    /**
     * Verify the order's payment with Flutterwave and settle the order.
     *
     * @return true|\WP_Error stackless_flutterwave_failed when the payment did not go through,
     *                        stackless_flutterwave_pending when nothing was paid yet,
     *                        stackless_flutterwave_pending_payment while a payment is processing.
     */
    public function confirm(\WC_Order $order, string $txRef, string $transactionId = '')
    {
        $mode = $this->gateway->orderMode($order);
        $transaction = $this->verify($txRef, $transactionId, $mode);
        if (is_wp_error($transaction)) {
            return Api::isNotFound($transaction)
                ? new \WP_Error('stackless_flutterwave_pending', __('Flutterwave has no payment for this order yet.', 'stackless-lite'))
                : $transaction;
        }
        if (!$this->belongsTo($transaction, $order)) {
            return new \WP_Error('stackless_flutterwave_mismatch', __('This Flutterwave transaction belongs to a different order.', 'stackless-lite'));
        }
        $state = EventMap::transactionState((string) ($transaction['status'] ?? ''));
        if ($state === 'failed') {
            return new \WP_Error('stackless_flutterwave_failed', __('Your payment did not go through. Please try again or choose another payment method.', 'stackless-lite'));
        }
        if ($state !== 'paid') {
            return new \WP_Error('stackless_flutterwave_pending_payment', __('Flutterwave has not completed this payment yet. It will be confirmed automatically.', 'stackless-lite'));
        }
        $outcome = $this->settle($order, $transaction, $mode);
        return in_array($outcome, ['paid', 'already paid'], true)
            ? true
            : new \WP_Error('stackless_flutterwave_' . str_replace(' ', '_', $outcome), $outcome);
    }

    /** A verified transaction is this order's when its tx_ref was made for it. */
    public function belongsTo(array $transaction, \WC_Order $order): bool
    {
        $txRef = (string) ($transaction['tx_ref'] ?? '');
        if ($txRef === '') {
            return false;
        }
        if ($txRef === (string) $order->get_meta(Gateway::META_TX_REF) || Checkout::referenceFor($txRef, $order->get_id())) {
            $meta = is_array($transaction['meta'] ?? null) ? $transaction['meta'] : [];
            $site = (string) ($meta['wc_site'] ?? '');
            return $site === '' || $site === self::siteHost();
        }
        return false;
    }

    /** charge.completed: verify with the API (in the mode the order was started in), then settle. */
    public function confirmPayment(array $data, string $signedMode): string
    {
        $txRef = (string) ($data['tx_ref'] ?? '');
        $order = $this->orderForReference($txRef);
        if (!$order) {
            return 'not ours';
        }
        $mode = (string) $order->get_meta(Gateway::META_MODE) !== '' ? $this->gateway->orderMode($order) : $signedMode;
        $transaction = $this->verify($txRef, (string) ($data['id'] ?? ''), $mode);
        if (is_wp_error($transaction)) {
            // Thrown so the webhook answers 500 and Flutterwave retries.
            throw new \RuntimeException(esc_html($transaction->get_error_message()));
        }
        if (EventMap::transactionState((string) ($transaction['status'] ?? '')) !== 'paid') {
            if (EventMap::transactionState((string) ($transaction['status'] ?? '')) === 'failed' && !$order->is_paid()) {
                $order->add_order_note(sprintf(
                    /* translators: 1: Flutterwave reference, 2: processor response */
                    __('Flutterwave payment %1$s failed: %2$s', 'stackless-lite'),
                    $txRef,
                    sanitize_text_field((string) ($transaction['processor_response'] ?? __('no reason given', 'stackless-lite')))
                ));
                return 'failed noted';
            }
            return 'not paid';
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
            $orderMode = (string) $order->get_meta(Gateway::META_MODE);
            if ($orderMode !== '' && $orderMode !== $mode) {
                $this->gateway->log('Ignored a ' . $mode . '-mode Flutterwave payment for ' . $orderMode . '-mode order ' . $order->get_id() . '.', 'warning');
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
            $order->update_meta_data(Gateway::META_TX_REF, (string) $transaction['tx_ref']);
            $order->update_meta_data(Gateway::META_MODE, $mode);
            if (!empty($transaction['flw_ref'])) {
                $order->update_meta_data(Gateway::META_FLW_REF, sanitize_text_field((string) $transaction['flw_ref']));
            }
            if (!empty($transaction['payment_type'])) {
                $order->update_meta_data(Gateway::META_PAYMENT_TYPE, sanitize_key((string) $transaction['payment_type']));
            }
            if (!Money::paidMatches($transaction, $order->get_total(), $currency)) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Flutterwave charged, 2: order total */
                    __('Flutterwave charged %1$s but the order total is %2$s. Check the transaction in Flutterwave before fulfilling.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price((float) ($transaction['amount'] ?? 0), ['currency' => $charged !== '' ? $charged : $currency])),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $currency]))
                ));
                return $charged !== $currency ? 'currency mismatch' : 'amount mismatch';
            }
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: Flutterwave reference, 2: payment type */
                __('Flutterwave payment %1$s verified (%2$s).', 'stackless-lite'),
                (string) $transaction['tx_ref'],
                (string) ($transaction['payment_type'] ?? __('unknown method', 'stackless-lite'))
            ));
            $order->payment_complete($transactionId);
            return 'paid';
        }, 'busy');
    }

    /* ---- Refunds ------------------------------------------------------------------ */

    public static function recordRefundId(\WC_Order $order, string $refundId): void
    {
        $ids = array_values(array_filter(explode(',', (string) $order->get_meta(Gateway::META_REFUND_IDS))));
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data(Gateway::META_REFUND_IDS, implode(',', $ids));
            $order->save();
        }
    }

    public static function hasRefundId(\WC_Order $order, string $refundId): bool
    {
        return in_array($refundId, array_filter(explode(',', (string) $order->get_meta(Gateway::META_REFUND_IDS))), true);
    }

    /**
     * A refund webhook (v3 sends a flat refund object). Refunds made here are already in
     * WooCommerce; one made in the Flutterwave dashboard is recorded once, after its status
     * is read back from the API.
     */
    public function recordRefund(array $data): string
    {
        $transactionId = (string) ($data['TransactionId'] ?? ($data['tx_id'] ?? ($data['transaction_id'] ?? '')));
        $order = $this->findOrder(Gateway::META_TRANSACTION_ID, $transactionId);
        if (!$order) {
            return 'not ours';
        }
        $refundId = (string) ($data['id'] ?? '');
        if ($refundId === '') {
            return 'no refund id';
        }
        $remote = $this->gateway->api($this->gateway->orderMode($order))->request('GET', 'refunds/' . rawurlencode($refundId));
        if (is_wp_error($remote)) {
            throw new \RuntimeException(esc_html($remote->get_error_message()));
        }
        $remoteTransaction = (string) ($remote['tx_id'] ?? ($remote['transaction_id'] ?? ''));
        if ($remoteTransaction !== '' && $remoteTransaction !== (string) $order->get_meta(Gateway::META_TRANSACTION_ID)) {
            return 'other transaction';
        }
        $status = (string) ($remote['status'] ?? ($data['status'] ?? ''));
        $amount = (float) ($remote['amount_refunded'] ?? ($data['AmountRefunded'] ?? ($data['amount_refunded'] ?? 0)));
        $state = EventMap::refundState($status);

        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refundId, $amount, $state) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            if ($state === 'failed') {
                if (self::hasRefundId($order, $refundId)) {
                    $order->add_order_note(sprintf(
                        /* translators: %s: Flutterwave refund id */
                        __('Flutterwave could not complete refund %s. The WooCommerce refund recorded for it did not reach the customer; refund another way or correct the order.', 'stackless-lite'),
                        $refundId
                    ));
                    return 'failed noted';
                }
                return 'failed';
            }
            if ($state !== 'done') {
                return 'not done';
            }
            if (self::hasRefundId($order, $refundId)) {
                return 'duplicate';
            }
            $amount = min($amount, (float) $order->get_remaining_refund_amount());
            self::recordRefundId($order, $refundId);
            if ($amount <= 0) {
                return 'nothing left';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, Money::decimals($order->get_currency())),
                /* translators: %s: Flutterwave refund id */
                'reason' => sprintf(__('Refunded in Flutterwave (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            return 'refunded';
        }, 'busy');
    }
}
