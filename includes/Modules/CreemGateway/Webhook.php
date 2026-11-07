<?php

namespace Stackless\Modules\CreemGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/creem/webhook          (the original gateway)
 * POST /wp-json/stackless/v1/creem/webhook/copyN    (a gateway copy, its own Creem store)
 *
 * Signed with creem-signature (HMAC-SHA256 of the body); the gateway's live and test secrets
 * are both tried, and the one that verifies decides which Creem environment the event is
 * from. Replays are refused by claiming the event id in WebhookLog, so a retried delivery is
 * applied once.
 */
final class Webhook
{
    public const LAST_SEEN = 'stackless_creem_gateway_last_webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', '/creem/webhook(?:/(?P<copy>copy[0-9]+))?', [
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

    /** @return array{signature:string,id:string,timestamp:string,std_signature:string} */
    public static function headers(\WP_REST_Request $request): array
    {
        return [
            'signature' => (string) ($request->get_header('creem_signature') ?: $request->get_header('x_creem_signature')),
            'id' => (string) $request->get_header('webhook_id'),
            'timestamp' => (string) $request->get_header('webhook_timestamp'),
            'std_signature' => (string) $request->get_header('webhook_signature'),
        ];
    }

    /** live|test for the secret that signed this body, or '' when none did. */
    public static function modeFor(string $body, array $headers, Gateway $gateway, ?int $now = null): string
    {
        foreach (['live', 'test'] as $mode) {
            if (Signature::verify($body, $headers, $gateway->webhookSecret($mode), $now)) {
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
            return new \WP_Error('stackless_creem_webhook_unknown', 'Unknown Creem gateway.', ['status' => 404]);
        }
        if (self::modeFor((string) $request->get_body(), self::headers($request), $gateway) === '') {
            $gateway->log('Rejected a Creem webhook with an invalid signature.', 'warning');
            return new \WP_Error('stackless_creem_webhook_invalid', 'Invalid Creem webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = self::gatewayFor($request);
        if (!$gateway) {
            return new \WP_REST_Response(['message' => 'Unknown Creem gateway.'], 404);
        }
        $body = (string) $request->get_body();
        $mode = self::modeFor($body, self::headers($request), $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Creem webhook signature.'], 401);
        }
        return self::process($gateway, $body, $mode);
    }

    /** Apply a verified delivery. Public so the staging simulator can drive it. */
    public static function process(Gateway $gateway, string $body, string $mode): \WP_REST_Response
    {
        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['eventType']) || empty($event['id']) || !is_array($event['object'] ?? null)) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        self::seen($gateway, $mode);
        $type = (string) $event['eventType'];
        $object = (array) $event['object'];
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $eventKey = $gateway->id . ':' . $mode . ':' . (string) $event['id'];
        $claim = WebhookLog::claim($eventKey, $type, EventMap::id($object['id'] ?? ''));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = self::dispatch($gateway, $handler, $type, $object, $mode);
            if ($outcome === 'busy' || strpos($outcome, 'busy;') === 0) {
                // The order lock never freed up, so nothing was applied. Leave the event open
                // and answer non-2xx so Creem delivers it again instead of it being dropped.
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Creem ' . $type . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Creem ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_creem_gateway_webhook', $type, $object, $mode, $gateway->id);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Creem webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    public static function dispatch(Gateway $gateway, string $handler, string $type, array $object, string $mode): string
    {
        $sync = new Sync($gateway);
        switch ($handler) {
            case 'checkout':
                return $sync->applyCheckout($object, $mode);
            case 'paid':
                return $sync->subscriptionPaid($object, $mode, $type);
            case 'failed':
                return $sync->renewalFailed($object, $mode, $type);
            case 'subscription':
                return $sync->syncSubscription($object, $mode, $type);
            case 'refund':
                return $sync->recordRefund($object);
            case 'dispute':
                return $sync->noteDispute($object);
            default:
                return 'ignored';
        }
    }

    /** Remember when each gateway and mode last delivered, for Billing Health (written at most hourly). */
    private static function seen(Gateway $gateway, string $mode): void
    {
        $seen = get_option(self::LAST_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        $key = $gateway->id . ':' . $mode;
        if ((int) ($seen[$key] ?? 0) < time() - HOUR_IN_SECONDS) {
            $seen[$key] = time();
            update_option(self::LAST_SEEN, $seen, false);
        }
    }
}
