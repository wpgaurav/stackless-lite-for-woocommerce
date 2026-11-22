<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/airwallex/webhook
 *
 * Airwallex signs "{x-timestamp}{raw body}" with HMAC-SHA256 and the endpoint's secret. The
 * secret that matches also says whether the event is sandbox or live. An event only names what
 * to read: the object is fetched from the Airwallex API before an order changes.
 *
 * One Airwallex account can serve several sites and plugins. Anything whose reference is not
 * this store's is answered 200 and left alone.
 */
final class Webhook
{
    public const REST_NAMESPACE = 'stackless/v1';
    public const ROUTE = '/airwallex/webhook';

    /**
     * x-timestamp is in milliseconds. Airwallex leaves the tolerance to the receiver and retries
     * for about three days, so the window covers that; event ids are kept far longer than this.
     */
    public const MAX_AGE = 345600;
    public const MAX_AHEAD = 300;

    /** The events to select in Airwallex. */
    public const EVENTS = [
        'checkout_session.completed',
        'checkout_session.cancelled',
        'payment_intent.succeeded',
        'payment_intent.pending',
        'payment_intent.pending_review',
        'payment_intent.payment_failed',
        'payment_intent.cancelled',
        'payment_consent.disabled',
        'refund.received',
        'refund.accepted',
        'refund.settled',
        'refund.failed',
        'payment_dispute.requires_response',
        'payment_dispute.challenged',
        'payment_dispute.accepted',
        'payment_dispute.expired',
        'payment_dispute.pending_closure',
        'payment_dispute.pending_decision',
        'payment_dispute.won',
        'payment_dispute.lost',
        'payment_dispute.reversed',
    ];

    public static function register(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    public static function signature(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . $body, $secret);
    }

