<?php

namespace Stackless\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/mollie/webhook
 *
 * Mollie's payment webhooks are not signed and carry only "id=tr_…". Nothing in the request is
 * trusted: the payment is read back from the Mollie API with the store's own key, and only that
 * answer changes an order. A forged id either does not exist at Mollie (404) or is someone
 * else's payment whose metadata does not name this site. Each state of a payment (status plus
 * refunded and charged-back amounts) is claimed in WebhookLog once, so repeats are no-ops.
 */
final class Webhook
{
    public const ROUTE = '/mollie/webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** The payment id Mollie posted, or '' when the request is not shaped like a Mollie webhook. */
    public static function paymentId(\WP_REST_Request $request): string
    {
        $id = (string) $request->get_param('id');
        return preg_match('/^tr_[A-Za-z0-9]{4,64}$/', $id) ? $id : '';
    }

    /**
     * Unsigned by design (see class note): a request is let through when it carries a payment
     * id, and the handler verifies it against Mollie.
     *
     * @return true|\WP_Error
     */
    public static function authorize(\WP_REST_Request $request)
    {
        if (self::paymentId($request) === '') {
            return new \WP_Error('stackless_mollie_webhook_invalid', 'Expected a Mollie payment id.', ['status' => 400]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = Gateway::instance();
        $paymentId = self::paymentId($request);
        if ($paymentId === '') {
            return new \WP_REST_Response(['message' => 'Expected a Mollie payment id.'], 400);
        }

        $found = self::fetch($gateway, $paymentId);
        if ($found === null) {
            // Unknown to both keys: not ours (or a forged id). Mollie needs a 2xx to stop retrying.
            $gateway->log('Webhook for unknown payment ' . $paymentId . ' ignored.', 'warning');
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        if ($found instanceof \WP_Error) {
            $gateway->log('Webhook for ' . $paymentId . ' could not read the payment: ' . $found->get_error_message(), 'warning');
            return new \WP_REST_Response(['message' => 'Could not read the payment; retry later.'], 503);
        }
        [$payment, $mode] = $found;

        $eventKey = self::eventKey($payment, $mode);
        $claim = WebhookLog::claim($eventKey, 'payment.' . (string) ($payment['status'] ?? ''), $paymentId);
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = (new Sync($gateway))->applyPayment($payment, $mode);
            if ($outcome === 'busy') {
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Mollie ' . $paymentId . ' (' . $mode . ', ' . (string) ($payment['status'] ?? '') . '): ' . $outcome);
            do_action('stackless_mollie_gateway_webhook', $payment, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Mollie webhook for ' . $paymentId . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    /**
     * Read the payment with the key of the order's mode first, then the other key.
     *
     * @return array{0:array,1:string}|\WP_Error|null null when neither key knows the payment.
     */
    public static function fetch(Gateway $gateway, string $paymentId)
    {
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => Gateway::META_PAYMENT_ID, 'meta_value' => $paymentId, 'type' => 'shop_order']); // phpcs:ignore WordPress.DB.SlowDBQuery
        $first = $orders && $orders[0] instanceof \WC_Order ? $gateway->orderMode($orders[0]) : $gateway->mode();
        $modes = array_unique([$first, $first === 'live' ? 'test' : 'live']);
        $lastError = null;
        foreach ($modes as $mode) {
            if ($gateway->apiKey($mode) === '') {
                continue;
            }
            $payment = $gateway->api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
            if (is_array($payment) && (string) ($payment['id'] ?? '') === $paymentId) {
                $paymentMode = (string) ($payment['mode'] ?? $mode);
                return [$payment, $paymentMode === 'test' ? 'test' : 'live'];
            }
            if (is_wp_error($payment) && Api::status($payment) !== 404) {
                $lastError = $payment;
            }
        }
        return $lastError;
    }

    /** One claim per payment state, so the same state is applied once. */
    public static function eventKey(array $payment, string $mode): string
    {
        return implode(':', [
            'mollie',
            $mode,
            (string) ($payment['id'] ?? ''),
            (string) ($payment['status'] ?? ''),
            (string) ($payment['amountRefunded']['value'] ?? '0'),
            (string) ($payment['amountChargedBack']['value'] ?? '0'),
        ]);
    }
}
