<?php

namespace Stackless\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Paddle Billing REST client. Live and sandbox are separate Paddle
 * accounts with separate API keys.
 */
class Api
{
    public const BASES = [
        'live' => 'https://api.paddle.com/',
        'sandbox' => 'https://sandbox-api.paddle.com/',
    ];

    /** Paddle Billing API version this client speaks. */
    public const VERSION = '1';

    /** @var string */
    private $mode;

    /** @var string */
    private $key;

    public function __construct(string $mode, string $key)
    {
        $this->mode = $mode === 'sandbox' ? 'sandbox' : 'live';
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
     * @param string $method GET, POST or PATCH.
     * @param string $path   Path such as "transactions" or "subscriptions/sub_…/cancel".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error The response "data" member (plus "_meta" when present).
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_paddle_key', $this->mode === 'sandbox'
                ? __('Paddle is not connected for the sandbox. Add a sandbox API key in WooCommerce → Settings → Payments → Paddle.', 'stackless-lite')
                : __('Paddle is not connected. Add a live API key in WooCommerce → Settings → Payments → Paddle.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASES[$this->mode] . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
                'Accept' => 'application/json',
                'Paddle-Version' => self::VERSION,
                'User-Agent' => self::userAgent(),
            ],
        ];
        if ($method === 'GET') {
            if ($data) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            }
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $data ? wp_json_encode($data) : '{}';
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_paddle_http', sprintf(__('Could not reach Paddle: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        $body = is_array($body) ? $body : [];
        if ($code >= 400) {
            $error = (array) ($body['error'] ?? []);
            return new \WP_Error('stackless_paddle_api', self::errorMessage($error, $code), [
                'status' => $code,
                'code' => (string) ($error['code'] ?? ''),
                'request_id' => (string) ($body['meta']['request_id'] ?? ''),
            ]);
        }
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        if (isset($body['meta']) && is_array($body['meta'])) {
            $data['_meta'] = $body['meta'];
        }
        return $data;
    }

    /** Turn Paddle's error object (detail plus per-field errors) into one sentence. */
    public static function errorMessage(array $error, int $code): string
    {
        $detail = trim((string) ($error['detail'] ?? ''));
        $fields = [];
        foreach ((array) ($error['errors'] ?? []) as $item) {
            if (is_array($item) && !empty($item['message'])) {
                $fields[] = trim((!empty($item['field']) ? (string) $item['field'] . ': ' : '') . (string) $item['message']);
            }
        }
        if ($fields) {
            $detail = trim($detail . ' ' . implode('; ', $fields));
        }
        if ($code === 401 || ($error['code'] ?? '') === 'authentication_malformed') {
            return __('Paddle rejected the API key. Check that it belongs to this environment (live or sandbox) and has not been revoked.', 'stackless-lite');
        }
        if ($code === 403 && $detail === '') {
            return __('The Paddle API key is missing a required permission.', 'stackless-lite');
        }
        return $detail !== ''
            /* translators: %s: Paddle error message */
            ? sprintf(__('Paddle: %s', 'stackless-lite'), $detail)
            /* translators: %d: HTTP status code */
            : sprintf(__('Paddle returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    /** Catches a key or token pasted into the wrong environment. '' when fine. */
    public static function keyProblem(string $mode, string $key, string $clientToken): string
    {
        $live = $mode === 'live';
        if ($key !== '' && strpos($key, $live ? 'pdl_sdbx_' : 'pdl_live_') === 0) {
            return $live
                ? __('The live Paddle API key field holds a sandbox key.', 'stackless-lite')
                : __('The sandbox Paddle API key field holds a live key.', 'stackless-lite');
        }
        if ($clientToken !== '' && strpos($clientToken, $live ? 'test_' : 'live_') === 0) {
            return $live
                ? __('The live Paddle client-side token field holds a sandbox token.', 'stackless-lite')
                : __('The sandbox Paddle client-side token field holds a live token.', 'stackless-lite');
        }
        return '';
    }
}