    /**
     * The mode whose secret signed the request.
     *
     * @return string|\WP_Error live|test
     */
    public static function verify(\WP_REST_Request $request)
    {
        $secrets = array_filter(['live' => Settings::secret('live'), 'test' => Settings::secret('test')]);
        if (!$secrets) {
            return new \WP_Error('stackless_airwallex_webhook_secret', 'The webhook secret is not set.', ['status' => 503]);
        }
        $timestamp = (string) $request->get_header('x-timestamp');
        $signature = (string) $request->get_header('x-signature');
        if ($signature === '' || !preg_match('/^\d{1,16}$/D', $timestamp)) {
            return new \WP_Error('stackless_airwallex_webhook_signature', 'Missing webhook signature.', ['status' => 401]);
        }
        $body = (string) $request->get_body();
        foreach ($secrets as $mode => $secret) {
            if (!hash_equals(self::signature($timestamp, $body, $secret), $signature)) {
                continue;
            }
            $age = time() - (int) floor((int) $timestamp / 1000);
            if ($age > self::MAX_AGE || $age < -self::MAX_AHEAD) {
                return new \WP_Error('stackless_airwallex_webhook_expired', 'The webhook timestamp is out of range.', ['status' => 401]);
            }
            return (string) $mode;
        }
        return new \WP_Error('stackless_airwallex_webhook_signature', 'Invalid webhook signature.', ['status' => 401]);
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        $mode = self::verify($request);
        return is_wp_error($mode) ? $mode : true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $mode = self::verify($request);
        if (is_wp_error($mode)) {
            return new \WP_REST_Response(['message' => 'Invalid webhook.'], 401);
        }
        $event = json_decode((string) $request->get_body(), true);
        $eventId = is_array($event) && is_string($event['id'] ?? null) ? $event['id'] : '';
        $name = is_array($event) && is_string($event['name'] ?? null) ? $event['name'] : '';
        if ($eventId === '' || strlen($eventId) > 180 || $name === '') {
            return new \WP_REST_Response(['message' => 'Expected an Airwallex event.'], 400);
        }
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        if (!in_array($name, self::EVENTS, true)) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }
        $account = (string) ($event['account_id'] ?? '');
        if ($account !== '' && Settings::account($mode) !== '' && $account !== Settings::account($mode)) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $key = $mode . ':' . $eventId;
        $claim = WebhookLog::claim($key, $name, Api::id($object['id'] ?? null));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        try {
            $outcome = self::process($name, $object, $mode);
            if ($outcome === 'busy') {
                WebhookLog::finish($key, 'failed', 'order locked by another request');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            if ($outcome === 'error') {
                WebhookLog::finish($key, 'failed', 'could not read or apply ' . $name);
                return new \WP_REST_Response(['message' => 'Could not read the object; retry later.'], 503);
            }
            WebhookLog::finish($key, 'done', $outcome);
            Settings::log(sprintf('Airwallex event %s (%s, %s): %s', $name, $mode, sanitize_text_field($eventId), $outcome));
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($key, 'failed', $e->getMessage());
            Settings::log('Airwallex webhook ' . $name . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    private static function process(string $name, array $object, string $mode): string
    {
        $family = explode('.', $name)[0];
        switch ($family) {
            case 'checkout_session':
                return self::session($object, $mode);
            case 'payment_intent':
                return self::intent($object, $mode);
            case 'refund':
                return self::refund($object, $mode);
            case 'payment_dispute':
                return self::dispute($object, $mode);
            case 'payment_consent':
                return self::consent($object, $mode);
        }
        return 'ignored';
    }

    /** Whether a reference in an event body already says the event is someone else's. */
    private static function foreign($reference): bool
    {
        return is_string($reference) && $reference !== '' && strpos($reference, Settings::REFERENCE_PREFIX . '-') !== 0;
    }

    /**
     * GET an object with the mode's credentials.
     *
     * @return array|string The object, "ignored" when the account has no such object, or "error".
     */
    private static function read(string $path, string $id, string $mode, string $idField = 'id')
    {
        $api = Settings::api($mode);
        if (!$api->configured()) {
            return 'error';
        }
        $object = $api->request('GET', $path . '/' . rawurlencode($id));
        if (is_wp_error($object)) {
            return Api::notFound($object) ? 'ignored' : 'error';
        }
        return (string) ($object[$idField] ?? '') === $id ? $object : 'error';
    }

    /** This store's order for a reference read from the API, in the event's mode and account. */
    private static function order($reference, string $mode): ?Sale
    {
        $sale = is_string($reference) ? Sale::fromReference($reference) : null;
        if (!$sale || $sale->text(Meta::MODE) !== $mode || $sale->text(Meta::ACCOUNT) !== Settings::account($mode)) {
            return null;
        }
        return $sale;
    }

    private static function session(array $body, string $mode): string
    {
        $id = Api::id($body['id'] ?? null);
        if ($id === '') {
            return 'ignored';
        }
        if (array_key_exists('metadata', $body)) {
            $meta = is_array($body['metadata']) ? $body['metadata'] : [];
            $adding = ($meta['stackless_site'] ?? '') === Settings::REFERENCE_PREFIX;
            if (!$adding && (!isset($meta['stackless_reference']) || self::foreign($meta['stackless_reference']))) {
                return 'ignored';
            }
        }
        $session = self::read('checkout/checkout_sessions', $id, $mode);
        if (is_string($session)) {
            return $session;
        }
        $meta = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $sale = self::order($meta['stackless_reference'] ?? null, $mode);
        if ($sale) {
            return (new Sync())->refresh($sale->id(), [(string) ($session['payment_intent_id'] ?? '')]);
        }
        $userId = absint($meta['stackless_user'] ?? 0);
        if ($userId > 0 && ($meta['stackless_site'] ?? '') === Settings::REFERENCE_PREFIX && ($session['status'] ?? '') === 'COMPLETED') {
            $saved = (new Checkout())->applyAdd($userId, $id, $mode);
            return $saved === 'pending' ? 'error' : $saved;
        }
        return 'ignored';
    }

    private static function intent(array $body, string $mode): string
    {
        $id = Api::id($body['id'] ?? null);
        if ($id === '' || self::foreign($body['merchant_order_id'] ?? null)) {
            return 'ignored';
        }
        $intent = self::read('pa/payment_intents', $id, $mode);
        if (is_string($intent)) {
            return $intent;
        }
        $sale = self::order($intent['merchant_order_id'] ?? null, $mode);
        return $sale ? (new Sync())->refresh($sale->id(), [$id]) : 'ignored';
    }

    private static function refund(array $body, string $mode): string
    {
        $id = Api::id($body['id'] ?? null);
        if ($id === '') {
            return 'ignored';
        }
        $refund = self::read('pa/refunds', $id, $mode);
        if (is_string($refund)) {
            return $refund;
        }
        $intentId = Api::id($refund['payment_intent_id'] ?? null);
        $intent = $intentId !== '' ? self::read('pa/payment_intents', $intentId, $mode) : 'ignored';
        if (is_string($intent)) {
            return $intent;
        }
        $sale = self::order($intent['merchant_order_id'] ?? null, $mode);
        return $sale ? (new Refunds())->syncInbound($sale->id(), $intent) : 'ignored';
    }

    private static function dispute(array $body, string $mode): string
    {
        $id = Api::id($body['id'] ?? ($body['dispute_id'] ?? null));
        $intentId = Api::id($body['payment_intent_id'] ?? null);
        if ($id === '' || self::foreign($body['merchant_order_id'] ?? null)) {
            return 'ignored';
        }
        if ($intentId === '') {
            $dispute = self::read('pa/payment_disputes', $id, $mode);
            if (is_string($dispute)) {
                return $dispute;
            }
            $intentId = Api::id($dispute['payment_intent_id'] ?? null);
            if ($intentId === '') {
                return 'ignored';
            }
        }
        // The payment first: reading disputes needs a permission, and most may be someone else's.
        $intent = self::read('pa/payment_intents', $intentId, $mode);
        if (is_string($intent)) {
            return $intent;
        }
        $sale = self::order($intent['merchant_order_id'] ?? null, $mode);
        if (!$sale) {
            return 'ignored';
        }
        $dispute = self::read('pa/payment_disputes', $id, $mode);
        if (is_string($dispute)) {
            return $dispute;
        }
        if ((string) ($dispute['payment_intent_id'] ?? '') !== $intentId) {
            return 'ignored';
        }
        return (new Disputes())->apply($sale->id(), $dispute);
    }

    private static function consent(array $body, string $mode): string
    {
        $id = Api::id($body['id'] ?? null);
        if ($id === '' || !Tokens::find($id)) {
            return 'ignored';
        }
        $consent = self::read('pa/payment_consents', $id, $mode);
        if (is_string($consent)) {
            return $consent;
        }
        if (($consent['status'] ?? '') !== 'DISABLED') {
            return 'ignored';
        }
        return 'removed ' . Tokens::forget($id, $mode) . ' saved card(s)';
    }
}
