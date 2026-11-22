<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * Airwallex Checkout Sessions for checkout, pay links and "add a payment method".
 *
 * PAYMENT sessions charge one line equal to the order total, so tax, discounts, fees and shipping
 * cannot drift by rounding. A payment that must renew sets payment_method_save to ENABLED with
 * next_triggered_by MERCHANT and offers cards only. An order with nothing to pay that must renew
 * uses a SETUP session, which saves the card without charging it.
 */
final class Checkout
{
    /** @var Sync */
    private $sync;

    public function __construct(?Sync $sync = null)
    {
        $this->sync = $sync ?: new Sync();
    }

    /**
     * Where the buyer goes to pay an order.
     *
     * @return string|\WP_Error The hosted page, or the receipt when nothing is left to pay.
     */
    public function start(int $orderId)
    {
        return Lock::run('order-' . $orderId, function () use ($orderId) {
            return $this->startLocked($orderId);
        }, self::processingError());
    }

    private static function processingError(): \WP_Error
    {
        return new \WP_Error('stackless_airwallex_processing', __('An earlier Airwallex payment for this order is still being checked. Please try again in a minute, and do not pay again another way.', 'stackless-lite'));
    }

    /** @return string|\WP_Error */
    private function startLocked(int $orderId)
    {
        $sale = Sale::get($orderId);
        if (!$sale) {
            return new \WP_Error('stackless_airwallex_order', __('Order not found.', 'stackless-lite'));
        }
        if ($sale->paid() || $sale->waiting()) {
            return $sale->receiptUrl();
        }
        if (!$sale->payable()) {
            return new \WP_Error('stackless_airwallex_status', __('This order cannot be paid in its current status.', 'stackless-lite'));
        }

        $state = $this->sync->reconcile($sale);
        if ($sale->paid() || $sale->waiting()) {
            return $sale->receiptUrl();
        }
        if ($state['outcome'] === 'held') {
            return new \WP_Error('stackless_airwallex_held', __('A payment for this order is being reviewed. Please contact us instead of paying again.', 'stackless-lite'));
        }
        if (in_array($state['outcome'], ['pending', 'error'], true) || $sale->data(Meta::CHARGE)) {
            return self::processingError();
        }

        $mode = Settings::mode();
        $api = Settings::api($mode);
        $currency = $sale->currency();
        $save = $sale->requiresSave();
        $setup = $sale->isZero();
        if (!$setup || $save) {
            if (!$api->configured()) {
                return new \WP_Error('stackless_airwallex_credentials', __('Airwallex is not connected. Choose another payment method.', 'stackless-lite'));
            }
            if (!Money::supported($currency)) {
                /* translators: %s: currency code */
                return new \WP_Error('stackless_airwallex_currency', sprintf(__('Airwallex does not take payments in %s.', 'stackless-lite'), $currency));
            }
        }

        // Placing the order again (back button, double click) reuses a session that still fits.
        $hash = self::fingerprint($sale, $mode, $api->account(), $save);
        $session = $state['session'];
        if ($session && ($session['status'] ?? '') === 'ACTIVE' && $sale->text(Meta::SESSION_HASH) === $hash) {
            $url = self::hostedUrl($session['url'] ?? '');
            if ($url !== '') {
                return $url;
            }
        }

        $released = $this->sync->release($sale, $state);
        if ($released === 'paid' || $sale->waiting()) {
            return $sale->receiptUrl();
        }
        if ($released !== 'free') {
            return self::processingError();
        }
        if ($setup && !$save) {
            $sale->complete();
            return $sale->receiptUrl();
        }

        $owner = self::owner($sale);
        $customer = '';
        if ($save) {
            $customer = $this->customerFor($sale, $api, $owner);
            if (is_wp_error($customer)) {
                return $customer;
            }
        }
        $reference = $sale->reference() ?: $sale->newReference();
        // Everything earlier is closed now. Save the reference first, so any payment can be found.
        $sale->put([
            Meta::REFERENCE => $reference,
            Meta::MODE => $mode,
            Meta::ACCOUNT => $api->account(),
            Meta::SAVE => $save ? 'yes' : null,
            Meta::OWNER => $owner,
            Meta::CUSTOMER => $save ? $customer : null,
            Meta::SESSION => null,
            Meta::SESSION_HASH => null,
            Meta::CHARGE_INTENT => null,
            Meta::OFF_SESSION => null,
            Meta::CONSENT_WAIT => null,
            Meta::CHECKS => null,
            Meta::RETRIES => null,
        ]);

        // A new request_id each time: a session whose answer was lost cannot be paid, because
        // nobody was ever given its URL.
        $created = $api->request('POST', 'checkout/checkout_sessions/create', $this->body($sale, $api, $reference, $save, $customer));
        if (is_wp_error($created)) {
            return $created;
        }
        $id = Api::id($created['id'] ?? null);
        $url = self::hostedUrl($created['url'] ?? '');
        if ($id === '' || $url === '' || ($created['status'] ?? '') !== 'ACTIVE') {
            return new \WP_Error('stackless_airwallex_checkout', __('Airwallex did not return a payment page for this order.', 'stackless-lite'));
        }
        $sale->put([Meta::SESSION => $id, Meta::SESSION_HASH => self::fingerprint($sale, $mode, $api->account(), $save)]);
        Renewals::scheduleCheck($sale);
        Settings::log(sprintf('Checkout Session %s created for order %d (%s).', $id, $orderId, $setup ? 'setup' : 'payment'));
        return $url;
    }

