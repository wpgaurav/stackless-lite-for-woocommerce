<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Flutterwave v3 REST client. Test and live mode share api.flutterwave.com; the
 * secret key (FLWSECK_TEST-… or FLWSECK-…) decides which one a request reaches.
 *
 * v3 is Flutterwave's stable API and the one its own WooCommerce plugin uses; v4 (OAuth
 * client credentials, no hosted checkout) is still a public beta.
 */
class Api
{
    public const BASE = 'https://api.flutterwave.com/v3/';

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
     * @param string $path   Path below /v3/, e.g. "payments".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error The response's "data" object.
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasKey()) {
            return new \WP_Error('stackless_flutterwave_key', $this->mode === 'test'
                ? __('Flutterwave is not connected for test mode. Add your test secret key in WooCommerce → Settings → Payments → Flutterwave.', 'stackless-lite')
                : __('Flutterwave is not connected. Add your live secret key in WooCommerce → Settings → Payments → Flutterwave.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASE . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 45,
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
            return new \WP_Error('stackless_flutterwave_http', sprintf(__('Could not reach Flutterwave: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        if ($code >= 400 || ($body['status'] ?? '') === 'error') {
            return new \WP_Error('stackless_flutterwave_api', self::errorMessage($body, $code, $this->mode), ['status' => $code]);
        }
        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /** Flutterwave errors are {"status": "error", "message": "..."}. */
    public static function errorMessage(array $body, int $code, string $mode = 'live'): string
    {
        if ($code === 401) {
            return $mode === 'test'
                ? __('Flutterwave rejected the test secret key. Copy the v3 secret key that starts with FLWSECK_TEST from Settings → API Keys.', 'stackless-lite')
                : __('Flutterwave rejected the live secret key. Copy the v3 secret key from Settings → API Keys, with the dashboard in live mode.', 'stackless-lite');
        }
        $message = trim((string) ($body['message'] ?? ''));
        return $message !== ''
            /* translators: %s: Flutterwave error message */
            ? sprintf(__('Flutterwave: %s', 'stackless-lite'), $message)
            /* translators: %d: HTTP status code */
            : sprintf(__('Flutterwave returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    /** True when an error says the transaction does not exist (yet). */
    public static function isNotFound(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        return $status === 404 || stripos($error->get_error_message(), 'no transaction') !== false;
    }
}
