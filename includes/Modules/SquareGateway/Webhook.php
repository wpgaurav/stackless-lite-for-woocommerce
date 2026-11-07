<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/square/webhook
 *
 * Signed with x-square-hmacsha256-signature: base64 HMAC-SHA256 of the notification URL plus
 * the raw body, keyed with the subscription's signature key. The production and sandbox keys
 * are both tried, and the one that verifies decides the mode. Square delivers at least once,
 * so each event_id is claimed in WebhookLog and applied once.
 */
final class Webhook
{
    public const ROUTE = '/square/webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** live|test for the key that signed this body, or '' when none did. */
    public static function modeFor(string $body, string $signature, Gateway $gateway): string
    {
        foreach (['live', 'test'] as $mode) {
            $key = $gateway->webhookKey($mode);
            if ($key !== '' && Signature::verify(Setup::signedUrl($mode, $gateway->webhookUrl()), $body, $signature, $key)) {
                return $mode;
            }
        }
        return '';
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        $gateway = Gateway::instance();
        if (self::modeFor((string) $request->get_body(), (string) $request->get_header('x_square_hmacsha256_signature'), $gateway) === '') {
            $gateway->log('Rejected a Square webhook with a missing or invalid signature.', 'warning');
            return new \WP_Error('stackless_square_webhook_invalid', 'Invalid Square webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = Gateway::instance();
        $body = (string) $request->get_body();
        $mode = self::modeFor($body, (string) $request->get_header('x_square_hmacsha256_signature'), $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Square webhook signature.'], 401);
        }
        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['type']) || empty($event['event_id'])) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        $type = strtolower((string) $event['type']);
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        $eventKey = 'square:' . $mode . ':' . (string) $event['event_id'];
        $claim = WebhookLog::claim($eventKey, $type, EventMap::resourceId($event));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }
        try {
            $outcome = self::dispatch($gateway, $handler, $event, $mode);
            if ($outcome === 'busy') {
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Square ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_square_gateway_webhook', $type, $event, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Square webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    public static function dispatch(Gateway $gateway, string $handler, array $event, string $mode): string
    {
        $payments = new Payments($gateway);
        switch ($handler) {
            case 'payment':
                $payment = EventMap::object($event, 'payment');
                return $payment ? $payments->webhookPayment($payment, $mode) : 'ignored: no payment';
            case 'refund':
                $refund = EventMap::object($event, 'refund');
                return $refund ? $payments->webhookRefund($refund) : 'ignored: no refund';
            case 'card_removed':
                $card = EventMap::object($event, 'card');
                $id = (string) ($card['id'] ?? EventMap::resourceId($event));
                return 'card removed (' . Tokens::forget($id) . ' tokens deleted)';
            case 'card_updated':
                return 'card updated (' . Tokens::updateFromCard(EventMap::object($event, 'card')) . ' tokens)';
            default:
                return 'ignored';
        }
    }
}
