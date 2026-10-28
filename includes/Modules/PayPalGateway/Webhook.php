<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\OrderQuery;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/stackless/v1/paypal/webhook
 *
 * The permission callback asks PayPal to verify the signature
 * (/v1/notifications/verify-webhook-signature) before anything runs; the event
 * id is then claimed in WebhookLog so replays and concurrent retries apply once.
 */
final class Webhook
{
    public const EVENTS = [
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.DECLINED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
        'VAULT.PAYMENT-TOKEN.CREATED',
        'VAULT.PAYMENT-TOKEN.DELETED',
    ];

    /** Transmissions older than this are refused even with a valid signature (PayPal retries for 3 days). */
    public const MAX_AGE = 345600; // 4 days

    private const HEADERS = [
        'auth_algo' => 'paypal-auth-algo',
        'cert_url' => 'paypal-cert-url',
        'transmission_id' => 'paypal-transmission-id',
        'transmission_sig' => 'paypal-transmission-sig',
        'transmission_time' => 'paypal-transmission-time',
    ];

    public static function register(): void
    {
        register_rest_route('stackless/v1', '/paypal/webhook', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    /** Event type => handler method. Unknown types are acknowledged and ignored. */
    public static function handlerFor(string $type): ?string
    {
        $map = [
            'PAYMENT.CAPTURE.COMPLETED' => 'onCaptureCompleted',
            'PAYMENT.CAPTURE.PENDING' => 'onCapturePending',
            'PAYMENT.CAPTURE.DENIED' => 'onCaptureDenied',
            'PAYMENT.CAPTURE.DECLINED' => 'onCaptureDenied',
            'PAYMENT.CAPTURE.REFUNDED' => 'onCaptureRefunded',
            'PAYMENT.CAPTURE.REVERSED' => 'onCaptureReversed',
            'VAULT.PAYMENT-TOKEN.CREATED' => 'onVaultCreated',
            'VAULT.PAYMENT-TOKEN.DELETED' => 'onVaultDeleted',
        ];
        return $map[strtoupper($type)] ?? null;
    }

    /* ---- Signature ---------------------------------------------------------- */

    /** Lower-cased header map from a request or $_SERVER-style array. */
    public static function headersFrom(\WP_REST_Request $request): array
    {
        $out = [];
        foreach (self::HEADERS as $header) {
            $out[$header] = (string) $request->get_header(str_replace('-', '_', $header));
        }
        return $out;
    }

    /**
     * Body for /v1/notifications/verify-webhook-signature, with the event embedded
     * byte-for-byte. Null when a header is missing or looks forged.
     */
    public static function verificationPayload(array $headers, string $rawBody, string $webhookId): ?string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $fields = [];
        foreach (self::HEADERS as $field => $header) {
            $value = trim((string) ($headers[$header] ?? ''));
            if ($value === '') {
                return null;
            }
            $fields[$field] = $value;
        }
        if ($webhookId === '' || !self::isPayPalCertUrl($fields['cert_url'])) {
            return null;
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return null;
        }
        $fields['webhook_id'] = $webhookId;
        $json = (string) wp_json_encode($fields, JSON_UNESCAPED_SLASHES);
        return substr($json, 0, -1) . ',"webhook_event":' . trim($rawBody) . '}';
    }

    public static function isPayPalCertUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        return $host === 'paypal.com' || substr($host, -strlen('.paypal.com')) === '.paypal.com';
    }

    public static function verificationSucceeded(array $response): bool
    {
        return strtoupper((string) ($response['verification_status'] ?? '')) === 'SUCCESS';
    }

    /** Refuse stale transmissions; PayPal stops retrying after three days. */
    public static function isFresh(string $transmissionTime, ?int $now = null): bool
    {
        $time = strtotime($transmissionTime);
        if (!$time) {
            return false;
        }
        $now = $now ?? time();
        return $time <= $now + 300 && $time >= $now - self::MAX_AGE;
    }

