<?php

namespace Stackless\Modules\MollieGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved mandates in WooCommerce's payment token table, which changes at checkout and in My Account.

defined('ABSPATH') || exit;

/**
 * Mollie mandates as WooCommerce payment tokens.
 *
 * token string = mandate id (mdt_…). Token meta records the Mollie mode, the customer
 * (cst_…) the mandate belongs to and the mandate method (creditcard, directdebit, paypal).
 */
final class Tokens
{
    public const META_MODE = 'stackless_mollie_mode';
    public const META_CUSTOMER = 'stackless_mollie_customer';
    public const META_METHOD = 'stackless_mollie_method';

    /** @var bool Set while the gateway deletes tokens itself, so Mollie is not called back. */
    private static $suppressRevoke = false;

    public static function registerHooks(): void
    {
        add_filter('woocommerce_payment_token_class', [self::class, 'filterTokenClass'], 10, 2);
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterByMode'], 10, 3);
        add_filter('woocommerce_payment_methods_list_item', [self::class, 'filterListItem'], 20, 2);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
    }

    /**
     * Saves (or finds) the token for a Mollie mandate object.
     *
     * @param array $mandate Mollie mandate (GET customers/{id}/mandates/{id}).
     */
    public static function saveFromMandate(array $mandate, int $userId, string $customerId, string $mode): ?\WC_Payment_Token
    {
        $mandateId = (string) ($mandate['id'] ?? '');
        if (strpos($mandateId, 'mdt_') !== 0 || $customerId === '') {
            return null;
        }
        $existing = self::find($mandateId, $userId);
        if ($existing) {
            return $existing[0];
        }

        $method = (string) ($mandate['method'] ?? '');
        $details = is_array($mandate['details'] ?? null) ? $mandate['details'] : [];
        if ($method === 'creditcard') {
            $token = new \WC_Payment_Token_CC();
            $token->set_card_type(self::brand((string) ($details['cardLabel'] ?? '')));
            $token->set_last4(self::last4((string) ($details['cardNumber'] ?? '')));
            $expiry = (string) ($details['cardExpiryDate'] ?? '');
            $time = $expiry !== '' ? strtotime($expiry) : false;
            $token->set_expiry_month($time ? gmdate('m', $time) : '12');
            $token->set_expiry_year($time ? gmdate('Y', $time) : (string) ((int) gmdate('Y') + 5));
        } elseif ($method === 'directdebit') {
            $token = new \WC_Payment_Token_ECheck();
            $token->set_last4(self::last4((string) ($details['consumerAccount'] ?? '')));
        } else {
            $token = new PaymentToken();
            $account = sanitize_text_field((string) ($details['consumerAccount'] ?? ''));
            $token->set_label(Methods::label($method !== '' ? $method : 'paypal') . ($account !== '' ? ' (' . $account . ')' : ''));
        }

        $token->set_token($mandateId);
        $token->set_gateway_id(Gateway::ID);
        $token->set_user_id(max(0, $userId));
        $token->add_meta_data(self::META_MODE, $mode === 'live' ? 'live' : 'test', true);
        $token->add_meta_data(self::META_CUSTOMER, $customerId, true);
        $token->add_meta_data(self::META_METHOD, $method, true);
        if ($userId > 0 && !\WC_Payment_Tokens::get_customer_default_token($userId)) {
            $token->set_default(true);
        }
        if (!$token->save()) {
            return null;
        }
        return $token;
    }

    /** @return \WC_Payment_Token[] */
    public static function find(string $mandateId, int $userId = -1): array
    {
        global $wpdb;
        if ($mandateId === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d', $table, Gateway::ID, $mandateId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s', $table, Gateway::ID, $mandateId));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = \WC_Payment_Tokens::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /** Token owned by $userId (0 = guest-owned) for this gateway, or null. */
    public static function getOwned(int $tokenId, int $userId): ?\WC_Payment_Token
    {
        $token = $tokenId > 0 ? \WC_Payment_Tokens::get($tokenId) : null;
        if (!$token || $token->get_gateway_id() !== Gateway::ID || (int) $token->get_user_id() !== $userId) {
            return null;
        }
        return $token;
    }

