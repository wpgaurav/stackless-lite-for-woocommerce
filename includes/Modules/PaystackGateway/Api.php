<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Paystack REST client. Test and live mode share api.paystack.co; the secret key
 * (sk_test_… or sk_live_…) decides which one a request reaches.
 */
class Api
{
    public const BASE = 'https://api.paystack.co/';

    /** @var string */
    private $mode;

    /** @var string */
    private $key;

    public function __construct(string $mode, string $key)
    {
        $this->mode = $mode === 'test' ? 'test' : 'live';
        $this->key = trim($key);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasKey(): bool
    {
        return $this->key !== '';
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' (WordPress/' . (string) $wp_version . '; +' . home_url('/') . ')';
    }

    /**
     * @param string $method GET or POST.
     * @param string $path   Path below the host, e.g. "transaction/initialize".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error The response's "data" object.
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_paystack_key', $this->mode === 'test'
                ? __('Paystack is not connected for test mode. Add your test secret key in WooCommerce → Settings → Payments → Paystack.', 'stackless-lite')
                : __('Paystack is not connected. Add your live secret key in WooCommerce → Settings → Payments → Paystack.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASE . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
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
        }

        $response = $this->mode === 'live' ? LiveTransport::send($url, $args) : wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_paystack_http', sprintf(__('Could not reach Paystack: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        if ($code >= 400 || (isset($body['status']) && $body['status'] === false)) {
            return new \WP_Error('stackless_paystack_api', self::errorMessage($body, $code, $this->mode), ['status' => $code]);
        }
        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /** Paystack errors are {"status": false, "message": "..."}. */
    public static function errorMessage(array $body, int $code, string $mode = 'live'): string
    {
        if ($code === 401) {
            return $mode === 'test'
                ? __('Paystack rejected the test secret key. Copy the key that starts with sk_test from Settings → API Keys & Webhooks.', 'stackless-lite')
                : __('Paystack rejected the live secret key. Copy the key that starts with sk_live from Settings → API Keys & Webhooks.', 'stackless-lite');
        }
        $message = trim((string) ($body['message'] ?? ''));
        return $message !== ''
            /* translators: %s: Paystack error message */
            ? sprintf(__('Paystack: %s', 'stackless-lite'), $message)
            /* translators: %d: HTTP status code */
            : sprintf(__('Paystack returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }
}
