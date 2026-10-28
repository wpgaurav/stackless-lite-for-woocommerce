<?php

namespace Stackless\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/paddle/webhook          (the original gateway)
 * POST /wp-json/stackless/v1/paddle/webhook/copyN    (a gateway copy, its own Paddle account)
 *
 * Signed with the Paddle-Signature header; the live and sandbox secrets are
 * both tried, and the one that verifies decides which Paddle environment the
 * event is from. Replays are refused by the 5-minute timestamp window and by
 * claiming the event_id in WebhookLog, so a retried delivery is applied once.
 */
final class Webhook
{
    public static function register(): void
    {
        register_rest_route('stackless/v1', '/paddle/webhook(?:/(?P<copy>copy[0-9]+))?', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** The gateway a webhook URL belongs to, or null for an unknown copy. */
    public static function gatewayFor(\WP_REST_Request $request): ?Gateway
    {
        $copy = (string) $request->get_param('copy');
        $id = Gateway::ID . ($copy !== '' ? '_' . $copy : '');
        return Copies::belongs($id) ? Gateway::instance($id) : null;
    }

    public static function header(\WP_REST_Request $request): string
    {
        return (string) $request->get_header('paddle_signature');
    }

    /** live|sandbox for the secret that signed this body, or '' when none did. */
    public static function modeFor(string $body, string $header, Gateway $gateway, ?int $now = null): string
    {
        foreach (['live', 'sandbox'] as $mode) {
            $secret = $gateway->webhookSecret($mode);
            if ($secret !== '' && Signature::verify($body, $header, $secret, $now)) {
                return $mode;
            }
        }
        return '';
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        $gateway = self::gatewayFor($request);
        if (!$gateway) {
            return new \WP_Error('stackless_paddle_webhook_unknown', 'Unknown Paddle gateway.', ['status' => 404]);
        }
        $mode = self::modeFor((string) $request->get_body(), self::header($request), $gateway);
        if ($mode === '') {
            $gateway->log('Rejected a Paddle webhook with an invalid or expired signature.', 'warning');
            return new \WP_Error('stackless_paddle_webhook_invalid', 'Invalid Paddle webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = self::gatewayFor($request);
        if (!$gateway) {
            return new \WP_REST_Response(['message' => 'Unknown Paddle gateway.'], 404);
        }
        $body = (string) $request->get_body();
        $mode = self::modeFor($body, self::header($request), $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Paddle webhook signature.'], 401);
        }
        return self::process($gateway, $body, $mode);
    }

    /** Apply a verified delivery. Public so the staging simulator can drive it. */
    public static function process(Gateway $gateway, string $body, string $mode): \WP_REST_Response
    {
        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['event_type']) || empty($event['event_id'])) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        $type = (string) $event['event_type'];
        $data = (array) ($event['data'] ?? []);
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        // The original keeps its pre-copies key format so old deliveries still dedupe.
        $eventKey = ($gateway->isOriginal() ? '' : $gateway->id . ':') . $mode . ':' . (string) $event['event_id'];
        $claim = WebhookLog::claim($eventKey, $type, (string) ($data['id'] ?? ''));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = self::dispatch($gateway, $handler, $type, $data, $mode);
            if ($outcome === 'busy') {
                // The order lock never freed up, so nothing was applied. Leave the event open
                // and answer non-2xx so Paddle delivers it again instead of it being dropped.
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Paddle ' . $type . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Paddle ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_paddle_gateway_webhook', $type, $data, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Paddle webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    public static function dispatch(Gateway $gateway, string $handler, string $type, array $data, string $mode): string
    {
        $sync = new Sync($gateway);
        switch ($handler) {
            case 'transaction':
                return $sync->confirmTransaction($data, $mode);
            case 'paymentFailed':
                return $sync->paymentFailed($data, $mode);
            case 'adjustment':
                return (new Refunds($gateway))->recordAdjustment($data, $mode);
            case 'subscription':
                return $sync->syncSubscription($data, $mode, $type);
            default:
                return 'ignored';
        }
    }
}