    /** @return array<string,mixed> */
    private function body(Sale $sale, Api $api, string $reference, bool $save, string $customer): array
    {
        // Not "order": that is a WordPress query variable.
        $return = ['order_id' => $sale->id(), 'key' => self::returnKey($sale)];
        $body = [
            'request_id' => Api::requestId(),
            'account_id' => $api->account(),
            'mode' => $sale->isZero() ? 'SETUP' : 'PAYMENT',
            'currency' => $sale->currency(),
            'success_url' => Returns::url($return),
            'back_url' => Returns::url($return + ['back' => 1]),
            'metadata' => ['stackless_reference' => $reference],
        ];
        $locale = self::locale();
        if ($locale !== '') {
            $body['locale'] = $locale;
        }
        if ($sale->isZero()) {
            $body['customer_id'] = $customer;
            $body['payment_options'] = [
                'payment_method_types' => ['card'],
                'payment_method_save' => ['next_triggered_by' => 'MERCHANT'],
            ];
            return $body;
        }

        $body['line_items'] = [[
            'quantity' => 1,
            'price' => [
                'currency' => $sale->currency(),
                'pricing_model' => 'PER_UNIT',
                'unit_amount' => Money::value($sale->total(), $sale->currency()),
                /* translators: %s: order number */
                'product' => ['name' => sprintf(__('Order %s', 'stackless-lite'), $sale->number())],
            ],
        ]];
        $body['payment_intent_data'] = ['merchant_order_id' => $reference];
        $body['customer_data_collection'] = ['tax_id' => 'DISABLED'];
        if ($save) {
            $body['customer_id'] = $customer;
            $body['payment_options'] = [
                'auto_capture' => true,
                'payment_method_types' => ['card'],
                'payment_method_save' => ['mode' => 'ENABLED', 'next_triggered_by' => 'MERCHANT'],
            ];
            return $body;
        }
        $body['payment_options'] = ['auto_capture' => true, 'payment_method_save' => ['mode' => 'DISABLED']];
        $buyer = array_filter([
            'email' => is_email($sale->email()) ? $sale->email() : '',
            'name' => trim($sale->firstName() . ' ' . $sale->lastName()),
            'country_code' => preg_match('/^[A-Z]{2}$/D', $sale->country()) ? $sale->country() : '',
        ]);
        if ($buyer) {
            $body['customer_data'] = $buyer;
        }
        return $body;
    }

    /** What a session was created for. A change means a new session. */
    private static function fingerprint(Sale $sale, string $mode, string $account, bool $save): string
    {
        return hash('sha256', implode('|', [
            $mode,
            $account,
            (string) Money::toMinor($sale->total(), $sale->currency()),
            $sale->currency(),
            $save ? 'save' : '',
            self::owner($sale),
            strtolower($sale->email()),
            $sale->reference(),
        ]));
    }

    public static function owner(Sale $sale): string
    {
        return $sale->userId() > 0 ? 'u:' . $sale->userId() : 'g:' . hash('sha256', strtolower(trim($sale->email())));
    }

    /** The session's URL, only when it is an Airwallex page over HTTPS. */
    public static function hostedUrl($url): string
    {
        if (!is_string($url) || $url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'])) {
            return '';
        }
        // A whole host of plain labels: browsers read a backslash as the end of the host.
        return preg_match('/^([a-z0-9-]+\.)*airwallex\.com$/iD', (string) ($parts['host'] ?? '')) ? $url : '';
    }

    /* ---- Customers -------------------------------------------------------------- */

