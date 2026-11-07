<?php

namespace Stackless\Modules\RazorpayGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Applies Razorpay payments, tokens and refunds to WooCommerce, from the signed browser
 * return, the signed webhook and renewals alike, under one lock per order. Every payment is
 * matched to the Razorpay order this store created for it, and its amount and currency are
 * checked before an order is marked paid.
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

    /** @return array<string,string> Notes sent with every Razorpay order for a WooCommerce order. */
    public static function notes(\WC_Order $order, string $flow): array
    {
        return [
            'wc_order_id' => (string) $order->get_id(),
            'wc_site' => self::site(),
            'wc_gateway' => Gateway::ID,
            'flow' => $flow,
        ];
    }

    /** @param array<string,string|int> $args */
    public static function returnUrl(array $args): string
    {
        return add_query_arg($args, WC()->api_request_url(Checkout::RETURN_ACTION));
    }

    public static function rememberRefund(\WC_Order $order, string $refundId): void
    {
        $ids = $order->get_meta(Gateway::META_REFUND_IDS);
        $ids = is_array($ids) ? $ids : [];
        if ($refundId !== '' && !in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data(Gateway::META_REFUND_IDS, array_values($ids));
            $order->save();
        }
    }

    /* ---- Browser return --------------------------------------------------------------- */

    /** woocommerce_api_stackless_razorpay_return: checkout.js posts the signed result here. */
    public static function handleReturn(): void
    {
        $gateway = Gateway::instance();
        // phpcs:disable WordPress.Security.NonceVerification -- Razorpay's HMAC signature, keyed with the store's key secret, authenticates the post.
        $paymentId = isset($_POST['razorpay_payment_id']) ? sanitize_text_field(wp_unslash((string) $_POST['razorpay_payment_id'])) : '';
        $rzpOrder = isset($_POST['razorpay_order_id']) ? sanitize_text_field(wp_unslash((string) $_POST['razorpay_order_id'])) : '';
        $signature = isset($_POST['razorpay_signature']) ? sanitize_text_field(wp_unslash((string) $_POST['razorpay_signature'])) : '';
        $flow = isset($_GET['flow']) ? sanitize_key(wp_unslash((string) $_GET['flow'])) : '';
        if ($flow === 'add') {
            $ref = isset($_GET['ref']) ? sanitize_text_field(wp_unslash((string) $_GET['ref'])) : '';
            self::finishAdd($gateway, get_current_user_id(), $ref, $paymentId, $rzpOrder, $signature);
            return;
        }
        $orderId = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        // phpcs:enable
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || $order->get_payment_method() !== Gateway::ID) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $mode = $gateway->orderMode($order);
        $expected = (string) $order->get_meta(Gateway::META_ORDER_ID);
        $outcome = 'invalid';
        // The Razorpay order id is ours, from the order; the browser only supplies the payment.
        if ($expected !== '' && hash_equals($expected, $rzpOrder) && Signature::payment($expected, $paymentId, $signature, $gateway->keySecret($mode))) {
            $payment = $gateway->api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
            $outcome = is_array($payment) ? (new self($gateway))->applyPayment($order, $payment, $mode) : 'unreadable';
        } else {
            $gateway->log('Rejected a Razorpay return for order ' . $order->get_id() . ' with a missing or invalid signature.', 'warning');
        }
        $order = wc_get_order($order->get_id());
        if ($order instanceof \WC_Order && ($order->is_paid() || $order->has_status('on-hold') || in_array($outcome, ['paid', 'awaiting_mandate', 'busy'], true))) {
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            wp_safe_redirect($gateway->get_return_url($order));
            exit;
        }
        wc_add_notice(__('The payment could not be confirmed. Please try again or choose another payment method.', 'stackless-lite'), 'error');
        wp_safe_redirect($order instanceof \WC_Order ? $order->get_checkout_payment_url() : wc_get_checkout_url());
        exit;
    }

    private static function finishAdd(Gateway $gateway, int $userId, string $ref, string $paymentId, string $rzpOrder, string $signature): void
    {
        $target = wc_get_account_endpoint_url('payment-methods');
        $pending = $userId > 0 ? get_user_meta($userId, Gateway::USER_META_PENDING_ADD, true) : null;
        if (!is_array($pending) || $ref === '' || !hash_equals((string) ($pending['ref'] ?? ''), $ref)) {
            wp_safe_redirect($target);
            exit;
        }
        $mode = (string) $pending['mode'];
        $token = null;
        if (hash_equals((string) $pending['order'], $rzpOrder) && Signature::payment($rzpOrder, $paymentId, $signature, $gateway->keySecret($mode))) {
            $payment = $gateway->api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
            if (is_array($payment)) {
                $token = (new self($gateway))->applyAddPayment($payment, $mode, $userId);
            }
        }
        delete_user_meta($userId, Gateway::USER_META_PENDING_ADD);
        if ($token instanceof \WC_Payment_Token) {
            wc_add_notice(Tokens::status($token) === 'confirmed'
                ? __('Payment method successfully added.', 'stackless-lite')
                : __('Payment method added. It can be used once your bank confirms the mandate.', 'stackless-lite'));
        } else {
            wc_add_notice(__('Unable to add payment method to your account.', 'stackless-lite'), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }

    /* ---- Payments --------------------------------------------------------------------- */

    /**
     * Apply a Razorpay payment entity to an order (return, webhook or renewal).
     *
     * @return string paid|awaiting_mandate|pending|failed|mismatch|ignored…|busy
     */
    public function applyPayment(\WC_Order $order, array $payment, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment, $mode) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            $paymentId = (string) ($payment['id'] ?? '');
            $rzpOrder = (string) ($payment['order_id'] ?? '');
            if ($rzpOrder === '' || $rzpOrder !== (string) $order->get_meta(Gateway::META_ORDER_ID)) {
                return 'ignored: payment for another Razorpay order';
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'authorized' && (int) ($payment['amount'] ?? -1) === 0) {
                $status = 'captured'; // A ₹0 eNACH authorization has nothing to capture.
            } elseif ($status === 'authorized') {
                $payment = $this->capture($order, $payment, $mode);
                $status = (string) ($payment['status'] ?? '');
            }
            if ($status === 'captured') {
                $outcome = $this->paid($order, $payment, $mode);
                $this->syncRefunds($order, $payment, $mode);
                return $outcome;
            }
            if ($status === 'failed') {
                return $this->failed($order, $payment);
            }
            return 'pending';
        }, 'busy');
    }

    /** Capture an authorized payment for the amount this store asked for. */
    private function capture(\WC_Order $order, array $payment, string $mode): array
    {
        $expected = (int) $order->get_meta(Gateway::META_AMOUNT);
        if ((int) ($payment['amount'] ?? -1) !== $expected || strtoupper((string) ($payment['currency'] ?? '')) !== strtoupper($order->get_currency())) {
            return $payment;
        }
        $captured = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/capture', [
            'amount' => $expected,
            'currency' => strtoupper($order->get_currency()),
        ]);
        if (is_wp_error($captured)) {
            $this->gateway->log('Capture of ' . (string) $payment['id'] . ' failed: ' . $captured->get_error_message(), 'warning');
            return $payment;
        }
        return $captured;
    }

    private function paid(\WC_Order $order, array $payment, string $mode): string
    {
        $paymentId = (string) $payment['id'];
        if ($order->is_paid() || ($order->get_date_paid() && $order->get_transaction_id() === $paymentId)) {
            return 'paid';
        }
        $expected = (int) $order->get_meta(Gateway::META_AMOUNT);
        if ((int) ($payment['amount'] ?? -1) !== $expected || strtoupper((string) ($payment['currency'] ?? '')) !== strtoupper($order->get_currency())) {
            if (!$order->has_status('on-hold')) {
                /* translators: %s: Razorpay payment id */
                $order->update_status('on-hold', sprintf(__('Razorpay payment %s does not match the amount this order asked for. Check it before shipping.', 'stackless-lite'), $paymentId));
            }
            return 'mismatch';
        }

        $flow = (string) $order->get_meta(Gateway::META_FLOW);
        if ($flow === 'auth') {
            $token = $this->saveToken($payment, (int) $order->get_customer_id(), $mode);
            if (!$token) {
                $order->add_order_note(__('Razorpay did not return a recurring token for this payment, so renewals will be invoiced.', 'stackless-lite'));
            } else {
                $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
                $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, Tokens::customer($token));
                /* translators: %s: saved method */
                $order->add_order_note(sprintf(__('Saved %s for renewals.', 'stackless-lite'), wp_strip_all_tags($token->get_display_name())));
                $orderMinor = Money::toMinor($order->get_total(), $order->get_currency());
                if (Mandate::needsFirstDebit(Tokens::method($token), $orderMinor)) {
                    // eNACH authorizes ₹0: the order's own amount is a separate debit.
                    $order->update_meta_data(Gateway::META_FIRST_DEBIT, $token->get_token());
                    $order->save();
                    wc_maybe_reduce_stock_levels($order->get_id());
                    $order->update_status('on-hold', __('eNACH mandate registered. The first payment is debited once the bank confirms the mandate, usually within two working days.', 'stackless-lite'));
                    if (Tokens::status($token) === 'confirmed') {
                        $this->firstDebit($order, $token);
                    }
                    return 'awaiting_mandate';
                }
            }
        }
        $order->update_meta_data(Gateway::META_PAYMENT_ID, $paymentId);
        $order->update_meta_data(Gateway::META_METHOD, (string) ($payment['method'] ?? ''));
        $order->save();
        if ($order->get_meta(Gateway::META_VERIFICATION) === 'yes') {
            $this->refundVerification($order, $payment, $mode);
        }
        $order->payment_complete($paymentId);
        return 'paid';
    }

    private function failed(\WC_Order $order, array $payment): string
    {
        if (!$order->needs_payment() && !$order->has_status('on-hold')) {
            return 'ignored: order already settled';
        }
        $decline = Declines::fromPayment($payment);
        $offSession = in_array((string) $order->get_meta(Gateway::META_FLOW), Renewals::FLOWS, true);
        if ($offSession || $order->has_status('on-hold')) {
            Declines::record($order, $decline);
            /* translators: 1: Razorpay payment id, 2: reason */
            $note = sprintf(__('Razorpay debit %1$s failed (%2$s).', 'stackless-lite'), (string) $payment['id'], $decline['code']);
            if (!$order->has_status('failed')) {
                $order->update_status('failed', $note);
            } else {
                $order->add_order_note($note);
            }
            return 'failed';
        }
        // A checkout attempt failed in Razorpay Checkout; the buyer can try again on the same order.
        /* translators: 1: Razorpay payment id, 2: reason */
        $order->add_order_note(sprintf(__('Razorpay payment attempt %1$s failed (%2$s).', 'stackless-lite'), (string) $payment['id'], $decline['code']));
        return 'failed';
    }

    /** Refund the ₹1 authorization of a free (all-trial) order. */
    private function refundVerification(\WC_Order $order, array $payment, string $mode): void
    {
        $refund = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/refund', [
            'amount' => (int) $payment['amount'],
            'notes' => ['wc_order_id' => (string) $order->get_id(), 'verification' => '1'],
        ], ['X-Refund-Idempotency' => 'stackless-verify-' . (string) $payment['id']]);
        if (is_wp_error($refund)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('The ₹1 authorization could not be refunded automatically: %s Refund it in Razorpay.', 'stackless-lite'), $refund->get_error_message()));
            return;
        }
        self::rememberRefund($order, (string) ($refund['id'] ?? ''));
        $order->add_order_note(__('The ₹1 authorization was refunded through Razorpay.', 'stackless-lite'));
    }

    /** Save the token an authorization payment created. */
    private function saveToken(array $payment, int $userId, string $mode): ?\WC_Payment_Token
    {
        $tokenId = (string) ($payment['token_id'] ?? '');
        $customerId = (string) ($payment['customer_id'] ?? '');
        if ($tokenId === '' || $customerId === '') {
            return null;
        }
        $entity = $this->gateway->api($mode)->request('GET', 'customers/' . rawurlencode($customerId) . '/tokens/' . rawurlencode($tokenId));
        if (is_wp_error($entity)) {
            $this->gateway->log('Could not read token ' . $tokenId . ': ' . $entity->get_error_message(), 'warning');
            return null;
        }
        if (in_array(Tokens::statusOf($entity), ['rejected', 'cancelled'], true)) {
            return null;
        }
        return Tokens::saveFromEntity($entity, $userId, $customerId, $mode, $payment);
    }

    /** My Account → Add payment method: save the token and refund the ₹1 authorization. */
    public function applyAddPayment(array $payment, string $mode, int $userId): ?\WC_Payment_Token
    {
        if ($userId <= 0 || !in_array($payment['status'] ?? '', ['captured', 'authorized'], true)) {
            return null;
        }
        $token = $this->saveToken($payment, $userId, $mode);
        if ($token && (int) ($payment['amount'] ?? 0) > 0 && (int) ($payment['amount_refunded'] ?? 0) === 0) {
            $refund = $this->gateway->api($mode)->request('POST', 'payments/' . rawurlencode((string) $payment['id']) . '/refund', [
                'amount' => (int) $payment['amount'],
            ], ['X-Refund-Idempotency' => 'stackless-verify-' . (string) $payment['id']]);
            if (is_wp_error($refund)) {
                $this->gateway->log('Verification refund for ' . (string) $payment['id'] . ' failed: ' . $refund->get_error_message(), 'error');
            }
        }
        return $token;
    }

    /** eNACH: debit an order's first payment once its mandate is confirmed. */
    public function firstDebit(\WC_Order $order, \WC_Payment_Token $token): void
    {
        if ((string) $order->get_meta(Gateway::META_FIRST_DEBIT) !== $token->get_token() || $order->is_paid()) {
            return;
        }
        $result = (new Renewals($this->gateway))->chargeToken($order, $token, 'first');
        if ($result['result'] === 'failed') {
            $this->gateway->log('First eNACH debit for order ' . $order->get_id() . ' failed: ' . ($result['message'] ?? ''), 'warning');
        }
    }

    /* ---- Webhook entities ------------------------------------------------------------ */

    /** A payment.* or order.paid webhook. */
    public function webhookPayment(array $payment, string $mode): string
    {
        $notes = is_array($payment['notes'] ?? null) ? $payment['notes'] : [];
        if (($notes['wc_site'] ?? '') !== '' && $notes['wc_site'] !== self::site()) {
            return 'ignored: another site';
        }
        $order = $this->findOrder($payment);
        if (!$order) {
            return 'ignored: no matching order';
        }
        return $this->applyPayment($order, $payment, $mode);
    }

    private function findOrder(array $payment): ?\WC_Order
    {
        $orderId = absint($payment['notes']['wc_order_id'] ?? 0);
        $order = $orderId ? wc_get_order($orderId) : null;
        if ($order instanceof \WC_Order && $order->get_payment_method() === Gateway::ID) {
            return $order;
        }
        $rzpOrder = (string) ($payment['order_id'] ?? '');
        if ($rzpOrder === '') {
            return null;
        }
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Gateway::META_ORDER_ID, 'meta_value' => $rzpOrder, 'type' => 'shop_order']); // phpcs:ignore WordPress.DB.SlowDBQuery
        return $orders && $orders[0] instanceof \WC_Order ? $orders[0] : null;
    }

    /** A refund.* webhook: refunds made in the Razorpay dashboard, and failures of ours. */
    public function webhookRefund(string $event, array $refund, array $payment, string $mode): string
    {
        $paymentId = (string) ($refund['payment_id'] ?? ($payment['id'] ?? ''));
        if ($paymentId === '') {
            return 'ignored: no payment';
        }
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Gateway::META_PAYMENT_ID, 'meta_value' => $paymentId, 'type' => 'shop_order']); // phpcs:ignore WordPress.DB.SlowDBQuery
        $order = $orders && $orders[0] instanceof \WC_Order ? $orders[0] : null;
        if (!$order) {
            return 'ignored: no matching order';
        }
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $event, $refund) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            $refundId = (string) ($refund['id'] ?? '');
            $known = $order->get_meta(Gateway::META_REFUND_IDS);
            $known = is_array($known) ? $known : [];
            if ($event === 'refund.failed') {
                if (in_array($refundId, $known, true) && !in_array($refundId . ':failed', $known, true)) {
                    /* translators: %s: Razorpay refund id */
                    $order->add_order_note(sprintf(__('Razorpay could not complete refund %s. Check it in the Razorpay dashboard.', 'stackless-lite'), $refundId));
                    self::rememberRefund($order, $refundId . ':failed');
                }
                return 'refund failed noted';
            }
            if ($refundId === '' || in_array($refundId, $known, true) || $order->get_meta(Gateway::META_VERIFICATION) === 'yes') {
                return 'refund already recorded';
            }
            $amount = min(Money::fromMinor((int) ($refund['amount'] ?? 0), $order->get_currency()), (float) $order->get_remaining_refund_amount());
            self::rememberRefund($order, $refundId);
            if ($amount <= 0) {
                return 'nothing left to refund';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                /* translators: %s: Razorpay refund id */
                'reason' => sprintf(__('Refunded in Razorpay (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            return is_wp_error($created) ? 'refund not recorded: ' . $created->get_error_message() : 'refund recorded';
        }, 'busy');
    }

    /** Refunds already on a captured payment that this store has not recorded (dashboard refunds). */
    private function syncRefunds(\WC_Order $order, array $payment, string $mode): void
    {
        if ((int) ($payment['amount_refunded'] ?? 0) <= 0 || $order->get_meta(Gateway::META_VERIFICATION) === 'yes') {
            return;
        }
        $list = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode((string) $payment['id']) . '/refunds', ['count' => 50]);
        if (!is_array($list)) {
            return;
        }
        foreach ((array) ($list['items'] ?? []) as $refund) {
            if (is_array($refund) && ($refund['status'] ?? '') !== 'failed') {
                $this->webhookRefund('refund.processed', $refund, $payment, $mode);
            }
        }
    }

    /** A token.* webhook: mandate confirmed, rejected, cancelled or paused. */
    public function webhookToken(string $event, array $entity, string $mode): string
    {
        $tokenId = (string) ($entity['id'] ?? '');
        if ($tokenId === '') {
            return 'ignored: no token';
        }
        $status = substr($event, strlen('token.'));
        $updated = Tokens::updateStatus($tokenId, $status);
        $waiting = wc_get_orders(['limit' => 20, 'meta_key' => Gateway::META_FIRST_DEBIT, 'meta_value' => $tokenId, 'type' => 'shop_order', 'status' => ['on-hold', 'pending']]); // phpcs:ignore WordPress.DB.SlowDBQuery
        $tokens = Tokens::find($tokenId);
        if ($status === 'confirmed') {
            foreach ($waiting as $order) {
                if ($order instanceof \WC_Order && $tokens) {
                    $this->firstDebit($order, $tokens[0]);
                }
            }
            return 'token confirmed (' . $updated . ' saved, ' . count($waiting) . ' first debits)';
        }
        if ($status === 'rejected' || $status === 'cancelled') {
            foreach ($waiting as $order) {
                if ($order instanceof \WC_Order && ($order->needs_payment() || $order->has_status('on-hold'))) {
                    Declines::record($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'mandate_' . $status]);
                    /* translators: %s: rejected or cancelled */
                    $order->update_status('failed', sprintf(__('The bank %s the eNACH mandate, so the first payment was not debited.', 'stackless-lite'), $status === 'rejected' ? __('rejected', 'stackless-lite') : __('cancelled', 'stackless-lite')));
                }
            }
            $forgotten = Tokens::forget($tokenId);
            return 'token ' . $status . ' (' . $forgotten . ' removed)';
        }
        return 'token ' . $status . ' (' . $updated . ' updated)';
    }
}
