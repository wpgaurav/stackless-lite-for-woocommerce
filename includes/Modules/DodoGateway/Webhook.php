<?php

namespace Stackless\Modules\DodoGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/dodo/webhook          (the original gateway)
 * POST /wp-json/stackless/v1/dodo/webhook/copyN    (a gateway copy, its own Dodo business)
 *
 * Signed with Standard Webhooks; the gateway's live and test secrets are both tried,
 * and the one that verifies decides which Dodo environment the event is from.
 * Replays are refused by the 5-minute timestamp window and by claiming the
 * webhook-id in WebhookLog, so a retried delivery is applied once.
 */
final class Webhook
{
    public static function register(): void
    {
        register_rest_route('stackless/v1', '/dodo/webhook(?:/(?P<copy>copy[0-9]+))?', [
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

    /** @return array{id:string,timestamp:string,signature:string} */
    public static function headers(\WP_REST_Request $request): array
    {
        return [
            'id' => (string) $request->get_header('webhook_id'),
            'timestamp' => (string) $request->get_header('webhook_timestamp'),
            'signature' => (string) $request->get_header('webhook_signature'),
        ];
    }

    /** live|test for the secret that signed this body, or '' when none did. */
    public static function modeFor(string $body, array $headers, Gateway $gateway, ?int $now = null): string
    {
        foreach (['live', 'test'] as $mode) {
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
            return new \WP_Error('stackless_dodo_webhook_unknown', 'Unknown Dodo Payments gateway.', ['status' => 404]);
        }
        if (self::modeFor((string) $request->get_body(), self::headers($request), $gateway) === '') {
            $gateway->log('Rejected a Dodo Payments webhook with an invalid or expired signature.', 'warning');
            return new \WP_Error('stackless_dodo_webhook_invalid', 'Invalid Dodo Payments webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = self::gatewayFor($request);
        if (!$gateway) {
            return new \WP_REST_Response(['message' => 'Unknown Dodo Payments gateway.'], 404);
        }
        $body = (string) $request->get_body();
        $headers = self::headers($request);
        $mode = self::modeFor($body, $headers, $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Dodo Payments webhook signature.'], 401);
        }

        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['type'])) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        $type = (string) $event['type'];
        $data = (array) ($event['data'] ?? []);
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $eventKey = $gateway->id . ':' . $mode . ':' . $headers['id'];
        $claim = WebhookLog::claim($eventKey, $type, EventMap::resourceId($data));
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
                // and answer non-2xx so Dodo delivers it again instead of it being dropped.
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Dodo ' . $type . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Dodo ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_dodo_gateway_webhook', $type, $data, $mode, $gateway->id);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Dodo webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    public static function dispatch(Gateway $gateway, string $handler, string $type, array $data, string $mode): string
    {
        $sync = new Sync($gateway);
        switch ($handler) {
            case 'payment':
                return $sync->confirmPayment($data, $mode);
            case 'refund':
                return $sync->recordRefund($data);
            case 'dispute':
                return $sync->noteDispute($type, $data);
            case 'renewed':
                // Arrives with payment.succeeded; whichever lands first records the renewal once.
                $outcome = '';
                $paymentId = (string) ($data['payment_id'] ?? '');
                if ($paymentId !== '') {
                    $payment = $gateway->api($mode)->request('GET', 'payments/' . rawurlencode($paymentId));
                    if (is_wp_error($payment)) {
                        throw new \RuntimeException(esc_html($payment->get_error_message()));
                    }
                    $outcome = $sync->confirmPayment($payment, $mode);
                    if ($outcome === 'busy') {
                        return 'busy';
                    }
                }
                $synced = $sync->syncSubscription($data, $mode, $type);
                return $outcome !== '' ? $outcome . '; ' . $synced : $synced;
            case 'subscription':
                return $sync->syncSubscription($data, $mode, $type);
            default:
                return 'ignored';
        }
    }
}