    /**
     * The account's Airwallex customer in this mode, or one of its own for a guest order. An
     * order that changed hands never reuses the earlier buyer's customer.
     *
     * @return string|\WP_Error cus_… id
     */
    private function customerFor(Sale $sale, Api $api, string $owner)
    {
        if ($sale->userId() > 0) {
            return $this->customerForUser($sale->userId(), $api, $sale->email(), $sale->firstName(), $sale->lastName());
        }
        $existing = Api::id($sale->text(Meta::CUSTOMER));
        if ($existing !== '' && $sale->text(Meta::OWNER) === $owner && $sale->text(Meta::MODE) === $api->mode() && $sale->text(Meta::ACCOUNT) === $api->account()) {
            return $existing;
        }
        return $this->createCustomer($api, 0, $sale->email(), $sale->firstName(), $sale->lastName());
    }

    /** @return string|\WP_Error */
    private function customerForUser(int $userId, Api $api, string $email = '', string $first = '', string $last = '')
    {
        $key = Meta::customerKey($api->mode(), $api->account());
        $stored = Api::id(get_user_meta($userId, $key, true));
        if ($stored !== '') {
            return $stored;
        }
        $user = get_userdata($userId);
        if ($user) {
            $email = $email !== '' ? $email : (string) $user->user_email;
            $first = $first !== '' ? $first : (string) $user->first_name;
            $last = $last !== '' ? $last : (string) $user->last_name;
        }
        $id = $this->createCustomer($api, $userId, $email, $first, $last);
        if (!is_wp_error($id)) {
            update_user_meta($userId, $key, $id);
        }
        return $id;
    }

    /** @return string|\WP_Error */
    private function createCustomer(Api $api, int $userId, string $email, string $first, string $last)
    {
        $body = array_filter([
            'email' => is_email($email) ? substr($email, 0, 256) : '',
            'first_name' => substr($first, 0, 128),
            'last_name' => substr($last, 0, 128),
        ]);
        $body['request_id'] = Api::requestId();
        $body['merchant_customer_id'] = Settings::REFERENCE_PREFIX . '-' . ($userId > 0 ? 'u' . $userId : 'guest') . '-' . bin2hex(random_bytes(8));
        $created = $api->request('POST', 'pa/customers/create', $body);
        if (is_wp_error($created)) {
            return $created;
        }
        $id = Api::id($created['id'] ?? null);
        return $id !== '' ? $id : new \WP_Error('stackless_airwallex_customer', __('Airwallex did not create a customer.', 'stackless-lite'));
    }

    /* ---- Add a payment method ---------------------------------------------------- */

    /**
     * A SETUP session that only saves a card.
     *
     * @return string|\WP_Error
     */
    public function startAdd(int $userId, string $currency, int $subscriptionId = 0)
    {
        $api = Settings::api();
        $currency = strtoupper($currency);
        if ($userId <= 0 || !$api->configured() || !Money::supported($currency)) {
            return new \WP_Error('stackless_airwallex_add', __('Airwallex cannot save a payment method right now.', 'stackless-lite'));
        }
        return Lock::run('user-' . $userId, function () use ($userId, $currency, $subscriptionId, $api) {
            $customer = $this->customerForUser($userId, $api);
            if (is_wp_error($customer)) {
                return $customer;
            }
            $ref = bin2hex(random_bytes(16));
            $back = ['flow' => 'add', 'ref' => $ref];
            $body = [
                'request_id' => Api::requestId(),
                'account_id' => $api->account(),
                'mode' => 'SETUP',
                'currency' => $currency,
                'customer_id' => $customer,
                'success_url' => Returns::url($back),
                'back_url' => Returns::url($back + ['back' => 1]),
                'metadata' => ['stackless_user' => (string) $userId, 'stackless_site' => Settings::REFERENCE_PREFIX],
                'payment_options' => [
                    'payment_method_types' => ['card'],
                    'payment_method_save' => ['next_triggered_by' => 'MERCHANT'],
                ],
            ];
            $session = $api->request('POST', 'checkout/checkout_sessions/create', $body);
            if (is_wp_error($session)) {
                return $session;
            }
            $id = Api::id($session['id'] ?? null);
            $url = self::hostedUrl($session['url'] ?? '');
            if ($id === '' || $url === '') {
                return new \WP_Error('stackless_airwallex_checkout', __('Airwallex did not return a page to save the card on.', 'stackless-lite'));
            }
            $pending = array_filter(self::pendingAdds($userId), static function ($entry) {
                return is_array($entry) && (int) ($entry['at'] ?? 0) > time() - 2 * DAY_IN_SECONDS;
            });
            $pending[$ref] = [
                'session' => $id,
                'customer' => $customer,
                'mode' => $api->mode(),
                'account' => $api->account(),
                'subscription' => $subscriptionId,
                'at' => time(),
            ];
            update_user_meta($userId, Meta::USER_PENDING_ADD, $pending);
            return $url;
        }, self::processingError());
    }

