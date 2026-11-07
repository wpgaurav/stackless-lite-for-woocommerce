<?php

namespace Stackless\Modules\RazorpayGateway;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- looks up saved tokens in WooCommerce's payment token table, which changes at checkout and in My Account.

defined('ABSPATH') || exit;

/**
 * Razorpay recurring tokens as WooCommerce payment tokens.
 *
 * token string = Razorpay token id (token_…). Token meta records the mode, the Razorpay
 * customer (cust_…), the method (card, upi, emandate), the mandate ceiling in paise, the
 * mandate status (initiated, confirmed, paused, rejected, cancelled) and the email and phone
 * Razorpay needs for every recurring debit.
 */
final class Tokens
{
    public const META_MODE = 'stackless_razorpay_mode';
    public const META_CUSTOMER = 'stackless_razorpay_customer';
    public const META_METHOD = 'stackless_razorpay_method';
    public const META_MAX = 'stackless_razorpay_max_amount';
    public const META_STATUS = 'stackless_razorpay_status';
    public const META_EMAIL = 'stackless_razorpay_email';
    public const META_CONTACT = 'stackless_razorpay_contact';

    /** @var bool Set while the gateway deletes tokens itself, so Razorpay is not called back. */
    private static $suppressCancel = false;

    public static function registerHooks(): void
    {
        add_filter('woocommerce_payment_token_class', [self::class, 'filterTokenClass'], 10, 2);
        add_filter('woocommerce_get_customer_payment_tokens', [self::class, 'filterByMode'], 10, 3);
        add_filter('woocommerce_payment_methods_list_item', [self::class, 'filterListItem'], 20, 2);
        add_action('woocommerce_payment_token_deleted', [self::class, 'onDeleted'], 10, 2);
    }

    /**
     * Save (or refresh) the WooCommerce token for a Razorpay token entity.
     *
     * @param array $entity  Razorpay token (GET customers/{id}/tokens/{id}).
     * @param array $payment The authorization payment (email and contact for later debits).
     */
    public static function saveFromEntity(array $entity, int $userId, string $customerId, string $mode, array $payment = []): ?\WC_Payment_Token
    {
        $tokenId = (string) ($entity['id'] ?? '');
        if (strpos($tokenId, 'token_') !== 0 || $customerId === '') {
            return null;
        }
        $existing = self::find($tokenId, $userId);
        if ($existing) {
            self::updateStatus($tokenId, self::statusOf($entity));
            return $existing[0];
        }

        $method = (string) ($entity['method'] ?? '');
        if ($method === 'nach') {
            $method = 'emandate';
        }
        if ($method === 'card' && is_array($entity['card'] ?? null)) {
            $card = $entity['card'];
            $token = new \WC_Payment_Token_CC();
            $token->set_card_type(self::brand((string) ($card['network'] ?? '')));
            $token->set_last4(self::last4((string) ($card['last4'] ?? '')));
            $month = (int) ($card['expiry_month'] ?? 12);
            $year = (int) ($card['expiry_year'] ?? ((int) gmdate('Y') + 5));
            $token->set_expiry_month(str_pad((string) max(1, min(12, $month)), 2, '0', STR_PAD_LEFT));
            $token->set_expiry_year((string) ($year < 100 ? 2000 + $year : $year));
        } else {
            $token = new PaymentToken();
            $token->set_label(self::label($method, $entity));
        }

        $token->set_token($tokenId);
        $token->set_gateway_id(Gateway::ID);
        $token->set_user_id(max(0, $userId));
        $token->add_meta_data(self::META_MODE, $mode === 'live' ? 'live' : 'test', true);
        $token->add_meta_data(self::META_CUSTOMER, $customerId, true);
        $token->add_meta_data(self::META_METHOD, $method, true);
        $token->add_meta_data(self::META_MAX, (string) (int) ($entity['max_amount'] ?? 0), true);
        $token->add_meta_data(self::META_STATUS, self::statusOf($entity), true);
        $token->add_meta_data(self::META_EMAIL, sanitize_email((string) ($payment['email'] ?? '')), true);
        $token->add_meta_data(self::META_CONTACT, preg_replace('/[^0-9+]/', '', (string) ($payment['contact'] ?? '')), true);
        if ($userId > 0 && !\WC_Payment_Tokens::get_customer_default_token($userId)) {
            $token->set_default(true);
        }
        if (!$token->save()) {
            return null;
        }
        return $token;
    }

    /** initiated|confirmed|paused|rejected|cancelled from a token entity. */
    public static function statusOf(array $entity): string
    {
        $status = (string) ($entity['recurring_details']['status'] ?? '');
        if ($status === '' && isset($entity['recurring']) && $entity['recurring'] === true) {
            $status = 'confirmed';
        }
        return $status !== '' ? $status : 'initiated';
    }

