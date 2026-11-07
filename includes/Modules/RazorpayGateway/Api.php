<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Razorpay REST client (v1, Basic auth with the key id and secret). Test and live
 * share one host; the key id says which mode it is (rzp_test_… or rzp_live_…).
 */
class Api
{
    public const BASE = 'https://api.razorpay.com/v1/';

    /** @var string */
    private $mode;

    /** @var string */
    private $keyId;

    /** @var string */
    private $keySecret;

    public function __construct(string $mode, string $keyId, string $keySecret)
    {
        $this->mode = $mode === 'test' ? 'test' : 'live';
        $this->keyId = trim($keyId);
        $this->keySecret = trim($keySecret);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasKeys(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /** Whether a key id belongs to a mode: rzp_test_… for test, rzp_live_… for live. */
    public static function keyMatchesMode(string $keyId, string $mode): bool
    {
        return strpos(trim($keyId), $mode === 'test' ? 'rzp_test_' : 'rzp_live_') === 0;
    }

    public static function userAgent(): string
    {
        global $wp_version;
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' (WordPress/' . (string) $wp_version . '; +' . home_url('/') . ')';
    }

    /**
     * @param string $method  GET, POST, PUT, PATCH or DELETE.
     * @param string $path    Path below /v1/, e.g. "orders".
     * @param array  $data    JSON body for writes, query arguments for GET.
     * @param array  $headers Extra headers (X-Refund-Idempotency).
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [], array $headers = [])
    {
        if (!$this->hasKeys()) {
            return new \WP_Error('stackless_razorpay_key', $this->mode === 'test'
                ? __('Razorpay is not connected for test mode. Add the test key id and secret in WooCommerce → Settings → Payments → Razorpay.', 'stackless-lite')
                : __('Razorpay is not connected. Add the live key id and secret in WooCommerce → Settings → Payments → Razorpay.', 'stackless-lite'), ['status' => 0]);
        }
        if (!self::keyMatchesMode($this->keyId, $this->mode)) {
            return new \WP_Error('stackless_razorpay_key', $this->mode === 'test'
                ? __('The Razorpay test key id must start with rzp_test_.', 'stackless-lite')
                : __('The Razorpay live key id must start with rzp_live_.', 'stackless-lite'), ['status' => 0]);
        }

        $method = strtoupper($method);
        $url = self::BASE . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => array_merge([
                'Authorization' => 'Basic ' . base64_encode($this->keyId . ':' . $this->keySecret),
                'Accept' => 'application/json',
                'User-Agent' => self::userAgent(),
            ], $headers),
        ];
        if ($method === 'GET') {
            if ($data) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            }
        } elseif ($data) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_razorpay_http', sprintf(__('Could not reach Razorpay: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 400) {
            $body = is_array($body) ? $body : [];
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            return new \WP_Error('stackless_razorpay_api', self::errorMessage($error, $code, $this->mode), [
                'status' => $code,
                'code' => (string) ($error['code'] ?? ''),
                'reason' => (string) ($error['reason'] ?? ''),
                'source' => (string) ($error['source'] ?? ''),
            ]);
        }
        return is_array($body) ? $body : [];
    }

    /** Razorpay errors are {"error": {"code", "description", "source", "step", "reason"}}. */
    public static function errorMessage(array $error, int $code, string $mode = 'live'): string
    {
        if ($code === 401) {
            return $mode === 'test'
                ? __('Razorpay rejected the test key id or secret. Generate test keys in Account & Settings → API keys with Test mode on.', 'stackless-lite')
                : __('Razorpay rejected the live key id or secret. Generate live keys in Account & Settings → API keys.', 'stackless-lite');
        }
        $description = trim((string) ($error['description'] ?? ''));
        return $description !== ''
            /* translators: %s: Razorpay error message */
            ? sprintf(__('Razorpay: %s', 'stackless-lite'), $description)
            /* translators: %d: HTTP status code */
            : sprintf(__('Razorpay returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    public static function status(\WP_Error $error): int
    {
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    /** @return string The error's reason field (e.g. insufficient_funds), or ''. */
    public static function reason(\WP_Error $error): string
    {
        $data = $error->get_error_data();
        return is_array($data) ? (string) ($data['reason'] ?? '') : '';
    }

    /** True for errors worth retrying later: no answer, rate limits and Razorpay-side failures. */
    public static function isTransient(\WP_Error $error): bool
    {
        $status = self::status($error);
        return $status === 0 || $status === 429 || $status >= 500;
    }
}