    /** @return true|\WP_Error */
    public static function authorize(\WP_REST_Request $request)
    {
        $gateway = Gateway::instance();
        $webhookId = $gateway->webhookId();
        $headers = self::headersFrom($request);
        if ($webhookId === '') {
            return new \WP_Error('stackless_paypal_webhook_unconfigured', 'Webhook ID not configured.', ['status' => 401]);
        }
        if (!self::isFresh($headers['paypal-transmission-time'])) {
            return new \WP_Error('stackless_paypal_webhook_stale', 'Stale or missing transmission time.', ['status' => 401]);
        }
        $verified = $gateway->api()->verifyWebhookSignature($headers, (string) $request->get_body(), $webhookId);
        if (is_wp_error($verified)) {
            $gateway->log('Webhook verification call failed: ' . $verified->get_error_message(), 'error');
            // PayPal retries on non-2xx, so a temporary outage on the verify call is not fatal.
            return new \WP_Error('stackless_paypal_webhook_unverified', 'Could not verify signature.', ['status' => 503]);
        }
        if (!$verified) {
            $gateway->log('Rejected a PayPal webhook with an invalid signature.', 'warning');
            return new \WP_Error('stackless_paypal_webhook_invalid', 'Invalid signature.', ['status' => 401]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $event = json_decode((string) $request->get_body(), true);
        if (!is_array($event) || empty($event['id'])) {
            return new \WP_REST_Response(['message' => 'Invalid payload.'], 400);
        }

        $eventId = (string) $event['id'];
        $type = strtoupper((string) ($event['event_type'] ?? ''));
        $resource = (array) ($event['resource'] ?? []);
        $handler = self::handlerFor($type);
        if ($handler === null) {
            return new \WP_REST_Response(['received' => true, 'ignored' => true], 200);
        }

        $claim = WebhookLog::claim($eventId, $type, (string) ($resource['id'] ?? ''));
        if ($claim === 'duplicate') {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }
        if ($claim === 'busy') {
            return new \WP_REST_Response(['message' => 'Event is being processed.'], 409);
        }

        $gateway = Gateway::instance();
        try {
            $note = (new self($gateway))->{$handler}($resource);
            if ($note === 'busy') {
                // The order lock never freed up, so nothing was applied. Leave the event open
                // and answer non-2xx so PayPal delivers it again instead of it being dropped.
                WebhookLog::finish($eventId, 'failed', 'order locked by another request');
                $gateway->log('Webhook ' . $type . ' ' . $eventId . ' deferred: the order is locked by another request.', 'warning');
                return new \WP_REST_Response(['message' => 'Order is busy; retry later.', 'retry' => true], 409);
            }
            WebhookLog::finish($eventId, 'done', is_string($note) ? $note : '');
            do_action('stackless_paypal_gateway_webhook', $type, $resource, $event);
            return new \WP_REST_Response(['received' => true], 200);
        } catch (\Throwable $e) {
            WebhookLog::finish($eventId, 'failed', $e->getMessage());
            $gateway->log('Webhook ' . $type . ' ' . $eventId . ' failed: ' . $e->getMessage(), 'error');
            return new \WP_REST_Response(['message' => 'Processing failed.'], 500);
        }
    }

    /* ---- Handlers ------------------------------------------------------------- */

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** WooCommerce order a capture belongs to, or null when it isn't ours. */
    private function orderForCapture(array $capture): ?\WC_Order
    {
        $captureId = (string) ($capture['id'] ?? '');
        $paypalOrderId = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
        $custom = absint($capture['custom_id'] ?? 0);

        if ($custom) {
            $order = wc_get_order($custom);
            if ($order instanceof \WC_Order && $order->get_payment_method() === Gateway::ID) {
                $known = [(string) $order->get_meta(Gateway::META_ORDER_ID), (string) $order->get_meta(Gateway::META_CAPTURE_ID)];
                if (($paypalOrderId !== '' && in_array($paypalOrderId, $known, true)) || ($captureId !== '' && in_array($captureId, $known, true))) {
                    return $order;
                }
            }
        }

        foreach ([[Gateway::META_CAPTURE_ID, $captureId], [Gateway::META_ORDER_ID, $paypalOrderId]] as [$key, $value]) {
            if ($value === '') {
                continue;
            }
            $order = self::findOrderByMeta($key, $value);
            if ($order) {
                return $order;
            }
        }
        return null;
    }

    public static function findOrderByMeta(string $key, string $value): ?\WC_Order
    {
        return OrderQuery::findByMeta($key, $value, ['payment_method' => Gateway::ID]);
    }

    public function onCaptureCompleted(array $capture): string
    {
        $order = $this->orderForCapture($capture);
        if (!$order) {
            return 'not ours';
        }
        $captureId = (string) ($capture['id'] ?? '');

        return (string) Lock::run('order-' . $order->get_id(), function () use ($order, $capture, $captureId) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return 'gone';
            }
            if ($order->is_paid() && (string) $order->get_meta(Gateway::META_CAPTURE_ID) === $captureId) {
                return 'already paid';
            }
            if (!Amount::matches((array) ($capture['amount'] ?? []), $order->get_total(), $order->get_currency())) {
                $order->update_status($order->is_paid() ? $order->get_status() : 'on-hold', sprintf(
                    /* translators: 1: captured amount, 2: order total */
                    __('PayPal reported a capture of %1$s but the order total is %2$s. Check it in PayPal before fulfilling.', 'stackless-lite'),
                    (string) ($capture['amount']['value'] ?? '?') . ' ' . (string) ($capture['amount']['currency_code'] ?? ''),
                    Amount::format($order->get_total(), $order->get_currency()) . ' ' . $order->get_currency()
                ));
                return 'amount mismatch';
            }
            $order->update_meta_data(Gateway::META_CAPTURE_ID, $captureId);
            $order->save();
            if (!$order->is_paid()) {
                /* translators: %s: capture id */
                $order->add_order_note(sprintf(__('PayPal confirmed the payment (%s).', 'stackless-lite'), $captureId));
                $order->payment_complete($captureId);
            } else {
                $order->set_transaction_id($captureId);
                $order->save();
            }
            return 'completed';
        }, 'busy');
    }

