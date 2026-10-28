<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Minimal PayPal REST client (OAuth client credentials, Orders v2, Payments v2, Vault v3).
 *
 * Access tokens are cached in a transient per environment and client id, and
 * refreshed once automatically when PayPal answers 401.
 */
class Api
{
    public const LIVE = 'https://api-m.paypal.com';
    public const SANDBOX = 'https://api-m.sandbox.paypal.com';

    /** @var bool */
    private $sandbox;

    /** @var string */
    private $clientId;

    /** @var string */
    private $secret;

    public function __construct(bool $sandbox, string $clientId, string $secret)
    {
        $this->sandbox = $sandbox;
        $this->clientId = trim($clientId);
        $this->secret = trim($secret);
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function hasCredentials(): bool
    {
        return $this->clientId !== '' && $this->secret !== '';
    }

    public function base(): string
    {
        return $this->sandbox ? self::SANDBOX : self::LIVE;
    }

    private function tokenKey(): string
    {
        return 'stackless_paypal_gateway_token_' . md5(($this->sandbox ? 'sandbox' : 'live') . '|' . $this->clientId . '|' . $this->secret);
    }

    public function forgetToken(): void
    {
        delete_transient($this->tokenKey());
    }

    /** @return string|\WP_Error */
    public function accessToken(bool $refresh = false)
    {
        if (!$this->hasCredentials()) {
            return new \WP_Error('stackless_paypal_credentials', __('PayPal is not connected. Add the client ID and secret in WooCommerce → Settings → Payments → PayPal.', 'stackless-lite'));
        }

        $key = $this->tokenKey();
        if (!$refresh) {
            $cached = get_transient($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = wp_remote_post($this->base() . '/v1/oauth2/token', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->secret),
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => self::userAgent(),
            ],
            'body' => 'grant_type=client_credentials',
        ]);

        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_paypal_http', sprintf(__('Could not reach PayPal: %s', 'stackless-lite'), $response->get_error_message()));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body) || empty($body['access_token'])) {
            return new \WP_Error('stackless_paypal_auth', __('PayPal rejected the client ID or secret. Check that they belong to this environment (live or sandbox).', 'stackless-lite'), ['status' => $code]);
        }

