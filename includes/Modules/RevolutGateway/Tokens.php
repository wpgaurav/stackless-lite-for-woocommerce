<?php

namespace Stackless\Modules\RevolutGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved cards in WooCommerce's payment token table, which changes at checkout and in My Account.

defined('ABSPATH') || exit;

/**
 * Cards saved at Revolut for merchant use, as WooCommerce card tokens.
 *
 * token string = Revolut payment method id. Token meta records the mode and the Revolut
 * customer the card belongs to. Cards saved for the customer only are never stored.
 */
final class Tokens
{
    public const META_MODE = 'stackless_revolut_mode';
    public const META_CUSTOMER = 'stackless_revolut_customer';

    public static function registerHooks(): void
    {
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterByMode'], 10, 3);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
    }

    /** @return array{id:int, user:int, method:string, customer:string, mode:string}|null */
    public static function get(int $tokenId): ?array
    {
        $token = $tokenId > 0 ? \WC_Payment_Tokens::get($tokenId) : null;
        if (!$token || $token->get_gateway_id() !== Keys::GATEWAY_ID) {
            return null;
        }
        return [
            'id' => (int) $token->get_id(),
            'user' => (int) $token->get_user_id(),
            'method' => (string) $token->get_token(),
            'customer' => (string) $token->get_meta(self::META_CUSTOMER),
            'mode' => self::mode($token),
        ];
    }

    /**
     * Saves (or finds) the token of a saved card.
     *
     * @param array<string,mixed> $method A Revolut payment method.
     * @return int token id, 0 when it could not be saved
     */
    public static function save(array $method, int $userId, string $customer, string $mode): int
    {
        $id = (string) ($method['id'] ?? '');
        if ($id === '' || $customer === '' || ($method['saved_for'] ?? '') !== 'merchant') {
            return 0;
        }
        $mode = $mode === 'live' ? 'live' : 'test';
        $userId = max(0, $userId);
        foreach (self::find($id, $userId) as $existing) {
            if (self::mode($existing) === $mode && (string) $existing->get_meta(self::META_CUSTOMER) === $customer) {
                return (int) $existing->get_id();
            }
        }
        $token = new \WC_Payment_Token_CC();
        $token->set_card_type(self::brand((string) ($method['brand'] ?? '')));
        $token->set_last4(str_pad(substr((string) preg_replace('/\D/', '', (string) ($method['last_four'] ?? '')), -4), 4, '0', STR_PAD_LEFT));
        $month = (int) ($method['expiry_month'] ?? 0);
        $year = (int) ($method['expiry_year'] ?? 0);
        $token->set_expiry_month($month >= 1 && $month <= 12 ? sprintf('%02d', $month) : '12');
        $token->set_expiry_year((string) ($year >= 2000 ? $year : (int) gmdate('Y') + 5));
        $token->set_token($id);
        $token->set_gateway_id(Keys::GATEWAY_ID);
        $token->set_user_id($userId);
        $token->add_meta_data(self::META_MODE, $mode, true);
        $token->add_meta_data(self::META_CUSTOMER, $customer, true);
        if ($userId > 0 && !\WC_Payment_Tokens::get_customer_default_token($userId)) {
            $token->set_default(true);
        }
        return $token->save() ? (int) $token->get_id() : 0;
    }

    public static function label(int $tokenId): string
    {
        $token = \WC_Payment_Tokens::get($tokenId);
        return $token ? wp_strip_all_tags($token->get_display_name()) : __('a card', 'stackless-lite');
    }

    /** @return \WC_Payment_Token[] rows of a card, of one user (>= 0) or of everyone (-1) */
    public static function find(string $methodId, int $userId = -1): array
    {
        global $wpdb;
        if ($methodId === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d', $table, Keys::GATEWAY_ID, $methodId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s', $table, Keys::GATEWAY_ID, $methodId));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = \WC_Payment_Tokens::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    private static function mode(\WC_Payment_Token $token): string
    {
        return (string) $token->get_meta(self::META_MODE) === 'live' ? 'live' : 'test';
    }

    /**
     * Hide cards of the other mode: a sandbox card cannot pay a live order.
     *
     * @param mixed  $tokens
     * @param int    $customerId
     * @param string $gatewayId
     * @return mixed
     */
    public static function filterByMode($tokens, $customerId = 0, $gatewayId = '')
    {
        if (!is_array($tokens)) {
            return $tokens;
        }
        $mode = Settings::mode();
        foreach ($tokens as $key => $token) {
            if ($token instanceof \WC_Payment_Token && $token->get_gateway_id() === Keys::GATEWAY_ID && self::mode($token) !== $mode) {
                unset($tokens[$key]);
            }
        }
        return $tokens;
    }

    /**
     * Customer removed a saved card: delete it at Revolut so it can never be charged again. A
     * copy of the live store leaves Revolut alone.
     *
     * @param int   $tokenId
     * @param mixed $token
     */
    public static function onDeleted($tokenId, $token): void
    {
        if (!$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Keys::GATEWAY_ID || !Settings::liveStore()) {
            return;
        }
        $method = (string) $token->get_token();
        $customer = (string) $token->get_meta(self::META_CUSTOMER);
        if ($method === '' || $customer === '' || self::find($method)) {
            return; // Another token row still uses this card.
        }
        $result = Settings::api(self::mode($token))->request('DELETE', 'customers/' . rawurlencode($customer) . '/payment-methods/' . rawurlencode($method));
        if (is_wp_error($result) && Api::status($result) !== 404) {
            Settings::log(sprintf('Could not delete the card of token %d at Revolut: %s', (int) $tokenId, $result->get_error_message()), 'warning');
            return;
        }
        Settings::log(sprintf('Deleted the card of token %d at Revolut.', (int) $tokenId));
    }

    private static function brand(string $brand): string
    {
        $brand = strtolower($brand);
        $map = ['american_express' => 'amex'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }
}
