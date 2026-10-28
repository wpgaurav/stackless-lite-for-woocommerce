<?php

namespace Stackless\Modules\PolarGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/polar/webhook          (the original gateway)
 * POST /wp-json/stackless/v1/polar/webhook/copyN    (a gateway copy, its own Polar organization)
 *
 * Signed with Standard Webhooks; the live and sandbox secrets are both tried,
 * and the one that verifies decides which Polar environment the event is from.
 * Replays are refused by the 5-minute timestamp window and by claiming the
 * webhook-id in WebhookLog, so a retried delivery is applied once.
 */
final class Webhook
{
    public static function register(): void
    {
        register_rest_route('stackless/v1', '/polar/webhook(?:/(?P<copy>copy[0-9]+))?', [
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

    /** Webhook log key: the original keeps its pre-copies format so old deliveries still dedupe. */
    public static function eventKey(Gateway $gateway, string $mode, string $id): string
    {
        return ($gateway->isOriginal() ? '' : $gateway->id . ':') . $mode . ':' . $id;
    }

    /** @return array{id:string,timestamp:string,signature:string} */
    public static function headers(\WP_REST_Request $request): array
    {
        return [
            'id' => (string) $request->get_header('webhook_id'),
            'timestamp' => (string) $request->get_header('webhook_timestamp'),
            'signature' => (string) $request->get_header('webhook_signature'),
        ];
    }

    /** live|sandbox for the secret that signed this body, or '' when none did. */
    public static function modeFor(string $body, array $headers, Gateway $gateway, ?int $now = null): string
    {
        foreach (['live', 'sandbox'] as $mode) {
            $secret = $gateway->webhookSecret($mode);
            if ($secret !== '' && Signature::verify($body, $headers, $secret, $now)) {
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
            return new \WP_Error('stackless_polar_webhook_unknown', 'Unknown Polar gateway.', ['status' => 404]);
        }
        $mode = self::modeFor((string) $request->get_body(), self::headers($request), $gateway);
        if ($mode === '') {
            $gateway->log('Rejected a Polar webhook with an invalid or expired signature.', 'warning');
            return new \WP_Error('stackless_polar_webhook_invalid', 'Invalid Polar webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = self::gatewayFor($request);
        if (!$gateway) {
            return new \WP_REST_Response(['message' => 'Unknown Polar gateway.'], 404);
        }
        $body = (string) $request->get_body();
        $headers = self::headers($request);
        $mode = self::modeFor($body, $headers, $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Polar webhook signature.'], 401);
        }

        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['type'])) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        $type = (string) $event['type'];
        $data = (array) ($event['data'] ?? []);
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 202);
        }

        $eventKey = self::eventKey($gateway, $mode, $headers['id']);
        $claim = WebhookLog::claim($eventKey, $type, (string) ($data['id'] ?? ''));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 202);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = self::dispatch($gateway, $handler, $type, $data, $mode);
            if ($outcome === 'busy') {
                // The order lock never freed up, so nothing was applied. Leave the event open
                // and answer non-2xx so Polar delivers it again instead of it being dropped.
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Polar ' . $type . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Polar ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_polar_gateway_webhook', $type, $data, $mode);
            return new \WP_REST_Response(['received' => true], 202);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Polar webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    public static function dispatch(Gateway $gateway, string $handler, string $type, array $data, string $mode): string
    {
        $sync = new Sync($gateway);
        switch ($handler) {
            case 'order':
                return $sync->confirmOrder($data, $mode);
            case 'orderRefunded':
                return $sync->refundsForOrder($data, $mode);
            case 'refund':
                return $sync->recordRefund($data, $mode);
            case 'subscription':
                return $sync->syncSubscription($data, $mode, $type);
            default:
                return 'ignored';
        }
    }
}
