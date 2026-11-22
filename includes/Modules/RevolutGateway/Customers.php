<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Revolut customers, which a card must hang on to be saved: one per account and mode, one per
 * guest order. A stored id is read back first, so a change of Revolut account starts a new one.
 */
final class Customers
{
    /** @return string|\WP_Error */
    public static function forOrder(int $orderId, string $mode)
    {
        $userId = Orders::user($orderId);
        $email = Orders::email($orderId);
        $name = Orders::name($orderId);
        if ($userId > 0) {
            return self::forUser($userId, $mode, $name, $email);
        }
        $owner = $mode . ':' . Attempts::buyer(0, $email) . ':';
        $stored = Orders::meta($orderId, Keys::GUEST_CUSTOMER);
        $customer = self::ensure($mode, strpos($stored, $owner) === 0 ? substr($stored, strlen($owner)) : '', $name, $email);
        if (!is_wp_error($customer) && $stored !== $owner . $customer) {
            Orders::setMeta($orderId, [Keys::GUEST_CUSTOMER => $owner . $customer]);
        }
        return $customer;
    }

    /** @return string|\WP_Error */
    public static function forUser(int $userId, string $mode, string $name = '', string $email = '')
    {
        $key = Keys::USER_CUSTOMER . $mode;
        $stored = (string) get_user_meta($userId, $key, true);
        $user = get_userdata($userId);
        if ($user) {
            // The account's own details: a posted email never renames or claims a customer.
            $name = $name !== '' ? $name : trim($user->first_name . ' ' . $user->last_name);
            $email = (string) $user->user_email !== '' ? (string) $user->user_email : $email;
        }
        $customer = self::ensure($mode, $stored, $name, $email);
        if (!is_wp_error($customer) && $customer !== $stored) {
            update_user_meta($userId, $key, $customer);
        }
        return $customer;
    }

    /** @return string|\WP_Error */
    private static function ensure(string $mode, string $stored, string $name, string $email)
    {
        $api = Settings::api($mode);
        if ($stored !== '' && Api::isId($stored)) {
            $found = $api->request('GET', 'customers/' . rawurlencode($stored));
            if (!is_wp_error($found) && (string) ($found['id'] ?? '') === $stored) {
                return $stored;
            }
            if (is_wp_error($found) && Api::status($found) !== 404) {
                return $found;
            }
        }
        if (!is_email($email)) {
            return new \WP_Error('stackless_revolut_email', __('An email address is needed to save a card with Revolut.', 'stackless-lite'));
        }
        $body = ['email' => $email];
        if ($name !== '') {
            $body['full_name'] = Api::text($name, 250);
        }
        $created = $api->request('POST', 'customers', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = (string) ($created['id'] ?? '');
        return Api::isId($id) ? $id : new \WP_Error('stackless_revolut_customer', __('Revolut did not create a customer.', 'stackless-lite'));
    }
}
