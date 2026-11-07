<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * Applies Mercado Pago payments, refunds and chargebacks to WooCommerce orders.
 *
 * Nothing is taken from a notification or a return address except which payment to read:
 * each payment is fetched from the API in the mode its order was started in, then applied
 * under a lock. Approved payments pay the order once, when the reference, currency, amount
 * and test/live mode all match; refunds are keyed by Mercado Pago refund id.
 */
class Sync
{
    public const META_CHARGEBACK = '_stackless_mercadopago_chargeback';

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

    /** @return array|\WP_Error */
    public function payment(string $paymentId, string $mode)
    {
        return $this->gateway->api($mode)->request('GET', 'v1/payments/' . rawurlencode($paymentId));
    }

    /**
     * The order's most relevant payment: an approved one, else the newest. Empty when the
     * buyer has not paid anything yet.
     *
     * @return array|\WP_Error
     */
    public function latestPayment(\WC_Order $order, string $mode)
    {
        $found = $this->gateway->api($mode)->request('GET', 'v1/payments/search', [
            'external_reference' => Checkout::externalReference($order),
            'sort' => 'date_created',
            'criteria' => 'desc',
            'limit' => 20,
        ]);
        if (is_wp_error($found)) {
            return $found;
        }
        $results = array_values(array_filter((array) ($found['results'] ?? []), 'is_array'));
        foreach ($results as $payment) {
            if (EventMap::paymentState((string) ($payment['status'] ?? '')) === 'paid') {
                return $payment;
            }
        }
        return $results[0] ?? [];
    }

    /** A payment is this order's when its external reference (or metadata) names the order on this site. */
    public function belongsTo(array $payment, \WC_Order $order): bool
    {
        $reference = (string) ($payment['external_reference'] ?? '');
        if ($reference !== '') {
            return $reference === Checkout::externalReference($order);
        }
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $site = (string) ($metadata['wc_site'] ?? '');
        return (string) ($metadata['wc_order_id'] ?? '') === (string) $order->get_id() && ($site === '' || $site === self::siteHost());
    }

