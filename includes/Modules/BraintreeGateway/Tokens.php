<?php

namespace Stackless\Modules\BraintreeGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved cards in WooCommerce's payment token table, which changes at checkout and in My Account.

defined('ABSPATH') || exit;

/**
 * Vaulted cards as WooCommerce card tokens. The token string is Braintree's payment method id;
 * token meta binds it to a mode, a Braintree merchant and a Braintree customer.
 */
final class Tokens
{
    public const META_MODE = 'stackless_braintree_mode';
    public const META_ACCOUNT = 'stackless_braintree_account';
    public const META_CUSTOMER = 'stackless_braintree_customer';
    public const META_LEGACY = 'stackless_braintree_legacy';

    public static function registerHooks(): void
    {
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterUsable'], 10, 3);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
    }

    public static function save(Card $card): ?Card
    {
        foreach (self::find($card->remote, $card->user) as $existing) {
            return self::toCard($existing);
        }
        $token = new \WC_Payment_Token_CC();
        $token->set_card_type($card->brand);
        $token->set_last4(str_pad($card->last4, 4, '0', STR_PAD_LEFT));
        $token->set_expiry_month($card->month !== '' ? $card->month : '12');
        $token->set_expiry_year($card->year !== '' ? $card->year : (string) ((int) gmdate('Y') + 5));
        $token->set_token($card->remote);
        $token->set_gateway_id(Gateway::ID);
        $token->set_user_id($card->user);
        $token->add_meta_data(self::META_MODE, $card->mode, true);
        $token->add_meta_data(self::META_ACCOUNT, $card->account, true);
        $token->add_meta_data(self::META_CUSTOMER, $card->customer, true);
        $token->add_meta_data(self::META_LEGACY, $card->legacy, true);
        if ($card->user > 0 && !\WC_Payment_Tokens::get_customer_default_token($card->user)) {
            $token->set_default(true);
        }
        if (!$token->save()) {
            return null;
        }
        $card->id = (int) $token->get_id();
        return $card;
    }

    public static function get(int $tokenId): ?Card
    {
        $token = $tokenId > 0 ? \WC_Payment_Tokens::get($tokenId) : null;
        return $token && $token->get_gateway_id() === Gateway::ID ? self::toCard($token) : null;
    }

    public static function toCard(\WC_Payment_Token $token): Card
    {
        $card = new Card();
        $card->id = (int) $token->get_id();
        $card->user = (int) $token->get_user_id();
        $card->remote = (string) $token->get_token();
        $card->mode = (string) $token->get_meta(self::META_MODE) === 'live' ? 'live' : 'test';
        $card->account = (string) $token->get_meta(self::META_ACCOUNT);
        $card->customer = (string) $token->get_meta(self::META_CUSTOMER);
        $card->legacy = (string) $token->get_meta(self::META_LEGACY);
        if ($token instanceof \WC_Payment_Token_CC) {
            $card->brand = (string) $token->get_card_type();
            $card->last4 = (string) $token->get_last4();
            $card->month = (string) $token->get_expiry_month();
            $card->year = (string) $token->get_expiry_year();
        }
        return $card;
    }

    /** @return \WC_Payment_Token[] */
    private static function find(string $remote, int $user = -1): array
    {
        global $wpdb;
        if ($remote === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        $ids = $user >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d', $table, Gateway::ID, $remote, $user))
            : $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s', $table, Gateway::ID, $remote));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = \WC_Payment_Tokens::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    public static function inUse(string $remote): bool
    {
        return (bool) self::find($remote);
    }

    /**
     * Hide cards of the other mode or of a merchant that is no longer connected.
     *
     * @param \WC_Payment_Token[] $tokens
     * @param int $customerId
     * @param string $gatewayId
     * @return \WC_Payment_Token[]
     */
    public static function filterUsable($tokens, $customerId = 0, $gatewayId = '')
    {
        if (!is_array($tokens)) {
            return $tokens;
        }
        $mode = Config::mode();
        $connection = null;
        foreach ($tokens as $key => $token) {
            if (!$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Gateway::ID) {
                continue;
            }
            $connection = $connection ?? (Connection::cached($mode) ?: []);
            if ((string) $token->get_meta(self::META_MODE) !== $mode || ($connection && (string) $token->get_meta(self::META_ACCOUNT) !== $connection['account'])) {
                unset($tokens[$key]);
            }
        }
        return $tokens;
    }

    /**
     * The customer removed a card: delete it from the vault so it is never charged again.
     *
     * @param int $tokenId
     * @param \WC_Payment_Token $token
     */
    public static function onDeleted($tokenId, $token): void
    {
        if ($token instanceof \WC_Payment_Token && $token->get_gateway_id() === Gateway::ID) {
            Vault::deleteRemote(self::toCard($token));
        }
    }
}
