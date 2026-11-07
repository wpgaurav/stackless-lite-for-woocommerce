<?php

namespace Stackless\Modules\SquareGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved cards in WooCommerce's payment token table, which changes at checkout and in My Account.

defined('ABSPATH') || exit;

/**
 * Square cards on file as WooCommerce card tokens.
 *
 * token string = Square card id (ccof:…). Token meta records the Square mode and the
 * Square customer the card belongs to.
 */
final class Tokens
{
    public const META_MODE = 'stackless_square_mode';
    public const META_CUSTOMER = 'stackless_square_customer';

    /** @var bool Set while the gateway deletes tokens itself, so Square is not called back. */
    private static $suppressDisable = false;

    public static function registerHooks(): void
    {
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterByMode'], 10, 3);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
    }

    /** Save (or find) the token for a Square card object. */
    public static function saveFromCard(array $card, int $userId, string $mode): ?\WC_Payment_Token
    {
        $cardId = (string) ($card['id'] ?? '');
        $customerId = (string) ($card['customer_id'] ?? '');
        if ($cardId === '' || $customerId === '' || (isset($card['enabled']) && $card['enabled'] === false)) {
            return null;
        }
        $existing = self::find($cardId, $userId);
        if ($existing) {
            return $existing[0];
        }
        $token = new \WC_Payment_Token_CC();
        $token->set_card_type(self::brand((string) ($card['card_brand'] ?? '')));
        $token->set_last4(str_pad(substr(preg_replace('/\D/', '', (string) ($card['last_4'] ?? '')), -4), 4, '0', STR_PAD_LEFT));
        $token->set_expiry_month(str_pad((string) max(1, min(12, (int) ($card['exp_month'] ?? 12))), 2, '0', STR_PAD_LEFT));
        $token->set_expiry_year((string) ((int) ($card['exp_year'] ?? ((int) gmdate('Y') + 5))));
        $token->set_token($cardId);
        $token->set_gateway_id(Gateway::ID);
        $token->set_user_id(max(0, $userId));
        $token->add_meta_data(self::META_MODE, $mode === 'live' ? 'live' : 'test', true);
        $token->add_meta_data(self::META_CUSTOMER, $customerId, true);
        if ($userId > 0 && !\WC_Payment_Tokens::get_customer_default_token($userId)) {
            $token->set_default(true);
        }
        return $token->save() ? $token : null;
    }

    /** Refresh expiry and last four after Square's account updater changed a card. */
    public static function updateFromCard(array $card): int
    {
        $count = 0;
        foreach (self::find((string) ($card['id'] ?? '')) as $token) {
            if (!$token instanceof \WC_Payment_Token_CC) {
                continue;
            }
            if (!empty($card['exp_month'])) {
                $token->set_expiry_month(str_pad((string) (int) $card['exp_month'], 2, '0', STR_PAD_LEFT));
            }
            if (!empty($card['exp_year'])) {
                $token->set_expiry_year((string) (int) $card['exp_year']);
            }
            if (!empty($card['last_4'])) {
                $token->set_last4(str_pad(substr(preg_replace('/\D/', '', (string) $card['last_4']), -4), 4, '0', STR_PAD_LEFT));
            }
            $token->save();
            $count++;
        }
        return $count;
    }

    /** @return \WC_Payment_Token[] */
    public static function find(string $cardId, int $userId = -1): array
    {
        global $wpdb;
        if ($cardId === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d', $table, Gateway::ID, $cardId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s', $table, Gateway::ID, $cardId));
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

    public static function forget(string $cardId): int
    {
        $count = 0;
        self::$suppressDisable = true;
        try {
            foreach (self::find($cardId) as $token) {
                \WC_Payment_Tokens::delete($token->get_id());
                $count++;
            }
        } finally {
            self::$suppressDisable = false;
        }
        return $count;
    }

    /**
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
     * Customer removed a saved card in My Account: disable it at Square so it is never charged.
     *
     * @param int $tokenId
     * @param \WC_Payment_Token $token
     */
    public static function onDeleted($tokenId, $token): void
    {
        if (self::$suppressDisable || !$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Gateway::ID) {
            return;
        }
        $cardId = $token->get_token();
        if ($cardId === '' || self::find($cardId)) {
            return;
        }
        $gateway = Gateway::instance();
        $result = $gateway->api(self::mode($token))->request('POST', 'cards/' . rawurlencode($cardId) . '/disable');
        if (is_wp_error($result) && Api::status($result) !== 404) {
            $gateway->log(sprintf('Could not disable card %s after token %d was deleted: %s', $cardId, (int) $tokenId, $result->get_error_message()), 'warning');
        }
    }

    private static function brand(string $brand): string
    {
        $map = [
            'VISA' => 'visa',
            'MASTERCARD' => 'mastercard',
            'AMERICAN_EXPRESS' => 'amex',
            'DISCOVER' => 'discover',
            'DISCOVER_DINERS' => 'diners',
            'JCB' => 'jcb',
            'CHINA_UNIONPAY' => 'unionpay',
            'INTERAC' => 'interac',
            'EFTPOS' => 'eftpos',
            'FELICA' => 'felica',
        ];
        return $map[strtoupper($brand)] ?? 'card';
    }
}