    /** The order a payment was made for, when it is one of this gateway's on this site. */
    public function orderFor(array $payment): ?\WC_Order
    {
        $orderId = EventMap::orderIdFrom((string) ($payment['external_reference'] ?? ''));
        if (!$orderId) {
            $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
            $orderId = absint($metadata['wc_order_id'] ?? 0);
        }
        $order = $orderId ? wc_get_order($orderId) : false;
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== $this->gateway->id || !$this->belongsTo($payment, $order)) {
            return null;
        }
        return $order;
    }

    /** A payment notification: read the payment in the order's own mode and apply it. */
    public function confirmNotification(string $paymentId, string $notifiedMode): string
    {
        if ($paymentId === '' || !ctype_digit($paymentId)) {
            return 'no payment id';
        }
        $payment = $this->payment($paymentId, $notifiedMode);
        if (is_wp_error($payment)) {
            $other = $notifiedMode === 'live' ? 'test' : 'live';
            $retry = $this->gateway->accessToken($other) !== '' ? $this->payment($paymentId, $other) : $payment;
            if (is_wp_error($retry)) {
                // Thrown so the webhook answers 500 and Mercado Pago retries.
                throw new \RuntimeException(esc_html($payment->get_error_message()));
            }
            $payment = $retry;
            $notifiedMode = $other;
        }
        $order = $this->orderFor($payment);
        if (!$order) {
            return 'not ours';
        }
        $mode = $this->gateway->orderMode($order);
        if ($mode !== $notifiedMode) {
            $payment = $this->payment($paymentId, $mode);
            if (is_wp_error($payment)) {
                throw new \RuntimeException(esc_html($payment->get_error_message()));
            }
        }
        return $this->apply($order, $payment, $mode);
    }

    /** Bring the order in line with a payment read from the API. */
    public function apply(\WC_Order $order, array $payment, string $mode): string
    {
        if (!$this->belongsTo($payment, $order)) {
            return 'not this order';
        }
        $state = EventMap::paymentState((string) ($payment['status'] ?? ''));
        switch ($state) {
            case 'paid':
                $outcome = $this->settle($order, $payment, $mode);
                $refunds = $this->reconcileRefunds($order, $payment);
                return $refunds !== '' ? $outcome . '; ' . $refunds : $outcome;
            case 'pending':
                return $this->awaiting($order, $payment);
            case 'refunded':
                $outcome = $this->reconcileRefunds($order, $payment);
                if ((string) ($payment['status'] ?? '') === 'charged_back') {
                    $outcome = trim($outcome . ' ' . $this->noteChargeback($order, $payment));
                }
                return $outcome !== '' ? $outcome : 'refunded';
            default:
                return $this->failed($order, $payment);
        }
    }

    /** Mark the order paid from an approved payment, once. */
    public function settle(\WC_Order $order, array $payment, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment, $mode) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            $paymentId = (string) ($payment['id'] ?? '');
            if ($order->is_paid()) {
                if ((string) $order->get_meta(Gateway::META_PAYMENT_ID) === '' && $paymentId !== '') {
                    $order->update_meta_data(Gateway::META_PAYMENT_ID, $paymentId);
                    $order->save();
                }
                return 'already paid';
            }
            if (EventMap::paymentState((string) ($payment['status'] ?? '')) !== 'paid') {
                return 'not paid';
            }

            $currency = strtoupper($order->get_currency());
            $order->update_meta_data(Gateway::META_PAYMENT_ID, $paymentId);
            $order->update_meta_data(Gateway::META_MODE, $mode);
            if (!empty($payment['payment_type_id'])) {
                $order->update_meta_data(Gateway::META_PAYMENT_TYPE, sanitize_key((string) $payment['payment_type_id']));
            }
            $order->delete_meta_data(Gateway::META_AWAITING);
            if ($mode === 'live' && isset($payment['live_mode']) && $payment['live_mode'] === false) {
                $order->save();
                $order->update_status('on-hold', __('Mercado Pago approved a test payment for this live order. No real money was taken; do not fulfil it.', 'stackless-lite'));
                return 'test payment on live order';
            }
            if (!Money::paidMatches($payment, $order->get_total(), $currency)) {
                $order->save();
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: amount Mercado Pago charged, 2: order total */
                    __('Mercado Pago charged %1$s but the order total is %2$s. Check the payment in Mercado Pago before fulfilling.', 'stackless-lite'),
                    wp_strip_all_tags(wc_price((float) ($payment['transaction_amount'] ?? 0), ['currency' => strtoupper((string) ($payment['currency_id'] ?? $currency))])),
                    wp_strip_all_tags(wc_price($order->get_total(), ['currency' => $currency]))
                ));
                return strtoupper((string) ($payment['currency_id'] ?? '')) !== $currency ? 'currency mismatch' : 'amount mismatch';
            }
            $order->save();
            $order->add_order_note(sprintf(
                /* translators: 1: Mercado Pago payment id, 2: payment method */
                __('Mercado Pago payment %1$s approved (%2$s).', 'stackless-lite'),
                $paymentId,
                (string) ($payment['payment_method_id'] ?? ($payment['payment_type_id'] ?? __('unknown method', 'stackless-lite')))
            ));
            $order->payment_complete($paymentId);
            return 'paid';
        }, 'busy');
    }

    /** A cash, bank or in-review payment: hold the order (stock kept) until Mercado Pago decides. */
    private function awaiting(\WC_Order $order, array $payment): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || $order->is_paid() || !$order->has_status(['pending', 'failed'])) {
                return 'no change';
            }
            $order->update_meta_data(Gateway::META_AWAITING, (string) ($payment['id'] ?? ''));
            $order->save();
            $order->update_status('on-hold', sprintf(
                /* translators: 1: Mercado Pago payment id, 2: status detail */
                __('Mercado Pago payment %1$s is waiting to be completed (%2$s). The order is paid automatically once Mercado Pago approves it.', 'stackless-lite'),
                (string) ($payment['id'] ?? ''),
                sanitize_text_field((string) ($payment['status_detail'] ?? ($payment['status'] ?? '')))
            ));
            return 'awaiting';
        }, 'busy');
    }

    /** A rejected or cancelled payment: an order held for it becomes failed so it can be paid again. */
    private function failed(\WC_Order $order, array $payment): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || $order->is_paid()) {
                return 'no change';
            }
            $paymentId = (string) ($payment['id'] ?? '');
            if ($order->has_status('on-hold') && (string) $order->get_meta(Gateway::META_AWAITING) === $paymentId) {
                $order->delete_meta_data(Gateway::META_AWAITING);
                $order->save();
                $order->update_status('failed', sprintf(
                    /* translators: 1: Mercado Pago payment id, 2: status detail */
                    __('Mercado Pago payment %1$s was not completed (%2$s).', 'stackless-lite'),
                    $paymentId,
                    sanitize_text_field((string) ($payment['status_detail'] ?? ($payment['status'] ?? '')))
                ));
                return 'failed';
            }
            return 'rejected';
        }, 'busy');
    }

    /* ---- Refunds and chargebacks ----------------------------------------------------- */

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

    /** Mirror approved refunds on a paid order that WooCommerce does not know about yet, once each. */
    public function reconcileRefunds(\WC_Order $order, array $payment): string
    {
        $refunds = array_values(array_filter((array) ($payment['refunds'] ?? []), 'is_array'));
        if (!$refunds) {
            return '';
        }
        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refunds, $payment) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order || (!$order->is_paid() && !$order->has_status('refunded'))) {
                return '';
            }
            if ((string) $order->get_meta(Gateway::META_PAYMENT_ID) !== (string) ($payment['id'] ?? '')) {
                return '';
            }
            $currency = strtoupper($order->get_currency());
            $recorded = 0;
            foreach ($refunds as $refund) {
                $refundId = (string) ($refund['id'] ?? '');
                if ($refundId === '' || self::hasRefundId($order, $refundId) || !in_array((string) ($refund['status'] ?? 'approved'), ['approved', 'authorized'], true)) {
                    continue;
                }
                $amount = min(Money::amount((float) ($refund['amount'] ?? 0), $currency), (float) $order->get_remaining_refund_amount());
                self::recordRefundId($order, $refundId);
                if ($amount <= 0) {
                    continue;
                }
                $created = wc_create_refund([
                    'order_id' => $order->get_id(),
                    'amount' => wc_format_decimal($amount, Money::decimals($currency)),
                    /* translators: %s: Mercado Pago refund id */
                    'reason' => sprintf(__('Refunded in Mercado Pago (%s)', 'stackless-lite'), $refundId),
                    'refund_payment' => false,
                    'restock_items' => false,
                ]);
                if (is_wp_error($created)) {
                    throw new \RuntimeException(esc_html($created->get_error_message()));
                }
                $recorded++;
            }
            return $recorded ? 'refunded ' . $recorded : '';
        }, 'busy');
    }

    private function noteChargeback(\WC_Order $order, array $payment): string
    {
        $paymentId = (string) ($payment['id'] ?? '');
        if ((string) $order->get_meta(self::META_CHARGEBACK) === $paymentId) {
            return '';
        }
        $order->update_meta_data(self::META_CHARGEBACK, $paymentId);
        $order->save();
        /* translators: %s: Mercado Pago payment id */
        $order->add_order_note(sprintf(__('Mercado Pago reports a chargeback on payment %s. Review it in your Mercado Pago account.', 'stackless-lite'), $paymentId));
        return 'chargeback noted';
    }
}
