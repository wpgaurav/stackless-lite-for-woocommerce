<?php

namespace Stackless\Modules\StripeGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved cards in WooCommerce's payment token table, which changes at checkout and in My Account.

defined('ABSPATH') || exit;

/**
 * Saved payment methods as WooCommerce payment tokens.
 *
 * token string = Stripe PaymentMethod id (pm_...). Token meta records the Stripe mode, the
 * Stripe customer the method is attached to, and the Stripe payment method type.
 */
final class Tokens
{
    public const META_MODE = 'stackless_stripe_mode';
    public const META_CUSTOMER = 'stackless_stripe_customer';
    public const META_TYPE = 'stackless_stripe_type';

    /** Bank debit types stored as eCheck tokens (they expose last4). */
    private const BANK_TYPES = ['sepa_debit', 'us_bank_account', 'bacs_debit', 'au_becs_debit', 'acss_debit', 'nz_bank_account'];

    /** @var bool Set while a webhook deletes a token so we don't call Stripe back. */
    private static $suppressDetach = false;

    /**
     * Saves (or finds) the WooCommerce token for a Stripe PaymentMethod object.
     *
     * @param array<string,mixed> $pm Stripe PaymentMethod object.
     */
    public static function saveFromPaymentMethod(array $pm, int $user_id, string $customer_id, string $mode): ?\WC_Payment_Token
    {
        $pm_id = (string) ($pm['id'] ?? '');
        if ($pm_id === '' || (strpos($pm_id, 'pm_') !== 0 && strpos($pm_id, 'card_') !== 0 && strpos($pm_id, 'src_') !== 0)) {
            return null;
        }

        $existing = self::findByPaymentMethod($pm_id, $user_id);
        if ($existing) {
            return $existing[0];
        }

        $type = (string) ($pm['type'] ?? 'card');
        if ($type === 'card' && isset($pm['card']) && is_array($pm['card'])) {
            $card = $pm['card'];
            $token = new \WC_Payment_Token_CC();
            $token->set_card_type(self::brand((string) ($card['display_brand'] ?? $card['brand'] ?? 'card')));
            $token->set_last4(self::last4((string) ($card['last4'] ?? '')));
            $token->set_expiry_month(str_pad((string) ((int) ($card['exp_month'] ?? 1)), 2, '0', STR_PAD_LEFT));
            $token->set_expiry_year((string) ((int) ($card['exp_year'] ?? gmdate('Y'))));
        } elseif (in_array($type, self::BANK_TYPES, true) && isset($pm[$type]['last4'])) {
            $token = new \WC_Payment_Token_ECheck();
            $token->set_last4(self::last4((string) $pm[$type]['last4']));
        } else {
            $token = new PaymentToken();
            $token->set_method_type($type);
            $token->set_label(self::label($pm));
        }

        $token->set_token($pm_id);
        $token->set_gateway_id(Settings::GATEWAY_ID);
        $token->set_user_id(max(0, $user_id));
        $token->add_meta_data(self::META_MODE, $mode, true);
        $token->add_meta_data(self::META_CUSTOMER, $customer_id, true);
        $token->add_meta_data(self::META_TYPE, $type, true);

        if ($user_id > 0 && !\WC_Payment_Tokens::get_customer_default_token($user_id)) {
            $token->set_default(true);
        }
        if (!$token->save()) {
            Settings::log(sprintf('Could not save a payment token for %s.', $pm_id), 'error');
            return null;
        }
        Settings::log(sprintf('Saved payment token %d for %s (user %d).', $token->get_id(), $pm_id, $user_id), 'info');
        return $token;
    }

