<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Cards vaulted at PayPal (Advanced Card Fields, Fastlane, adopted WooCommerce PayPal Payments
 * cards), stored as WooCommerce card tokens for this gateway (2.0.0).
 *
 * token = PayPal vault id; meta records the PayPal environment, the PayPal customer id and
 * that the vault pays with a card, so renewals send payment_source.card.vault_id.
 */
final class CardTokens
{
    public const META_SOURCE = 'stackless_paypal_source';
    public const META_ENV = 'stackless_paypal_environment';
    public const META_CUSTOMER = 'stackless_paypal_customer';

    public static function isCard(\WC_Payment_Token $token): bool
    {
        return $token instanceof \WC_Payment_Token_CC && $token->get_gateway_id() === Gateway::ID;
    }

    public static function environment(\WC_Payment_Token $token): string
    {
        $env = (string) $token->get_meta(self::META_ENV);
        return $env === 'sandbox' ? 'sandbox' : ($env === 'live' ? 'live' : '');
    }

    public static function customer(\WC_Payment_Token $token): string
    {
        return (string) $token->get_meta(self::META_CUSTOMER);
    }

    /**
     * Saves (or finds) the token for a vaulted card.
     *
     * @param array<string,mixed> $card payment_source.card: brand, last_digits, expiry (YYYY-MM)
     */
    public static function save(int $userId, string $vaultId, array $card, string $customerId, string $environment): int
    {
        if ($vaultId === '') {
            return 0;
        }
        foreach (\WC_Payment_Tokens::get_customer_tokens($userId, Gateway::ID) as $existing) {
            if ($existing->get_token() === $vaultId) {
                return (int) $existing->get_id();
            }
        }
        $token = new \WC_Payment_Token_CC();
        $token->set_token($vaultId);
        $token->set_gateway_id(Gateway::ID);
        $token->set_user_id(max(0, $userId));
        $token->set_card_type(self::brand((string) ($card['brand'] ?? '')));
        $token->set_last4(self::last4((string) ($card['last_digits'] ?? '')));
        [$year, $month] = self::expiry((string) ($card['expiry'] ?? ''));
        $token->set_expiry_month($month);
        $token->set_expiry_year($year);
        $token->add_meta_data(self::META_SOURCE, 'card', true);
        $token->add_meta_data(self::META_ENV, $environment === 'sandbox' ? 'sandbox' : 'live', true);
        if ($customerId !== '') {
            $token->add_meta_data(self::META_CUSTOMER, $customerId, true);
        }
        if (!$token->save()) {
            return 0;
        }
        return (int) $token->get_id();
    }

    /** PayPal card brand (VISA, MASTERCARD, AMEX…) as a WooCommerce card type. */
    public static function brand(string $brand): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $brand));
        $map = ['mastercard' => 'mastercard', 'master-card' => 'mastercard', 'american-express' => 'amex', 'amex' => 'amex', 'diners' => 'diners', 'diners-club' => 'diners', 'unknown' => 'card'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    public static function last4(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        return str_pad(substr($digits, -4), 4, '0', STR_PAD_LEFT);
    }

    /** "2028-07" → ["2028", "07"]; anything else → a far-future placeholder WooCommerce accepts. @return array{0:string,1:string} */
    public static function expiry(string $value): array
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($value), $m)) {
            return [$m[1], str_pad((string) ((int) $m[2]), 2, '0', STR_PAD_LEFT)];
        }
        return [gmdate('Y', time() + 5 * YEAR_IN_SECONDS), '12'];
    }
}