    public function onCapturePending(array $capture): string
    {
        $order = $this->orderForCapture($capture);
        if (!$order || $order->is_paid() || $order->has_status('on-hold')) {
            return 'skipped';
        }
        $order->update_meta_data(Gateway::META_CAPTURE_ID, (string) ($capture['id'] ?? ''));
        $order->save();
        $order->update_status('on-hold', sprintf(
            /* translators: %s: PayPal reason code */
            __('PayPal payment is pending (%s).', 'stackless-lite'),
            (string) ($capture['status_details']['reason'] ?? 'PENDING')
        ));
        return 'on-hold';
    }

    public function onCaptureDenied(array $capture): string
    {
        $order = $this->orderForCapture($capture);
        if (!$order) {
            return 'not ours';
        }
        if ($order->is_paid() && !$order->has_status('on-hold')) {
            /* translators: %s: capture id */
            $order->add_order_note(sprintf(__('PayPal reported capture %s as denied after it was marked paid. Check it in PayPal.', 'stackless-lite'), (string) ($capture['id'] ?? '')));
            return 'note';
        }
        /* translators: %s: capture id */
        $order->update_status('failed', sprintf(__('PayPal denied the payment (%s).', 'stackless-lite'), (string) ($capture['id'] ?? '')));
        return 'failed';
    }

