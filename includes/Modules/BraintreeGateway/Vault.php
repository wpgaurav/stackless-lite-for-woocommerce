<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/** Braintree customers and vaulted cards. Customers are kept per mode and merchant. */
final class Vault
{
    private const ADD_LIMIT = 5;
    private const ADD_WINDOW = 3600;

    public static function customerKey(string $mode, string $account): string
    {
        return Meta::USER_CUSTOMER . ($mode === 'live' ? 'live' : 'test') . '_' . substr(hash('sha256', $account), 0, 10);
    }

    /**
     * @param array<string,mixed> $order Orders::summary()
     * @return string|\WP_Error
     */
    public static function customerForOrder(array $order, string $mode, string $account)
    {
        if ($order['user'] > 0) {
            return self::customerForUser($order['user'], $mode, $account, $order['first'], $order['last'], $order['email']);
        }
        $stored = Orders::meta($order['id'], Meta::CUSTOMER);
        if (is_array($stored) && ($stored['account'] ?? '') === $account && ($stored['mode'] ?? '') === $mode && !empty($stored['id'])) {
            return (string) $stored['id'];
        }
        $id = self::create($mode, $order['first'], $order['last'], $order['email']);
        if (is_string($id)) {
            Orders::setMeta($order['id'], [Meta::CUSTOMER => ['account' => $account, 'mode' => $mode, 'id' => $id]]);
        }
        return $id;
    }

    /** @return string|\WP_Error */
    public static function customerForUser(int $user, string $mode, string $account, string $first = '', string $last = '', string $email = '')
    {
        $key = self::customerKey($mode, $account);
        $stored = (string) get_user_meta($user, $key, true);
        if ($stored !== '') {
            return $stored;
        }
        $data = get_userdata($user);
        if ($data) {
            $first = $first !== '' ? $first : (string) $data->first_name;
            $last = $last !== '' ? $last : (string) $data->last_name;
            $email = $email !== '' ? $email : (string) $data->user_email;
        }
        $id = self::create($mode, $first, $last, $email);
        if (is_string($id)) {
            update_user_meta($user, $key, $id);
        }
        return $id;
    }

    /** @param array<string,mixed> $order */
    public static function forgetCustomer(array $order, string $mode, string $account): void
    {
        if ($order['user'] > 0) {
            delete_user_meta($order['user'], self::customerKey($mode, $account));
        } else {
            Orders::setMeta($order['id'], [Meta::CUSTOMER => null]);
        }
    }

    /** @return string|\WP_Error */
    private static function create(string $mode, string $first, string $last, string $email)
    {
        $customer = [];
        if ($first !== '') {
            $customer['firstName'] = self::cut($first);
        }
        if ($last !== '') {
            $customer['lastName'] = self::cut($last);
        }
        if ($email !== '' && is_email($email)) {
            $customer['email'] = $email;
        }
        $variables = $customer ? ['input' => ['customer' => $customer]] : [];
        $data = Config::api($mode)->query(Queries::CREATE_CUSTOMER, $variables, 20);
        if (is_wp_error($data)) {
            return $data;
        }
        $id = (string) ($data['createCustomer']['customer']['id'] ?? '');
        return $id !== '' ? $id : new \WP_Error('stackless_braintree_customer', __('Braintree did not create a customer.', 'stackless-lite'));
    }

