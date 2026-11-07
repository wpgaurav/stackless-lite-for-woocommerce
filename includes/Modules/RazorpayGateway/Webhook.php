<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/razorpay/webhook
 *
 * Signed with HMAC-SHA256 of the raw body (X-Razorpay-Signature). The live and test webhook
 * secrets are both tried, and the one that verifies decides the mode. Deliveries are
 * at-least-once, so each event id (X-Razorpay-Event-Id) is claimed in WebhookLog and applied once.
 */
final class Webhook
{
    public const ROUTE = '/razorpay/webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** live|test for the secret that signed this body, or '' when none did. */
    public static function modeFor(string $body, string $signature, Gateway $gateway): string
    {
        foreach (['live', 'test'] as $mode) {
            $secret = $gateway->webhookSecret($mode);
            if ($secret !== '' && Signature::webhook($body, $signature, $secret)) {
                return $mode;
            }
        }
        return '';
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        $gateway = Gateway::instance();
        if (self::modeFor((string) $request->get_body(), (string) $request->get_header('x_razorpay_signature'), $gateway) === '') {
            $gateway->log('Rejected a Razorpay webhook with a missing or invalid signature.', 'warning');
            return new \WP_Error('stackless_razorpay_webhook_invalid', 'Invalid Razorpay webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = Gateway::instance();
        $body = (string) $request->get_body();
        $mode = self::modeFor($body, (string) $request->get_header('x_razorpay_signature'), $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Razorpay webhook signature.'], 401);
        }
        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['event'])) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        $type = strtolower((string) $event['event']);
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        $eventId = (string) $request->get_header('x_razorpay_event_id');
        if ($eventId === '') {
            // Older deliveries without the header: the body itself identifies the event.
            $eventId = 'body-' . hash('sha256', $body);
        }
        $eventKey = 'razorpay:' . $mode . ':' . $eventId;
        $claim = WebhookLog::claim($eventKey, $type, EventMap::resourceId($event));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = self::dispatch($gateway, $handler, $type, $event, $mode);
            if ($outcome === 'busy') {
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Razorpay ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_razorpay_gateway_webhook', $type, $event, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Razorpay webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    public static function dispatch(Gateway $gateway, string $handler, string $type, array $event, string $mode): string
    {
        $sync = new Sync($gateway);
        switch ($handler) {
            case 'payment':
                $payment = EventMap::entity($event, 'payment');
                return $payment ? $sync->webhookPayment($payment, $mode) : 'ignored: no payment';
            case 'refund':
                return $sync->webhookRefund($type, EventMap::entity($event, 'refund'), EventMap::entity($event, 'payment'), $mode);
            case 'token':
                return $sync->webhookToken($type, EventMap::entity($event, 'token'), $mode);
            default:
                return 'ignored';
        }
    }
}
