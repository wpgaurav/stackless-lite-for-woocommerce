<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Airwallex REST client, pinned to one API version.
 *
 * Sandbox and production are separate hosts with separate credentials. The client id and API key
 * only go to the login endpoint; the 30-minute token it returns is cached per mode and credential
 * fingerprint, and replaced once when Airwallex answers 401.
 *
 * Every error says whether the request may have reached Airwallex, because a charge without an
 * answer is unknown, never failed.
 */
class Api
{
    /** Checkout Session needs 2026-05-29 or later; the payloads this module reads are this version's. */
    public const VERSION = '2026-08-21';

    public const HOSTS = [
        'live' => 'https://api.airwallex.com',
        'test' => 'https://api.sandbox.airwallex.com',
    ];

    /** Error codes that do not prove the request had no effect. */
    private const AMBIGUOUS = ['duplicate_request', 'duplicate_request_id', 'invalid_status_for_operation', 'provider_unavailable', 'issuer_unavailable', 'internal_error'];

    /** Renew this long before expires_at, and never keep a token for longer than MAX_TOKEN_AGE. */
    private const TOKEN_MARGIN = 120;
    private const MAX_TOKEN_AGE = 1500;

    /** @var string */
    private $mode;

    /** @var string */
    private $clientId;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $account;

    /** @var bool Send x-login-as, for keys linked to several accounts. */
    private $loginAs;

