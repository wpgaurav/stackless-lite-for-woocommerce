<?php

namespace Stackless\Modules\MollieGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies a Mollie payment, as read from the Mollie API, to its WooCommerce order: payment,
 * saved mandate, verification refund, failures (with decline details on renewals), refunds
 * made in the Mollie dashboard and chargebacks. The browser return, the webhook and renewals
 * all come through here, under one lock per order, so an order is settled once.
 */
final class Sync
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public static function site(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** @return array<string,string> Metadata sent with every payment for an order. */
    public static function metadata(\WC_Order $order, string $flow): array
    {
        return [
            'wc_order_id' => (string) $order->get_id(),
            'wc_site' => self::site(),
            'wc_gateway' => Gateway::ID,
            'flow' => $flow,
        ];
    }

    public static function rememberRefund(\WC_Order $order, string $refundId): void
    {
        $ids = $order->get_meta(Gateway::META_REFUND_IDS);
        $ids = is_array($ids) ? $ids : [];
        if (!in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data(Gateway::META_REFUND_IDS, array_values($ids));
            $order->save();
        }
    }

    /**
     * Read the order's payment from Mollie and apply it (browser return).
     *
     * @return string paid|pending|open|failed|canceled|expired|mismatch|busy|none
     */
    public function refreshOrder(\WC_Order $order): string
    {
        $paymentId = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
        if ($paymentId === '') {
            return 'none';
        }
        $mode = $this->gateway->orderMode($order);
        $payment = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
        if (is_wp_error($payment)) {
            $this->gateway->log('Could not read payment ' . $paymentId . ': ' . $payment->get_error_message(), 'warning');
            return 'open';
        }
        return $this->applyToOrder($order, $payment, $mode);
    }

    /**
     * A webhook: the payment as Mollie returned it. Finds the order (or the My Account flow)
     * it belongs to and applies it.
     */
    public function applyPayment(array $payment, string $mode): string
    {
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        if (($metadata['wc_gateway'] ?? Gateway::ID) !== Gateway::ID || (($metadata['wc_site'] ?? '') !== '' && $metadata['wc_site'] !== self::site())) {
            return 'ignored: another site or gateway';
        }
        if (($metadata['flow'] ?? '') === 'add') {
            $token = $this->applyAddPayment($payment, $mode);
            return $token ? 'method saved (token ' . $token->get_id() . ')' : 'add flow: ' . (string) ($payment['status'] ?? '');
        }
        $order = $this->findOrder($payment);
        if (!$order) {
            return 'ignored: no matching order';
        }
        return $this->applyToOrder($order, $payment, $mode);
    }

    private function findOrder(array $payment): ?\WC_Order
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $orderId = absint($payment['metadata']['wc_order_id'] ?? 0);
        $order = $orderId ? wc_get_order($orderId) : null;
        if ($order instanceof \WC_Order && $order->get_payment_method() === Gateway::ID) {
            return $order;
        }
        if ($paymentId === '') {
            return null;
        }
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Gateway::META_PAYMENT_ID, 'meta_value' => $paymentId, 'type' => 'shop_order']); // phpcs:ignore WordPress.DB.SlowDBQuery
        return $orders && $orders[0] instanceof \WC_Order ? $orders[0] : null;
    }

    /** Apply a payment to an order, under the order lock. */
    public function applyToOrder(\WC_Order $order, array $payment, string $mode): string
    {
        $paymentId = (string) ($payment['id'] ?? '');
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment, $paymentId, $mode) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            // Only the payment this order is waiting for may change it (an older, replaced one may not).
            $current = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
            if ($current !== '' && $current !== $paymentId) {
                return 'ignored: superseded payment';
            }
            $status = (string) ($payment['status'] ?? '');
            switch ($status) {
                case 'paid':
                    $outcome = $this->paid($order, $payment, $mode);
                    $this->syncRefunds($order, $payment, $mode);
                    return $outcome;
                case 'authorized':
                    if ($order->needs_payment()) {
                        $order->set_transaction_id($paymentId);
                        /* translators: %s: Mollie payment id */
                        $order->update_status('on-hold', sprintf(__('Mollie authorized payment %s. Capture it in Mollie to collect the money.', 'stackless-lite'), $paymentId));
                    }
                    return 'pending';
                case 'pending':
                    if ($order->needs_payment() && !self::isOffSession($order, $payment)) {
                        $order->set_transaction_id($paymentId);
                        wc_maybe_reduce_stock_levels($order->get_id());
                        /* translators: %s: Mollie payment id */
                        $order->update_status('on-hold', sprintf(__('Mollie is processing payment %s. The order updates when Mollie confirms it.', 'stackless-lite'), $paymentId));
                    }
                    return 'pending';
                case 'failed':
                case 'canceled':
                case 'expired':
                    return $this->unpaid($order, $payment, $status);
                case 'open':
                default:
                    return 'open';
            }
        }, 'busy');
    }

    /** Whether a payment was charged without the buyer present (renewal or saved-method charge). */
    private static function isOffSession(\WC_Order $order, array $payment): bool
    {
        return (string) ($payment['sequenceType'] ?? '') === 'recurring' || stackless_is_renewal_order($order);
    }

    private function paid(\WC_Order $order, array $payment, string $mode): string
    {
        $paymentId = (string) $payment['id'];
        // Already applied (a replay, or the order was put on hold later, e.g. for a chargeback).
        if ($order->is_paid() || ($order->get_date_paid() && $order->get_transaction_id() === $paymentId)) {
            return 'paid';
        }
        $verification = $order->get_meta(Gateway::META_VERIFICATION) === 'yes';
        $amountOk = $verification
            ? Money::fromAmount($payment['amount'] ?? null) <= (float) Money::smallest($order->get_currency()) + 0.0001
            : Money::matches($payment['amount'] ?? null, $order->get_total(), $order->get_currency());
        if (!$amountOk) {
            if (!$order->has_status('on-hold')) {
                /* translators: 1: Mollie payment id, 2: amount */
                $order->update_status('on-hold', sprintf(__('Mollie payment %1$s was for %2$s, which does not match the order total. Check the payment before shipping.', 'stackless-lite'), $paymentId, wp_strip_all_tags(wc_price(Money::fromAmount($payment['amount'] ?? null), ['currency' => (string) ($payment['amount']['currency'] ?? '')]))));
            }
            return 'mismatch';
        }

        if ((string) ($payment['sequenceType'] ?? '') === 'first') {
            $token = $this->saveMandate($payment, (int) $order->get_customer_id(), $mode);
            if ($token) {
                $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
                /* translators: %s: saved method, e.g. "Visa ending in 4242" */
                $order->add_order_note(sprintf(__('Saved %s for future payments.', 'stackless-lite'), wp_strip_all_tags($token->get_display_name())));
            } else {
                $order->add_order_note(__('Mollie did not return a usable mandate for this payment, so renewals will be invoiced.', 'stackless-lite'));
            }
        }
        $order->update_meta_data(Gateway::META_METHOD, (string) ($payment['method'] ?? ''));
        $order->save();
        if ($verification && Money::fromAmount($payment['amount'] ?? null) > 0) {
            $this->refundVerification($order, $payment, $mode);
        }
        $order->payment_complete($paymentId);
        return 'paid';
    }

    /** Refund the one-cent payment that only verified a bank method. */
    private function refundVerification(\WC_Order $order, array $payment, string $mode): void
    {
        $refund = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/refunds', [
            'amount' => $payment['amount'],
            'description' => __('Verification refund', 'stackless-lite'),
            'metadata' => ['wc_order_id' => (string) $order->get_id(), 'verification' => '1'],
        ], 'stackless-verify-refund-' . (string) $payment['id']);
        if (is_wp_error($refund)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('The verification payment could not be refunded automatically: %s Refund it in Mollie.', 'stackless-lite'), $refund->get_error_message()));
            return;
        }
        self::rememberRefund($order, (string) ($refund['id'] ?? ''));
        $order->add_order_note(__('The verification payment was refunded through Mollie.', 'stackless-lite'));
    }

    private function unpaid(\WC_Order $order, array $payment, string $status): string
    {
        if (!$order->needs_payment() && !$order->has_status('on-hold')) {
            return 'ignored: order already settled';
        }
        $paymentId = (string) $payment['id'];
        $decline = Declines::fromPayment($payment);
        $offSession = self::isOffSession($order, $payment);
        if ($offSession || $status === 'failed' || $order->has_status('on-hold')) {
            Declines::record($order, $decline);
            $note = sprintf(
                /* translators: 1: Mollie payment id, 2: status, 3: reason code */
                __('Mollie payment %1$s %2$s (%3$s).', 'stackless-lite'),
                $paymentId,
                $status,
                $decline['code']
            );
            if (!$order->has_status('failed')) {
                $order->update_status('failed', $note);
            } else {
                $order->add_order_note($note);
            }
            return 'failed';
        }
        // The buyer left the hosted page or let it expire: the order stays payable.
        /* translators: 1: Mollie payment id, 2: status */
        $order->add_order_note(sprintf(__('Mollie payment %1$s was %2$s. The customer can try again from the order’s payment page.', 'stackless-lite'), $paymentId, $status));
        return $status;
    }

    /**
     * Save the mandate a first payment created, for the order's customer (0 = guest).
     */
    private function saveMandate(array $payment, int $userId, string $mode): ?\WC_Payment_Token
    {
        $customerId = (string) ($payment['customerId'] ?? '');
        $mandateId = (string) ($payment['mandateId'] ?? '');
        if ($customerId === '' || $mandateId === '') {
            return null;
        }
        $mandate = $this->gateway->api($mode)->request('GET', 'customers/' . rawurlencode($customerId) . '/mandates/' . rawurlencode($mandateId));
        if (is_wp_error($mandate) || ($mandate['status'] ?? '') === 'invalid') {
            return null;
        }
        return Tokens::saveFromMandate($mandate, $userId, $customerId, $mode);
    }

    /**
     * My Account → Add payment method: save the mandate once the first payment is paid,
     * and refund the verification amount.
     */
    public function applyAddPayment(array $payment, string $mode): ?\WC_Payment_Token
    {
        $userId = absint($payment['metadata']['wp_user_id'] ?? 0);
        if ($userId <= 0 || ($payment['status'] ?? '') !== 'paid' || (string) ($payment['sequenceType'] ?? '') !== 'first') {
            return null;
        }
        $paymentId = (string) $payment['id'];
        return Lock::run('add-' . $paymentId, function () use ($payment, $userId, $mode, $paymentId) {
            $token = $this->saveMandate($payment, $userId, $mode);
            if ($token && Money::fromAmount($payment['amount'] ?? null) > 0 && (float) ($payment['amountRefunded']['value'] ?? 0) <= 0) {
                $refund = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode($paymentId) . '/refunds', [
                    'amount' => $payment['amount'],
                    'description' => __('Verification refund', 'stackless-lite'),
                ], 'stackless-verify-refund-' . $paymentId);
                if (is_wp_error($refund)) {
                    $this->gateway->log('Verification refund for ' . $paymentId . ' failed: ' . $refund->get_error_message(), 'error');
                }
            }
            return $token;
        });
    }

    /**
     * Bring refunds and chargebacks made in Mollie into WooCommerce: each Mollie refund this
     * store did not start becomes a WooCommerce refund (without calling Mollie again).
     */
    private function syncRefunds(\WC_Order $order, array $payment, string $mode): void
    {
        $refunded = Money::fromAmount($payment['amountRefunded'] ?? null);
        if ($refunded > 0 && $order->get_meta(Gateway::META_VERIFICATION) !== 'yes') {
            $list = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode((string) $payment['id']) . '/refunds', ['limit' => 50]);
            if (is_array($list)) {
                $known = $order->get_meta(Gateway::META_REFUND_IDS);
                $known = is_array($known) ? $known : [];
                foreach ((array) ($list['_embedded']['refunds'] ?? []) as $refund) {
                    $id = (string) ($refund['id'] ?? '');
                    $state = (string) ($refund['status'] ?? '');
                    if ($id === '' || in_array($id, $known, true)) {
                        if ($id !== '' && $state === 'failed' && !in_array($id . ':failed', $known, true)) {
                            /* translators: %s: Mollie refund id */
                            $order->add_order_note(sprintf(__('Mollie could not complete refund %s. Check it in the Mollie dashboard.', 'stackless-lite'), $id));
                            self::rememberRefund($order, $id . ':failed');
                        }
                        continue;
                    }
                    if (in_array($state, ['failed', 'canceled'], true)) {
                        continue;
                    }
                    $amount = min(Money::fromAmount($refund['amount'] ?? null), (float) $order->get_remaining_refund_amount());
                    if ($amount <= 0) {
                        self::rememberRefund($order, $id);
                        continue;
                    }
                    $created = wc_create_refund([
                        'order_id' => $order->get_id(),
                        'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                        /* translators: %s: Mollie refund id */
                        'reason' => sprintf(__('Refunded in Mollie (%s)', 'stackless-lite'), $id),
                        'refund_payment' => false,
                        'restock_items' => false,
                    ]);
                    if (!is_wp_error($created)) {
                        self::rememberRefund($order, $id);
                    }
                }
            }
        }

        $chargedBack = Money::fromAmount($payment['amountChargedBack'] ?? null);
        if ($chargedBack > 0 && (string) $order->get_meta(Gateway::META_CHARGEBACK) !== Money::value($chargedBack, $order->get_currency())) {
            $order->update_meta_data(Gateway::META_CHARGEBACK, Money::value($chargedBack, $order->get_currency()));
            $order->save();
            /* translators: %s: amount */
            $order->update_status('on-hold', sprintf(__('Mollie reports a chargeback of %s on this payment.', 'stackless-lite'), wp_strip_all_tags(wc_price($chargedBack, ['currency' => $order->get_currency()]))));
        }
    }
}