    /** @return \WC_Payment_Token[] */
    public static function find(string $tokenId, int $userId = -1): array
    {
        global $wpdb;
        if ($tokenId === '') {
            return [];
        }
        $table = $wpdb->prefix . 'woocommerce_payment_tokens';
        $ids = $userId >= 0
            ? $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s AND user_id = %d', $table, Gateway::ID, $tokenId, $userId))
            : $wpdb->get_col($wpdb->prepare('SELECT token_id FROM %i WHERE gateway_id = %s AND token = %s', $table, Gateway::ID, $tokenId));
        $tokens = [];
        foreach ((array) $ids as $id) {
            $token = \WC_Payment_Tokens::get((int) $id);
            if ($token) {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    public static function updateStatus(string $tokenId, string $status): int
    {
        $count = 0;
        foreach (self::find($tokenId) as $token) {
            if ((string) $token->get_meta(self::META_STATUS) !== $status) {
                $token->update_meta_data(self::META_STATUS, $status);
                $token->save();
                $count++;
            }
        }
        return $count;
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
        return $method !== '' ? $method : ($token instanceof \WC_Payment_Token_CC ? 'card' : 'upi');
    }

    public static function status(\WC_Payment_Token $token): string
    {
        $status = (string) $token->get_meta(self::META_STATUS);
        return $status !== '' ? $status : 'confirmed';
    }

    /** Mandate ceiling in paise (0 = unknown). */
    public static function maxMinor(\WC_Payment_Token $token): int
    {
        return (int) $token->get_meta(self::META_MAX);
    }

    /** Delete every token row of a Razorpay token without calling Razorpay (it is gone there). */
    public static function forget(string $tokenId): int
    {
        $count = 0;
        self::$suppressCancel = true;
        try {
            foreach (self::find($tokenId) as $token) {
                \WC_Payment_Tokens::delete($token->get_id());
                $count++;
            }
        } finally {
            self::$suppressCancel = false;
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
     * Hide tokens from the other Razorpay mode: a test mandate cannot pay a live order.
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
        }
        if ($token instanceof \WC_Payment_Token && $token->get_gateway_id() === Gateway::ID && in_array(self::status($token), ['initiated', 'paused'], true)) {
            $item['method']['brand'] = (string) ($item['method']['brand'] ?? '') . ' ' . (self::status($token) === 'paused' ? __('(paused)', 'stackless-lite') : __('(awaiting bank confirmation)', 'stackless-lite'));
        }
        return $item;
    }

    /**
     * Customer removed a saved method in My Account: cancel the mandate at Razorpay so it is
     * never debited again (UPI Autopay has its own cancel call; cards and eNACH are deleted).
     *
     * @param int $tokenId
     * @param \WC_Payment_Token $token
     */
    public static function onDeleted($tokenId, $token): void
    {
        if (self::$suppressCancel || !$token instanceof \WC_Payment_Token || $token->get_gateway_id() !== Gateway::ID) {
            return;
        }
        $remoteId = $token->get_token();
        $customerId = self::customer($token);
        if ($remoteId === '' || $customerId === '' || self::find($remoteId)) {
            return; // Another token row still uses it.
        }
        $gateway = Gateway::instance();
        $api = $gateway->api(self::mode($token));
        $path = 'customers/' . rawurlencode($customerId) . '/tokens/' . rawurlencode($remoteId);
        $result = self::method($token) === 'upi' ? $api->request('PUT', $path . '/cancel') : $api->request('DELETE', $path);
        if (is_wp_error($result) && Api::status($result) !== 404) {
            $gateway->log(sprintf('Could not cancel token %s after token %d was deleted: %s', $remoteId, (int) $tokenId, $result->get_error_message()), 'warning');
            return;
        }
        $gateway->log(sprintf('Cancelled token %s after token %d was deleted.', $remoteId, (int) $tokenId));
    }

    private static function label(string $method, array $entity): string
    {
        if ($method === 'upi') {
            $vpa = is_array($entity['vpa'] ?? null) ? $entity['vpa'] : [];
            $address = trim((string) ($vpa['username'] ?? '') . (isset($vpa['handle']) ? '@' . $vpa['handle'] : ''), '@');
            return Mandate::label('upi') . ($address !== '' ? ' (' . sanitize_text_field($address) . ')' : '');
        }
        $bank = sanitize_text_field((string) ($entity['bank'] ?? ''));
        $account = (string) ($entity['bank_details']['account_number'] ?? '');
        $tail = $account !== '' ? ' ••' . self::last4($account) : '';
        return Mandate::label('emandate') . ($bank !== '' || $tail !== '' ? ' (' . trim($bank . $tail) . ')' : '');
    }

    private static function brand(string $network): string
    {
        $brand = strtolower(str_replace(['_', ' '], '-', $network));
        $map = ['american-express' => 'amex', 'diners-club' => 'diners', 'mastercard' => 'mastercard', 'visa' => 'visa', 'rupay' => 'rupay', 'unknown' => 'card'];
        return $map[$brand] ?? ($brand !== '' ? $brand : 'card');
    }

    private static function last4(string $value): string
    {
        $digits = preg_replace('/[^0-9A-Za-z]/', '', $value);
        return str_pad(substr((string) $digits, -4), 4, '0', STR_PAD_LEFT);
    }
}