    /** @return array<string,array> */
    private static function pendingAdds(int $userId): array
    {
        wp_cache_delete($userId, 'user_meta');
        $pending = get_user_meta($userId, Meta::USER_PENDING_ADD, true);
        return is_array($pending) ? $pending : [];
    }

    /**
     * The signed-in customer came back from saving a card.
     *
     * @return array{status:string, subscription:int} status: saved|pending|failed|none
     */
    public function finishAdd(int $userId, string $ref): array
    {
        $entry = $userId > 0 && $ref !== '' ? (self::pendingAdds($userId)[$ref] ?? null) : null;
        if (!is_array($entry)) {
            return ['status' => 'none', 'subscription' => 0];
        }
        return ['status' => $this->applyAdd($userId, (string) $entry['session'], (string) $entry['mode']), 'subscription' => (int) ($entry['subscription'] ?? 0)];
    }

    /**
     * Save the card of a completed "add" session, read from Airwallex, once. Only a session this
     * site started for this user counts, whatever its metadata says.
     *
     * @return string saved|pending|failed|none|busy
     */
    public function applyAdd(int $userId, string $sessionId, string $mode): string
    {
        return (string) Lock::run('user-' . $userId, function () use ($userId, $sessionId, $mode) {
            $pending = self::pendingAdds($userId);
            foreach ($pending as $ref => $entry) {
                if (!is_array($entry) || (string) ($entry['session'] ?? '') !== $sessionId || (string) ($entry['mode'] ?? '') !== $mode) {
                    continue;
                }
                if (!empty($entry['token'])) {
                    return 'saved';
                }
                $api = Settings::api($mode);
                if (!$api->configured() || $api->account() !== (string) $entry['account']) {
                    return 'failed';
                }
                $session = $api->request('GET', 'checkout/checkout_sessions/' . rawurlencode($sessionId));
                if (is_wp_error($session)) {
                    return 'pending';
                }
                if ((string) ($session['id'] ?? '') !== $sessionId || ($session['mode'] ?? '') !== 'SETUP'
                    || (string) ($session['account_id'] ?? '') !== $api->account()
                    || (string) ($session['customer_id'] ?? '') !== (string) $entry['customer']) {
                    return 'failed';
                }
                if (($session['status'] ?? '') !== 'COMPLETED') {
                    return ($session['status'] ?? '') === 'ACTIVE' ? 'pending' : 'failed';
                }
                $consent = Api::id($session['payment_consent_id'] ?? null);
                if ($consent === '') {
                    return 'failed';
                }
                $saved = $this->sync->saveConsent($api, $consent, $userId, (string) $entry['customer']);
                if (is_wp_error($saved)) {
                    return 'pending';
                }
                if (!$saved) {
                    return 'failed';
                }
                $pending[$ref]['token'] = $saved->id;
                update_user_meta($userId, Meta::USER_PENDING_ADD, $pending);
                return 'saved';
            }
            return 'none';
        }, 'busy');
    }

    /* ---- Return URLs ------------------------------------------------------------- */

    /** Signature for links back from Airwallex. The order key is not put in those URLs. */
    public static function returnKey(Sale $sale): string
    {
        return substr(hash_hmac('sha256', 'airwallex-return|' . $sale->id() . '|' . $sale->reference(), wp_salt('auth')), 0, 32);
    }

    /** The order a return link was signed for. */
    public static function returningOrder(int $orderId, string $key): ?Sale
    {
        $sale = $orderId > 0 && $key !== '' ? Sale::get($orderId) : null;
        return $sale && $sale->reference() !== '' && hash_equals(self::returnKey($sale), $key) ? $sale : null;
    }

    /** A return parameter, without anything a provider appended to it with "?" or "&". */
    public static function param($value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{1,64}/', $value, $match) ? $match[0] : '';
    }

    /** Checkout Session locale from the site language ('' lets Airwallex follow the browser). */
    public static function locale(): string
    {
        $supported = ['ar', 'da', 'de', 'en', 'es', 'fi', 'fr', 'he', 'id', 'it', 'ja', 'ko', 'ms', 'nl', 'pl', 'pt', 'ro', 'ru', 'si', 'sv', 'tr', 'vi', 'ur', 'zh'];
        $locale = (string) determine_locale();
        if (in_array($locale, ['zh_TW', 'zh_HK'], true)) {
            return 'zh-HK';
        }
        $language = strtolower(explode('_', $locale)[0]);
        return in_array($language, $supported, true) ? $language : '';
    }
}
