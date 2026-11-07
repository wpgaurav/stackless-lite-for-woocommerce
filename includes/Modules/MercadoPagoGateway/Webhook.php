<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/mercadopago/webhook?data.id=…&type=payment
 *
 * Verified with x-signature (HMAC-SHA256 of the id/request-id/ts manifest) and the webhook
 * secret of the mode the notification says it is from (live_mode), falling back to the other
 * mode's secret. The notification only names a payment; the payment itself is read from the
 * API. Deliveries are claimed in WebhookLog by notification id, so a retry is applied once.
 */
final class Webhook
{
    public const LAST_SEEN = 'stackless_mercadopago_gateway_last_webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', '/mercadopago/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** data.id from the notification URL (PHP turns the dot into an underscore), else from the body. */
    public static function dataId(\WP_REST_Request $request, array $notification): string
    {
        foreach (['data_id', 'data.id'] as $name) {
            $value = $request->get_param($name);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        return isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '';
    }

    /** live|test for the secret that signed this notification, or '' when none did. */
    public static function modeFor(array $notification, string $dataId, string $signature, string $requestId, Gateway $gateway): string
    {
        $first = isset($notification['live_mode']) && $notification['live_mode'] === false ? 'test' : 'live';
        foreach ([$first, $first === 'live' ? 'test' : 'live'] as $mode) {
            if (Signature::verify($signature, $dataId, $requestId, $gateway->webhookSecret($mode))) {
                return $mode;
            }
        }
        return '';
    }

    /** @return array{0:array,1:string,2:string,3:string} notification, data id, mode, request id */
    private static function read(\WP_REST_Request $request): array
    {
        $notification = json_decode((string) $request->get_body(), true);
        $notification = is_array($notification) ? $notification : [];
        $dataId = self::dataId($request, $notification);
        $requestId = (string) $request->get_header('x_request_id');
        $mode = self::modeFor($notification, $dataId, (string) $request->get_header('x_signature'), $requestId, Gateway::instance());
        return [$notification, $dataId, $mode, $requestId];
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        [, , $mode] = self::read($request);
        if ($mode === '') {
            Gateway::instance()->log('Rejected a Mercado Pago notification with an invalid signature.', 'warning');
            return new \WP_Error('stackless_mercadopago_webhook_invalid', 'Invalid Mercado Pago webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        [$notification, $dataId, $mode, $requestId] = self::read($request);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Mercado Pago webhook signature.'], 401);
        }
        return self::process(Gateway::instance(), $notification, $dataId, $mode, $requestId);
    }

    /** Apply a verified notification. Public so the staging simulator can drive it. */
    public static function process(Gateway $gateway, array $notification, string $dataId, string $mode, string $requestId = ''): \WP_REST_Response
    {
        self::seen($mode);
        $type = (string) ($notification['type'] ?? ($notification['topic'] ?? ''));
        if (EventMap::handler($type) === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        $key = EventMap::eventKey($notification, $dataId, $requestId);
        if ($key === '') {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }

        $eventKey = $mode . ':' . $key;
        $claim = WebhookLog::claim($eventKey, $type . ':' . (string) ($notification['action'] ?? ''), $dataId);
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = (new Sync($gateway))->confirmNotification($dataId, $mode);
            if (strpos($outcome, 'busy') !== false) {
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Mercado Pago payment ' . $dataId . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Mercado Pago payment ' . $dataId . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_mercadopago_gateway_webhook', $type, $notification, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Mercado Pago notification for payment ' . $dataId . ' failed: ' . $e->getMessage(), 'error');
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
