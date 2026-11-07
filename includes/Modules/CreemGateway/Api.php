<?php

namespace Stackless\Modules\CreemGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Creem REST client. Live and test mode are separate Creem environments
 * (api.creem.io and test-api.creem.io) with separate API keys.
 */
class Api
{
    public const BASES = [
        'live' => 'https://api.creem.io/',
        'test' => 'https://test-api.creem.io/',
    ];

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
     * @param string $path   Path below the host, e.g. "v1/checkouts".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_creem_key', $this->mode === 'test'
                ? __('Creem is not connected for test mode. Add a test API key in WooCommerce → Settings → Payments → Creem.', 'stackless-lite')
                : __('Creem is not connected. Add a live API key in WooCommerce → Settings → Payments → Creem.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASES[$this->mode] . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'x-api-key' => $this->key,
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

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_creem_http', sprintf(__('Could not reach Creem: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 400) {
            return new \WP_Error('stackless_creem_api', self::errorMessage(is_array($body) ? $body : [], $code, $this->mode), ['status' => $code]);
        }
        return is_array($body) ? $body : [];
    }

    /** Creem errors are {"status": 400, "error": "Bad Request", "message": ["…"], "trace_id": "…"}. */
    public static function errorMessage(array $body, int $code, string $mode = 'live'): string
    {
        if ($code === 401 || $code === 403) {
            return $mode === 'test'
                ? __('Creem rejected the test API key. Switch the Creem dashboard to test mode, then copy the key that starts with creem_test from Developers → API keys.', 'stackless-lite')
                : __('Creem rejected the live API key, or it lacks a permission this store needs. Copy a live key from Developers → API keys.', 'stackless-lite');
        }
        $message = $body['message'] ?? '';
        $message = trim(is_array($message) ? implode(' ', array_map('strval', $message)) : (string) $message);
        return $message !== ''
            /* translators: %s: Creem error message */
            ? sprintf(__('Creem: %s', 'stackless-lite'), $message)
            /* translators: %d: HTTP status code */
            : sprintf(__('Creem returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    /** True when an error says a product in the checkout no longer exists (archived or deleted in Creem). */
    public static function isProductError(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        return in_array($status, [400, 404], true) && stripos($error->get_error_message(), 'product') !== false;
    }
}
