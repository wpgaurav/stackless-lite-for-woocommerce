<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/revolut/webhook/{live|test}
 *
 * One route per mode, each with its own signing secret. A signed event only says what to read:
 * the order or dispute is fetched from the API with the store's key and that answer is applied.
 * Revolut retries on a 4xx, so anything worth retrying answers 409.
 */
final class Webhook
{
    public const ROUTE = '/revolut/webhook/';

    public const EVENTS = [
        'ORDER_COMPLETED',
        'ORDER_AUTHORISED',
        'ORDER_CANCELLED',
        'ORDER_FAILED',
        'ORDER_PAYMENT_AUTHENTICATION_CHALLENGED',
        'ORDER_PAYMENT_DECLINED',
        'ORDER_PAYMENT_FAILED',
        'DISPUTE_ACTION_REQUIRED',
        'DISPUTE_UNDER_REVIEW',
        'DISPUTE_WON',
        'DISPUTE_LOST',
    ];

    public static function register(): void
    {
        // Two routes, not a "mode" parameter: a parameter could be overridden from the body.
        foreach (['live', 'test'] as $mode) {
            register_rest_route('stackless/v1', self::ROUTE . $mode, [
                'methods' => 'POST',
                'callback' => static function (\WP_REST_Request $request) use ($mode) {
                    return self::handle($request, $mode);
                },
                'permission_callback' => static function (\WP_REST_Request $request) use ($mode) {
                    return self::authorize($request, $mode);
                },
            ]);
        }
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request, string $mode)
    {
        $secret = Settings::webhookSecret($mode);
        if ($secret === '') {
            return new \WP_Error('stackless_revolut_webhook_unset', 'No signing secret is saved for this endpoint.', ['status' => 401]);
        }
        $timestamp = trim((string) $request->get_header('revolut-request-timestamp'));
        if (!Signature::fresh($timestamp)) {
            return new \WP_Error('stackless_revolut_webhook_stale', 'Missing or stale timestamp.', ['status' => 403]);
        }
        if (!Signature::valid((string) $request->get_body(), $timestamp, (string) $request->get_header('revolut-signature'), $secret)) {
            return new \WP_Error('stackless_revolut_webhook_signature', 'Invalid signature.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request, string $mode): \WP_REST_Response
    {
        $raw = (string) $request->get_body();
        $event = json_decode($raw, true);
        if (!is_array($event) || !isset($event['event']) || !is_string($event['event'])) {
            return new \WP_REST_Response(['message' => 'Expected a Revolut event.'], 400);
        }
        $type = $event['event'];
        $dispute = strpos($type, 'DISPUTE_') === 0;
        $resource = $event[$dispute ? 'dispute_id' : 'order_id'] ?? '';
        if (strpos($type, 'ORDER_') !== 0 && !$dispute) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        if (!is_string($resource) || !Api::isId($resource)) {
            return new \WP_REST_Response(['message' => 'Expected a Revolut id.'], 400);
        }

        $timestamp = trim((string) $request->get_header('revolut-request-timestamp'));
        $key = self::eventKey($raw, $timestamp, $mode);
        $claim = WebhookLog::claim($key, $type, $resource);
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = $dispute ? self::dispute($resource, $mode) : self::order($resource, $mode);
            if ($outcome === 'retry' || $outcome === 'busy') {
                WebhookLog::finish($key, 'failed', $outcome === 'busy' ? 'order locked by another request' : 'could not be applied yet');
                return new \WP_REST_Response(['message' => 'Retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($key, 'done', $outcome);
            Settings::log('Revolut ' . $type . ' ' . $resource . ' (' . $mode . '): ' . $outcome);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($key, 'failed', $e->getMessage());
            Settings::log('Revolut webhook for ' . $resource . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed; retry later.'], 409);
        }
    }

    /** One claim per signed delivery: the same bytes at the same time are the same delivery. */
    public static function eventKey(string $body, string $timestamp, string $mode): string
    {
        return 'revolut:' . $mode . ':' . hash('sha256', $timestamp . '.' . $body);
    }

    private static function order(string $id, string $mode): string
    {
        $api = Settings::api($mode);
        $remote = Remote::read($api, $id);
        if (is_wp_error($remote)) {
            return Api::status($remote) === 404 ? 'ignored: unknown to Revolut' : 'retry';
        }
        $type = (string) ($remote['type'] ?? 'payment');
        $sync = new Sync();
        if ($type === 'refund') {
            $related = Remote::read($api, (string) ($remote['related_order_id'] ?? ''));
            if (is_wp_error($related)) {
                return Api::status($related) === 404 ? 'ignored: refund without a payment' : 'retry';
            }
            $orderId = self::storeOrder($related, $mode);
            return $orderId ? $sync->applyRefund($orderId, $remote, (string) $related['id'], $mode) : 'ignored: no matching order';
        }
        if ($type !== 'payment') {
            return 'ignored: ' . substr((string) preg_replace('/[^a-z_]/', '', $type), 0, 30);
        }
        $user = absint(Remote::metadata($remote)[Keys::REMOTE_USER] ?? 0);
        if ($user > 0 && Adds::byId($user, $id, $mode)) {
            $added = $sync->applyAdd($user, $id, $mode);
            return $added['state'] === 'retry' ? 'retry' : 'add card: ' . $added['state'];
        }
        $orderId = self::storeOrder($remote, $mode);
        return $orderId ? $sync->apply($orderId, $id, $mode) : 'ignored: no matching order';
    }

    private static function dispute(string $id, string $mode): string
    {
        $api = Settings::api($mode);
        $dispute = $api->request('GET', 'disputes/' . rawurlencode($id));
        if (is_wp_error($dispute)) {
            return Api::status($dispute) === 404 ? 'ignored: unknown dispute' : 'retry';
        }
        $payment = isset($dispute['payment']) && is_array($dispute['payment']) ? $dispute['payment'] : [];
        $remote = Remote::read($api, (string) ($payment['order_id'] ?? ''));
        if (is_wp_error($remote)) {
            return Api::status($remote) === 404 ? 'ignored: dispute without a payment' : 'retry';
        }
        $orderId = self::storeOrder($remote, $mode);
        return $orderId ? (new Sync())->applyDispute($orderId, $dispute) : 'ignored: no matching order';
    }

    /**
     * The store order of a Revolut order: named in its metadata, and listing it as an attempt.
     *
     * @param array<string,mixed> $remote
     */
    private static function storeOrder(array $remote, string $mode): int
    {
        $orderId = absint(Remote::metadata($remote)[Keys::REMOTE_ORDER] ?? 0);
        return $orderId > 0 && Orders::exists($orderId) && Attempts::match($orderId, $remote, $mode) ? $orderId : 0;
    }
}