    /**
     * @return \WC_Payment_Token[]
     */
    public static function findByPaymentMethod(string $pm_id, int $user_id = -1): array
    {
        global $wpdb;
        if ($pm_id === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        if ($user_id >= 0) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d", $table, Settings::GATEWAY_ID, $pm_id, $user_id));
        } else {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s", $table, Settings::GATEWAY_ID, $pm_id));
        }
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = \WC_Payment_Tokens::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /** Token owned by $user_id (0 = guest-owned) for this gateway, or null. */
    public static function getOwned(int $token_id, int $user_id): ?\WC_Payment_Token
    {
        $token = $token_id > 0 ? \WC_Payment_Tokens::get($token_id) : null;
        if (!$token || $token->get_gateway_id() !== Settings::GATEWAY_ID || (int) $token->get_user_id() !== $user_id) {
            return null;
        }
        return $token;
    }

    public static function mode(\WC_Payment_Token $token): string
    {
        $mode = (string) $token->get_meta(self::META_MODE);
        return $mode === 'live' ? 'live' : ($mode === 'test' ? 'test' : Settings::mode());
    }

    public static function customer(\WC_Payment_Token $token): string
    {
        return (string) $token->get_meta(self::META_CUSTOMER);
    }

    public static function methodType(\WC_Payment_Token $token): string
    {
        $type = (string) $token->get_meta(self::META_TYPE);
        if ($type !== '') {
            return $type;
        }
        return $token instanceof \WC_Payment_Token_ECheck ? 'sepa_debit' : 'card';
    }

    /* ---------------------------------------------------------------------
     * Hooks
     * ------------------------------------------------------------------- */

    public static function registerHooks(): void
    {
        add_filter('woocommerce_payment_token_class', [self::class, 'filterTokenClass'], 10, 2);
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterByMode'], 10, 3);
        add_filter('woocommerce_payment_methods_list_item', [self::class, 'filterListItem'], 20, 2);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
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
     * Hide tokens created in the other Stripe mode: a test card cannot pay a live order.
     *
     * @param \WC_Payment_Token[] $tokens
     * @param int $customer_id
     * @param string $gateway_id
     * @return \WC_Payment_Token[]
     */
    public static function filterByMode($tokens, $customer_id = 0, $gateway_id = '')
    {
        if (!is_array($tokens)) {
            return $tokens;
        }
        $mode = Settings::mode();
        foreach ($tokens as $key => $token) {
            if ($token instanceof \WC_Payment_Token && $token->get_gateway_id() === Settings::GATEWAY_ID && self::mode($token) !== $mode) {
                unset($tokens[$key]);
            }
        }
        return $tokens;
    }

    /**
     * @param array<string,mixed> $item
     * @param \WC_Payment_Token $token
     * @return array<string,mixed>
     */
    public static function filterListItem($item, $token)
    {
        if ($token instanceof PaymentToken) {
            $item['method']['brand'] = $token->get_display_name();
        } elseif ($token instanceof \WC_Payment_Token_ECheck && $token->get_gateway_id() === Settings::GATEWAY_ID) {
            $item['method']['brand'] = self::typeLabel(self::methodType($token));
        }
        return $item;
    }

    /**
     * Customer removed a saved method in My Account: detach it at Stripe so it can never be charged.
     *
     * @param int $token_id
     * @param \WC_Payment_Token $token
     */
    public static function onDeleted($token_id, $token): void
    {
        if (self::$suppressDetach || !$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Settings::GATEWAY_ID) {
            return;
        }
        $pm_id = $token->get_token();
        if ($pm_id === '' || self::findByPaymentMethod($pm_id)) {
            return; // Another token row still uses this method.
        }
        try {
            Settings::client(self::mode($token))->post('payment_methods/' . rawurlencode($pm_id) . '/detach');
            Settings::log(sprintf('Detached %s after token %d was deleted.', $pm_id, (int) $token_id), 'info');
        } catch (StripeApiException $e) {
            Settings::log(sprintf('Could not detach %s: %s', $pm_id, $e->logSummary()), 'warning');
        }
    }

    /** Delete tokens for a PaymentMethod that Stripe says is gone, without calling Stripe again. */
    public static function deleteForPaymentMethod(string $pm_id): int
    {
        $count = 0;
        self::$suppressDetach = true;
        try {
            foreach (self::findByPaymentMethod($pm_id) as $token) {
                \WC_Payment_Tokens::delete($token->get_id());
                $count++;
            }
        } finally {
            self::$suppressDetach = false;
        }
        return $count;
    }

    /**
     * Refresh card details after the network updated them (new expiry, reissued card).
     *
     * @param array<string,mixed> $pm
     */
    public static function updateFromPaymentMethod(array $pm): int
    {
        $count = 0;
        $card = isset($pm['card']) && is_array($pm['card']) ? $pm['card'] : null;
        foreach (self::findByPaymentMethod((string) ($pm['id'] ?? '')) as $token) {
            if ($card && $token instanceof \WC_Payment_Token_CC) {
                if (!empty($card['exp_month'])) {
                    $token->set_expiry_month(str_pad((string) ((int) $card['exp_month']), 2, '0', STR_PAD_LEFT));
                }
                if (!empty($card['exp_year'])) {
                    $token->set_expiry_year((string) ((int) $card['exp_year']));
                }
                if (!empty($card['last4'])) {
                    $token->set_last4(self::last4((string) $card['last4']));
                }
                if (!empty($card['brand'])) {
                    $token->set_card_type(self::brand((string) ($card['display_brand'] ?? $card['brand'])));
                }
                $token->save();
                $count++;
            } elseif ($token instanceof PaymentToken) {
                $token->set_label(self::label($pm));
                $token->save();
                $count++;
            }
        }
        return $count;
    }

    /* ---------------------------------------------------------------------
     * Labels
     * ------------------------------------------------------------------- */

    /** @param array<string,mixed> $pm */
    private static function label(array $pm): string
    {
        $type = (string) ($pm['type'] ?? '');
        $name = self::typeLabel($type);
        $details = isset($pm[$type]) && is_array($pm[$type]) ? $pm[$type] : [];
        if (!empty($details['email'])) {
            return $name . ' (' . sanitize_text_field((string) $details['email']) . ')';
        }
        if (!empty($details['last4'])) {
            /* translators: 1: payment method name, 2: last four digits */
            return sprintf(__('%1$s ending in %2$s', 'stackless-lite'), $name, self::last4((string) $details['last4']));
        }
        return $name;
    }

    public static function typeLabel(string $type): string
    {
        $labels = [
            'card' => __('Card', 'stackless-lite'),
            'link' => __('Link', 'stackless-lite'),
            'sepa_debit' => __('SEPA Direct Debit', 'stackless-lite'),
            'us_bank_account' => __('US bank account', 'stackless-lite'),
            'bacs_debit' => __('Bacs Direct Debit', 'stackless-lite'),
            'au_becs_debit' => __('BECS Direct Debit', 'stackless-lite'),
            'acss_debit' => __('Pre-authorized debit', 'stackless-lite'),
            'paypal' => __('PayPal', 'stackless-lite'),
            'cashapp' => __('Cash App Pay', 'stackless-lite'),
            'revolut_pay' => __('Revolut Pay', 'stackless-lite'),
            'amazon_pay' => __('Amazon Pay', 'stackless-lite'),
        ];
        return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    private static function brand(string $brand): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $brand));
        $map = ['american-express' => 'amex', 'diners-club' => 'diners', 'cartes-bancaires' => 'cartes_bancaires', 'unknown' => 'card'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    private static function last4(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        return str_pad(substr((string) $digits, -4), 4, '0', STR_PAD_LEFT);
    }
}