    public static function mode(\WC_Payment_Token $token): string
    {
        return (string) $token->get_meta(self::META_MODE) === 'live' ? 'live' : 'test';
    }

    public static function customer(\WC_Payment_Token $token): string
    {
        return (string) $token->get_meta(self::META_CUSTOMER);
    }

    public static function method(\WC_Payment_Token $token): string
    {
        $method = (string) $token->get_meta(self::META_METHOD);
        if ($method !== '') {
            return $method;
        }
        return $token instanceof \WC_Payment_Token_ECheck ? 'directdebit' : ($token instanceof \WC_Payment_Token_CC ? 'creditcard' : 'paypal');
    }

    /** Delete every token of a mandate without calling Mollie (it is already gone there). */
    public static function forget(string $mandateId): int
    {
        $count = 0;
        self::$suppressRevoke = true;
        try {
            foreach (self::find($mandateId) as $token) {
                \WC_Payment_Tokens::delete($token->get_id());
                $count++;
            }
        } finally {
            self::$suppressRevoke = false;
        }
        return $count;
    }

    /**
     * @param string $class
     * @param string $type
     * @return string
     */
    public static function filterTokenClass($class, $type)
    {
        return strtolower((string) $type) === strtolower(PaymentToken::TYPE) ? PaymentToken::class : $class;
    }

    /**
     * Hide mandates from the other Mollie mode: a test mandate cannot pay a live order.
     *
     * @param \WC_Payment_Token[] $tokens
     * @param int $customerId
     * @param string $gatewayId
     * @return \WC_Payment_Token[]
     */
    public static function filterByMode($tokens, $customerId = 0, $gatewayId = '')
    {
        if (!is_array($tokens)) {
            return $tokens;
        }
        $mode = Gateway::instance()->mode();
        foreach ($tokens as $key => $token) {
            if ($token instanceof \WC_Payment_Token && $token->get_gateway_id() === Gateway::ID && self::mode($token) !== $mode) {
                unset($tokens[$key]);
            }
        }
        return $tokens;
    }

    /**
     * @param array $item
     * @param \WC_Payment_Token $token
     * @return array
     */
    public static function filterListItem($item, $token)
    {
        if ($token instanceof PaymentToken) {
            $item['method']['brand'] = $token->get_display_name();
        } elseif ($token instanceof \WC_Payment_Token_ECheck && $token->get_gateway_id() === Gateway::ID) {
            $item['method']['brand'] = Methods::label('directdebit');
        }
        return $item;
    }

    /**
     * Customer removed a saved method in My Account: revoke the mandate at Mollie so it can
     * never be charged again.
     *
     * @param int $tokenId
     * @param \WC_Payment_Token $token
     */
    public static function onDeleted($tokenId, $token): void
    {
        if (self::$suppressRevoke || !$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Gateway::ID) {
            return;
        }
        $mandateId = $token->get_token();
        $customerId = self::customer($token);
        if ($mandateId === '' || $customerId === '' || self::find($mandateId)) {
            return; // Another token row still uses this mandate.
        }
        $gateway = Gateway::instance();
        $result = $gateway->api(self::mode($token))->request('DELETE', 'customers/' . rawurlencode($customerId) . '/mandates/' . rawurlencode($mandateId));
        if (is_wp_error($result) && Api::status($result) !== 404) {
            $gateway->log(sprintf('Could not revoke mandate %s after token %d was deleted: %s', $mandateId, (int) $tokenId, $result->get_error_message()), 'warning');
            return;
        }
        $gateway->log(sprintf('Revoked mandate %s after token %d was deleted.', $mandateId, (int) $tokenId));
    }

    private static function brand(string $label): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $label));
        $map = ['american-express' => 'amex', 'diners-club' => 'diners', 'carte-bancaire' => 'cartes_bancaires', 'cartes-bancaires' => 'cartes_bancaires'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    private static function last4(string $value): string
    {
        $digits = preg_replace('/[^0-9A-Za-z]/', '', $value);
        return str_pad(substr((string) $digits, -4), 4, '0', STR_PAD_LEFT);
    }
}
