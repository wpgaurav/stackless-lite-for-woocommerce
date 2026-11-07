<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Contracts\SavedMethodCharger for PayPal (2.0.0): charges a vaulted PayPal account or card
 * for a new order outside the renewal schedule (post-purchase offers).
 *
 * The token must belong to the order's customer, to this gateway and to the current PayPal
 * environment; a copy of the live store never charges. The PayPal-Request-Id is keyed by
 * the order and token, so a double click or a retry replays PayPal's first answer.
 */
final class SavedCharges
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** @return array{result:string, message?:string, redirect?:string} */
    public function charge(\WC_Order $order, \WC_Payment_Token $token, bool $onSession): array
    {
        if (!LiveStore::isLive()) {
            return ['result' => 'failed', 'message' => __('This site is a copy of the live store, so it does not charge saved payment methods.', 'stackless-lite')];
        }
        $refusal = $this->refusal($order, $token);
        if ($refusal !== '') {
            $this->gateway->log('Saved-method charge for order ' . $order->get_id() . ' refused: ' . $refusal, 'warning');
            return ['result' => 'failed', 'message' => $refusal];
        }
        if ($order->is_paid()) {
            return ['result' => 'success'];
        }
        $type = CardTokens::isCard($token) ? 'card' : 'paypal';
        $vaultId = $token->get_token();
        $payments = $this->gateway->payments();

        $done = Lock::run('order-' . $order->get_id(), function () use ($order, $token, $type, $vaultId, $onSession, $payments) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
            }
            if ($order->is_paid()) {
                return ['result' => 'success'];
            }
            $order->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $token->get_id());
            $order->update_meta_data(Gateway::META_VAULT_ID, $vaultId);
            $order->update_meta_data(Gateway::META_VAULT_TYPE, $type);
            $order->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
            $order->save();

            $api = $this->gateway->api();
            $requestId = self::requestId($order->get_id(), (int) $token->get_id(), $onSession);
            $body = $payments->builder()->savedCharge($order, $vaultId, $type, $onSession, $payments->returnUrl($order, 'order'));
            $response = $api->createOrder($body, $requestId);
            if (is_wp_error($response) && Payments::isTransient($response)) {
                $response = $api->createOrder($body, $requestId);
            }
            if (is_wp_error($response)) {
                return $this->failed($order, $response);
            }
            if (!empty($response['id'])) {
                $order->update_meta_data(Gateway::META_ORDER_ID, (string) $response['id']);
                $order->save();
            }
            $status = (string) ($response['status'] ?? '');
            if ($status === 'PAYER_ACTION_REQUIRED') {
                $link = Payments::link($response, ['payer-action', 'approve']);
                return [
                    'result' => 'action_required',
                    'message' => __('Confirm the payment with PayPal to finish.', 'stackless-lite'),
                    'redirect' => $onSession && $link !== '' ? $link : $order->get_checkout_payment_url(),
                ];
            }
            if (in_array($status, ['CREATED', 'APPROVED', 'SAVED'], true) && !empty($response['id'])) {
                $response = $api->captureOrder((string) $response['id'], $requestId . '-capture');
                if (is_wp_error($response)) {
                    return $this->failed($order, $response);
                }
            }
            $result = $payments->applyResult($order, $response, 'saved_method');
            return $result['result'] === 'failed'
                ? ['result' => 'failed', 'message' => (string) ($result['message'] ?? __('PayPal declined the payment.', 'stackless-lite'))]
                : ['result' => $result['result']];
        }, null, 20);

        return is_array($done) ? $done : ['result' => 'pending', 'message' => __('This order is already being processed.', 'stackless-lite')];
    }

    /** Why the token may not pay this order, or ''. */
    public function refusal(\WC_Order $order, \WC_Payment_Token $token): string
    {
        $customer = (int) $order->get_customer_id();
        if ($order->get_payment_method() !== $this->gateway->id) {
            return __('The order is not set to pay with PayPal.', 'stackless-lite');
        }
        if ($token->get_gateway_id() !== $this->gateway->id || $customer <= 0 || (int) $token->get_user_id() !== $customer) {
            return __('That saved payment method does not belong to this customer.', 'stackless-lite');
        }
        if (!$token instanceof PaymentToken && !CardTokens::isCard($token)) {
            return __('That saved payment method cannot be charged by PayPal.', 'stackless-lite');
        }
        $environment = $token instanceof PaymentToken ? $token->get_environment() : CardTokens::environment($token);
        if ($environment !== '' && $environment !== $this->gateway->environment()) {
            return __('That saved payment method belongs to the other PayPal environment (sandbox or live).', 'stackless-lite');
        }
        if (!$order->is_paid() && !$order->needs_payment()) {
            return __('The order cannot be paid in its current status.', 'stackless-lite');
        }
        if ((float) $order->get_total() <= 0 || !Amount::supported($order->get_currency())) {
            /* translators: %s: currency code */
            return sprintf(__('PayPal cannot take this payment in %s.', 'stackless-lite'), $order->get_currency());
        }
        return '';
    }

    public static function requestId(int $orderId, int $tokenId, bool $onSession): string
    {
        return 'stackless-saved-' . $orderId . '-' . $tokenId . ($onSession ? '-on' : '-off');
    }

    /** @return array{result:string, message:string, redirect?:string} */
    private function failed(\WC_Order $order, \WP_Error $error): array
    {
        [$category, $code] = Declines::fromError($error);
        Declines::write($order, $category, $code);
        $order->save();
        /* translators: %s: PayPal error */
        $order->add_order_note(sprintf(__('Charging the saved PayPal method failed: %s', 'stackless-lite'), $error->get_error_message()));
        $this->gateway->log('Saved-method charge for order ' . $order->get_id() . ' failed: ' . $error->get_error_message() . ' ' . wp_json_encode($error->get_error_data()), 'warning');
        if ($category === SubscriptionData::DECLINE_AUTH) {
            return ['result' => 'action_required', 'message' => __('Confirm the payment with PayPal to finish.', 'stackless-lite'), 'redirect' => $order->get_checkout_payment_url()];
        }
        return ['result' => 'failed', 'message' => $error->get_error_message()];
    }
}
