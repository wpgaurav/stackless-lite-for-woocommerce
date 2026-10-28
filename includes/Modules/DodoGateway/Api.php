<?php

namespace Stackless\Modules\DodoGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Dodo Payments REST client. Live and test mode are separate Dodo
 * environments with separate API keys; a live key is refused by test mode.
 */
class Api
{
    public const BASES = [
        'live' => 'https://live.dodopayments.com/',
        'test' => 'https://test.dodopayments.com/',
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
     * @param string $method GET, POST, PATCH or DELETE.
     * @param string $path   Path below the host, e.g. "checkouts".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_dodo_key', $this->mode === 'test'
                ? __('Dodo Payments is not connected for test mode. Add a test API key in WooCommerce → Settings → Payments → Dodo Payments.', 'stackless-lite')
                : __('Dodo Payments is not connected. Add a live API key in WooCommerce → Settings → Payments → Dodo Payments.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASES[$this->mode] . ltrim($path, '/');
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

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_dodo_http', sprintf(__('Could not reach Dodo Payments: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 400) {
            return new \WP_Error('stackless_dodo_api', self::errorMessage(is_array($body) ? $body : [], $code, $this->mode), ['status' => $code]);
        }
        return is_array($body) ? $body : [];
    }

    /** Dodo errors are {"code": "...", "message": "..."}. */
    public static function errorMessage(array $body, int $code, string $mode = 'live'): string
    {
        if ($code === 401) {
            return $mode === 'test'
                ? __('Dodo Payments rejected the test mode API key. Create the key with Test Mode switched on in the Dodo dashboard; a live key does not work here.', 'stackless-lite')
                : __('Dodo Payments rejected the live API key. Check that it was created in live mode and has not been deleted.', 'stackless-lite');
        }
        $message = trim((string) ($body['message'] ?? ''));
        return $message !== ''
            /* translators: %s: Dodo Payments error message */
            ? sprintf(__('Dodo Payments: %s', 'stackless-lite'), $message)
            /* translators: %d: HTTP status code */
            : sprintf(__('Dodo Payments returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    /** True when an error says a product in the cart no longer exists (archived or deleted in Dodo). */
    public static function isProductError(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        return in_array($status, [404, 422], true) && stripos($error->get_error_message(), 'product') !== false;
    }
}
