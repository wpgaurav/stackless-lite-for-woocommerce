<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Revolut Merchant API client. Live and sandbox are separate hosts with separate
 * accounts and keys, so a key is only ever sent to the host of the mode it was entered for.
 *
 * Only refunds take an Idempotency-Key. Creating and paying an order do not, so Attempts
 * records each one before it is sent and Remote::resolve() reads it back before another.
 */
class Api
{
    public const VERSION = '2026-09-22';
    public const HOSTS = [
        'live' => 'https://merchant.revolut.com',
        'test' => 'https://sandbox-merchant.revolut.com',
    ];

    /** Longest Idempotency-Key Revolut accepts. */
    private const KEY_LENGTH = 50;

    /** @var string */
    private $mode;

    /** @var string */
    private $key;

    public function __construct(string $mode, string $key)
    {
        $this->mode = $mode === 'live' ? 'live' : 'test';
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

    public static function host(string $mode): string
    {
        return self::HOSTS[$mode === 'live' ? 'live' : 'test'];
    }

    /** Revolut ids are UUIDs, or UUID-shaped. */
    public static function isId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F-]{20,40}$/', $id);
    }

    /**
     * @param string              $method GET, POST, PATCH or DELETE.
     * @param string              $path   Path below /api/, e.g. "orders".
     * @param array<string,mixed> $data   JSON body for writes, query arguments for GET.
     * @return array<int|string,mixed>|\WP_Error
     */
    public function request(string $method, string $path, array $data = [], string $idempotencyKey = '')
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_revolut_key', $this->mode === 'test'
                ? __('Revolut is not connected for test mode. Add a sandbox secret key in the Revolut settings.', 'stackless-lite')
                : __('Revolut is not connected. Add a live secret key in the Revolut settings.', 'stackless-lite'), ['status' => 0, 'code' => 'no_key']);
        }
        if (strpos($this->key, 'pk_') === 0) {
            return new \WP_Error('stackless_revolut_key', __('That is a Revolut public key. Paste the secret key, which starts with sk_.', 'stackless-lite'), ['status' => 0, 'code' => 'public_key']);
        }

        $method = strtoupper($method);
        $url = self::host($this->mode) . '/api/' . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 25,
            // The secret key must never follow a redirect to another host.
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
                'Accept' => 'application/json',
                'Revolut-Api-Version' => self::VERSION,
            ],
        ];
        if ($method === 'GET') {
            if ($data) {
                $url .= '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            }
        } elseif ($data) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }
        if ($method === 'POST' && $idempotencyKey !== '') {
            $args['headers']['Idempotency-Key'] = substr($idempotencyKey, 0, self::KEY_LENGTH);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_revolut_http', sprintf(__('Could not reach Revolut: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0, 'code' => 'unreachable']);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        if ($status >= 300 || $status < 200) {
            $body = is_array($body) ? $body : [];
            $code = isset($body['code']) && is_scalar($body['code']) ? (string) preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $body['code']) : '';
            return new \WP_Error('stackless_revolut_api', self::errorMessage($body, $status, $this->mode), [
                'status' => $status,
                'code' => $code !== '' ? substr($code, 0, 64) : 'http_' . $status,
            ]);
        }
        if (!is_array($body)) {
            // Revolut accepted the request and we cannot read what it did: treat it as unknown.
            return new \WP_Error('stackless_revolut_body', __('Revolut sent an answer that could not be read.', 'stackless-lite'), ['status' => 0, 'code' => 'unreadable']);
        }
        return $body;
    }

    /**
     * Errors are {"code": "...", "message": "...", "timestamp": ...}; message is not always there.
     *
     * @param array<int|string,mixed> $body
     */
    public static function errorMessage(array $body, int $status, string $mode = 'live'): string
    {
        if ($status === 401) {
            return $mode === 'test'
                ? __('Revolut rejected the sandbox secret key. Keys from the live account do not work in the sandbox.', 'stackless-lite')
                : __('Revolut rejected the live secret key. Check the key, and that it was created without request signing, which Stackless does not support.', 'stackless-lite');
        }
        if ($status === 403) {
            return __('Revolut refused the request. The secret key needs read and write access to orders, payments, customers and webhooks, and read access to disputes, and must not require request signing.', 'stackless-lite');
        }
        $message = isset($body['message']) && is_string($body['message']) ? self::text(wp_strip_all_tags($body['message']), 200) : '';
        return $message !== ''
            /* translators: %s: Revolut error message */
            ? sprintf(__('Revolut: %s', 'stackless-lite'), $message)
            /* translators: %d: HTTP status code */
            : sprintf(__('Revolut returned an unexpected error (HTTP %d).', 'stackless-lite'), $status);
    }

    /** HTTP status of an error from request() (0 = no usable answer). */
    public static function status(\WP_Error $error): int
    {
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    /** Revolut's error code, for notes and decline details. */
    public static function code(\WP_Error $error): string
    {
        $data = $error->get_error_data();
        return is_array($data) ? (string) ($data['code'] ?? '') : '';
    }

    /**
     * True when the outcome is unknown or may differ later: no answer, conflicts, rate limits,
     * Revolut-side failures, redirects, and key problems the store can fix.
     */
    public static function isTransient(\WP_Error $error): bool
    {
        $status = self::status($error);
        return $status < 400 || $status >= 500 || in_array($status, [401, 403, 408, 409, 425, 429], true);
    }

    /** A trimmed string of at most $length characters. */
    public static function text(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
