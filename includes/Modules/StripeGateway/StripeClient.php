<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Stripe REST client on top of the WordPress HTTP API. No SDK.
 *
 * Every request pins Stripe-Version so response shapes never change under us when the
 * merchant upgrades their account's default API version. POSTs carry an idempotency key
 * when the caller provides one, and transient failures are retried with the same key.
 */
final class StripeClient
{
    public const API_BASE = 'https://api.stripe.com/v1/';
    public const API_VERSION = '2024-06-20';

    private const MAX_RETRIES = 2;

    /** @var string */
    private $secret;

    /** @var string test|live */
    private $mode;

    public function __construct(string $secret, string $mode = 'test')
    {
        $this->secret = $secret;
        $this->mode = $mode === 'live' ? 'live' : 'test';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasKey(): bool
    {
        return $this->secret !== '';
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function post(string $path, array $params = [], string $idempotency_key = ''): array
    {
        return $this->request('POST', $path, $params, $idempotency_key);
    }

    /**
     * @return array<string,mixed>
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws StripeApiException
     */
    public function request(string $method, string $path, array $params = [], string $idempotency_key = ''): array
    {
        if ($this->secret === '') {
            throw new StripeApiException(esc_html__('Stripe is not configured: the secret key is missing.', 'stackless-lite'), 401, ['type' => 'authentication_error', 'code' => 'missing_key']);
        }

        $url = self::API_BASE . ltrim($path, '/');
        $body = self::encode($params);
        if ($method === 'GET' && $body !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $body;
            $body = '';
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->secret,
            'Stripe-Version' => self::API_VERSION,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-Stripe-Client-User-Agent' => (string) wp_json_encode([
                'lang' => 'php',
                'publisher' => 'stackless',
                'bindings_version' => defined('STACKLESS_VERSION') ? STACKLESS_VERSION : '0',
            ]),
        ];
        if ($idempotency_key !== '' && $method === 'POST') {
            $headers['Idempotency-Key'] = $idempotency_key;
        }

        $can_retry = $method === 'GET' || $method === 'DELETE' || $idempotency_key !== '';
        $attempt = 0;

        while (true) {
            $response = wp_remote_request($url, [
                'method' => $method,
                'headers' => $headers,
                'body' => $method === 'GET' ? null : $body,
                'timeout' => 45,
                'user-agent' => 'GTExtensionsWooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : '0') . '; ' . home_url('/'),
                'redirection' => 0,
            ]);

            try {
                return $this->parse($response, $method, $path);
            } catch (StripeApiException $e) {
                $should_retry = $can_retry && $attempt < self::MAX_RETRIES && $e->isRetryable();
                if (!is_wp_error($response)) {
                    $hint = wp_remote_retrieve_header($response, 'stripe-should-retry');
                    if ($hint === 'false') {
                        $should_retry = false;
                    } elseif ($hint === 'true' && $can_retry && $attempt < self::MAX_RETRIES) {
                        $should_retry = true;
                    }
                }
                if (!$should_retry) {
                    throw $e;
                }
                $attempt++;
                usleep((int) (250000 * (2 ** $attempt)));
            }
        }
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     * @return array<string,mixed>
     * @throws StripeApiException
     */
    private function parse($response, string $method, string $path): array
    {
        if (is_wp_error($response)) {
            Settings::log(sprintf('Stripe %s %s connection error: %s', $method, self::redactPath($path), $response->get_error_message()), 'error');
            throw StripeApiException::connection(esc_html($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        $request_id = (string) wp_remote_retrieve_header($response, 'request-id');

        if ($status >= 200 && $status < 300 && is_array($data)) {
            Settings::log(sprintf('Stripe %s %s -> %d (%s)', $method, self::redactPath($path), $status, $request_id), 'debug');
            return $data;
        }

        $error = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
        $message = isset($error['message']) ? (string) $error['message'] : sprintf('Unexpected response from Stripe (HTTP %d).', $status);
        if (!$error && $status >= 200 && $status < 300) {
            $status = 502;
            $error = ['type' => 'api_error', 'code' => 'invalid_json'];
        }
        $exception = new StripeApiException($message, $status, $error, $request_id);
        Settings::log(sprintf('Stripe %s %s failed. %s', $method, self::redactPath($path), $exception->logSummary()), $status >= 500 || $status === 0 ? 'error' : 'warning');
        throw $exception;
    }

    /**
     * Form-encodes params the way Stripe expects: nested arrays as a[b][c]=, lists as a[0]=,
     * booleans as "true"/"false", nulls dropped.
     *
     * @param array<string|int,mixed> $params
     */
    public static function encode(array $params, string $prefix = ''): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                if ($value === []) {
                    // An empty value clears a field (e.g. metadata keys) in Stripe.
                    $pairs[] = rawurlencode($name) . '=';
                    continue;
                }
                $nested = self::encode($value, $name);
                if ($nested !== '') {
                    $pairs[] = $nested;
                }
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $pairs);
    }

    /** Strips client secrets from paths before logging. */
    private static function redactPath(string $path): string
    {
        return (string) preg_replace('/_secret_[A-Za-z0-9]+/', '_secret_***', $path);
    }
}