    public function __construct(string $mode, string $clientId, string $apiKey, string $account, bool $loginAs = false)
    {
        $this->mode = $mode === 'live' ? 'live' : 'test';
        $this->clientId = trim($clientId);
        $this->apiKey = trim($apiKey);
        $this->account = trim($account);
        $this->loginAs = $loginAs;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function account(): string
    {
        return $this->account;
    }

    public function configured(): bool
    {
        return $this->clientId !== '' && $this->apiKey !== '' && $this->account !== '';
    }

    /** A random request_id (Airwallex allows 64 characters). */
    public static function requestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The same request_id for the same operation, so a retry cannot repeat it. */
    public static function stableId(string ...$parts): string
    {
        return substr(hash('sha256', implode('|', $parts)), 0, 48);
    }

    /** An id read from Airwallex, or '' when it is not safe for a path, a note or a log. */
    public static function id($value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{3,128}$/D', $value) ? $value : '';
    }

    /**
     * @param string $method GET or POST.
     * @param string $path   Path below /api/v1/, e.g. "pa/payment_intents/create".
     * @param array  $data   JSON body for POST, query arguments for GET.
     * @return array|\WP_Error
     */
    public function request(string $method, string $path, array $data = [])
    {
        if (!$this->configured()) {
            return new \WP_Error('stackless_airwallex_credentials', $this->mode === 'test'
                ? __('Airwallex is not connected for sandbox mode. Add the client ID, API key and account ID.', 'stackless-lite')
                : __('Airwallex is not connected. Add the live client ID, API key and account ID.', 'stackless-lite'), ['status' => 0, 'code' => 'not_configured', 'sent' => false]);
        }
        $token = $this->token();
        if (is_wp_error($token)) {
            return $token;
        }
        $result = $this->send($method, $path, $data, ['Authorization' => 'Bearer ' . $token]);
        if (is_wp_error($result) && self::status($result) === 401) {
            // Airwallex refused the token, so nothing was done: one new token, one more try.
            $this->forgetToken();
            $token = $this->token();
            if (is_wp_error($token)) {
                return $token;
            }
            $result = $this->send($method, $path, $data, ['Authorization' => 'Bearer ' . $token]);
        }
        return $result;
    }

    public function cacheKey(): string
    {
        $fingerprint = hash('sha256', implode('|', [$this->mode, $this->clientId, $this->apiKey, $this->loginAs ? $this->account : '']));
        return 'stackless_airwallex_gateway_token_' . substr($fingerprint, 0, 40);
    }

    public function forgetToken(): void
    {
        delete_transient($this->cacheKey());
    }

    /** @return string|\WP_Error */
    private function token()
    {
        $cached = get_transient($this->cacheKey());
        if (is_array($cached) && !empty($cached['token']) && (int) ($cached['until'] ?? 0) > time()) {
            return (string) $cached['token'];
        }
        $headers = ['x-client-id' => $this->clientId, 'x-api-key' => $this->apiKey];
        if ($this->loginAs) {
            $headers['x-login-as'] = $this->account;
        }
        $result = $this->send('POST', 'authentication/login', [], $headers);
        if (is_wp_error($result)) {
            $status = self::status($result);
            $message = $status === 400 || $status === 401 || $status === 403
                ? __('Airwallex rejected the client ID or API key for this mode.', 'stackless-lite')
                : $result->get_error_message();
            // The request itself was never sent, whatever happened to the login.
            return new \WP_Error('stackless_airwallex_auth', $message, ['status' => $status, 'code' => 'authentication', 'sent' => false]);
        }
        $token = is_string($result['token'] ?? null) ? $result['token'] : '';
        if ($token === '') {
            return new \WP_Error('stackless_airwallex_auth', __('Airwallex did not return an access token.', 'stackless-lite'), ['status' => 0, 'code' => 'authentication', 'sent' => false]);
        }
        $expires = is_string($result['expires_at'] ?? null) ? strtotime($result['expires_at']) : false;
        $ttl = min(self::MAX_TOKEN_AGE, ($expires ? $expires - time() : self::MAX_TOKEN_AGE) - self::TOKEN_MARGIN);
        if ($ttl > 0) {
            set_transient($this->cacheKey(), ['token' => $token, 'until' => time() + $ttl], $ttl);
        }
        return $token;
    }

    /**
     * @param array<string,string> $headers
     * @return array|\WP_Error
     */
    private function send(string $method, string $path, array $data, array $headers)
    {
        $method = strtoupper($method);
        $url = self::HOSTS[$this->mode] . '/api/v1/' . ltrim($path, '/');
        $args = [
            'method' => $method,
            'timeout' => 25,
            // The credentials in the headers never follow a redirect.
            'redirection' => 0,
            'headers' => $headers + [
                'x-api-version' => self::VERSION,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => Settings::userAgent(),
            ],
        ];
        if ($method === 'GET') {
            if ($data) {
                $url .= '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            }
        } else {
            $args['body'] = self::encode($data);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_airwallex_http', sprintf(__('Could not reach Airwallex: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0, 'code' => 'no_response', 'sent' => true]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $body = json_decode($raw, true);
        if ($status >= 200 && $status < 300 && is_array($body)) {
            return $body;
        }
        $body = is_array($body) ? $body : [];
        // A success without a readable body may still have moved money.
        $status = $status >= 200 && $status < 300 ? 502 : $status;
        $details = is_array($body['details'] ?? null) ? $body['details'] : [];
        $response_code = $body['provider_original_response_code'] ?? ($details['original_response_code'] ?? '');
        return new \WP_Error('stackless_airwallex_api', self::errorMessage($body, $status), [
            'status' => $status,
            'code' => is_string($body['code'] ?? null) ? $body['code'] : '',
            'sent' => true,
            'response_code' => is_scalar($response_code) ? (string) $response_code : '',
            'card_brand' => is_string($details['card_brand'] ?? null) ? $details['card_brand'] : '',
        ]);
    }

    /**
     * Amounts travel as decimal strings inside PHP and as JSON numbers on the wire, so no float
     * formatting (serialize_precision) can change what is charged.
     */
    public static function encode(array $data): string
    {
        $json = $data ? (string) wp_json_encode($data) : '{}';
        return (string) preg_replace('/"(amount|unit_amount)":"(\d+(?:\.\d+)?)"/', '"$1":$2', $json);
    }

    /** Airwallex errors are {"code": "...", "message": "...", "source": "...", "trace_id": "..."}. */
    public static function errorMessage(array $body, int $status): string
    {
        if ($status === 401) {
            return __('Airwallex rejected the access token. Check the client ID and API key.', 'stackless-lite');
        }
        if ($status === 403) {
            return __('This Airwallex API key is not allowed to do that. Check its permissions and account.', 'stackless-lite');
        }
        $message = is_string($body['message'] ?? null) ? trim(wp_strip_all_tags($body['message'])) : '';
        return $message !== ''
            /* translators: %s: Airwallex error message */
            ? sprintf(__('Airwallex: %s', 'stackless-lite'), substr($message, 0, 300))
            /* translators: %d: HTTP status code */
            : sprintf(__('Airwallex returned an unexpected error (HTTP %d).', 'stackless-lite'), $status);
    }

    /** HTTP status of an error from request() (0 = the request never got an answer). */
    public static function status(\WP_Error $error): int
    {
        $data = $error->get_error_data();
        return is_array($data) ? (int) ($data['status'] ?? 0) : 0;
    }

    /** @return string code, response_code or card_brand of an error */
    public static function detail(\WP_Error $error, string $key = 'code'): string
    {
        $data = $error->get_error_data();
        return is_array($data) ? (string) ($data[$key] ?? '') : '';
    }

    /** Whether the request may have reached Airwallex. */
    public static function wasSent(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        return !is_array($data) || !empty($data['sent']);
    }

    public static function notFound(\WP_Error $error): bool
    {
        return self::wasSent($error) && self::status($error) === 404;
    }

    /** True only when Airwallex answered and the answer proves the request had no effect. */
    public static function isRejected(\WP_Error $error): bool
    {
        return self::wasSent($error)
            && in_array(self::status($error), [400, 404, 422], true)
            && !in_array(self::detail($error), self::AMBIGUOUS, true);
    }
}
