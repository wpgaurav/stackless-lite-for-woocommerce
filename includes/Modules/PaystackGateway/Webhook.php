<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/paystack/webhook
 *
 * Verified with the Paystack-Signature header and the webhook signing secret (live and
 * test are both tried), or with the legacy x-paystack-signature and the secret key. The
 * secret that verifies decides which mode the event is from. Charges are claimed in
 * WebhookLog by transaction id so a retried delivery is applied once; refund events are
 * reconciled against Paystack's refund list, which is safe to repeat.
 */
final class Webhook
{
    public const LAST_SEEN = 'stackless_paystack_gateway_last_webhook';

    public static function register(): void
    {
        register_rest_route('stackless/v1', '/paystack/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** @return array{signature:string,legacy:string} */
    public static function headers(\WP_REST_Request $request): array
    {
        return [
            'signature' => (string) $request->get_header('paystack_signature'),
            'legacy' => (string) $request->get_header('x_paystack_signature'),
        ];
    }

    /** live|test for the secret that signed this body, or '' when none did. */
    public static function modeFor(string $body, array $headers, Gateway $gateway, ?int $now = null): string
    {
        foreach (['live', 'test'] as $mode) {
            $secret = $gateway->webhookSecret($mode);
            if ($secret !== '' && Signature::verify($body, (string) ($headers['signature'] ?? ''), $secret, $now)) {
                return $mode;
            }
        }
        foreach (['live', 'test'] as $mode) {
            $key = $gateway->secretKey($mode);
            // Restricted keys never sign; only a full secret key does.
            if (strpos($key, 'sk_') === 0 && Signature::verifyLegacy($body, (string) ($headers['legacy'] ?? ''), $key)) {
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
            $gateway->log('Rejected a Paystack webhook with an invalid or expired signature.', 'warning');
            return new \WP_Error('stackless_paystack_webhook_invalid', 'Invalid Paystack webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $gateway = Gateway::instance();
        $body = (string) $request->get_body();
        $mode = self::modeFor($body, self::headers($request), $gateway);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Paystack webhook signature.'], 401);
        }
        return self::process($gateway, $body, $mode);
    }

    /** Apply a verified delivery. Public so the staging simulator can drive it. */
    public static function process(Gateway $gateway, string $body, string $mode): \WP_REST_Response
    {
        $event = json_decode($body, true);
        if (!is_array($event) || empty($event['event']) || !is_array($event['data'] ?? null)) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        self::seen($mode);
        $type = (string) $event['event'];
        $data = (array) $event['data'];
        $handler = EventMap::handler($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $key = EventMap::eventKey($type, $data);
        // Events without a stable id get a one-off key: they are logged but never deduplicated.
        $eventKey = $mode . ':' . ($key !== '' ? $key : $type . ':' . EventMap::resourceId($data) . ':' . microtime(true) . ':' . wp_rand());
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
                WebhookLog::finish($eventKey, 'failed', 'order locked by another request');
                $gateway->log('Paystack ' . $type . ' (' . $mode . ') deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventKey, 'done', $outcome);
            $gateway->log('Paystack ' . $type . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_paystack_gateway_webhook', $type, $data, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventKey, 'failed', $e->getMessage());
            $gateway->log('Paystack webhook ' . $type . ' failed: ' . $e->getMessage(), 'error');
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
                return $sync->reconcileRefunds($data, $mode);
            case 'dispute':
                return $sync->noteDispute($type, $data);
            default:
                return 'ignored';
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
