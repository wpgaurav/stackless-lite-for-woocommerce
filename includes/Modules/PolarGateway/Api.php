<?php

namespace Stackless\Modules\PolarGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Polar REST client. Live and sandbox are separate Polar environments
 * with separate organization access tokens.
 *
 * Polar's API sits behind Cloudflare, which blocks some default HTTP client
 * user agents, so every request names this plugin and WordPress explicitly.
 */
class Api
{
    public const BASES = [
        'live' => 'https://api.polar.sh/v1/',
        'sandbox' => 'https://sandbox-api.polar.sh/v1/',
    ];

    /** @var string */
    private $mode;

    /** @var string */
    private $token;

    public function __construct(string $mode, string $token)
    {
        $this->mode = $mode === 'sandbox' ? 'sandbox' : 'live';
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
        return 'GT-Extensions-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev') . ' (WordPress/' . (string) $wp_version . '; +' . home_url('/') . ')';
    }

    /**
     * @param string $method GET, POST, PATCH or DELETE.
     * @param string $path   Path below /v1/, e.g. "checkouts/".
     * @param array  $data   JSON body for writes, query arguments for GET.
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->hasToken()) {
            return new \WP_Error('stackless_polar_token', $this->mode === 'sandbox'
                ? __('Polar is not connected for the sandbox. Add a sandbox access token in WooCommerce → Settings → Payments → Polar.', 'stackless-lite')
                : __('Polar is not connected. Add a live access token in WooCommerce → Settings → Payments → Polar.', 'stackless-lite'));
        }

        $method = strtoupper($method);
        $url = self::BASES[$this->mode] . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
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
            return new \WP_Error('stackless_polar_http', sprintf(__('Could not reach Polar: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = $raw === '' ? [] : json_decode($raw, true);
        if ($code >= 400) {
            return new \WP_Error('stackless_polar_api', self::errorMessage(is_array($body) ? $body : [], $code), ['status' => $code]);
        }
        return is_array($body) ? $body : [];
    }

    /** Turn Polar's error shapes (string detail or FastAPI validation list) into one sentence. */
    public static function errorMessage(array $body, int $code): string
    {
        $detail = $body['detail'] ?? ($body['error'] ?? '');
        if (is_array($detail)) {
            $parts = [];
            foreach ($detail as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $location = isset($item['loc']) && is_array($item['loc']) ? implode('.', array_slice($item['loc'], 1)) : '';
                $parts[] = trim(($location !== '' ? $location . ': ' : '') . (string) ($item['msg'] ?? ''));
            }
            $detail = implode('; ', array_filter($parts));
        }
        if ($code === 401) {
            return __('Polar rejected the access token. Check that it belongs to this environment (live or sandbox) and has not been revoked.', 'stackless-lite');
        }
        if ($code === 403 && !$detail) {
            return __('The Polar access token is missing a required scope.', 'stackless-lite');
        }
        return $detail
            /* translators: %s: Polar error message */
            ? sprintf(__('Polar: %s', 'stackless-lite'), (string) $detail)
            /* translators: %d: HTTP status code */
            : sprintf(__('Polar returned an unexpected error (HTTP %d).', 'stackless-lite'), $code);
    }

    /** True when an error says the referenced product no longer accepts checkouts. */
    public static function isProductError(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        return in_array($status, [404, 422], true) && stripos($error->get_error_message(), 'product') !== false;
    }
}
