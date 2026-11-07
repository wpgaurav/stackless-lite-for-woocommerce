<?php

namespace Stackless\Modules\MollieGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Mollie REST client (v2). Test and live mode share one host; the API key decides
 * the mode (test_… or live_…), so a key is only ever used for the mode it was entered for.
 */
class Api
{
    public const BASE = 'https://api.mollie.com/v2/';

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

    /** Whether a key belongs to a mode: test_… for test, live_… for live. */
    public static function keyMatchesMode(string $key, string $mode): bool
    {
        return strpos(trim($key), $mode === 'test' ? 'test_' : 'live_') === 0;
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' (WordPress/' . (string) $wp_version . '; +' . home_url('/') . ')';
    }

    /**
     * @param string $method GET, POST, PATCH or DELETE.
     * @param string $path   Path below /v2/, e.g. "payments".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @param string $idempotencyKey Sent as Idempotency-Key on POST (Mollie keeps it for an hour).
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [], string $idempotencyKey = '')
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_mollie_key', $this->mode === 'test'
                ? __('Mollie is not connected for test mode. Add a test API key in WooCommerce → Settings → Payments → Mollie.', 'stackless-lite')
                : __('Mollie is not connected. Add a live API key in WooCommerce → Settings → Payments → Mollie.', 'stackless-lite'), ['status' => 0]);
        }
        if (!self::keyMatchesMode($this->key, $this->mode)) {
            return new \WP_Error('stackless_mollie_key', $this->mode === 'test'
                ? __('The Mollie test API key must start with test_.', 'stackless-lite')
                : __('The Mollie live API key must start with live_.', 'stackless-lite'), ['status' => 0]);
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
        } elseif ($data) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }
        if ($method === 'POST' && $idempotencyKey !== '') {
            $args['headers']['Idempotency-Key'] = substr($idempotencyKey, 0, 255);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_mollie_http', sprintf(__('Could not reach Mollie: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 400) {
            $body = is_array($body) ? $body : [];
            return new \WP_Error('stackless_mollie_api', self::errorMessage($body, $code, $this->mode), [
                'status' => $code,
                'field' => (string) ($body['field'] ?? ''),
                'title' => (string) ($body['title'] ?? ''),
            ]);
        }
        return is_array($body) ? $body : [];
    }

    /** Mollie errors are {"status": 422, "title": "...", "detail": "...", "field": "..."}. */
    public static function errorMessage(array $body, int $code, string $mode = 'live'): string
    {
        if ($code === 401) {
            return $mode === 'test'
                ? __('Mollie rejected the test API key. Copy the key that starts with test_ from Developers → API keys.', 'stackless-lite')
                : __('Mollie rejected the live API key. Copy the key that starts with live_ from Developers → API keys; live keys work once the account is activated.', 'stackless-lite');
        }
        $detail = trim((string) ($body['detail'] ?? ''));
        return $detail !== ''
            /* translators: %s: Mollie error message */
            ? sprintf(__('Mollie: %s', 'stackless-lite'), $detail)
            /* translators: %d: HTTP status code */
            : sprintf(__('Mollie returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    /** HTTP status of an error from request() (0 = the request never got an answer). */
    public static function status(\WP_Error $error): int
    {
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    /** True for errors worth retrying later: no answer, rate limits and Mollie-side failures. */
    public static function isTransient(\WP_Error $error): bool
    {
        $status = self::status($error);
        return $status === 0 || $status === 429 || $status >= 500;
    }
}
