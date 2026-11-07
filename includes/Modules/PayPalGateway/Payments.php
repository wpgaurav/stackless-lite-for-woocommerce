<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Payment flows: checkout capture/authorize, vault-with-purchase, vault setup
 * tokens, the redirect fallback, renewals, refunds and authorization capture.
 *
 * Every path that settles an order ends in applyResult(), which checks the
 * PayPal amount and currency against the WooCommerce order before marking it
 * paid, and is safe to run more than once for the same order.
 */
class Payments
{
    private const SESSION_KEY = 'stackless_paypal_gateway_ids';

    /** @var Gateway */
    private $gateway;

    /** @var OrderBuilder */
    private $builder;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
        $this->builder = new OrderBuilder($gateway);
    }

    public function builder(): OrderBuilder
    {
        return $this->builder;
    }

    /* ---- Session binding --------------------------------------------------- */

    /** Remember a PayPal order or setup token created for this browser session. */
    public function remember(string $id): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        $ids = (array) WC()->session->get(self::SESSION_KEY, []);
        $ids[$id] = time();
        $ids = array_filter($ids, static function ($time) {
            return (int) $time > time() - 3 * HOUR_IN_SECONDS;
        });
        arsort($ids);
        WC()->session->set(self::SESSION_KEY, array_slice($ids, 0, 10, true));
    }

    public function isRemembered(string $id): bool
    {
        if ($id === '' || !function_exists('WC') || !WC()->session) {
            return false;
        }
        $ids = (array) WC()->session->get(self::SESSION_KEY, []);
        return isset($ids[$id]) && (int) $ids[$id] > time() - 3 * HOUR_IN_SECONDS;
    }

    public function returnUrl(\WC_Order $order, string $type): string
    {
        return add_query_arg([
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
            'type' => $type,
        ], WC()->api_request_url('stackless_paypal_return'));
    }

    /* ---- Creating PayPal objects from the browser ------------------------- */

    /**
     * PayPal order for the current cart (classic and block checkout).
     *
     * @return array|\WP_Error ['id' => ...]
     */
    public function createForCart(string $funding)
    {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return new \WP_Error('stackless_paypal_cart', __('Your cart is empty.', 'stackless-lite'));
        }
        $cart->calculate_totals();
        $currency = get_woocommerce_currency();
        if (!Amount::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_paypal_currency', sprintf(__('PayPal cannot take payments in %s.', 'stackless-lite'), $currency));
        }

        $vault = stackless_cart_requires_saved_payment_method();
        if ((float) $cart->get_total('edit') <= 0) {
            return new \WP_Error('stackless_paypal_zero', __('Nothing to pay today. Use the PayPal button again to save your account for future payments.', 'stackless-lite'));
        }

        $response = $this->gateway->api()->createOrder($this->builder->fromCart($cart, $vault, $funding));
        if (is_wp_error($response) && $this->isShippingError($response)) {
            $response = $this->gateway->api()->createOrder($this->builder->fromCart($cart, $vault, $funding, false));
        }
        if (is_wp_error($response)) {
            $this->gateway->log('Create order (cart) failed: ' . $response->get_error_message() . ' ' . wp_json_encode($response->get_error_data()), 'error');
            return $response;
        }
        if (empty($response['id'])) {
            return new \WP_Error('stackless_paypal_create', __('PayPal did not return an order.', 'stackless-lite'));
        }

        $this->remember((string) $response['id']);
        $this->gateway->log('Created PayPal order ' . $response['id'] . ' for the cart.');
        return ['id' => (string) $response['id']];
    }

    /**
     * PayPal order for an existing WooCommerce order (order-pay page).
     *
     * @return array|\WP_Error
     */
    public function createForOrder(\WC_Order $order, string $funding)
    {
        if (!$order->needs_payment() && (float) $order->get_total() > 0) {
            return new \WP_Error('stackless_paypal_paid', __('This order does not need payment.', 'stackless-lite'));
        }
        if (!Amount::supported($order->get_currency())) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_paypal_currency', sprintf(__('PayPal cannot take payments in %s.', 'stackless-lite'), $order->get_currency()));
        }
        if ((float) $order->get_total() <= 0) {
            return new \WP_Error('stackless_paypal_zero', __('Nothing to pay today. Use the PayPal button again to save your account for future payments.', 'stackless-lite'));
        }

        $vault = stackless_order_requires_saved_payment_method($order);
        $response = $this->gateway->api()->createOrder($this->builder->fromOrder($order, $vault, $funding));
        if (is_wp_error($response) && $this->isShippingError($response)) {
            $response = $this->gateway->api()->createOrder($this->builder->fromOrder($order, $vault, $funding, '', false));
        }
        if (is_wp_error($response)) {
            $this->gateway->log('Create order for #' . $order->get_id() . ' failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        if (empty($response['id'])) {
            return new \WP_Error('stackless_paypal_create', __('PayPal did not return an order.', 'stackless-lite'));
        }

        $order->update_meta_data(Gateway::META_ORDER_ID, (string) $response['id']);
        $order->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
        $order->save();
        $this->remember((string) $response['id']);
        return ['id' => (string) $response['id']];
    }

    /**
     * Vault setup token for a zero-total checkout, an order-pay link, or My Account.
     *
     * @return array|\WP_Error
     */
    public function createSetupToken(string $returnUrl, string $cancelUrl, ?\WC_Order $order = null)
    {
        $response = $this->gateway->api()->createSetupToken($this->builder->setupToken($returnUrl, $cancelUrl));
        if (is_wp_error($response)) {
            $this->gateway->log('Create setup token failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        if (empty($response['id'])) {
            return new \WP_Error('stackless_paypal_setup', __('PayPal did not return a setup token.', 'stackless-lite'));
        }
        if ($order) {
            $order->update_meta_data(Gateway::META_SETUP_TOKEN, (string) $response['id']);
            $order->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
            $order->save();
        }
        $this->remember((string) $response['id']);
        return ['id' => (string) $response['id'], 'approve' => self::link($response, ['approve', 'payer-action'])];
    }

    /* ---- process_payment ---------------------------------------------------- */

    /** @return array process_payment result */
    public function process(\WC_Order $order, string $paypalOrderId, string $setupToken): array
    {
        $currency = $order->get_currency();
        if (!Amount::supported($currency)) {
            /* translators: %s: currency code */
            return ['result' => 'failure', 'message' => sprintf(__('PayPal cannot take payments in %s. Choose another payment method.', 'stackless-lite'), $currency)];
        }

        $requiresVault = stackless_order_requires_saved_payment_method($order);
        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->save();

        if ((float) $order->get_total() <= 0) {
            if (!$requiresVault) {
                $order->payment_complete();
                return ['result' => 'success', 'redirect' => $this->gateway->get_return_url($order)];
            }
            if ($setupToken !== '') {
                if (!$this->isRemembered($setupToken) && $setupToken !== (string) $order->get_meta(Gateway::META_SETUP_TOKEN)) {
                    return ['result' => 'failure', 'message' => __('Your PayPal session expired. Please use the PayPal button again.', 'stackless-lite')];
                }
                $saved = $this->completeSetupToken($order, $setupToken);
                return is_wp_error($saved)
                    ? ['result' => 'failure', 'message' => $saved->get_error_message()]
                    : ['result' => 'success', 'redirect' => $this->gateway->get_return_url($order)];
            }
            // No buttons (no JS, or Place order clicked): send the buyer to PayPal to approve.
            $token = $this->createSetupToken($this->returnUrl($order, 'setup'), $order->get_checkout_payment_url(), $order);
            if (is_wp_error($token) || empty($token['approve'])) {
                return ['result' => 'failure', 'message' => is_wp_error($token) ? $token->get_error_message() : __('PayPal did not return an approval link.', 'stackless-lite')];
            }
            return ['result' => 'success', 'redirect' => (string) $token['approve']];
        }

        if ($paypalOrderId !== '') {
            if (!$this->isRemembered($paypalOrderId) && $paypalOrderId !== (string) $order->get_meta(Gateway::META_ORDER_ID)) {
                return ['result' => 'failure', 'message' => __('Your PayPal session expired. Please use the PayPal button again.', 'stackless-lite')];
            }
            $result = $this->completeCheckout($order, $paypalOrderId);
            if ($result['result'] === 'failed') {
                return ['result' => 'failure', 'message' => (string) ($result['message'] ?? __('PayPal could not take this payment.', 'stackless-lite'))];
            }
            return ['result' => 'success', 'redirect' => $this->gateway->get_return_url($order)];
        }

        // Redirect fallback: create the PayPal order for this WooCommerce order and send the buyer to approve it.
        $body = $this->builder->fromOrder($order, $requiresVault, 'paypal', $this->returnUrl($order, 'order'));
        $response = $this->gateway->api()->createOrder($body);
        if (is_wp_error($response) && $this->isShippingError($response)) {
            $response = $this->gateway->api()->createOrder($this->builder->fromOrder($order, $requiresVault, 'paypal', $this->returnUrl($order, 'order'), false));
        }
        if (is_wp_error($response)) {
            $this->gateway->log('Create order (redirect) for #' . $order->get_id() . ' failed: ' . $response->get_error_message(), 'error');
            return ['result' => 'failure', 'message' => $response->get_error_message()];
        }
        $approve = self::link($response, ['payer-action', 'approve']);
        if (empty($response['id']) || $approve === '') {
            return ['result' => 'failure', 'message' => __('PayPal did not return an approval link.', 'stackless-lite')];
        }
        $order->update_meta_data(Gateway::META_ORDER_ID, (string) $response['id']);
        $order->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
        $order->save();
        return ['result' => 'success', 'redirect' => $approve];
    }

    /**
     * Settle an approved PayPal order against a WooCommerce order.
     *
     * @return array{result:string,message?:string}
     */
    public function completeCheckout(\WC_Order $order, string $paypalOrderId): array
    {
        $done = Lock::run('order-' . $order->get_id(), function () use ($order, $paypalOrderId) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
            }
            $same = (string) $order->get_meta(Gateway::META_ORDER_ID) === $paypalOrderId;
            if ($order->is_paid() || (!$same && !$order->needs_payment())) {
                if (!$same) {
                    // Another tab or request already paid (or authorized) this order: never capture a second PayPal order.
                    $this->gateway->log(sprintf('Order %d no longer needs payment; PayPal order %s was approved but not captured.', $order->get_id(), $paypalOrderId), 'warning');
                }
                if ($order->is_paid() || $order->has_status('on-hold')) {
                    return ['result' => 'success'];
                }
                return ['result' => 'failed', 'message' => __('This order can no longer be paid. Nothing was charged.', 'stackless-lite')];
            }

            $api = $this->gateway->api();
            $paypal = $api->getOrder($paypalOrderId);
            if (is_wp_error($paypal)) {
                return ['result' => 'failed', 'message' => $paypal->get_error_message()];
            }

            $unit = (array) ($paypal['purchase_units'][0] ?? []);
            $custom = (string) ($unit['custom_id'] ?? '');
            if ($custom !== '' && $custom !== (string) $order->get_id()) {
                $this->gateway->log('PayPal order ' . $paypalOrderId . ' belongs to order ' . $custom . ', not ' . $order->get_id(), 'warning');
                return ['result' => 'failed', 'message' => __('This PayPal payment belongs to a different order. Please use the PayPal button again.', 'stackless-lite')];
            }

            $status = (string) ($paypal['status'] ?? '');
            if ($status === 'COMPLETED') {
                return $this->applyResult($order, $paypal, 'checkout');
            }
            if ($status !== 'APPROVED') {
                return ['result' => 'failed', 'message' => __('PayPal has not approved this payment yet. Please finish in the PayPal window.', 'stackless-lite')];
            }

            if (strtoupper((string) ($unit['amount']['currency_code'] ?? '')) !== strtoupper($order->get_currency())) {
                return ['result' => 'failed', 'message' => __('The order currency changed after PayPal approved the payment. Please use the PayPal button again.', 'stackless-lite')];
            }

            $authentication = CardSecurity::resultOf($paypal);
            if ($authentication !== null) {
                $decision = CardSecurity::decide($authentication);
                if ($decision !== CardSecurity::CONTINUE) {
                    $order->add_order_note(sprintf(
                        /* translators: 1: PayPal order id, 2: liability shift */
                        __('PayPal card payment %1$s was not captured: 3-D Secure did not pass (liability shift %2$s).', 'stackless-lite'),
                        $paypalOrderId,
                        (string) ($authentication['liability_shift'] ?? '?')
                    ));
                    return ['result' => 'failed', 'message' => $decision === CardSecurity::REJECT
                        ? __('Your bank could not verify this card. Use another card or payment method.', 'stackless-lite')
                        : __('Your bank could not verify this card right now. Try again, or use another card.', 'stackless-lite')];
                }
            }

            $ops = $this->builder->patchOperations($order, $paypal);
            if ($ops) {
                $patched = $api->patchOrder($paypalOrderId, $ops);
                if (is_wp_error($patched)) {
                    $this->gateway->log('Patch of ' . $paypalOrderId . ' failed: ' . $patched->get_error_message(), 'error');
                    return ['result' => 'failed', 'message' => __('Your order total changed after PayPal approved the payment. Please use the PayPal button again.', 'stackless-lite')];
                }
            }

            $order->update_meta_data(Gateway::META_ORDER_ID, $paypalOrderId);
            $order->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
            $order->save();

            $intent = strtoupper((string) ($paypal['intent'] ?? 'CAPTURE'));
            $requestId = 'stackless-' . strtolower($intent) . '-' . $order->get_id() . '-' . $paypalOrderId;
            $settled = $intent === 'AUTHORIZE' ? $api->authorizeOrder($paypalOrderId, $requestId) : $api->captureOrder($paypalOrderId, $requestId);

            if (is_wp_error($settled)) {
                $data = (array) $settled->get_error_data();
                $issue = (string) ($data['issue'] ?? '');
                if (in_array($issue, ['ORDER_ALREADY_CAPTURED', 'ORDER_ALREADY_AUTHORIZED'], true)) {
                    $settled = $api->getOrder($paypalOrderId);
                }
                if (is_wp_error($settled)) {
                    $this->gateway->log(ucfirst(strtolower($intent)) . ' of ' . $paypalOrderId . ' failed: ' . $settled->get_error_message(), 'error');
                    $message = $issue === 'INSTRUMENT_DECLINED' || $issue === 'PAYER_ACTION_REQUIRED'
                        ? __('PayPal declined that funding source. Please use the PayPal button again and pick another way to pay.', 'stackless-lite')
                        : $settled->get_error_message();
                    $order->add_order_note(sprintf(
                        /* translators: %s: PayPal error */
                        __('PayPal payment failed: %s', 'stackless-lite'),
                        $settled->get_error_message()
                    ));
                    return ['result' => 'failed', 'message' => $message];
                }
            }

            return $this->applyResult($order, $settled, 'checkout');
        }, null, 20);

        return is_array($done) ? $done : ['result' => 'failed', 'message' => __('This order is already being processed. Refresh the page in a moment.', 'stackless-lite')];
    }

    /**
     * Apply a PayPal order (after capture/authorize, or as fetched) to a WooCommerce order.
     *
     * @return array{result:string,message?:string} success|pending|failed
     */
    public function applyResult(\WC_Order $order, array $paypal, string $context): array
    {
        $unit = (array) ($paypal['purchase_units'][0] ?? []);
        $custom = (string) ($unit['custom_id'] ?? '');
        if ($custom !== '' && $custom !== (string) $order->get_id()) {
            return ['result' => 'failed', 'message' => __('PayPal returned a payment for a different order.', 'stackless-lite')];
        }

        $currency = $order->get_currency();
        $expected = (float) $order->get_total();
        $capture = (array) ($unit['payments']['captures'][0] ?? []);
        $authorization = (array) ($unit['payments']['authorizations'][0] ?? []);
        $paypalOrderId = (string) ($paypal['id'] ?? '');

        if ($paypalOrderId !== '') {
            $order->update_meta_data(Gateway::META_ORDER_ID, $paypalOrderId);
        }
        $email = (string) ($paypal['payment_source']['paypal']['email_address'] ?? ($paypal['payer']['email_address'] ?? ''));
        if ($email !== '') {
            $order->update_meta_data(Gateway::META_PAYER_EMAIL, sanitize_email($email));
        }

        if ($capture) {
            $captureId = (string) ($capture['id'] ?? '');
            $status = (string) ($capture['status'] ?? '');
            $order->update_meta_data(Gateway::META_CAPTURE_ID, $captureId);
            $order->set_transaction_id($captureId);
            $order->save();

            if (in_array($status, ['DECLINED', 'FAILED'], true)) {
                $note = sprintf(/* translators: %s: PayPal capture id */ __('PayPal declined the payment (capture %s).', 'stackless-lite'), $captureId);
                if (!$order->is_paid()) {
                    // Dunning reads the decline when the order turns failed, so it is written first.
                    [$category, $code] = Declines::fromPayment($capture);
                    Declines::write($order, $category, $code);
                    $order->save();
                    $order->update_status('failed', $note);
                }
                return ['result' => 'failed', 'message' => __('PayPal declined the payment. Please try another way to pay.', 'stackless-lite')];
            }

            if (!Amount::matches((array) ($capture['amount'] ?? []), $expected, $currency)) {
                $note = sprintf(
                    /* translators: 1: amount PayPal captured, 2: order total */
                    __('PayPal captured %1$s but the order total is %2$s. Check the payment in PayPal before fulfilling.', 'stackless-lite'),
                    (string) ($capture['amount']['value'] ?? '?') . ' ' . (string) ($capture['amount']['currency_code'] ?? ''),
                    Amount::format($expected, $currency) . ' ' . $currency
                );
                if (!$order->is_paid()) {
                    $order->update_status('on-hold', $note);
                } else {
                    $order->add_order_note($note);
                }
                $this->gateway->log('Amount mismatch on order ' . $order->get_id() . ': ' . $note, 'warning');
                return ['result' => $context === 'checkout' ? 'success' : 'pending', 'message' => $note];
            }

            $this->maybeSaveVault($order, $paypal);

            if ($status === 'PENDING') {
                $reason = (string) ($capture['status_details']['reason'] ?? '');
                if (!$order->is_paid() && !$order->has_status('on-hold')) {
                    $order->update_status('on-hold', sprintf(
                        /* translators: 1: capture id, 2: PayPal reason code */
                        __('PayPal payment %1$s is pending (%2$s). The order updates when PayPal confirms it.', 'stackless-lite'),
                        $captureId,
                        $reason !== '' ? $reason : __('no reason given', 'stackless-lite')
                    ));
                }
                return ['result' => 'pending'];
            }

            if (in_array($status, ['COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
                if (!$order->is_paid()) {
                    $order->add_order_note(sprintf(/* translators: %s: capture id */ __('PayPal payment captured (%s).', 'stackless-lite'), $captureId));
                    $order->payment_complete($captureId);
                }
                return ['result' => 'success'];
            }

            return ['result' => 'pending'];
        }

        if ($authorization) {
            $authId = (string) ($authorization['id'] ?? '');
            $status = (string) ($authorization['status'] ?? '');
            $order->update_meta_data(Gateway::META_AUTH_ID, $authId);
            $order->set_transaction_id($authId);
            $order->save();

            if (in_array($status, ['DENIED', 'VOIDED', 'EXPIRED'], true)) {
                if (!$order->is_paid()) {
                    [$category, $code] = Declines::fromPayment($authorization);
                    Declines::write($order, $category, $code);
                    $order->save();
                    $order->update_status('failed', sprintf(/* translators: 1: authorization id, 2: status */ __('PayPal authorization %1$s is %2$s.', 'stackless-lite'), $authId, strtolower($status)));
                }
                return ['result' => 'failed', 'message' => __('PayPal declined the payment. Please try another way to pay.', 'stackless-lite')];
            }
            if (!Amount::matches((array) ($authorization['amount'] ?? []), $expected, $currency)) {
                $order->update_status('on-hold', __('The PayPal authorization amount does not match the order total. Check it in PayPal before capturing.', 'stackless-lite'));
                return ['result' => 'success'];
            }
            $this->maybeSaveVault($order, $paypal);
            if (!$order->has_status(['on-hold', 'processing', 'completed'])) {
                $order->update_status('on-hold', sprintf(
                    /* translators: %s: authorization id */
                    __('PayPal payment authorized (%s). Change the order to Processing or Completed to capture it.', 'stackless-lite'),
                    $authId
                ));
            }
            return ['result' => 'success'];
        }

        return ['result' => 'failed', 'message' => __('PayPal did not report a payment for this order.', 'stackless-lite')];
    }

    /* ---- Vault --------------------------------------------------------------- */

    /** Save the vault id PayPal returned with a purchase (vault-with-purchase). */
    private function maybeSaveVault(\WC_Order $order, array $paypal): void
    {
        if (isset($paypal['payment_source']['card'])) {
            $this->maybeSaveCardVault($order, (array) $paypal['payment_source']['card']);
            return;
        }
        $source = (array) ($paypal['payment_source']['paypal'] ?? []);
        $vault = (array) ($source['attributes']['vault'] ?? []);
        $vaultId = (string) ($vault['id'] ?? '');

        if ($vaultId === '' || ($vault['status'] ?? 'VAULTED') !== 'VAULTED') {
            if (stackless_order_requires_saved_payment_method($order) && (string) $order->get_meta(SubscriptionData::META_TOKEN_ID) === '') {
                $order->add_order_note(__('PayPal is still saving the buyer’s account for renewals; it will be attached when PayPal confirms it.', 'stackless-lite'));
            }
            return;
        }

        $tokenId = $this->saveToken(
            (int) $order->get_customer_id(),
            $vaultId,
            (string) ($source['email_address'] ?? ''),
            (string) ($source['account_id'] ?? ''),
            (string) ($vault['customer']['id'] ?? '')
        );
        $this->attachToken($order, $tokenId, $vaultId, (string) ($vault['customer']['id'] ?? ''));
    }

    /** Card vaulted with a purchase (card fields or Fastlane with store_in_vault). */
    private function maybeSaveCardVault(\WC_Order $order, array $card): void
    {
        $vault = (array) ($card['attributes']['vault'] ?? []);
        $vaultId = (string) ($vault['id'] ?? '');
        if ($vaultId === '' || ($vault['status'] ?? 'VAULTED') !== 'VAULTED') {
            if (stackless_order_requires_saved_payment_method($order) && (string) $order->get_meta(SubscriptionData::META_TOKEN_ID) === '') {
                $order->add_order_note(__('PayPal is still saving the card for renewals; it will be attached when PayPal confirms it.', 'stackless-lite'));
            }
            return;
        }
        $customerId = (string) ($vault['customer']['id'] ?? '');
        $tokenId = CardTokens::save((int) $order->get_customer_id(), $vaultId, $card, $customerId, $this->gateway->environment());
        $this->attachToken($order, $tokenId, $vaultId, $customerId, 'card');
    }

    /** Store (or reuse) a WooCommerce payment token for a vault id. */
    public function saveToken(int $userId, string $vaultId, string $email, string $payerId, string $customerId, string $environment = ''): int
    {
        $environment = $environment !== '' ? $environment : $this->gateway->environment();
        foreach (\WC_Payment_Tokens::get_customer_tokens($userId, $this->gateway->id) as $existing) {
            if ($existing->get_token() === $vaultId) {
                return (int) $existing->get_id();
            }
        }
        $token = new PaymentToken();
        $token->set_token($vaultId);
        $token->set_gateway_id($this->gateway->id);
        $token->set_user_id($userId);
        $token->set_email($email);
        $token->set_payer_id($payerId);
        $token->set_customer_id($customerId);
        $token->set_environment($environment);
        $token->save();
        return (int) $token->get_id();
    }

    /**
     * Write the token on the parent order (and any subscriptions already created from it).
     *
     * @param string $type paypal|card: what the vault id pays with.
     */
    public function attachToken(\WC_Order $order, int $tokenId, string $vaultId, string $customerId, string $type = 'paypal'): void
    {
        $write = function (\WC_Order $target) use ($tokenId, $vaultId, $customerId, $type) {
            $target->update_meta_data(SubscriptionData::META_TOKEN_ID, (string) $tokenId);
            $target->update_meta_data(Gateway::META_VAULT_ID, $vaultId);
            $target->update_meta_data(Gateway::META_VAULT_TYPE, $type === 'card' ? 'card' : 'paypal');
            $target->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
            if ($customerId !== '') {
                $target->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customerId);
            }
            $target->save();
        };

        $write($order);
        foreach (stackless_get_order_subscriptions($order) as $subscription) {
            if ($subscription instanceof \WC_Order && in_array($subscription->get_payment_method(), ['', $this->gateway->id], true)) {
                $write($subscription);
            }
        }
        $order->add_order_note($type === 'card'
            ? __('Card saved at PayPal for automatic renewals.', 'stackless-lite')
            : __('PayPal account saved for automatic renewals.', 'stackless-lite'));
    }

    /**
     * Turn an approved setup token into a vaulted payment token for an order.
     *
     * @return true|\WP_Error
     */
    public function completeSetupToken(\WC_Order $order, string $setupToken)
    {
        $done = Lock::run('order-' . $order->get_id(), function () use ($order, $setupToken) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return new \WP_Error('stackless_paypal_order', __('Order not found.', 'stackless-lite'));
            }
            if ($order->is_paid() && (string) $order->get_meta(SubscriptionData::META_TOKEN_ID) !== '') {
                return true;
            }
            $saved = $this->vaultSetupToken($setupToken, (int) $order->get_customer_id());
            if (is_wp_error($saved)) {
                $order->add_order_note(sprintf(/* translators: %s: error */ __('Saving the PayPal account failed: %s', 'stackless-lite'), $saved->get_error_message()));
                return $saved;
            }
            $order->update_meta_data(Gateway::META_SETUP_TOKEN, $setupToken);
            $this->attachToken($order, $saved['token_id'], $saved['vault_id'], $saved['customer_id']);
            $order->payment_complete();
            return true;
        }, null, 20);

        return $done === null ? new \WP_Error('stackless_paypal_busy', __('This order is already being processed. Refresh the page in a moment.', 'stackless-lite')) : $done;
    }

    /**
     * @return array{token_id:int,vault_id:string,customer_id:string}|\WP_Error
     */
    private function vaultSetupToken(string $setupToken, int $userId)
    {
        $api = $this->gateway->api();
        $setup = $api->getSetupToken($setupToken);
        if (is_wp_error($setup)) {
            return $setup;
        }
        $status = (string) ($setup['status'] ?? '');
        if (!in_array($status, ['APPROVED', 'VAULTED', 'TOKENIZED'], true)) {
            return new \WP_Error('stackless_paypal_setup_status', __('PayPal has not approved saving this account yet. Please finish in the PayPal window.', 'stackless-lite'));
        }

        $payment = $api->createPaymentToken($setupToken, 'stackless-vault-' . $setupToken);
        if (is_wp_error($payment)) {
            return $payment;
        }
        $vaultId = (string) ($payment['id'] ?? '');
        if ($vaultId === '') {
            return new \WP_Error('stackless_paypal_vault', __('PayPal did not return a saved payment token.', 'stackless-lite'));
        }
        $source = (array) ($payment['payment_source']['paypal'] ?? []);
        $customerId = (string) ($payment['customer']['id'] ?? '');
        $tokenId = $this->saveToken($userId, $vaultId, (string) ($source['email_address'] ?? ''), (string) ($source['account_id'] ?? ''), $customerId);
        return ['token_id' => $tokenId, 'vault_id' => $vaultId, 'customer_id' => $customerId];
    }

    /** @return true|\WP_Error */
    public function addPaymentMethod(int $userId, string $setupToken)
    {
        if ($userId <= 0) {
            return new \WP_Error('stackless_paypal_login', __('Log in to save a PayPal account.', 'stackless-lite'));
        }
        if ($setupToken === '' || !$this->isRemembered($setupToken)) {
            return new \WP_Error('stackless_paypal_setup', __('Use the PayPal button to connect your account first.', 'stackless-lite'));
        }
        $saved = $this->vaultSetupToken($setupToken, $userId);
        if (is_wp_error($saved)) {
            return $saved;
        }
        do_action('stackless_paypal_gateway_payment_method_added', $saved['token_id'], $userId);
        return true;
    }

    /**
     * Fastlane: pay an order with the single-use card token from the Fastlane component.
     * PayPal charges on create; 3-D Secure, when the bank asks for it, sends the buyer to
     * PayPal and back to handleReturn(), which captures the order.
     *
     * @return array process_payment result
     */
    public function payWithSingleUseToken(\WC_Order $order, string $token): array
    {
        $currency = $order->get_currency();
        if (!Amount::supported($currency) || (float) $order->get_total() <= 0) {
            return ['result' => 'failure', 'message' => __('Fastlane cannot take this payment. Choose another payment method.', 'stackless-lite')];
        }
        $order->set_payment_method($this->gateway->id);
        $order->set_payment_method_title($this->gateway->get_title());
        $order->update_meta_data(Gateway::META_FUNDING, 'fastlane');
        $order->update_meta_data(Gateway::META_ENV, $this->gateway->environment());
        $order->save();

        $done = Lock::run('order-' . $order->get_id(), function () use ($order, $token) {
            $order = \Stackless\Core\FreshOrder::get($order->get_id());
            if (!$order instanceof \WC_Order) {
                return ['result' => 'failure', 'message' => __('Order not found.', 'stackless-lite')];
            }
            if ($order->is_paid()) {
                return ['result' => 'success', 'redirect' => $this->gateway->get_return_url($order)];
            }
            $vault = stackless_order_requires_saved_payment_method($order);
            $requestId = 'stackless-fastlane-' . $order->get_id() . '-' . substr(md5($token), 0, 16);
            $response = $this->gateway->api()->createOrder($this->builder->fromSingleUseToken($order, $token, $vault, $this->returnUrl($order, 'order')), $requestId);
            if (is_wp_error($response)) {
                $this->gateway->log('Fastlane order for #' . $order->get_id() . ' failed: ' . $response->get_error_message(), 'error');
                /* translators: %s: PayPal error */
                $order->add_order_note(sprintf(__('Fastlane payment failed: %s', 'stackless-lite'), $response->get_error_message()));
                return ['result' => 'failure', 'message' => __('The card was declined. Try another card or payment method.', 'stackless-lite')];
            }
            if (!empty($response['id'])) {
                $order->update_meta_data(Gateway::META_ORDER_ID, (string) $response['id']);
                $order->save();
            }
            $status = (string) ($response['status'] ?? '');
            if ($status === 'PAYER_ACTION_REQUIRED') {
                $link = self::link($response, ['payer-action', 'approve']);
                return $link !== ''
                    ? ['result' => 'success', 'redirect' => $link]
                    : ['result' => 'failure', 'message' => __('Your bank needs to verify this card, but PayPal did not return a verification page.', 'stackless-lite')];
            }
            if (in_array($status, ['CREATED', 'APPROVED'], true) && !empty($response['id'])) {
                // Already under this order's lock, so capture here rather than through completeCheckout().
                $response = $this->gateway->api()->captureOrder((string) $response['id'], $requestId . '-capture');
                if (is_wp_error($response)) {
                    return ['result' => 'failure', 'message' => $response->get_error_message()];
                }
            }
            $result = $this->applyResult($order, $response, 'checkout');
            return $result['result'] === 'failed'
                ? ['result' => 'failure', 'message' => (string) ($result['message'] ?? '')]
                : ['result' => 'success', 'redirect' => $this->gateway->get_return_url($order)];
        }, null, 20);

        if (!is_array($done)) {
            return ['result' => 'failure', 'message' => __('This order is already being processed. Refresh the page in a moment.', 'stackless-lite')];
        }
        if (($done['result'] ?? '') === 'failed') {
            return ['result' => 'failure', 'message' => (string) ($done['message'] ?? '')];
        }
        return $done;
    }

    /* ---- Browser return from PayPal (redirect flow) ---------------------------- */

    public function handleReturn(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the order key.
        $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $type = isset($_GET['type']) && $_GET['type'] === 'setup' ? 'setup' : 'order';
        $returned = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $approval = isset($_GET['approval_token_id']) ? sanitize_text_field(wp_unslash($_GET['approval_token_id'])) : '';
        // phpcs:enable

        $order = $orderId ? wc_get_order($orderId) : false;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || $order->get_payment_method() !== $this->gateway->id) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }

        if ($type === 'setup') {
            $stored = (string) $order->get_meta(Gateway::META_SETUP_TOKEN);
            if ($stored === '' || ($approval !== '' && $approval !== $stored)) {
                $result = new \WP_Error('stackless_paypal_return', __('This PayPal approval does not match the order.', 'stackless-lite'));
            } else {
                $result = $this->completeSetupToken($order, $stored);
            }
            $ok = !is_wp_error($result);
            $message = is_wp_error($result) ? $result->get_error_message() : '';
        } else {
            $stored = (string) $order->get_meta(Gateway::META_ORDER_ID);
            if ($stored === '' || ($returned !== '' && $returned !== $stored)) {
                $ok = false;
                $message = __('This PayPal approval does not match the order.', 'stackless-lite');
            } else {
                $result = $this->completeCheckout($order, $stored);
                $ok = $result['result'] !== 'failed';
                $message = (string) ($result['message'] ?? '');
            }
        }

        if ($ok) {
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            wp_safe_redirect($this->gateway->get_return_url($order));
            exit;
        }

        wc_add_notice($message !== '' ? $message : __('PayPal could not take this payment.', 'stackless-lite'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /* ---- Renewals ------------------------------------------------------------- */

    /**
     * Merchant-initiated renewal against the vaulted PayPal account.
     *
     * @param SubscriptionData&\WC_Order $subscription
     * @return array{result:string,message?:string}
     */
    public function chargeRenewal(\WC_Order $renewal, $subscription): array
    {
        if ($renewal->is_paid()) {
            return ['result' => 'success'];
        }
        if ((float) $renewal->get_total() <= 0) {
            $renewal->payment_complete();
            return ['result' => 'success'];
        }
        if (!Amount::supported($renewal->get_currency())) {
            /* translators: %s: currency */
            return $this->failRenewal($renewal, sprintf(__('PayPal cannot charge in %s.', 'stackless-lite'), $renewal->get_currency()), false, SubscriptionData::DECLINE_HARD, 'currency_not_supported');
        }

        $source = $this->renewalSource($renewal, $subscription);
        if (is_wp_error($source)) {
            $code = $source->get_error_code() === 'stackless_paypal_token_owner' ? 'token_owner_mismatch' : 'payment_method_missing';
            return $this->failRenewal($renewal, $source->get_error_message(), false, SubscriptionData::DECLINE_HARD, $code);
        }
        [$vaultId, $environment, $sourceType] = $source;
        if ($environment !== $this->gateway->environment()) {
            return $this->failRenewal($renewal, __('The saved PayPal account belongs to the other PayPal environment (sandbox vs live). Ask the customer to update their payment method.', 'stackless-lite'), false, SubscriptionData::DECLINE_HARD, 'environment_mismatch');
        }

        $done = Lock::run('order-' . $renewal->get_id(), function () use ($renewal, $vaultId, $sourceType) {
            $renewal = \Stackless\Core\FreshOrder::get($renewal->get_id());
            if (!$renewal instanceof \WC_Order) {
                return ['result' => 'failed', 'message' => __('Order not found.', 'stackless-lite')];
            }
            if ($renewal->is_paid()) {
                return ['result' => 'success'];
            }

            $attempts = (int) $renewal->get_meta(Gateway::META_RENEWAL_ATTEMPTS);
            $requestId = self::renewalRequestId($renewal->get_id(), $attempts);
            $renewal->set_payment_method($this->gateway->id);
            $renewal->set_payment_method_title($this->gateway->get_title());
            $renewal->update_meta_data(Gateway::META_VAULT_ID, $vaultId);
            $renewal->save();

            $api = $this->gateway->api();
            $body = $this->builder->renewal($renewal, $vaultId, $sourceType);
            $response = $api->createOrder($body, $requestId);
            if (is_wp_error($response) && self::isTransient($response)) {
                // Same PayPal-Request-Id: if the first call went through, PayPal replays its result.
                $response = $api->createOrder($body, $requestId);
            }
            if (is_wp_error($response)) {
                $this->gateway->log('Renewal ' . $renewal->get_id() . ' create failed: ' . $response->get_error_message() . ' ' . wp_json_encode($response->get_error_data()), 'error');
                [$category, $code] = Declines::fromError($response);
                return $this->failRenewal($renewal, $response->get_error_message(), !self::isTransient($response), $category, $code);
            }

            $status = (string) ($response['status'] ?? '');
            if (!empty($response['id'])) {
                $renewal->update_meta_data(Gateway::META_ORDER_ID, (string) $response['id']);
                $renewal->save();
            }
            if (in_array($status, ['CREATED', 'APPROVED', 'SAVED'], true) && !empty($response['id'])) {
                $response = $api->captureOrder((string) $response['id'], $requestId . '-capture');
                if (is_wp_error($response)) {
                    [$category, $code] = Declines::fromError($response);
                    return $this->failRenewal($renewal, $response->get_error_message(), !self::isTransient($response), $category, $code);
                }
            } elseif ($status === 'PAYER_ACTION_REQUIRED') {
                return $this->failRenewal($renewal, __('PayPal needs the customer to approve this payment, so it cannot be charged automatically.', 'stackless-lite'), true, SubscriptionData::DECLINE_AUTH, 'PAYER_ACTION_REQUIRED');
            }

            $result = $this->applyResult($renewal, $response, 'renewal');
            if ($result['result'] === 'failed') {
                $renewal->update_meta_data(Gateway::META_RENEWAL_ATTEMPTS, (string) ($attempts + 1));
                $renewal->save();
            }
            return $result;
        }, null, 20);

        return is_array($done) ? $done : ['result' => 'pending', 'message' => __('Another process is already charging this renewal.', 'stackless-lite')];
    }

    /** "stackless-renewal-{id}" for the first attempt, "-2", "-3"… after a definitive decline. */
    public static function renewalRequestId(int $orderId, int $attempts): string
    {
        return 'stackless-renewal-' . $orderId . ($attempts > 0 ? '-' . ($attempts + 1) : '');
    }

    /**
     * Vault id, environment and source type (paypal|card) for a renewal: the renewal's token
     * meta, else the subscription's.
     *
     * @param \WC_Order $subscription
     * @return array{0:string,1:string,2:string}|\WP_Error
     */
    private function renewalSource(\WC_Order $renewal, $subscription)
    {
        foreach ([$renewal, $subscription] as $source) {
            if (!$source instanceof \WC_Order) {
                continue;
            }
            $tokenId = absint($source->get_meta(SubscriptionData::META_TOKEN_ID));
            if ($tokenId) {
                $token = \WC_Payment_Tokens::get($tokenId);
                if ($token && $token->get_gateway_id() === $this->gateway->id && ($token instanceof PaymentToken || CardTokens::isCard($token))) {
                    if ((int) $token->get_user_id() !== 0 && (int) $renewal->get_customer_id() !== 0 && (int) $token->get_user_id() !== (int) $renewal->get_customer_id()) {
                        return new \WP_Error('stackless_paypal_token_owner', __('The saved PayPal account belongs to a different customer.', 'stackless-lite'));
                    }
                    $environment = $token instanceof PaymentToken ? $token->get_environment() : CardTokens::environment($token);
                    return [$token->get_token(), $environment ?: (string) $source->get_meta(Gateway::META_ENV) ?: $this->gateway->environment(), CardTokens::isCard($token) ? 'card' : 'paypal'];
                }
            }
            $vaultId = (string) $source->get_meta(Gateway::META_VAULT_ID);
            if ($vaultId !== '') {
                $type = (string) $source->get_meta(Gateway::META_VAULT_TYPE) === 'card' ? 'card' : 'paypal';
                return [$vaultId, (string) $source->get_meta(Gateway::META_ENV) ?: $this->gateway->environment(), $type];
            }
        }
        return new \WP_Error('stackless_paypal_no_token', __('No saved PayPal account for this subscription.', 'stackless-lite'));
    }

    /**
     * Marks a renewal failed. The decline category and code go on the order first: Dunning
     * reads them when the order turns failed.
     *
     * @return array{result:string,message:string}
     */
    public function failRenewal(\WC_Order $renewal, string $message, bool $countAttempt, string $category = SubscriptionData::DECLINE_SOFT, string $code = 'declined'): array
    {
        if ($countAttempt) {
            $renewal->update_meta_data(Gateway::META_RENEWAL_ATTEMPTS, (string) ((int) $renewal->get_meta(Gateway::META_RENEWAL_ATTEMPTS) + 1));
        }
        if (!$renewal->is_paid()) {
            Declines::write($renewal, $category, $code);
        }
        $renewal->save();
        if (!$renewal->is_paid()) {
            /* translators: %s: error message */
            $renewal->update_status('failed', sprintf(__('PayPal renewal payment failed: %s', 'stackless-lite'), $message));
        }
        return ['result' => 'failed', 'message' => $message];
    }

    /* ---- Refunds and authorizations -------------------------------------------- */

    /** @return true|\WP_Error */
    public function refund(\WC_Order $order, float $amount, string $reason)
    {
        if ($amount <= 0) {
            return new \WP_Error('stackless_paypal_refund', __('Enter a refund amount above zero.', 'stackless-lite'));
        }
        $captureId = (string) $order->get_meta(Gateway::META_CAPTURE_ID);
        if ($captureId === '') {
            if ((string) $order->get_meta(Gateway::META_AUTH_ID) !== '') {
                return new \WP_Error('stackless_paypal_refund', __('This payment was only authorized, not captured. Cancel the order to void the authorization instead.', 'stackless-lite'));
            }
            $captureId = $order->get_transaction_id();
        }
        if ($captureId === '') {
            return new \WP_Error('stackless_paypal_refund', __('This order has no PayPal payment to refund.', 'stackless-lite'));
        }

        $refunds = $order->get_refunds();
        $latest = $refunds ? reset($refunds) : null;
        $requestId = 'stackless-refund-' . $order->get_id() . '-' . ($latest ? $latest->get_id() : md5((string) $amount . '|' . count($refunds)));

        $body = ['amount' => Amount::money($amount, $order->get_currency())];
        if ($reason !== '') {
            $body['note_to_payer'] = Amount::truncate($reason, 255);
        }
        $api = $this->gateway->apiFor((string) $order->get_meta(Gateway::META_ENV) ?: $this->gateway->environment());
        $response = $api->refundCapture($captureId, $body, $requestId);
        if (is_wp_error($response)) {
            $this->gateway->log('Refund on order ' . $order->get_id() . ' failed: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $status = (string) ($response['status'] ?? '');
        if (!in_array($status, ['COMPLETED', 'PENDING'], true)) {
            /* translators: %s: refund status */
            return new \WP_Error('stackless_paypal_refund', sprintf(__('PayPal did not complete the refund (status: %s).', 'stackless-lite'), $status ?: 'unknown'));
        }
        $this->recordRefundId($order, (string) ($response['id'] ?? ''));
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: refund id, 3: status */
            __('Refunded %1$s through PayPal (refund %2$s, %3$s).', 'stackless-lite'),
            wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
            (string) ($response['id'] ?? ''),
            strtolower($status)
        ));
        return true;
    }

    public function recordRefundId(\WC_Order $order, string $refundId): void
    {
        if ($refundId === '') {
            return;
        }
        $ids = array_filter(explode(',', (string) $order->get_meta(Gateway::META_REFUND_IDS)));
        if (!in_array($refundId, $ids, true)) {
            $ids[] = $refundId;
            $order->update_meta_data(Gateway::META_REFUND_IDS, implode(',', $ids));
            $order->save();
        }
    }

    public function hasRefundId(\WC_Order $order, string $refundId): bool
    {
        return in_array($refundId, array_filter(explode(',', (string) $order->get_meta(Gateway::META_REFUND_IDS))), true);
    }

    /** Capture an authorized payment when the merchant moves the order to Processing/Completed. */
    public function captureAuthorization(\WC_Order $order): void
    {
        $authId = (string) $order->get_meta(Gateway::META_AUTH_ID);
        if ($authId === '' || (string) $order->get_meta(Gateway::META_CAPTURE_ID) !== '' || $order->get_payment_method() !== $this->gateway->id) {
            return;
        }
        $api = $this->gateway->apiFor((string) $order->get_meta(Gateway::META_ENV) ?: $this->gateway->environment());
        $response = $api->captureAuthorization($authId, [
            'amount' => Amount::money($order->get_total(), $order->get_currency()),
            'final_capture' => true,
            'invoice_id' => Amount::truncate($this->gateway->invoicePrefix() . $order->get_order_number(), 127),
        ], 'stackless-auth-capture-' . $authId);

        if (is_wp_error($response)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Capturing the PayPal authorization failed: %s', 'stackless-lite'), $response->get_error_message()));
            $this->gateway->log('Authorization capture on ' . $order->get_id() . ' failed: ' . $response->get_error_message(), 'error');
            return;
        }

        $captureId = (string) ($response['id'] ?? '');
        $order->update_meta_data(Gateway::META_CAPTURE_ID, $captureId);
        $order->set_transaction_id($captureId);
        if (!$order->get_date_paid('edit') && ($response['status'] ?? '') === 'COMPLETED') {
            $order->set_date_paid(time());
        }
        $order->save();
        $order->add_order_note(sprintf(
            /* translators: 1: capture id, 2: status */
            __('PayPal authorization captured (%1$s, %2$s).', 'stackless-lite'),
            $captureId,
            strtolower((string) ($response['status'] ?? ''))
        ));
    }

    public function voidAuthorization(\WC_Order $order): void
    {
        $authId = (string) $order->get_meta(Gateway::META_AUTH_ID);
        if ($authId === '' || (string) $order->get_meta(Gateway::META_CAPTURE_ID) !== '' || $order->get_payment_method() !== $this->gateway->id || $order->get_meta('_stackless_paypal_voided')) {
            return;
        }
        $api = $this->gateway->apiFor((string) $order->get_meta(Gateway::META_ENV) ?: $this->gateway->environment());
        $response = $api->voidAuthorization($authId);
        if (is_wp_error($response)) {
            /* translators: %s: error */
            $order->add_order_note(sprintf(__('Voiding the PayPal authorization failed: %s', 'stackless-lite'), $response->get_error_message()));
            return;
        }
        $order->update_meta_data('_stackless_paypal_voided', 'yes');
        $order->save();
        $order->add_order_note(__('PayPal authorization voided.', 'stackless-lite'));
    }

    /* ---- Helpers ------------------------------------------------------------------ */

    /** First HATEOAS link with one of these rels. */
    public static function link(array $response, array $rels): string
    {
        foreach ($rels as $rel) {
            foreach ((array) ($response['links'] ?? []) as $link) {
                if (is_array($link) && ($link['rel'] ?? '') === $rel && !empty($link['href'])) {
                    return (string) $link['href'];
                }
            }
        }
        return '';
    }

    /** Network failure or a PayPal 5xx: the outcome is unknown, so don't count it as a decline. */
    public static function isTransient(\WP_Error $error): bool
    {
        $status = (int) (((array) $error->get_error_data())['status'] ?? 0);
        return $status === 0 || $status >= 500 || $status === 429;
    }

    private function isShippingError(\WP_Error $error): bool
    {
        $issue = strtoupper((string) (((array) $error->get_error_data())['issue'] ?? ''));
        return $issue !== '' && (strpos($issue, 'SHIPPING') !== false || strpos($issue, 'ADDRESS') !== false || strpos($issue, 'POSTAL') !== false || strpos($issue, 'STATE') !== false || strpos($issue, 'COUNTRY') !== false);
    }
}
