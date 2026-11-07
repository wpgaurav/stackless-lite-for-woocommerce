<?php

namespace Stackless\Modules\MollieGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Starts Mollie payments for checkout and My Account, and handles the buyer's return.
 *
 * A checkout that must save a method (a subscription, or the buyer ticked "save") becomes a
 * first payment on a Mollie customer, limited to methods that leave a mandate. A zero-total
 * order (all trial) still needs one: 0.00 for cards and PayPal, otherwise one cent that is
 * refunded once Mollie confirms the mandate.
 */
final class Checkout
{
    public const RETURN_ACTION = 'stackless_mollie_return';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Create (or reuse) the Mollie payment for an order.
     *
     * @return string|\WP_Error Mollie checkout URL.
     */
    public function start(\WC_Order $order, bool $save, string $method = '')
    {
        $mode = $this->gateway->mode();
        $api = $this->gateway->api($mode);
        $currency = strtoupper($order->get_currency());
        if (!Money::supported($currency)) {
            /* translators: %s: currency code */
            return new \WP_Error('stackless_mollie_currency', sprintf(__('Mollie does not take payments in %s.', 'stackless-lite'), $currency));
        }
        $total = (float) $order->get_total();
        $verification = $total <= 0;

        $methods = $this->methodsFor($api, $currency, $verification ? Money::smallest($currency) : Money::value($total, $currency), $save, $method);
        if ($save && !$methods) {
            return new \WP_Error('stackless_mollie_no_method', __('None of the Mollie payment methods for this order can be saved for later payments. Choose another payment method.', 'stackless-lite'));
        }
        $amount = $verification
            ? (count($methods) === 1 ? Methods::verificationAmount($methods[0], $currency) : Money::smallest($currency))
            : Money::value($total, $currency);

        // Placing the order again (back button, double click) reuses a still-open payment.
        $previous = (string) $order->get_meta(Gateway::META_PAYMENT_ID);
        $previousUrl = (string) $order->get_meta(Gateway::META_CHECKOUT_URL);
        if ($previous !== '' && $previousUrl !== '' && $this->gateway->orderMode($order) === $mode) {
            $existing = $api->request('GET', 'payments/' . rawurlencode($previous));
            if (is_array($existing) && ($existing['status'] ?? '') === 'open'
                && Money::matches($existing['amount'] ?? null, $amount, $currency)
                && (string) ($existing['sequenceType'] ?? 'oneoff') === ($save ? 'first' : 'oneoff')
                && !empty($existing['_links']['checkout']['href'])) {
                return (string) $existing['_links']['checkout']['href'];
            }
        }

        $body = [
            'amount' => ['currency' => $currency, 'value' => $amount],
            /* translators: 1: order number, 2: site name */
            'description' => substr(sprintf(__('Order %1$s – %2$s', 'stackless-lite'), $order->get_order_number(), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 255),
            'redirectUrl' => self::returnUrl(['order' => $order->get_id(), 'key' => $order->get_order_key()]),
            'cancelUrl' => $order->get_checkout_payment_url(),
            'webhookUrl' => $this->gateway->webhookUrl(),
            'locale' => self::locale(),
            'metadata' => Sync::metadata($order, $verification ? 'verify' : 'checkout'),
            'billingAddress' => self::address($order),
        ];
        if ($methods) {
            $body['method'] = count($methods) === 1 ? $methods[0] : $methods;
        }
        if ($save) {
            $customer = $this->gateway->customerFor($order, $mode);
            if (is_wp_error($customer)) {
                return $customer;
            }
            $body['sequenceType'] = 'first';
            $body['customerId'] = $customer;
            $order->update_meta_data(SubscriptionData::META_GATEWAY_CUSTOMER, $customer);
        }

        $payment = $api->request('POST', 'payments', array_filter($body), 'stackless-order-' . $order->get_id() . '-' . md5((string) wp_json_encode($body)));
        if (is_wp_error($payment)) {
            return $payment;
        }
        $url = (string) ($payment['_links']['checkout']['href'] ?? '');
        if (empty($payment['id']) || $url === '') {
            return new \WP_Error('stackless_mollie_checkout', __('Mollie did not return a checkout page for this payment.', 'stackless-lite'));
        }
        $order->update_meta_data(Gateway::META_PAYMENT_ID, (string) $payment['id']);
        $order->update_meta_data(Gateway::META_CHECKOUT_URL, $url);
        $order->update_meta_data(Gateway::META_MODE, $mode);
        $order->update_meta_data(Gateway::META_SEQUENCE, $save ? 'first' : 'oneoff');
        $order->update_meta_data(Gateway::META_VERIFICATION, $verification ? 'yes' : 'no');
        $order->save();
        $this->gateway->log(sprintf('Payment %s created for order %d (%s %s, %s).', (string) $payment['id'], $order->get_id(), $amount, $currency, $save ? 'first' : 'oneoff'));
        return $url;
    }

    /**
     * My Account → Add payment method: a first payment that only saves the method.
     *
     * @return string|\WP_Error
     */
    public function startAdd(int $userId, string $method = '')
    {
        $mode = $this->gateway->mode();
        $api = $this->gateway->api($mode);
        $currency = strtoupper(get_woocommerce_currency());
        if (!Money::supported($currency) || ($mode === 'test' && $currency !== 'EUR')) {
            $currency = 'EUR';
        }
        $methods = $this->methodsFor($api, $currency, Money::smallest($currency), true, $method);
        if (!$methods) {
            return new \WP_Error('stackless_mollie_no_method', __('No Mollie payment method that can be saved is switched on.', 'stackless-lite'));
        }
        $customer = $this->gateway->customerForUser($userId, $mode);
        if (is_wp_error($customer)) {
            return $customer;
        }
        $nonce = wp_generate_password(20, false);
        $amount = count($methods) === 1 ? Methods::verificationAmount($methods[0], $currency) : Money::smallest($currency);
        $body = [
            'amount' => ['currency' => $currency, 'value' => $amount],
            /* translators: %s: site name */
            'description' => substr(sprintf(__('Save a payment method – %s', 'stackless-lite'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)), 0, 255),
            'redirectUrl' => self::returnUrl(['flow' => 'add', 'ref' => $nonce]),
            'webhookUrl' => $this->gateway->webhookUrl(),
            'locale' => self::locale(),
            'sequenceType' => 'first',
            'customerId' => $customer,
            'method' => count($methods) === 1 ? $methods[0] : $methods,
            'metadata' => [
                'flow' => 'add',
                'wp_user_id' => (string) $userId,
                'wc_site' => Sync::site(),
                'wc_gateway' => Gateway::ID,
            ],
        ];
        $payment = $api->request('POST', 'payments', $body, 'stackless-add-' . $userId . '-' . $nonce);
        if (is_wp_error($payment)) {
            return $payment;
        }
        $url = (string) ($payment['_links']['checkout']['href'] ?? '');
        if (empty($payment['id']) || $url === '') {
            return new \WP_Error('stackless_mollie_checkout', __('Mollie did not return a checkout page for this payment.', 'stackless-lite'));
        }
        update_user_meta($userId, Gateway::USER_META_PENDING_ADD, ['payment' => (string) $payment['id'], 'ref' => $nonce, 'mode' => $mode, 'at' => time()]);
        return $url;
    }

    /**
     * Methods to send with a payment: the buyer's pick when it fits, else the store's list
     * narrowed to what Mollie offers here (only mandate methods when saving).
     *
     * @return string[]
     */
    private function methodsFor(Api $api, string $currency, string $amount, bool $first, string $picked): array
    {
        $available = Methods::enabled($api, $currency, $amount, $first);
        $ids = Methods::pick($this->gateway->configuredMethods(), $available, $first);
        if ($first) {
            $ids = array_values(array_filter($ids, static function ($id) use ($currency) {
                return Methods::mandateTakes(Methods::MANDATE_OF[$id] ?? '', $currency);
            }));
        }
        if ($picked !== '' && in_array($picked, $ids, true)) {
            return [$picked];
        }
        return $ids;
    }

    /** @param array<string,string|int> $args */
    public static function returnUrl(array $args): string
    {
        return add_query_arg($args, WC()->api_request_url(self::RETURN_ACTION));
    }

    /** Mollie locale from the site language (Mollie falls back to the browser for others). */
    public static function locale(): string
    {
        $supported = ['en_US', 'en_GB', 'nl_NL', 'nl_BE', 'fr_FR', 'fr_BE', 'de_DE', 'de_AT', 'de_CH', 'es_ES', 'ca_ES', 'pt_PT', 'it_IT', 'nb_NO', 'sv_SE', 'fi_FI', 'da_DK', 'is_IS', 'hu_HU', 'pl_PL', 'lv_LV', 'lt_LT'];
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (in_array($locale, $supported, true)) {
            return $locale;
        }
        foreach ($supported as $candidate) {
            if (strpos($candidate, substr($locale, 0, 2) . '_') === 0) {
                return $candidate;
            }
        }
        return 'en_US';
    }

    /** @return array<string,string> Mollie billing address (empty values left out). */
    public static function address(\WC_Order $order): array
    {
        $street = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $address = [
            'givenName' => $order->get_billing_first_name(),
            'familyName' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'streetAndNumber' => $street,
            'postalCode' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'country' => $order->get_billing_country(),
        ];
        // Mollie rejects partial addresses: send the street block only when it is complete.
        if ($street === '' || $address['city'] === '' || $address['country'] === '') {
            unset($address['streetAndNumber'], $address['postalCode'], $address['city'], $address['country']);
        }
        return array_filter($address, static function ($value) {
            return (string) $value !== '';
        });
    }

    /* ---- Return from Mollie ---------------------------------------------------------- */

    /** woocommerce_api_stackless_mollie_return */
    public static function handleReturn(): void
    {
        $gateway = Gateway::instance();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Mollie sends the buyer back; the order key or the stored reference authenticates the request.
        $flow = isset($_GET['flow']) ? sanitize_key(wp_unslash((string) $_GET['flow'])) : '';
        if ($flow === 'add') {
            $ref = isset($_GET['ref']) ? sanitize_text_field(wp_unslash((string) $_GET['ref'])) : '';
            self::finishAdd($gateway, get_current_user_id(), $ref);
            return;
        }
        $orderId = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : '';
        // phpcs:enable
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order instanceof \WC_Order || $key === '' || !hash_equals($order->get_order_key(), $key) || $order->get_payment_method() !== Gateway::ID) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }
        $outcome = (new Sync($gateway))->refreshOrder($order);
        $order = wc_get_order($order->get_id());
        if ($order instanceof \WC_Order && ($order->is_paid() || $order->has_status('on-hold') || in_array($outcome, ['paid', 'pending', 'busy'], true))) {
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            wp_safe_redirect($gateway->get_return_url($order));
            exit;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice($outcome === 'failed'
                ? __('Your payment was declined. Please try again or choose another payment method.', 'stackless-lite')
                : __('The payment was not completed. You can try again below.', 'stackless-lite'), 'error');
        }
        wp_safe_redirect($order instanceof \WC_Order ? $order->get_checkout_payment_url() : wc_get_checkout_url());
        exit;
    }

    private static function finishAdd(Gateway $gateway, int $userId, string $ref): void
    {
        $target = wc_get_account_endpoint_url('payment-methods');
        $pending = $userId > 0 ? get_user_meta($userId, Gateway::USER_META_PENDING_ADD, true) : null;
        if (!is_array($pending) || $ref === '' || !hash_equals((string) ($pending['ref'] ?? ''), $ref)) {
            wp_safe_redirect($target);
            exit;
        }
        $payment = $gateway->api((string) ($pending['mode'] ?? ''))->request('GET', 'payments/' . rawurlencode((string) $pending['payment']));
        $token = null;
        if (is_array($payment)) {
            $token = (new Sync($gateway))->applyAddPayment($payment, (string) $pending['mode']);
        }
        if ($token instanceof \WC_Payment_Token) {
            delete_user_meta($userId, Gateway::USER_META_PENDING_ADD);
            wc_add_notice(__('Payment method successfully added.', 'stackless-lite'));
        } elseif (is_array($payment) && in_array($payment['status'] ?? '', ['open', 'pending'], true)) {
            wc_add_notice(__('Mollie is still confirming your payment method. It appears here once confirmed.', 'stackless-lite'), 'notice');
        } else {
            delete_user_meta($userId, Gateway::USER_META_PENDING_ADD);
            wc_add_notice(__('Unable to add payment method to your account.', 'stackless-lite'), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }
}