    public function onCaptureRefunded(array $refund): string
    {
        $captureId = '';
        foreach ((array) ($refund['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'up' && preg_match('#/captures/([^/?]+)#', (string) ($link['href'] ?? ''), $m)) {
                $captureId = $m[1];
            }
        }
        $order = null;
        $custom = absint($refund['custom_id'] ?? 0);
        if ($custom) {
            $candidate = wc_get_order($custom);
            if ($candidate instanceof \WC_Order && $candidate->get_payment_method() === Gateway::ID && ($captureId === '' || (string) $candidate->get_meta(Gateway::META_CAPTURE_ID) === $captureId)) {
                $order = $candidate;
            }
        }
        if (!$order && $captureId !== '') {
            $order = self::findOrderByMeta(Gateway::META_CAPTURE_ID, $captureId);
        }
        if (!$order) {
            return 'not ours';
        }

        $refundId = (string) ($refund['id'] ?? '');
        $payments = $this->gateway->payments();

        return (string) Lock::run('refund-' . $order->get_id(), function () use ($order, $refund, $refundId, $payments) {
            $order = wc_get_order($order->get_id());
            if (!$order instanceof \WC_Order || $payments->hasRefundId($order, $refundId)) {
                return 'duplicate';
            }
            if (strtoupper((string) ($refund['amount']['currency_code'] ?? '')) !== strtoupper($order->get_currency())) {
                $order->add_order_note(__('PayPal reported a refund in a different currency. Record it manually.', 'stackless-lite'));
                $payments->recordRefundId($order, $refundId);
                return 'currency mismatch';
            }
            $amount = min((float) ($refund['amount']['value'] ?? 0), (float) $order->get_remaining_refund_amount());
            if ($amount <= 0) {
                $payments->recordRefundId($order, $refundId);
                return 'nothing left';
            }
            $created = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => wc_format_decimal($amount, wc_get_price_decimals()),
                /* translators: %s: PayPal refund id */
                'reason' => sprintf(__('Refunded in PayPal (%s)', 'stackless-lite'), $refundId),
                'refund_payment' => false,
                'restock_items' => false,
            ]);
            if (is_wp_error($created)) {
                throw new \RuntimeException(esc_html($created->get_error_message()));
            }
            $payments->recordRefundId($order, $refundId);
            return 'refunded';
        }, 'busy');
    }

    public function onCaptureReversed(array $capture): string
    {
        $order = $this->orderForCapture($capture);
        if (!$order) {
            return 'not ours';
        }
        /* translators: %s: capture id */
        $order->update_status('on-hold', sprintf(__('PayPal reversed payment %s (chargeback or buyer dispute). Review it in PayPal.', 'stackless-lite'), (string) ($capture['id'] ?? '')));
        return 'reversed';
    }

    /** Vault id that PayPal finished saving after a purchase (vault status was APPROVED at capture time). */
    public function onVaultCreated(array $token): string
    {
        $paypalOrderId = (string) ($token['metadata']['order_id'] ?? '');
        $vaultId = (string) ($token['id'] ?? '');
        if ($paypalOrderId === '' || $vaultId === '') {
            return 'no order';
        }
        $order = self::findOrderByMeta(Gateway::META_ORDER_ID, $paypalOrderId);
        if (!$order) {
            return 'not ours';
        }
        if ((string) $order->get_meta(Gateway::META_VAULT_ID) === $vaultId && (string) $order->get_meta(SubscriptionData::META_TOKEN_ID) !== '') {
            return 'already saved';
        }
        $payments = $this->gateway->payments();
        $source = (array) ($token['payment_source']['paypal'] ?? []);
        $customerId = (string) ($token['customer']['id'] ?? '');
        $tokenId = $payments->saveToken((int) $order->get_customer_id(), $vaultId, (string) ($source['email_address'] ?? ''), (string) ($source['account_id'] ?? ''), $customerId, (string) $order->get_meta(Gateway::META_ENV));
        $payments->attachToken($order, $tokenId, $vaultId, $customerId);
        return 'saved';
    }

    /** The buyer removed us in their PayPal account: drop the WooCommerce token so renewals stop trying it. */
    public function onVaultDeleted(array $token): string
    {
        $vaultId = (string) ($token['id'] ?? '');
        if ($vaultId === '') {
            return 'no id';
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col($wpdb->prepare("SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE gateway_id = %s AND token = %s", Gateway::ID, $vaultId));
        foreach ((array) $ids as $id) {
            \WC_Payment_Tokens::delete((int) $id);
        }
        do_action('stackless_paypal_gateway_vault_deleted', $vaultId, array_map('intval', (array) $ids));
        return 'deleted ' . count((array) $ids);
    }
}
