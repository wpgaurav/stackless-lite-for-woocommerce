<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Stripe Customer objects. One per WordPress user per mode (test customers do not exist in
 * live mode), stored in user meta. Guests get a customer stored on the order only.
 */
final class Customers
{
    public static function userMetaKey(string $mode): string
    {
        return '_stackless_stripe_customer_' . ($mode === 'live' ? 'live' : 'test');
    }

    public static function forUser(int $user_id, StripeClient $client, bool $create = true): string
    {
        if ($user_id <= 0) {
            return '';
        }
        $existing = (string) get_user_meta($user_id, self::userMetaKey($client->mode()), true);
        if ($existing !== '' || !$create) {
            return $existing;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }
        $customer = self::create($client, [
            'email' => $user->user_email,
            'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
            'metadata' => ['wp_user_id' => (string) $user_id],
        ], 'user-' . $user_id);
        update_user_meta($user_id, self::userMetaKey($client->mode()), $customer);
        return $customer;
    }

    /**
     * Customer for an order: the user's customer when the order belongs to a user, otherwise
     * the customer already on the order, otherwise a new one built from the billing details.
     */
    public static function forOrder(\WC_Order $order, StripeClient $client, bool $create = true): string
    {
        $user_id = (int) $order->get_customer_id();
        if ($user_id > 0) {
            $id = self::forUser($user_id, $client, $create);
            if ($id !== '') {
                return $id;
            }
        }
        $existing = (string) $order->get_meta(SubscriptionData::META_GATEWAY_CUSTOMER);
        if ($existing !== '' && (string) $order->get_meta(Payments::META_MODE) === $client->mode()) {
            return $existing;
        }
        if (!$create) {
            return '';
        }
        $params = [
            'email' => $order->get_billing_email(),
            'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'phone' => $order->get_billing_phone(),
            'metadata' => ['wc_order_id' => (string) $order->get_id(), 'stackless_site' => Settings::siteMarker()],
        ];
        $address = self::address($order, 'billing');
        if ($address) {
            $params['address'] = $address;
        }
        return self::create($client, array_filter($params), 'order-' . $order->get_id() . '-' . $client->mode());
    }

    /** Forget a stored customer id that Stripe reports as missing (deleted in the Dashboard). */
    public static function forget(int $user_id, string $mode, string $customer_id): void
    {
        if ($user_id > 0 && (string) get_user_meta($user_id, self::userMetaKey($mode), true) === $customer_id) {
            delete_user_meta($user_id, self::userMetaKey($mode));
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function create(StripeClient $client, array $params, string $scope): string
    {
        $params['metadata'] = array_merge(isset($params['metadata']) && is_array($params['metadata']) ? $params['metadata'] : [], [
            'stackless_site' => Settings::siteMarker(),
            'site_url' => home_url('/'),
        ]);
        // A fresh key per create call: network retries inside the client reuse it, but a
        // customer deleted in the Dashboard can still be recreated.
        $customer = $client->post('customers', $params, IdempotencyKey::customer($scope . '-' . Settings::siteMarker() . '-' . wp_generate_uuid4()));
        $id = (string) ($customer['id'] ?? '');
        if ($id === '') {
            throw new StripeApiException(esc_html__('Stripe did not return a customer.', 'stackless-lite'), 502, ['type' => 'api_error']);
        }
        Settings::log(sprintf('Created Stripe customer %s (%s).', $id, $scope), 'info');
        return $id;
    }

    /** @return array<string,string> */
    private static function address(\WC_Order $order, string $type): array
    {
        $getter = function (string $field) use ($order, $type): string {
            $method = 'get_' . $type . '_' . $field;
            return is_callable([$order, $method]) ? (string) $order->{$method}() : '';
        };
        $address = array_filter([
            'line1' => $getter('address_1'),
            'line2' => $getter('address_2'),
            'city' => $getter('city'),
            'state' => $getter('state'),
            'postal_code' => $getter('postcode'),
            'country' => $getter('country'),
        ]);
        return isset($address['line1']) || isset($address['country']) ? $address : [];
    }
}
