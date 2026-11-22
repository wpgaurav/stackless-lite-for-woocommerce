<?php

namespace Stackless\Modules\AirwallexGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved consents in WooCommerce's payment token table, which changes at checkout and in My Account.

use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Merchant-triggered card PaymentConsents as WooCommerce payment tokens.
 *
 * token string = consent id (cst_…). Token meta records the mode, the Airwallex account, the
 * customer (cus_…) and the payment method (mtd_…) the consent belongs to.
 */
final class Tokens
{
    public const META_MODE = 'stackless_airwallex_mode';
    public const META_ACCOUNT = 'stackless_airwallex_account_id';
    public const META_CUSTOMER = 'stackless_airwallex_customer';
    public const META_PAYMENT_METHOD = 'stackless_airwallex_payment_method';

    /** @var bool Set while the gateway deletes tokens itself, so Airwallex is not called back. */
    private static $suppressRevoke = false;

    public static function registerHooks(): void
    {
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterByConnection'], 10, 3);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
    }

    /**
     * Saves (or finds) the token for a consent that Sync read and checked.
     *
     * @param array $consent GET pa/payment_consents/{id}
     */
    public static function save(array $consent, int $userId, string $customerId, string $mode, string $account): ?SavedMethod
    {
        $consentId = Api::id($consent['id'] ?? null);
        $method = is_array($consent['payment_method'] ?? null) ? $consent['payment_method'] : [];
        $methodId = Api::id($method['id'] ?? null);
        if ($consentId === '' || $methodId === '' || $customerId === '' || $account === '') {
            return null;
        }
        foreach (self::find($consentId, $userId) as $existing) {
            return SavedMethod::fromToken($existing);
        }

        $card = is_array($method['card'] ?? null) ? $method['card'] : [];
        $token = new \WC_Payment_Token_CC();
        $brand = strtolower(sanitize_text_field((string) ($card['brand'] ?? '')));
        $token->set_card_type($brand === 'american express' ? 'amex' : ($brand !== '' ? $brand : 'card'));
        $token->set_last4(str_pad(substr((string) preg_replace('/\D/', '', (string) ($card['last4'] ?? '')), -4), 4, '0', STR_PAD_LEFT));
        $month = (int) ($card['expiry_month'] ?? 0);
        $year = (int) ($card['expiry_year'] ?? 0);
        $token->set_expiry_month(str_pad((string) ($month >= 1 && $month <= 12 ? $month : 12), 2, '0', STR_PAD_LEFT));
        $token->set_expiry_year((string) ($year >= 2000 && $year <= 9999 ? $year : (int) gmdate('Y') + 5));
        $token->set_token($consentId);
        $token->set_gateway_id(Settings::GATEWAY_ID);
        $token->set_user_id(max(0, $userId));
        $token->add_meta_data(self::META_MODE, $mode === 'live' ? 'live' : 'test', true);
        $token->add_meta_data(self::META_ACCOUNT, $account, true);
        $token->add_meta_data(self::META_CUSTOMER, $customerId, true);
        $token->add_meta_data(self::META_PAYMENT_METHOD, $methodId, true);
        if ($userId > 0 && !\WC_Payment_Tokens::get_customer_default_token($userId)) {
            $token->set_default(true);
        }
        return $token->save() ? SavedMethod::fromToken($token) : null;
    }

    public static function get(int $tokenId): ?SavedMethod
    {
        $token = $tokenId > 0 ? \WC_Payment_Tokens::get($tokenId) : null;
        if (!$token || $token->get_gateway_id() !== Settings::GATEWAY_ID) {
            return null;
        }
        $saved = SavedMethod::fromToken($token);
        return $saved->usable() ? $saved : null;
    }

    /** @return \WC_Payment_Token[] */
    public static function find(string $consentId, int $userId = -1): array
    {
        global $wpdb;
        if ($consentId === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d', $table, Settings::GATEWAY_ID, $consentId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s', $table, Settings::GATEWAY_ID, $consentId));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = \WC_Payment_Tokens::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /** Delete every token of a consent without calling Airwallex (it is already disabled there). */
    public static function forget(string $consentId, string $mode): int
    {
        $count = 0;
        self::$suppressRevoke = true;
        try {
            foreach (self::find($consentId) as $token) {
                if (SavedMethod::fromToken($token)->mode === $mode) {
                    \WC_Payment_Tokens::delete($token->get_id());
                    $count++;
                }
            }
        } finally {
            self::$suppressRevoke = false;
        }
        return $count;
    }

    /**
     * Hide cards saved in the other mode or another account: they cannot be charged there.
     *
     * @param \WC_Payment_Token[] $tokens
     * @param int $customerId
     * @param string $gatewayId
     * @return \WC_Payment_Token[]
     */
    public static function filterByConnection($tokens, $customerId = 0, $gatewayId = '')
    {
        if (!is_array($tokens)) {
            return $tokens;
        }
        foreach ($tokens as $key => $token) {
            if (!$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Settings::GATEWAY_ID) {
                continue;
            }
            $saved = SavedMethod::fromToken($token);
            if ($saved->mode !== Settings::mode() || $saved->account !== Settings::account()) {
                unset($tokens[$key]);
            }
        }
        return $tokens;
    }

    /**
     * Customer removed a saved card in My Account: disable the consent at Airwallex so it can
     * never be charged again. A copy of the live store leaves the live consent alone.
     *
     * @param int $tokenId
     * @param \WC_Payment_Token $token
     */
    public static function onDeleted($tokenId, $token): void
    {
        if (self::$suppressRevoke || !$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Settings::GATEWAY_ID) {
            return;
        }
        $saved = SavedMethod::fromToken($token);
        if (!$saved->usable() || !LiveStore::isLive() || $saved->account !== Settings::account($saved->mode) || self::find($saved->consent)) {
            return; // Not chargeable from here, or another token row still uses this consent.
        }
        $result = Settings::api($saved->mode)->request('POST', 'pa/payment_consents/' . rawurlencode($saved->consent) . '/disable', ['request_id' => Api::requestId()]);
        if (is_wp_error($result) && !Api::notFound($result)) {
            Settings::log(sprintf('Could not disable consent %s after token %d was deleted: %s', $saved->consent, (int) $tokenId, $result->get_error_message()), 'warning');
            return;
        }
        Settings::log(sprintf('Disabled consent %s after token %d was deleted.', $saved->consent, (int) $tokenId));
    }
}
