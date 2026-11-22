<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Minimal Braintree client. Payments go through the GraphQL API; the older gateway API is used
 * only for the two things GraphQL has no equivalent for: turning a saved card into a one-time
 * nonce (3-D Secure on a saved card needs one) and checking a Merchant ID.
 *
 * GraphQL answers HTTP 200 for errors too, so what matters is whether an error proves that
 * nothing happened (`definite`). Anything else on a write is an unknown outcome.
 */
class Api
{
    public const HOSTS = ['live' => 'payments.braintree-api.com', 'test' => 'payments.sandbox.braintree-api.com'];
    public const GATEWAY_HOSTS = ['live' => 'api.braintreegateway.com', 'test' => 'api.sandbox.braintreegateway.com'];

    /** Braintree-Version: the schema date this integration was written against. */
    public const VERSION = '2026-11-10';
    public const GATEWAY_API_VERSION = '6';

    /** Braintree recommends at least 60 seconds for anything that reaches a processor. */
    public const WRITE_TIMEOUT = 65;
    public const READ_TIMEOUT = 15;

    /** Error classes after which Braintree created nothing. INTERNAL, SERVICE_AVAILABILITY and RESOURCE_LIMIT are not. */
    private const DEFINITE = ['VALIDATION', 'AUTHENTICATION', 'AUTHORIZATION', 'NOT_FOUND', 'NOT_IMPLEMENTED', 'UNSUPPORTED_CLIENT'];

    /** apiRequestKey errors that say nothing about the first request: reused with other input, in flight, server error. */
    private const KEY_UNCERTAIN = ['915232', '915233', '915235'];

    /** @var string */
    private $mode;

    /** @var string */
    private $merchantId;

    /** @var string */
    private $publicKey;

    /** @var string */
    private $privateKey;

