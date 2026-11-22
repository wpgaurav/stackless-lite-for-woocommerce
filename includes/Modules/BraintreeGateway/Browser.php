<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * What the card form gets from the server. The JavaScript SDK is pinned to a release with the
 * SHA-384 of each file; v3 is Braintree's active major (v4 is a pre-release) and Drop-in, which
 * is deprecated, is not used.
 */
final class Browser
{
    public const SDK_VERSION = '3.148.0';
    private const SDK_BASE = 'https://js.braintreegateway.com/web/';
    private const SDK_FILES = [
        'client' => 'sha384-3/7aAk7+TMUXNBFwQWWT8DOOXV1YYmhdCdd5+rJ4hs/2ICrMKhrlvXP81i+5B6s1',
        'hostedFields' => 'sha384-FMBqa3D/HmPDJO5UbjrSvOsC23ear20P19x/h26TjT9optpaP3X7FX0E6bpkZsfJ',
        'threeDSecure' => 'sha384-Y+xqz9SNs1fTts69SRWrSUN1wqRctHh+22J4IEyPBP8xPYqhE0PUNCtS/UaVgYeN',
        'dataCollector' => 'sha384-Cv4BCFHvCWL5s7eFL5Pw3UKeVm4HKecPLbfj1SsT6Ue3QXznPYV951hkrtBb7PHf',
    ];
    private const SDK_NAMES = ['client' => 'client', 'hostedFields' => 'hosted-fields', 'threeDSecure' => 'three-d-secure', 'dataCollector' => 'data-collector'];
    /** A client token lasts 24 hours. */
    private const TOKEN_TTL = 3600;

    /** @return array<int,array{name:string, src:string, integrity:string}> */
    public static function scripts(): array
    {
        $wanted = ['client', 'hostedFields'];
        if (Config::threeDSecure() !== ThreeDSecure::OFF) {
            $wanted[] = 'threeDSecure';
        }
        if (Config::deviceData()) {
            $wanted[] = 'dataCollector';
        }
        $scripts = [];
        foreach ($wanted as $name) {
            $scripts[] = ['name' => $name, 'src' => self::SDK_BASE . self::SDK_VERSION . '/js/' . self::SDK_NAMES[$name] . '.min.js', 'integrity' => self::SDK_FILES[$name]];
        }
        return $scripts;
    }

    /**
     * A client token for the merchant account that will charge this currency: 3-D Secure fails
     * the charge (91584) when the two differ.
     *
     * @return string|\WP_Error
     */
    public static function clientToken(string $mode, string $currency)
    {
        $connection = Connection::ensure($mode);
        $merchantAccount = $connection ? Connection::merchantAccount($mode, $currency, $connection) : null;
        if (!$connection || !$merchantAccount) {
            return new \WP_Error('stackless_braintree_currency', __('Braintree does not take payments in this currency here. Choose another payment method.', 'stackless-lite'));
        }
        $key = 'stackless_braintree_gateway_client_' . md5($connection['fingerprint'] . '|' . $merchantAccount['id']);
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $data = Config::api($mode)->query(Queries::CLIENT_TOKEN, ['input' => ['clientToken' => ['merchantAccountId' => $merchantAccount['id']]]]);
        $token = is_wp_error($data) ? '' : (string) ($data['createClientToken']['clientToken'] ?? '');
        if ($token === '') {
            Config::log('Could not create a Braintree client token' . (is_wp_error($data) ? ': ' . $data->get_error_message() : '.'), 'warning');
            return new \WP_Error('stackless_braintree_token', __('Braintree could not load the card form. Reload the page or choose another payment method.', 'stackless-lite'));
        }
        set_transient($key, $token, self::TOKEN_TTL);
        return $token;
    }

    /**
     * A one-time nonce for the customer's own saved card, so 3-D Secure can run on it.
     *
     * @return array{nonce:string, bin:string}|\WP_Error
     */
    public static function savedCard(int $tokenId, int $user)
    {
        $card = $user > 0 ? Tokens::get($tokenId) : null;
        $unavailable = new \WP_Error('stackless_braintree_card', __('That saved card is not available. Choose another one.', 'stackless-lite'));
        if (!$card || $card->user !== $user || $card->mode !== Config::mode() || $card->legacy === '' || $card->account !== Connection::account($card->mode)) {
            return $unavailable;
        }
        $api = Config::api($card->mode);
        $read = $api->query(Queries::PAYMENT_METHOD, ['id' => $card->remote]);
        $bin = is_wp_error($read) ? '' : (string) ($read['node']['details']['bin'] ?? '');
        if ($bin === '') {
            return $unavailable;
        }
        $nonce = $api->nonceFor($card->legacy);
        if (is_wp_error($nonce)) {
            Config::log(sprintf('Could not create a nonce for saved card %d: %s', $card->id, $nonce->get_error_message()), 'warning');
            return $unavailable;
        }
        return ['nonce' => $nonce, 'bin' => $bin];
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [
            'loading' => __('Loading the card form…', 'stackless-lite'),
            'failed' => __('Braintree could not load the card form. Reload the page or choose another payment method.', 'stackless-lite'),
            'invalid' => __('Check your card details and try again.', 'stackless-lite'),
            'verify' => __('Your card could not be verified with your bank. Try again or use another card.', 'stackless-lite'),
            'changed' => __('The currency changed. Reload the page before paying.', 'stackless-lite'),
            'refreshed' => __('The checkout was updated. Check the total, then pay again.', 'stackless-lite'),
            'expired' => __('This page expired. Reload it and try again.', 'stackless-lite'),
        ];
    }
}
