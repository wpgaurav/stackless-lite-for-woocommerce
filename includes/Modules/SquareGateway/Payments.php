<?php

namespace Stackless\Modules\SquareGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Square payments: a new card or wallet token at checkout (saved on file when asked), a saved
 * card at checkout, renewals and saved-card charges without the buyer (merchant-initiated), and
 * webhook updates. Square answers card payments at once, so outcomes are success or failed,
 * except a transient error on a renewal, which is retried with the same idempotency key.
 */
final class Payments
{
    public const RETRY_HOOK = 'stackless_square_gateway_retry_charge';
    public const GROUP = 'stackless-square_gateway';
    public const META_ATTEMPT = '_stackless_square_attempt';
    public const META_TRANSIENT = '_stackless_square_transient_retries';

    private const MAX_TRANSIENT_RETRIES = 3;

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
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

    /**
     * Pay an order with a token from the card form or a wallet; save the card on file when asked.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeNew(\WC_Order $order, string $source, bool $save): array
    {
        $mode = $this->gateway->mode();
        $currency = strtoupper($order->get_currency());
        if ($this->gateway->currency($mode) !== '' && $currency !== $this->gateway->currency($mode)) {
            /* translators: %s: currency code */
            return ['result' => 'failed', 'message' => sprintf(__('Square takes payments in %s only.', 'stackless-lite'), $this->gateway->currency($mode))];
        }
        $userId = (int) $order->get_customer_id();
        $customer = '';
        if ($save) {
            $customer = $this->gateway->customerFor($userId, $mode, $order);
            if (is_wp_error($customer)) {
                return ['result' => 'failed', 'message' => $customer->get_error_message()];
            }
        }
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->save();

        if ((float) $order->get_total() <= 0) {
            if (!$save) {
                $order->payment_complete();
                return ['result' => 'success'];
            }
            $token = $this->createCard($source, $customer, $userId, $mode, $order);
            if (is_wp_error($token)) {
                $order->add_order_note(sprintf(
                    /* translators: %s: error */
                    __('Square could not save the card: %s', 'stackless-lite'),
                    $token->get_error_message()
                ));
                return ['result' => 'failed', 'message' => Declines::message(Api::code($token))];
            }
            Gateway::attachToken($order, $token);
            /* translators: %s: card, e.g. "Visa ending in 4242" */
            $order->add_order_note(sprintf(__('Saved %s with Square for future payments.', 'stackless-lite'), wp_strip_all_tags($token->get_display_name())));
            $order->payment_complete();
            return ['result' => 'success'];
        }

        $payment = $this->createPayment($order, $mode, [
            'source_id' => $source,
            'customer_id' => $customer !== '' ? $customer : null,
        ], Api::key('o' . $order->get_id(), substr(hash('sha256', $source), 0, 16)));
        if (is_wp_error($payment)) {
            return $this->checkoutError($order, $payment);
        }
        // Save the card before the order is marked paid: Subscriptions copies the token from the
        // parent order when payment completes (Contracts\SubscriptionGateway).
        if ($save && in_array($payment['status'] ?? '', ['COMPLETED', 'APPROVED'], true)) {
            $token = $this->createCard((string) $payment['id'], $customer, $userId, $mode, $order);
            if ($token instanceof \WC_Payment_Token) {
                Gateway::attachToken($order, $token);
                /* translators: %s: card, e.g. "Visa ending in 4242" */
                $order->add_order_note(sprintf(__('Saved %s with Square for future payments.', 'stackless-lite'), wp_strip_all_tags($token->get_display_name())));
            } else {
                /* translators: %s: error */
                $order->add_order_note(sprintf(__('The payment went through, but Square could not save the card for renewals: %s Renewals will be invoiced.', 'stackless-lite'), is_wp_error($token) ? $token->get_error_message() : ''));
            }
        }
        $outcome = $this->applyPayment($order, $payment, $mode);
        if ($outcome === 'busy' && ($payment['status'] ?? '') === 'COMPLETED') {
            $outcome = 'paid'; // The webhook is recording this same payment right now.
        }
        if ($outcome !== 'paid' && $outcome !== 'pending') {
            return ['result' => 'failed', 'message' => Declines::message((string) (Declines::fromPayment($payment)['code']))];
        }
        return ['result' => 'success'];
    }

    /**
     * Pay with a saved card while the buyer is at checkout.
     *
     * @return array{result:string, message?:string}
     */
    public function chargeCard(\WC_Order $order, \WC_Payment_Token $token, string $flow, bool $onSession = true): array
    {
        if ($order->is_paid()) {
            return ['result' => 'success'];
        }
        $mode = Tokens::mode($token);
        $currency = strtoupper($order->get_currency());
        if ($this->gateway->currency($mode) !== '' && $currency !== $this->gateway->currency($mode)) {
            /* translators: %s: currency code */
            return $this->fail($order, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'CURRENCY_MISMATCH'], sprintf(__('Square takes payments in %s only.', 'stackless-lite'), $this->gateway->currency($mode)), $flow);
        }
        Gateway::attachToken($order, $token);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->save();
        if ((float) $order->get_total() <= 0) {
            $order->payment_complete();
            return ['result' => 'success'];
        }

        // A charge that already went through for this order is reported, not repeated.
        $previous = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
        if ($previous !== '') {
            $existing = $this->gateway->api($mode)->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && is_array($existing['payment'] ?? null) && in_array($existing['payment']['status'] ?? '', ['COMPLETED', 'APPROVED', 'PENDING'], true)) {
                return $this->applyPayment($order, $existing['payment'], $mode) === 'paid' ? ['result' => 'success'] : ['result' => 'pending'];
            }
        }

        $attempt = (int) $order->get_meta(self::META_ATTEMPT);
        $payment = $this->createPayment($order, $mode, [
            'source_id' => $token->get_token(),
            'customer_id' => Tokens::customer($token),
            // Without the buyer present, Square treats the charge as merchant-initiated (no 3-D Secure).
            'customer_details' => ['customer_initiated' => $onSession, 'seller_keyed_in' => false],
        ], Api::key(substr($flow, 0, 1) . $order->get_id(), (string) $attempt));
        if (is_wp_error($payment)) {
            if ($flow === 'checkout') {
                return $this->checkoutError($order, $payment);
            }
            return $this->offSessionError($order, $payment, $flow);
        }
        $outcome = $this->applyPayment($order, $payment, $mode);
        if ($outcome === 'paid') {
            $order->delete_meta_data(self::META_TRANSIENT);
            $order->save();
            return ['result' => 'success'];
        }
        if ($outcome === 'pending') {
            return ['result' => 'pending'];
        }
        $decline = Declines::fromPayment($payment);
        return $flow === 'checkout'
            ? ['result' => 'failed', 'message' => Declines::message($decline['code'])]
            : $this->fail($order, $decline, Declines::message($decline['code']), $flow);
    }

    /**
     * Renewal (Contracts\SubscriptionGateway).
     *
     * @return array{result:string, message?:string}
     */
    public function renew(\WC_Order $renewal, ?\WC_Order $subscription): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'pending', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $token = self::resolveToken($renewal, $subscription);
        if (!$token) {
            return $this->fail($renewal, ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'NO_CARD_ON_FILE'], __('No saved Square card is attached to this subscription.', 'stackless-lite'), 'renewal');
        }
        if (!$renewal->needs_payment()) {
            return $renewal->is_paid() ? ['result' => 'success'] : ['result' => 'failed', 'message' => __('The renewal order cannot be paid in its current status.', 'stackless-lite')];
        }
        return $this->chargeCard($renewal, $token, 'renewal', false);
    }

    /**
     * Charge a saved card for a new order (Contracts\SavedMethodCharger).
     *
     * @return array{result:string, message?:string}
     */
    public function chargeSaved(\WC_Order $order, \WC_Payment_Token $token, bool $onSession): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        if ($token->get_gateway_id() !== Gateway::ID || (int) $order->get_customer_id() <= 0 || (int) $token->get_user_id() !== (int) $order->get_customer_id()) {
            return ['result' => 'failed', 'message' => __('That saved card does not belong to this customer.', 'stackless-lite')];
        }
        if ($order->get_payment_method() !== Gateway::ID || !$order->needs_payment()) {
            return ['result' => 'failed', 'message' => __('The order is not waiting for a Square payment.', 'stackless-lite')];
        }
        $result = $this->chargeCard($order, $token, 'saved', $onSession);
        if ($result['result'] === 'failed' && $onSession && (string) $order->get_meta(SubscriptionData::META_DECLINE) === SubscriptionData::DECLINE_AUTH) {
            // The bank wants the buyer to verify: let them enter the card on the order's pay page.
            return ['result' => 'action_required', 'message' => $result['message'] ?? '', 'redirect' => $order->get_checkout_payment_url()];
        }
        return $result;
    }

    /**
     * My Account → Add payment method: store a card from a STORE-intent token.
     *
     * @return \WC_Payment_Token|\WP_Error
     */
    public function storeCard(string $source, int $userId, string $mode)
    {
        $customer = $this->gateway->customerFor($userId, $mode);
        if (is_wp_error($customer)) {
            return $customer;
        }
        return $this->createCard($source, $customer, $userId, $mode);
    }

    /**
     * POST /v2/cards from a token (STORE intent) or a payment id (charged within 24 hours).
     *
     * @return \WC_Payment_Token|\WP_Error
     */
    private function createCard(string $source, string $customer, int $userId, string $mode, ?\WC_Order $order = null)
    {
        $card = array_filter([
            'customer_id' => $customer,
            'cardholder_name' => $order ? trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) : '',
            'reference_id' => $order ? 'wc_order_' . $order->get_id() : 'wp_user_' . $userId,
            'billing_address' => $order ? self::address($order) : null,
        ]);
        $response = $this->gateway->api($mode)->request('POST', 'cards', [
            'idempotency_key' => Api::key('card', substr(hash('sha256', $source . '|' . $customer), 0, 20)),
            'source_id' => $source,
            'card' => $card,
        ]);
        if (is_wp_error($response)) {
            $this->gateway->log('Saving a card failed: ' . Api::code($response) . ' ' . $response->get_error_message(), 'warning');
            return $response;
        }
        $token = Tokens::saveFromCard(is_array($response['card'] ?? null) ? $response['card'] : [], $userId, $mode);
        return $token ?: new \WP_Error('stackless_square_card', __('Square saved the card, but it could not be stored here.', 'stackless-lite'));
    }

    /** @return array|\WP_Error The payment object. */
    private function createPayment(\WC_Order $order, string $mode, array $source, string $idempotencyKey)
    {
        $body = array_filter(array_merge($source, [
            'idempotency_key' => $idempotencyKey,
            'amount_money' => Money::money($order->get_total(), $order->get_currency()),
            'location_id' => $this->gateway->locationId($mode),
            'autocomplete' => true,
            'reference_id' => substr((string) $order->get_id(), 0, 40),
            /* translators: 1: order number, 2: site name */
            'note' => substr(sprintf(__('Order %1$s – %2$s', 'stackless-lite'), $order->get_order_number(), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 500),
            'buyer_email_address' => is_email($order->get_billing_email()) ? $order->get_billing_email() : null,
            'billing_address' => self::address($order) ?: null,
        ]), static function ($value) {
            return $value !== null && $value !== '';
        });
        $response = $this->gateway->api($mode)->request('POST', 'payments', $body);
        if (is_wp_error($response)) {
            return $response;
        }
        $payment = is_array($response['payment'] ?? null) ? $response['payment'] : [];
        if (empty($payment['id'])) {
            return new \WP_Error('stackless_square_payment', __('Square did not return a payment.', 'stackless-lite'), ['status' => 502]);
        }
        $order->update_meta_data(Gateway::META_PAYMENT_ID, (string) $payment['id']);
        $order->save();
        return $payment;
    }

    /**
     * Apply a Square payment object to its order (checkout, renewal or webhook).
     *
     * @return string paid|pending|failed|mismatch|busy|ignored…
     */
    public function applyPayment(\WC_Order $order, array $payment, string $mode): string
    {
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $payment) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            $paymentId = (string) ($payment['id'] ?? '');
            $current = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
            if ($current !== '' && $current !== $paymentId) {
                return 'ignored: another payment';
            }
            $status = (string) ($payment['status'] ?? '');
            if ($status === 'COMPLETED' || $status === 'APPROVED') {
                if ($order->is_paid() || ($order->get_date_paid() && $order->get_transaction_id() === $paymentId)) {
                    return 'paid';
                }
                if (!Money::matches($payment['amount_money'] ?? null, $order->get_total(), $order->get_currency())) {
                    if (!$order->has_status('on-hold')) {
                        /* translators: %s: Square payment id */
                        $order->update_status('on-hold', sprintf(__('Square payment %s does not match the order total. Check it before shipping.', 'stackless-lite'), $paymentId));
                    }
                    return 'mismatch';
                }
                $order->payment_complete($paymentId);
                return 'paid';
            }
            if ($status === 'PENDING') {
                if ($order->needs_payment() && !stackless_is_renewal_order($order)) {
                    $order->set_transaction_id($paymentId);
                    /* translators: %s: Square payment id */
                    $order->update_status('on-hold', sprintf(__('Square is processing payment %s.', 'stackless-lite'), $paymentId));
                }
                return 'pending';
            }
            if ($status === 'FAILED' || $status === 'CANCELED') {
                if ($order->needs_payment() && stackless_is_renewal_order($order)) {
                    $decline = Declines::fromPayment($payment);
                    Declines::record($order, $decline);
                    if (!$order->has_status('failed')) {
                        /* translators: 1: Square payment id, 2: error code */
                        $order->update_status('failed', sprintf(__('Square payment %1$s failed (%2$s).', 'stackless-lite'), $paymentId, $decline['code']));
                    }
                }
                return 'failed';
            }
            return 'pending';
        }, 'busy');
    }

    /** @return array{result:string, message:string} */
    private function checkoutError(\WC_Order $order, \WP_Error $error): array
    {
        $code = Api::code($error);
        /* translators: 1: error code, 2: message */
        $order->add_order_note(sprintf(__('Square payment attempt failed: %1$s (%2$s)', 'stackless-lite'), $error->get_error_message(), $code !== '' ? $code : 'HTTP ' . Api::status($error)));
        $this->gateway->log('Checkout payment for order ' . $order->get_id() . ' failed: ' . $code, Api::isTransient($error) ? 'error' : 'info');
        return ['result' => 'failed', 'message' => $code !== '' ? Declines::message($code) : $error->get_error_message()];
    }

    /** @return array{result:string, message?:string} */
    private function offSessionError(\WC_Order $order, \WP_Error $error, string $flow): array
    {
        if (Api::isTransient($error)) {
            $retries = (int) $order->get_meta(self::META_TRANSIENT);
            if ($retries < self::MAX_TRANSIENT_RETRIES && function_exists('as_schedule_single_action')) {
                $order->update_meta_data(self::META_TRANSIENT, (string) ($retries + 1));
                $order->save();
                as_schedule_single_action(time() + 900 * ($retries + 1), self::RETRY_HOOK, ['order_id' => $order->get_id()], self::GROUP);
                /* translators: %s: error */
                $order->add_order_note(sprintf(__('Square could not be reached for this charge (%s). Retrying automatically with the same idempotency key.', 'stackless-lite'), $error->get_error_message()));
                return ['result' => 'pending'];
            }
        }
        $decline = Declines::fromError($error);
        return $this->fail($order, $decline, Declines::message($decline['code']), $flow);
    }

    /**
     * @param array{category:string, code:string} $decline
     * @return array{result:string, message:string}
     */
    private function fail(\WC_Order $order, array $decline, string $message, string $flow): array
    {
        if ($flow === 'checkout') {
            return ['result' => 'failed', 'message' => $message];
        }
        $order->update_meta_data(self::META_ATTEMPT, (string) ((int) $order->get_meta(self::META_ATTEMPT) + 1));
        $order->delete_meta_data(self::META_TRANSIENT);
        $order->delete_meta_data(Gateway::META_PAYMENT_ID);
        $order->save();
        Declines::record($order, $decline);
        /* translators: 1: reason, 2: error code */
        $note = sprintf(__('Square charge failed: %1$s (%2$s)', 'stackless-lite'), $message, $decline['code']);
        if ($order->has_status('pending')) {
            $order->update_status('failed', $note);
        } else {
            $order->add_order_note($note);
        }
        $this->gateway->log(sprintf('Charge for order %d failed: %s', $order->get_id(), $decline['code']), 'warning');
        return ['result' => 'failed', 'message' => $message];
    }

    /** Action Scheduler callback: retry a charge that could not reach Square. */
    public static function retry(int $order_id): void
    {
        if (LiveStore::hold(self::RETRY_HOOK, ['order_id' => $order_id], self::GROUP)) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== Gateway::ID || !$order->needs_payment()) {
            return;
        }
        $subscriptions = stackless_get_order_subscriptions($order);
        $subscription = $subscriptions ? reset($subscriptions) : null;
        $token = self::resolveToken($order, $subscription instanceof \WC_Order ? $subscription : null);
        if ($token) {
            (new self(Gateway::instance()))->chargeCard($order, $token, stackless_is_renewal_order($order) ? 'renewal' : 'saved', false);
        }
    }

    public static function resolveToken(\WC_Order $order, $subscription = null): ?\WC_Payment_Token
    {
        foreach ([$order, $subscription] as $source) {
            if (!$source instanceof \WC_Order) {
                continue;
            }
            $tokenId = (int) $source->get_meta(SubscriptionData::META_TOKEN_ID);
            $token = $tokenId > 0 ? \WC_Payment_Tokens::get($tokenId) : null;
            if ($token && $token->get_gateway_id() === Gateway::ID && Tokens::customer($token) !== '') {
                return $token;
            }
        }
        return null;
    }

    /** @return array<string,string> Square address (empty values left out). */
    public static function address(\WC_Order $order): array
    {
        return array_filter([
            'address_line_1' => $order->get_billing_address_1(),
            'address_line_2' => $order->get_billing_address_2(),
            'locality' => $order->get_billing_city(),
            'administrative_district_level_1' => $order->get_billing_state(),
            'postal_code' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
        ], static function ($value) {
            return (string) $value !== '';
        });
    }

    /* ---- Webhook ----------------------------------------------------------------------- */

    /** payment.created / payment.updated */
    public function webhookPayment(array $payment, string $mode): string
    {
        $order = $this->findOrder((string) ($payment['id'] ?? ''));
        if (!$order) {
            return 'ignored: no matching order';
        }
        return $this->applyPayment($order, $payment, $mode);
    }

    /** refund.created / refund.updated: refunds made in Square, and failures of ours. */
    public function webhookRefund(array $refund): string
    {
        $order = $this->findOrder((string) ($refund['payment_id'] ?? ''));
        if (!$order) {
            return 'ignored: no matching order';
        }
        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $refund) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'none';
            }
            $refundId = (string) ($refund['id'] ?? '');
            $status = (string) ($refund['status'] ?? '');
            $known = $order->get_meta(Gateway::META_REFUND_IDS);
            $known = is_array($known) ? $known : [];
            if (in_array($status, ['REJECTED', 'FAILED'], true)) {
                if (in_array($refundId, $known, true) && !in_array($refundId . ':failed', $known, true)) {
                    /* translators: %s: Square refund id */
                    $order->add_order_note(sprintf(__('Square could not complete refund %s. Check it in the Square Dashboard.', 'stackless-lite'), $refundId));
                    self::rememberRefund($order, $refundId . ':failed');
                }
                return 'refund failed noted';
            }
            if ($refundId === '' || in_array($refundId, $known, true)) {
                return 'refund already recorded';
            }
            $amount = min(Money::fromMinor((int) ($refund['amount_money']['amount'] ?? 0), $order->get_currency()), (float) $order->get_remaining_refund_amount());
            self::rememberRefund($order, $refundId);
            if ($amount <= 0) {
                return 'nothing left to refund';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                /* translators: %s: Square refund id */
                'reason' => sprintf(__('Refunded in Square (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            return is_wp_error($created) ? 'refund not recorded: ' . $created->get_error_message() : 'refund recorded';
        }, 'busy');
    }

    /**
     * The order a Square payment belongs to, by the payment id stored when it was created.
     * reference_id alone is not trusted: another store on the same Square account can use the
     * same order numbers.
     */
    private function findOrder(string $paymentId): ?\WC_Order
    {
        if ($paymentId === '') {
            return null;
        }
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Gateway::META_PAYMENT_ID, 'meta_value' => $paymentId, 'type' => 'shop_order']); // phpcs:ignore WordPress.DB.SlowDBQuery
        return $orders && $orders[0] instanceof \WC_Order ? $orders[0] : null;
    }
}