        $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - 120);
        set_transient($key, (string) $body['access_token'], $ttl);
        return (string) $body['access_token'];
    }

    /**
     * @param array|null $body    JSON body (null = none).
     * @param array      $headers Extra headers, e.g. PayPal-Request-Id.
     * @return array|\WP_Error Decoded response.
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = [])
    {
        $json = $body === null ? null : (string) wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        return $this->send($method, $path, $json, $headers);
    }

    /**
     * Send a pre-encoded JSON body (used where PayPal needs bytes passed through untouched).
     *
     * @return array|\WP_Error
     */
    public function send(string $method, string $path, ?string $json, array $headers = [], bool $retried = false)
    {
        $token = $this->accessToken($retried);
        if (is_wp_error($token)) {
            return $token;
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 45,
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
                'User-Agent' => self::userAgent(),
            ], $headers),
        ];
        if ($json !== null) {
            $args['body'] = $json;
        }

        $response = wp_remote_request($this->base() . '/' . ltrim($path, '/'), $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_paypal_http', sprintf(__('Could not reach PayPal: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401 && !$retried) {
            return $this->send($method, $path, $json, $headers, true);
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($code >= 400) {
            return new \WP_Error('stackless_paypal_api', self::errorMessage($decoded, $code), [
                'status' => $code,
                'name' => (string) ($decoded['name'] ?? ''),
                'issue' => self::firstIssue($decoded),
                'debug_id' => (string) ($decoded['debug_id'] ?? wp_remote_retrieve_header($response, 'paypal-debug-id')),
            ]);
        }

        return $decoded;
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'GT-Extensions-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' WordPress/' . (string) $wp_version . (defined('WC_VERSION') ? ' WooCommerce/' . WC_VERSION : '');
    }

    public static function firstIssue(array $body): string
    {
        if (!empty($body['details'][0]['issue'])) {
            return (string) $body['details'][0]['issue'];
        }
        return (string) ($body['name'] ?? '');
    }

    /** One readable sentence from PayPal's error shapes. */
    public static function errorMessage(array $body, int $code): string
    {
        $parts = [];
        foreach ((array) ($body['details'] ?? []) as $detail) {
            if (is_array($detail) && !empty($detail['description'])) {
                $parts[] = (string) $detail['description'];
            } elseif (is_array($detail) && !empty($detail['issue'])) {
                $parts[] = (string) $detail['issue'];
            }
        }
        $message = $parts ? implode(' ', array_unique($parts)) : (string) ($body['message'] ?? ($body['error_description'] ?? ''));

        if ($code === 401) {
            return __('PayPal rejected the API credentials.', 'stackless-lite');
        }
        if ($message === '') {
            /* translators: %d: HTTP status code */
            return sprintf(__('PayPal returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
        }
        /* translators: %s: PayPal error description */
        return sprintf(__('PayPal: %s', 'stackless-lite'), $message);
    }

    /* ---- Endpoints ------------------------------------------------------ */

    /** @return array|\WP_Error */
    public function createOrder(array $body, string $requestId = '')
    {
        return $this->request('POST', 'v2/checkout/orders', $body, $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function getOrder(string $id)
    {
        return $this->request('GET', 'v2/checkout/orders/' . rawurlencode($id));
    }

    /** @return array|\WP_Error */
    public function patchOrder(string $id, array $operations)
    {
        $json = (string) wp_json_encode(array_values($operations), JSON_UNESCAPED_SLASHES);
        return $this->send('PATCH', 'v2/checkout/orders/' . rawurlencode($id), $json);
    }

    /** @return array|\WP_Error */
    public function captureOrder(string $id, string $requestId = '')
    {
        return $this->send('POST', 'v2/checkout/orders/' . rawurlencode($id) . '/capture', '{}', $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function authorizeOrder(string $id, string $requestId = '')
    {
        return $this->send('POST', 'v2/checkout/orders/' . rawurlencode($id) . '/authorize', '{}', $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function captureAuthorization(string $authorizationId, array $body, string $requestId = '')
    {
        return $this->request('POST', 'v2/payments/authorizations/' . rawurlencode($authorizationId) . '/capture', $body, $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function voidAuthorization(string $authorizationId)
    {
        return $this->send('POST', 'v2/payments/authorizations/' . rawurlencode($authorizationId) . '/void', null);
    }

    /** @return array|\WP_Error */
    public function refundCapture(string $captureId, array $body, string $requestId = '')
    {
        return $this->request('POST', 'v2/payments/captures/' . rawurlencode($captureId) . '/refund', $body, $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function createSetupToken(array $body, string $requestId = '')
    {
        return $this->request('POST', 'v3/vault/setup-tokens', $body, $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function getSetupToken(string $id)
    {
        return $this->request('GET', 'v3/vault/setup-tokens/' . rawurlencode($id));
    }

    /** @return array|\WP_Error */
    public function createPaymentToken(string $setupTokenId, string $requestId = '')
    {
        return $this->request('POST', 'v3/vault/payment-tokens', [
            'payment_source' => ['token' => ['id' => $setupTokenId, 'type' => 'SETUP_TOKEN']],
        ], $requestId !== '' ? ['PayPal-Request-Id' => $requestId] : []);
    }

    /** @return array|\WP_Error */
    public function deletePaymentToken(string $vaultId)
    {
        return $this->send('DELETE', 'v3/vault/payment-tokens/' . rawurlencode($vaultId), null);
    }

    /**
     * Ask PayPal whether a webhook delivery is genuine.
     *
     * The event is passed through as the raw request body: re-encoding it would
     * change the bytes PayPal signed and fail verification.
     *
     * @return bool|\WP_Error
     */
    public function verifyWebhookSignature(array $headers, string $rawBody, string $webhookId)
    {
        $payload = Webhook::verificationPayload($headers, $rawBody, $webhookId);
        if ($payload === null) {
            return false;
        }
        $response = $this->send('POST', 'v1/notifications/verify-webhook-signature', $payload);
        if (is_wp_error($response)) {
            return $response;
        }
        return Webhook::verificationSucceeded($response);
    }
}