    public function __construct(string $mode, string $merchantId, string $publicKey, string $privateKey)
    {
        $this->mode = $mode === 'live' ? 'live' : 'test';
        $this->merchantId = $merchantId;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasKeys(): bool
    {
        return $this->merchantId !== '' && $this->publicKey !== '' && $this->privateKey !== '';
    }

    public static function userAgent(): string
    {
        return 'Stackless-for-WooCommerce/' . (defined('STACKLESS_VERSION') ? STACKLESS_VERSION : 'dev');
    }

    /**
     * Run a GraphQL document and keep whatever data came back, even next to errors: a mutation
     * can create a transaction and still report an error on one of the fields asked for.
     *
     * @param array<string,mixed> $variables
     * @return array{data:array<string,mixed>, error:\WP_Error|null}
     */
    public function request(string $document, array $variables = [], int $timeout = self::READ_TIMEOUT): array
    {
        if (!$this->hasKeys()) {
            return ['data' => [], 'error' => $this->missingKeys()];
        }
        $body = ['query' => $document];
        if ($variables) {
            $body['variables'] = $variables;
        }
        $response = wp_remote_post('https://' . self::HOSTS[$this->mode] . '/graphql', [
            'timeout' => $timeout,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->publicKey . ':' . $this->privateKey),
                'Braintree-Version' => self::VERSION,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => self::userAgent(),
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return ['data' => [], 'error' => new \WP_Error('stackless_braintree_http', sprintf(__('Could not reach Braintree: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0, 'definite' => false])];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];

        if ($status === 401 || $status === 403) {
            return ['data' => [], 'error' => new \WP_Error('stackless_braintree_auth', $this->rejectedKeys(), ['status' => $status, 'class' => 'AUTHENTICATION', 'definite' => true])];
        }
        if ($status !== 200 || (!$data && !$errors)) {
            /* translators: %d: HTTP status code */
            return ['data' => $data, 'error' => new \WP_Error('stackless_braintree_response', sprintf(__('Braintree returned an unexpected response (HTTP %d).', 'stackless-lite'), $status), ['status' => $status, 'definite' => false])];
        }
        return ['data' => $data, 'error' => $errors ? $this->error($errors, $status) : null];
    }

    /**
     * A read that is only useful when it is complete: any error fails it.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed>|\WP_Error
     */
    public function query(string $document, array $variables = [], int $timeout = self::READ_TIMEOUT)
    {
        $result = $this->request($document, $variables, $timeout);
        return $result['error'] ?: $result['data'];
    }

    /** @param array<int,mixed> $errors */
    private function error(array $errors, int $status): \WP_Error
    {
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $extensions = is_array($first['extensions'] ?? null) ? $first['extensions'] : [];
        $input = is_array($extensions['inputPath'] ?? null) ? $extensions['inputPath'] : [];
        $class = strtoupper((string) ($extensions['errorClass'] ?? ''));

        $definite = true;
        foreach ($errors as $error) {
            $details = is_array($error) && is_array($error['extensions'] ?? null) ? $error['extensions'] : [];
            $path = is_array($error) && is_array($error['path'] ?? null) ? $error['path'] : [];
            if (
                !in_array(strtoupper((string) ($details['errorClass'] ?? '')), self::DEFINITE, true)
                || in_array((string) ($details['legacyCode'] ?? ''), self::KEY_UNCERTAIN, true)
                // A deeper path means the mutation ran and one field of its answer failed.
                || count($path) > 1
            ) {
                $definite = false;
                break;
            }
        }

        $message = trim(wp_strip_all_tags((string) ($first['message'] ?? '')));
        if ($class === 'AUTHENTICATION') {
            $message = $this->rejectedKeys();
        } elseif ($message !== '') {
            /* translators: %s: Braintree error message */
            $message = sprintf(__('Braintree: %s', 'stackless-lite'), substr($message, 0, 200));
        } else {
            $message = __('Braintree could not complete the request.', 'stackless-lite');
        }
        return new \WP_Error('stackless_braintree_api', $message, [
            'status' => $status,
            'class' => $class,
            'code' => (string) ($extensions['legacyCode'] ?? ''),
            'input' => $input ? (string) end($input) : '',
            'definite' => $definite,
        ]);
    }

    private function missingKeys(): \WP_Error
    {
        $message = $this->mode === 'test'
            ? __('Braintree is not connected for the sandbox. Add the sandbox Merchant ID, public key and private key.', 'stackless-lite')
            : __('Braintree is not connected. Add the production Merchant ID, public key and private key.', 'stackless-lite');
        return new \WP_Error('stackless_braintree_keys', $message, ['status' => 0, 'class' => 'AUTHENTICATION', 'definite' => true]);
    }

    private function rejectedKeys(): string
    {
        return $this->mode === 'test'
            ? __('Braintree rejected the sandbox keys. Copy the public and private key from your sandbox Control Panel (API).', 'stackless-lite')
            : __('Braintree rejected the production keys. Copy the public and private key from your production Control Panel (API).', 'stackless-lite');
    }

    /** True only when the error proves Braintree created nothing. */
    public static function isDefinite(\WP_Error $error): bool
    {
        return !empty(self::detail($error, 'definite'));
    }

    public static function errorClass(\WP_Error $error): string
    {
        return (string) self::detail($error, 'class');
    }

    public static function code(\WP_Error $error): string
    {
        return (string) self::detail($error, 'code');
    }

    /** The input field a validation error points at, e.g. customerId. */
    public static function inputField(\WP_Error $error): string
    {
        return (string) self::detail($error, 'input');
    }

    /** @return mixed '' when absent */
    private static function detail(\WP_Error $error, string $key)
    {
        $data = $error->get_error_data();
        return is_array($data) ? ($data[$key] ?? '') : '';
    }

    /* ---- Gateway API (no GraphQL equivalent) ------------------------------------ */

    /**
     * A one-time nonce for a saved card (PaymentMethodNonce: Create).
     *
     * @param string $token The card's legacy id (payment method token).
     * @return string|\WP_Error
     */
    public function nonceFor(string $token)
    {
        if (!self::pathSafe($token)) {
            return new \WP_Error('stackless_braintree_token', __('That saved card cannot be used.', 'stackless-lite'));
        }
        $body = $this->gatewayRequest('POST', 'payment_methods/' . $token . '/nonces');
        if (is_wp_error($body)) {
            return $body;
        }
        $xml = Notification::xml($body);
        $nonce = $xml && $xml->nodeName === 'payment-method-nonce' ? Notification::child($xml, 'nonce') : '';
        if ($nonce === '') {
            return new \WP_Error('stackless_braintree_nonce', __('Braintree did not return a verification token for this card.', 'stackless-lite'));
        }
        return $nonce;
    }

    /**
     * Proves the Merchant ID goes with these keys (MerchantAccount: Find).
     *
     * @return true|\WP_Error
     */
    public function checkMerchant(string $merchantAccountId)
    {
        $checked = self::pathSafe($this->merchantId) && self::pathSafe($merchantAccountId)
            ? $this->gatewayRequest('GET', 'merchant_accounts/' . $merchantAccountId)
            : new \WP_Error('stackless_braintree_merchant', '');
        if (is_wp_error($checked)) {
            return new \WP_Error('stackless_braintree_merchant', $this->mode === 'test'
                ? __('The sandbox Merchant ID could not be confirmed with these keys. Copy all three from the same API key screen.', 'stackless-lite')
                : __('The production Merchant ID could not be confirmed with these keys. Copy all three from the same API key screen.', 'stackless-lite'));
        }
        return true;
    }

    /** @return string|\WP_Error Raw XML. */
    private function gatewayRequest(string $method, string $path)
    {
        if (!$this->hasKeys() || !self::pathSafe($this->merchantId)) {
            return $this->missingKeys();
        }
        $response = wp_remote_request('https://' . self::GATEWAY_HOSTS[$this->mode] . '/merchants/' . $this->merchantId . '/' . $path, [
            'method' => $method,
            'timeout' => self::READ_TIMEOUT,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->publicKey . ':' . $this->privateKey),
                'X-ApiVersion' => self::GATEWAY_API_VERSION,
                'Accept' => 'application/xml',
                'Content-Type' => 'application/xml',
                'User-Agent' => self::userAgent(),
            ],
        ]);
        if (is_wp_error($response)) {
            /* translators: %s: transport error */
            return new \WP_Error('stackless_braintree_http', sprintf(__('Could not reach Braintree: %s', 'stackless-lite'), $response->get_error_message()), ['status' => 0]);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200 && $status !== 201) {
            /* translators: %d: HTTP status code */
            return new \WP_Error('stackless_braintree_response', sprintf(__('Braintree returned an unexpected response (HTTP %d).', 'stackless-lite'), $status), ['status' => $status]);
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /** Braintree ids are 1 to 64 letters, digits, dashes and underscores (best practices). */
    private static function pathSafe(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
    }
}
