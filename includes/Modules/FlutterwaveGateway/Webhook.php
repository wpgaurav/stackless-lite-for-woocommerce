<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/flutterwave/webhook
 *
 * Authenticated by the secret hash (verif-hash, or the v4 flutterwave-signature HMAC);
 * the live and test hashes are both tried. Nothing in a delivery is trusted beyond which
 * transaction to look at: the payment is verified with the API in the mode its order was
 * started in. Deliveries are claimed in WebhookLog by event, object id and status, so a
 * retried delivery is applied once.
 */
final class Webhook
{
    public const LAST_SEEN = 'stackless_flutterwave_gateway_last_webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', '/flutterwave/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** @return array{verif_hash:string,signature:string} */
    public static function headers(\WP_REST_Request $request): array
    {
        return [
            'verif_hash' => (string) $request->get_header('verif_hash'),
            'signature' => (string) $request->get_header('flutterwave_signature'),
        ];
    }

    /** live|test for the secret hash that authenticated this delivery, or '' when none did. */
    public static function modeFor(string $body, array $headers, Gateway $gateway): string
    {
        foreach (['live', 'test'] as $mode) {
            if (Signature::verify($body, $headers, $gateway->secretHash($mode))) {
                return $mode;
            }
        }
        return '';
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        $gateway = Gateway::instance();
        if (self::modeFor((string) $request->get_body(), self::headers($request), $gateway) === '') {
            $gateway->log('Rejected a Flutterwave webhook with a missing or wrong secret hash.', 'warning');
            return new \WP_Error('stackless_flutterwave_webhook_invalid', 'Invalid Flutterwave webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = Gateway::instance();
        $body = (string) $request->get_body();
        $mode = self::modeFor($body, self::headers($request), $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Flutterwave webhook signature.'], 401);
        }
        return self::process($gateway, $body, $mode);
    }

    /** Apply an authenticated delivery. Public so the staging simulator can drive it. */
    public static function process(Gateway $gateway, string $body, string $mode): \WP_REST_Response
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        [$type, $data] = EventMap::normalize($payload);
        self::seen($mode);
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        $key = EventMap::eventKey($type, $data);
        if ($key === '') {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }

        $eventKey = $mode . ':' . $key;
        $claim = WebhookLog::claim($eventKey, $type, EventMap::resourceId($data));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $sync = new Sync($gateway);
            $outcome = $handler === 'payment' ? $sync->confirmPayment($data, $mode) : $sync->recordRefund($data);
            if ($outcome === 'busy') {
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Flutterwave ' . $type . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Flutterwave ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_flutterwave_gateway_webhook', $type, $data, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Flutterwave webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    /** Remember when each mode last delivered, for Billing Health (written at most hourly). */
    private static function seen(string $mode): void
    {
        $seen = get_option(self::LAST_SEEN, []);
        $seen = is_array($seen) ? $seen : [];
        if ((int) ($seen[$mode] ?? 0) < time() - HOUR_IN_SECONDS) {
            $seen[$mode] = time();
            update_option(self::LAST_SEEN, $seen, false);
        }
    }
}
