<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Square REST client. Production and sandbox are separate hosts with separate
 * access tokens, so a token only ever works in the mode it was made for.
 */
class Api
{
    public const BASES = [
        'live' => 'https://connect.squareup.com/v2/',
        'test' => 'https://connect.squareupsandbox.com/v2/',
    ];

    /** Square API version this integration was written against. */
    public const VERSION = '2026-09-16';

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

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' (WordPress/' . (string) $wp_version . '; +' . home_url('/') . ')';
    }

    /**
     * @param string $method GET, POST, PUT or DELETE.
     * @param string $path   Path below /v2/, e.g. "payments".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasToken()) {
            return new \WP_Error('stackless_square_token', $this->mode === 'test'
                ? __('Square is not connected for sandbox mode. Add a sandbox access token in WooCommerce → Settings → Payments → Square.', 'stackless-lite')
                : __('Square is not connected. Add a production access token in WooCommerce → Settings → Payments → Square.', 'stackless-lite'), ['status' => 0]);
        }
        $method = strtoupper($method);
        $url = self::BASES[$this->mode] . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Square-Version' => self::VERSION,
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
            $args['body'] = $data ? wp_json_encode($data) : '{}';
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_square_http', sprintf(__('Could not reach Square: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        $body = is_array($body) ? $body : [];
        if ($code >= 400 || !empty($body['errors'])) {
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            return new \WP_Error('stackless_square_api', self::errorMessage($first, $code, $this->mode), [
                'status' => $code,
                'code' => (string) ($first['code'] ?? ''),
                'category' => (string) ($first['category'] ?? ''),
                // A declined payment may come back with the payment object (status FAILED).
                'payment' => is_array($body['payment'] ?? null) ? $body['payment'] : null,
            ]);
        }
        return $body;
    }

    /** Square errors are {"errors": [{"category", "code", "detail", "field"}]}. */
    public static function errorMessage(array $error, int $code, string $mode = 'live'): string
    {
        if ($code === 401 || ($error['category'] ?? '') === 'AUTHENTICATION_ERROR') {
            return $mode === 'test'
                ? __('Square rejected the sandbox access token. Copy the Sandbox access token from your application in the Square Developer Console.', 'stackless-lite')
                : __('Square rejected the production access token. Copy the Production access token from your application in the Square Developer Console.', 'stackless-lite');
        }
        $detail = trim((string) ($error['detail'] ?? ''));
        return $detail !== ''
            /* translators: %s: Square error message */
            ? sprintf(__('Square: %s', 'stackless-lite'), $detail)
            /* translators: %d: HTTP status code */
            : sprintf(__('Square returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    public static function status(\WP_Error $error): int
    {
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    public static function code(\WP_Error $error): string
    {
        $data = $error->get_error_data();
        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }

    /** True for errors worth retrying with the same idempotency key. */
    public static function isTransient(\WP_Error $error): bool
    {
        $status = self::status($error);
        return $status === 0 || $status === 429 || $status >= 500 || in_array(self::code($error), ['TEMPORARY_ERROR', 'RATE_LIMITED', 'INTERNAL_SERVER_ERROR', 'SERVICE_UNAVAILABLE', 'GATEWAY_TIMEOUT', 'BAD_GATEWAY', 'REQUEST_TIMEOUT'], true);
    }

    /** Idempotency key within Square's 45-character limit. */
    public static function key(string $scope, string $suffix = ''): string
    {
        $key = 'sl-' . $scope . ($suffix !== '' ? '-' . $suffix : '');
        return strlen($key) <= 45 ? $key : 'sl-' . substr(hash('sha256', $key), 0, 42);
    }
}
