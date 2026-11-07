<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Mercado Pago REST client. Test and production share api.mercadopago.com; the
 * access token decides which account (a test user's or the real seller's) a request reaches.
 */
class Api
{
    public const BASE = 'https://api.mercadopago.com/';

    /** @var string */
    private $mode;

    /** @var string */
    private $token;

    public function __construct(string $mode, string $token)
    {
        $this->mode = $mode === 'test' ? 'test' : 'live';
        $this->token = trim($token);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasKey(): bool
    {
        return $this->token !== '';
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' (WordPress/' . (string) $wp_version . '; +' . home_url('/') . ')';
    }

    /**
     * @param string $method        GET or POST.
     * @param string $path          Path below the host, e.g. "checkout/preferences".
     * @param array  $data          JSON body for writes, query arguments for GET.
     * @param string $idempotency   X-Idempotency-Key for writes that must happen once.
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [], string $idempotency = '')
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_mercadopago_key', $this->mode === 'test'
                ? __('Mercado Pago is not connected for test mode. Add your test access token in WooCommerce → Settings → Payments → Mercado Pago.', 'stackless-lite')
                : __('Mercado Pago is not connected. Add your production access token in WooCommerce → Settings → Payments → Mercado Pago.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASE . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'User-Agent' => self::userAgent(),
            ],
        ];
        if ($method === 'GET') {
            if ($data) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            }
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data ?: new \stdClass());
            if ($idempotency !== '') {
                $args['headers']['X-Idempotency-Key'] = $idempotency;
            }
        }

        $response = $this->mode === 'live' ? LiveTransport::send($url, $args) : wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_mercadopago_http', sprintf(__('Could not reach Mercado Pago: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        if ($code >= 400) {
            return new \WP_Error('stackless_mercadopago_api', self::errorMessage($body, $code, $this->mode), ['status' => $code]);
        }
        return $body;
    }

    /** Mercado Pago errors are {"message": "...", "error": "...", "status": n, "cause": [...]}. */
    public static function errorMessage(array $body, int $code, string $mode = 'live'): string
    {
        if ($code === 401) {
            return $mode === 'test'
                ? __('Mercado Pago rejected the test access token. Copy it from Your integrations → your application → Test credentials.', 'stackless-lite')
                : __('Mercado Pago rejected the production access token. Copy it from Your integrations → your application → Production credentials.', 'stackless-lite');
        }
        $message = trim((string) ($body['message'] ?? ''));
        $cause = is_array($body['cause'] ?? null) && isset($body['cause'][0]['description']) ? trim((string) $body['cause'][0]['description']) : '';
        if ($cause !== '' && stripos($message, $cause) === false) {
            $message = trim($message . ' (' . $cause . ')');
        }
        return $message !== ''
            /* translators: %s: Mercado Pago error message */
            ? sprintf(__('Mercado Pago: %s', 'stackless-lite'), $message)
            /* translators: %d: HTTP status code */
            : sprintf(__('Mercado Pago returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }
}
