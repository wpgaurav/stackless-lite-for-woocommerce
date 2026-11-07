<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * stackless_paypal_gateway_adopt_vault (2.0.0): turns a vault id saved by WooCommerce PayPal
 * Payments into a token for this gateway, so subscriptions moved from WooCommerce
 * Subscriptions keep renewing.
 *
 * The vault token is read back from PayPal (Vault v3) with this store's credentials for the
 * given environment. PayPal accounts (and Venmo) become Stackless_PayPal tokens; vaulted
 * cards become card tokens that renew with payment_source.card.vault_id.
 */
final class Adoption
{
    /**
     * @param mixed $value null, or an earlier answer (kept as is).
     * @param mixed $args  ['vault_id' => string, 'customer_id' => string, 'user_id' => int, 'email' => string, 'mode' => 'live'|'test']
     * @return int|\WP_Error|mixed
     */
    public static function adoptVault($value, $args = [])
    {
        if ($value !== null) {
            return $value;
        }
        $args = is_array($args) ? $args : [];
        $vaultId = trim((string) ($args['vault_id'] ?? ''));
        $customerId = trim((string) ($args['customer_id'] ?? ''));
        $userId = max(0, (int) ($args['user_id'] ?? 0));
        $email = sanitize_email((string) ($args['email'] ?? ''));
        $mode = (string) ($args['mode'] ?? '');
        $environment = $mode === 'live' ? 'live' : ($mode === 'test' || $mode === 'sandbox' ? 'sandbox' : '');

        if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/', $vaultId)) {
            return new \WP_Error('stackless_paypal_adopt_invalid', __('A PayPal vault id is required.', 'stackless-lite'));
        }
        if ($environment === '') {
            return new \WP_Error('stackless_paypal_adopt_invalid', __('The PayPal mode (live or test) is required.', 'stackless-lite'));
        }
        $gateway = Gateway::instance();
        foreach (\WC_Payment_Tokens::get_customer_tokens($userId, $gateway->id) as $existing) {
            if ($existing->get_token() === $vaultId) {
                return (int) $existing->get_id();
            }
        }
        $api = $gateway->apiFor($environment);
        if (!$api->hasCredentials()) {
            /* translators: %s: sandbox or live */
            return new \WP_Error('stackless_paypal_adopt_credentials', sprintf(__('Add the PayPal %s credentials before moving saved payment methods.', 'stackless-lite'), $environment));
        }
        $remote = $api->getPaymentToken($vaultId);
        if (is_wp_error($remote)) {
            $gateway->log('Adopting vault ' . $vaultId . ' failed: ' . $remote->get_error_message(), 'warning');
            return new \WP_Error('stackless_paypal_adopt_lookup', $remote->get_error_message());
        }
        $parsed = self::parse($remote);
        if ($customerId !== '' && $parsed['customer_id'] !== '' && !hash_equals($parsed['customer_id'], $customerId)) {
            return new \WP_Error('stackless_paypal_adopt_customer', __('The PayPal vault id belongs to a different PayPal customer.', 'stackless-lite'));
        }
        $customerId = $parsed['customer_id'] !== '' ? $parsed['customer_id'] : $customerId;

        if ($parsed['type'] === 'card') {
            $tokenId = CardTokens::save($userId, $vaultId, $parsed['card'], $customerId, $environment);
        } elseif ($parsed['type'] === 'paypal') {
            $tokenId = $gateway->payments()->saveToken($userId, $vaultId, $parsed['email'] !== '' ? $parsed['email'] : $email, $parsed['payer_id'], $customerId, $environment);
        } else {
            /* translators: %s: payment source, e.g. apple_pay */
            return new \WP_Error('stackless_paypal_adopt_type', sprintf(__('Saved %s methods cannot renew through Stackless. The subscription falls back to manual renewal.', 'stackless-lite'), $parsed['type'] !== '' ? $parsed['type'] : 'unknown'));
        }
        if ($tokenId <= 0) {
            return new \WP_Error('stackless_paypal_adopt_save', __('The PayPal vault id could not be saved as a token.', 'stackless-lite'));
        }
        $gateway->log(sprintf('Adopted PayPal vault %s (%s) as token %d for user %d.', $vaultId, $parsed['type'], $tokenId, $userId), 'info');
        return $tokenId;
    }

    /**
     * What a Vault v3 payment token pays with. Venmo vaults charge like PayPal wallets.
     *
     * @return array{type:string, email:string, payer_id:string, customer_id:string, card:array<string,mixed>}
     */
    public static function parse(array $remote): array
    {
        $source = (array) ($remote['payment_source'] ?? []);
        $out = ['type' => '', 'email' => '', 'payer_id' => '', 'customer_id' => (string) ($remote['customer']['id'] ?? ''), 'card' => []];
        if (isset($source['card'])) {
            $out['type'] = 'card';
            $out['card'] = (array) $source['card'];
        } elseif (isset($source['paypal']) || isset($source['venmo'])) {
            $wallet = (array) ($source['paypal'] ?? $source['venmo']);
            $out['type'] = 'paypal';
            $out['email'] = sanitize_email((string) ($wallet['email_address'] ?? ''));
            $out['payer_id'] = (string) ($wallet['payer_id'] ?? ($wallet['account_id'] ?? ''));
        } elseif ($source) {
            $out['type'] = (string) array_key_first($source);
        }
        return $out;
    }
}
