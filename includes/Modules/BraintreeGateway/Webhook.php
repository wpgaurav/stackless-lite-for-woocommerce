<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/braintree/webhook
 *
 * Verified on the posted bt_payload before anything parses it; no keys, no verification. The
 * sandbox and production keys are both tried and the pair that verifies decides the mode. The
 * body only says which payment to read back.
 */
final class Webhook
{
    public const REST_NAMESPACE = 'stackless/v1';
    public const ROUTE = '/braintree/webhook';
    public const LAST_OPTION = 'stackless_braintree_gateway_webhook_seen_';

    /** What to switch on in the Control Panel. */
    public const EVENTS = [
        'dispute_opened',
        'dispute_lost',
        'dispute_won',
        'dispute_accepted',
        'dispute_auto_accepted',
        'dispute_disputed',
        'dispute_expired',
        'dispute_under_review',
        'transaction_settlement_declined',
        'transaction_reviewed',
        'refund_failed',
    ];

    public static function register(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /**
     * The fields from the raw body, untouched by WordPress's slashing.
     *
     * @return array{0:string, 1:string} signature, payload
     */
    public static function posted(string $body): array
    {
        $fields = [];
        parse_str($body, $fields);
        $signature = $fields['bt_signature'] ?? '';
        $payload = $fields['bt_payload'] ?? '';
        return [is_string($signature) ? $signature : '', is_string($payload) ? $payload : ''];
    }

    /** live|test for the keys that signed this body, or ''. */
    public static function modeFor(string $body): string
    {
        [$signature, $payload] = self::posted($body);
        foreach (Config::MODES as $mode) {
            if (Notification::verify($signature, $payload, Config::publicKey($mode), Config::privateKey($mode))) {
                return $mode;
            }
        }
        return '';
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        if (self::modeFor((string) $request->get_body()) === '') {
            Config::log('Rejected a Braintree webhook with a missing or invalid signature.', 'warning');
            return new \WP_Error('stackless_braintree_webhook_invalid', 'Invalid Braintree webhook signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = (string) $request->get_body();
        $mode = self::modeFor($body);
        if ($mode === '') {
            return new \WP_REST_Response(['message' => 'Invalid Braintree webhook signature.'], 401);
        }
        $event = Notification::parse(self::posted($body)[1]);
        if (!$event) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }
        update_option(self::LAST_OPTION . $mode, time(), false);
        if (!in_array($event['kind'], self::EVENTS, true)) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $key = 'braintree:' . $mode . ':' . $event['id'];
        $claim = WebhookLog::claim($key, $event['kind'], $event['transaction']);
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }
        try {
            $outcome = (new Sync())->webhook($event, $mode);
            if ($outcome === 'busy' || $outcome === 'retry') {
                WebhookLog::finish($key, 'failed', $outcome === 'busy' ? 'order locked by another request' : 'could not read the payment from Braintree');
                return new \WP_REST_Response(['message' => 'Not processed; retry later.', 'retry' => true], $outcome === 'busy' ? 409 : 503);
            }
            WebhookLog::finish($key, 'done', $outcome);
            Config::log('Braintree ' . $event['kind'] . ' (' . $mode . '): ' . $outcome);
            do_action('stackless_braintree_gateway_webhook', $event['kind'], $event, $mode);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($key, 'failed', $e->getMessage());
            Config::log('Braintree webhook ' . $event['kind'] . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }
}