    private static function cut(string $value): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, 255) : substr($value, 0, 255);
    }

    /**
     * Verify and vault a card without charging it. The verification keeps the nonce's 3-D
     * Secure result, which is what lets later merchant-initiated charges through.
     *
     * @param array<string,string> $billing
     * @return Card|array{category:string, code:string} The saved card, or why not.
     */
    public static function store(string $mode, string $account, string $nonce, string $customer, string $merchantAccount, int $user, array $billing = [])
    {
        $input = [
            'paymentMethodId' => $nonce,
            'customerId' => $customer,
            'verification' => ['merchantAccountId' => $merchantAccount, 'skip' => false],
        ];
        if ($billing) {
            $input['billingAddress'] = $billing;
        }
        $result = Config::api($mode)->request(Queries::VAULT, ['input' => $input], Api::WRITE_TIMEOUT);
        $payload = $result['data']['vaultCreditCard'] ?? null;
        $verification = is_array($payload) && is_array($payload['verification'] ?? null) ? $payload['verification'] : null;
        if ($verification && ($verification['status'] ?? '') !== 'VERIFIED') {
            return Declines::fromVerification($verification);
        }
        $card = is_array($payload) ? Card::fromPaymentMethod($payload['paymentMethod'] ?? null, $user, $mode, $account) : null;
        if (!$card) {
            if ($user > 0 && $result['error'] && Api::inputField($result['error']) === 'customerId') {
                // Deleted at Braintree: the next try creates a new one.
                delete_user_meta($user, self::customerKey($mode, $account));
            }
            return $result['error'] ? Declines::fromError($result['error']) : ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => 'not_saved'];
        }
        $saved = Tokens::save($card);
        return $saved ?: ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => 'not_stored'];
    }

    /**
     * The account pages' "Add payment method".
     *
     * @return Card|\WP_Error
     */
    public static function addCard(int $user, string $postedNonce, string $currency)
    {
        $mode = Config::mode();
        $nonce = Payments::cleanNonce($postedNonce);
        if ($user <= 0 || $nonce === '') {
            return new \WP_Error('stackless_braintree_card', __('Enter your card details to continue.', 'stackless-lite'));
        }
        // Each try is a card verification, which is what card testers are after.
        $limit = 'stackless_braintree_gateway_add_' . $user;
        $tries = (int) get_transient($limit);
        if ($tries >= self::ADD_LIMIT) {
            return new \WP_Error('stackless_braintree_card', __('Too many cards were tried. Wait an hour, then try again.', 'stackless-lite'));
        }
        set_transient($limit, $tries + 1, self::ADD_WINDOW);

        $connection = Connection::ensure($mode);
        $merchantAccount = $connection ? Connection::merchantAccount($mode, $currency, $connection) : null;
        if (!$connection || !$merchantAccount) {
            return new \WP_Error('stackless_braintree_card', __('Cards cannot be saved with Braintree right now.', 'stackless-lite'));
        }
        $saved = Lock::run('user-' . $user, static function () use ($mode, $connection, $merchantAccount, $nonce, $user) {
            $read = Config::api($mode)->query(Queries::PAYMENT_METHOD, ['id' => $nonce]);
            if (is_wp_error($read)) {
                return Declines::fromError($read);
            }
            $refusal = ThreeDSecure::refusal($read['node'] ?? null, Config::threeDSecure());
            if ($refusal) {
                return $refusal;
            }
            $customer = self::customerForUser($user, $mode, (string) $connection['account']);
            if (is_wp_error($customer)) {
                return Declines::fromError($customer);
            }
            return self::store($mode, (string) $connection['account'], $nonce, $customer, $merchantAccount['id'], $user);
        }, null);
        if ($saved instanceof Card) {
            return $saved;
        }
        return new \WP_Error('stackless_braintree_card', is_array($saved) ? Declines::message($saved) : __('Your card is already being saved. Wait a moment, then reload the page.', 'stackless-lite'));
    }

    /**
     * Delete a card the customer removed, unless another row still uses it. Never from a copy
     * of the live store, which would delete the live store's card.
     */
    public static function deleteRemote(Card $card): void
    {
        if ($card->remote === '' || Tokens::inUse($card->remote) || !LiveStore::isLive()) {
            return;
        }
        if (Connection::account($card->mode) !== $card->account) {
            return;
        }
        $result = Config::api($card->mode)->query(Queries::DELETE_CARD, ['input' => ['paymentMethodId' => $card->remote]]);
        if (is_wp_error($result) && Api::errorClass($result) !== 'NOT_FOUND') {
            Config::log(sprintf('Could not delete saved card %d from the Braintree vault: %s', $card->id, $result->get_error_message()), 'warning');
        }
    }
}
