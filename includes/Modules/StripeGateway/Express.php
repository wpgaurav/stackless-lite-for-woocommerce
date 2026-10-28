<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Express Checkout Element (Apple Pay, Google Pay, Link) on product, cart and checkout pages.
 *
 * The wallet sheet collects addresses; these wc-ajax endpoints keep the WooCommerce cart in
 * step (shipping address → rates → totals) and finally run WooCommerce's own checkout
 * (WC_Checkout::process_checkout) with the wallet details, so taxes, coupons, fees, stock,
 * emails and other plugins' checkout hooks behave exactly as on a normal checkout.
 *
 * On a product page the wallet buys only that product: the shopper's existing cart is put
 * aside in the session and restored when the sheet is cancelled or after the order.
 */
final class Express
{
    public const NONCE = 'stackless_stripe_gateway_express';
    private const SESSION_SAVED_CART = 'stackless_stripe_express_saved_cart';
    private const SESSION_PRODUCT_MODE = 'stackless_stripe_express_product';

    /** @var bool */
    private static $rendered = false;

    public static function registerHooks(): void
    {
        if (Settings::expressEnabledOn('product')) {
            add_action('woocommerce_after_add_to_cart_form', [self::class, 'renderProduct'], 5);
        }
        if (Settings::expressEnabledOn('cart')) {
            add_action('woocommerce_proceed_to_checkout', [self::class, 'renderCart'], 5);
        }
        if (Settings::expressEnabledOn('checkout')) {
            add_action('woocommerce_before_checkout_form', [self::class, 'renderCheckout'], 15);
        }
        add_action('wc_ajax_stackless_stripe_gateway_express_update', [self::class, 'ajaxUpdate']);
        add_action('wc_ajax_stackless_stripe_gateway_express_checkout', [self::class, 'ajaxCheckout']);
        add_action('wc_ajax_stackless_stripe_gateway_express_cancel', [self::class, 'ajaxCancel']);
        add_action('template_redirect', [self::class, 'maybeRestoreAfterOrder'], 99);
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    public static function renderProduct(): void
    {
        global $product;
        if (!$product instanceof \WC_Product || !self::canShow()) {
            return;
        }
        if (!$product->is_purchasable() || !$product->is_in_stock() || !in_array($product->get_type(), ['simple', 'variable', 'subscription', 'variable-subscription'], true)) {
            return;
        }
        $price = (float) wc_get_price_to_display($product);
        $currency = get_woocommerce_currency();
        if ($product->get_type() === 'simple' && ($price <= 0 || !Amount::meetsMinimum($price, $currency))) {
            return;
        }
        self::output('product', [
            'productId' => $product->get_id(),
            'productType' => $product->is_type('variable') ? 'variable' : 'simple',
            'amount' => $price > 0 ? Amount::toMinor($price, $currency) : 0,
            'currency' => strtolower($currency),
            'needsShipping' => $product->needs_shipping(),
            'requiresSave' => stackless_is_subscription_product($product),
            'label' => wp_strip_all_tags($product->get_name()),
        ]);
    }

    public static function renderCart(): void
    {
        if (!self::canShow()) {
            return;
        }
        $data = self::cartData('cart');
        if ($data) {
            self::output('cart', $data);
        }
    }

    public static function renderCheckout(): void
    {
        if (!self::canShow() || (function_exists('is_checkout_pay_page') && is_checkout_pay_page())) {
            return;
        }
        $data = self::cartData('checkout');
        if ($data) {
            self::output('checkout', $data);
        }
    }

    /** @return array<string,mixed>|null */
    public static function cartData(string $context): ?array
    {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return null;
        }
        $total = (float) WC()->cart->get_total('edit');
        $currency = get_woocommerce_currency();
        if ($total <= 0 || !Amount::meetsMinimum($total, $currency)) {
            return null;
        }
        return [
            'amount' => Amount::toMinor($total, $currency),
            'currency' => strtolower($currency),
            'needsShipping' => WC()->cart->needs_shipping(),
            'requiresSave' => stackless_cart_requires_saved_payment_method(),
            'context' => $context,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function output(string $context, array $data): void
    {
        if (self::$rendered) {
            return;
        }
        self::$rendered = true;
        Assets::enqueueExpress();
        stackless_get_template('stripe_gateway/express-buttons.php', [
            'context' => $context,
            'config' => array_merge(['context' => $context], $data),
            'terms_url' => wc_terms_and_conditions_page_id() ? get_permalink(wc_terms_and_conditions_page_id()) : '',
        ]);
    }

    /** Gateway on, keys present, and the shopper can finish a checkout without filling a form. */
    public static function canShow(): bool
    {
        $gateway = Module::gateway();
        if ($gateway->enabled !== 'yes' || !Settings::isConfigured() || !Settings::expressLocations()) {
            return false;
        }
        if (!Amount::isSupported(get_woocommerce_currency())) {
            return false;
        }
        return self::accountRequirementsMet();
    }

    public static function accountRequirementsMet(): bool
    {
        if (is_user_logged_in() || !WC()->checkout()->is_registration_required()) {
            return true;
        }
        return WC()->checkout()->is_registration_enabled()
            && get_option('woocommerce_registration_generate_username', 'yes') === 'yes'
            && get_option('woocommerce_registration_generate_password', 'yes') === 'yes';
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------- */

    public static function ajaxUpdate(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        wc_maybe_define_constant('WOOCOMMERCE_CART', true);

        $context = isset($_POST['context']) ? sanitize_key(wp_unslash((string) $_POST['context'])) : 'cart';
        if ($context === 'product' && !empty($_POST['prepare'])) {
            $error = self::prepareProductCart();
            if ($error !== '') {
                wp_send_json_error(['message' => $error], 400);
            }
        }

        if (isset($_POST['address']) && is_array($_POST['address'])) {
            self::applyLocation(self::cleanAddress(wp_unslash($_POST['address']))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned field by field.
        }
        if (!empty($_POST['rate'])) {
            self::chooseRate(sanitize_text_field(wp_unslash((string) $_POST['rate'])));
        }

        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        if (WC()->cart->is_empty()) {
            wp_send_json_error(['message' => __('Your cart is empty.', 'stackless-lite')], 400);
        }
        wp_send_json_success(self::totals());
    }

    public static function ajaxCheckout(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $context = isset($_POST['context']) ? sanitize_key(wp_unslash((string) $_POST['context'])) : 'cart';
        if ($context === 'product' && !empty($_POST['prepare'])) {
            $error = self::prepareProductCart();
            if ($error !== '') {
                wp_send_json(['result' => 'failure', 'messages' => esc_html($error)]);
            }
        }
        if (!self::accountRequirementsMet()) {
            wp_send_json(['result' => 'failure', 'messages' => esc_html__('Sign in to check out.', 'stackless-lite')]);
        }

        $billing = isset($_POST['billing']) && is_array($_POST['billing']) ? wp_unslash($_POST['billing']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
        $shipping = isset($_POST['shipping']) && is_array($_POST['shipping']) ? wp_unslash($_POST['shipping']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
        $rate = isset($_POST['rate']) ? sanitize_text_field(wp_unslash((string) $_POST['rate'])) : '';
        $token = isset($_POST['confirmation_token']) ? sanitize_text_field(wp_unslash((string) $_POST['confirmation_token'])) : '';

        $billing_address = self::cleanAddress(isset($billing['address']) && is_array($billing['address']) ? $billing['address'] : []);
        $shipping_address = self::cleanAddress(isset($shipping['address']) && is_array($shipping['address']) ? $shipping['address'] : []);
        list($billing_first, $billing_last) = self::splitName(sanitize_text_field((string) ($billing['name'] ?? '')));
        list($shipping_first, $shipping_last) = self::splitName(sanitize_text_field((string) ($shipping['name'] ?? ($billing['name'] ?? ''))));
        if (!$billing_address['country'] && $shipping_address['country']) {
            $billing_address = $shipping_address;
        }

        $posted = [
            'billing_first_name' => $billing_first,
            'billing_last_name' => $billing_last,
            'billing_company' => '',
            'billing_email' => sanitize_email((string) ($billing['email'] ?? '')),
            'billing_phone' => wc_sanitize_phone_number((string) ($billing['phone'] ?? '')),
            'billing_country' => $billing_address['country'],
            'billing_address_1' => $billing_address['line1'],
            'billing_address_2' => $billing_address['line2'],
            'billing_city' => $billing_address['city'],
            'billing_state' => $billing_address['state'],
            'billing_postcode' => $billing_address['postal_code'],
            'payment_method' => Settings::GATEWAY_ID,
            'stackless_stripe_express' => '1',
            Gateway::FIELD_CONFIRMATION_TOKEN => $token,
            'woocommerce-process-checkout-nonce' => wp_create_nonce('woocommerce-process_checkout'),
            'order_comments' => '',
        ];
        if (WC()->cart->needs_shipping()) {
            $posted += [
                'ship_to_different_address' => '1',
                'shipping_first_name' => $shipping_first,
                'shipping_last_name' => $shipping_last,
                'shipping_company' => '',
                'shipping_country' => $shipping_address['country'],
                'shipping_address_1' => $shipping_address['line1'],
                'shipping_address_2' => $shipping_address['line2'],
                'shipping_city' => $shipping_address['city'],
                'shipping_state' => $shipping_address['state'],
                'shipping_postcode' => $shipping_address['postal_code'],
            ];
            if ($rate !== '') {
                self::chooseRate($rate);
                $posted['shipping_method'] = [0 => $rate];
            }
        }
        if (wc_terms_and_conditions_checkbox_enabled()) {
            // The express buttons show the terms link next to them; paying with a wallet accepts them.
            $posted['terms'] = '1';
            $posted['terms-field'] = '1';
        }
        if (!is_user_logged_in() && WC()->checkout()->is_registration_required()) {
            $posted['createaccount'] = '1';
        }

        foreach ($posted as $key => $value) {
            $_POST[$key] = $value;
            $_REQUEST[$key] = $value;
        }

        wc_maybe_define_constant('WOOCOMMERCE_CHECKOUT', true);
        WC()->checkout()->process_checkout();
        wp_send_json(['result' => 'failure', 'messages' => esc_html(ErrorMapper::generic())]);
    }

    public static function ajaxCancel(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        self::restoreSavedCart();
        wp_send_json_success();
    }

    /** After an express product-page order, put the shopper's earlier cart back. */
    public static function maybeRestoreAfterOrder(): void
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page() || !WC()->session || !WC()->session->get(self::SESSION_SAVED_CART)) {
            return;
        }
        self::restoreSavedCart();
    }

    /* ---------------------------------------------------------------------
     * Cart helpers
     * ------------------------------------------------------------------- */

    /** Replace the cart with the product being bought. Returns an error message or ''. */
    private static function prepareProductCart(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- callers verified the express nonce.
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? wc_stock_amount((float) sanitize_text_field(wp_unslash((string) $_POST['quantity']))) : 1;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $attributes = isset($_POST['attributes']) && is_array($_POST['attributes']) ? map_deep(wp_unslash($_POST['attributes']), 'sanitize_text_field') : [];
        // phpcs:enable

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            return __('This product cannot be purchased.', 'stackless-lite');
        }
        $quantity = max(1, (int) $quantity);

        $variation = [];
        if ($product->is_type('variable')) {
            $variation_product = $variation_id ? wc_get_product($variation_id) : null;
            if (!$variation_product || $variation_product->get_parent_id() !== $product->get_id()) {
                $variation_id = (int) (new \WC_Product_Data_Store_CPT())->find_matching_product_variation($product, $attributes);
                $variation_product = $variation_id ? wc_get_product($variation_id) : null;
            }
            if (!$variation_product) {
                return __('Choose product options before paying.', 'stackless-lite');
            }
            foreach ((array) $attributes as $name => $value) {
                $name = sanitize_title((string) $name);
                if (strpos($name, 'attribute_') !== 0) {
                    $name = 'attribute_' . $name;
                }
                $variation[$name] = (string) $value;
            }
        } else {
            $variation_id = 0;
        }

        if (!WC()->session->get(self::SESSION_PRODUCT_MODE) && !WC()->cart->is_empty()) {
            WC()->session->set(self::SESSION_SAVED_CART, [
                'cart' => WC()->cart->get_cart_for_session(),
                'coupons' => WC()->cart->get_applied_coupons(),
            ]);
        }
        WC()->session->set(self::SESSION_PRODUCT_MODE, true);
        WC()->cart->empty_cart(false);
        wc_clear_notices();

        $added = WC()->cart->add_to_cart($product->get_id(), $quantity, $variation_id, $variation);
        if (!$added) {
            $notices = wc_get_notices('error');
            wc_clear_notices();
            $first = $notices ? reset($notices) : null;
            $message = is_array($first) && isset($first['notice']) ? wp_strip_all_tags((string) $first['notice']) : __('This product could not be added to the cart.', 'stackless-lite');
            self::restoreSavedCart();
            return $message;
        }
        return '';
    }

    private static function restoreSavedCart(): void
    {
        if (!WC()->session) {
            return;
        }
        $saved = WC()->session->get(self::SESSION_SAVED_CART);
        $was_product_mode = (bool) WC()->session->get(self::SESSION_PRODUCT_MODE);
        WC()->session->set(self::SESSION_SAVED_CART, null);
        WC()->session->set(self::SESSION_PRODUCT_MODE, null);
        if (!$was_product_mode) {
            return;
        }
        WC()->cart->empty_cart(false);
        if (is_array($saved) && !empty($saved['cart']) && is_array($saved['cart'])) {
            WC()->session->set('cart', $saved['cart']);
            WC()->session->set('applied_coupons', isset($saved['coupons']) && is_array($saved['coupons']) ? $saved['coupons'] : []);
            WC()->cart->get_cart_from_session();
            WC()->cart->calculate_totals();
        }
    }

    /** @param array{country:string, state:string, postal_code:string, city:string, line1:string, line2:string} $address */
    private static function applyLocation(array $address): void
    {
        $customer = WC()->customer;
        if (!$customer || $address['country'] === '') {
            return;
        }
        $customer->set_shipping_location($address['country'], $address['state'], $address['postal_code'], $address['city']);
        $customer->set_shipping_address_1($address['line1']);
        $customer->set_shipping_address_2($address['line2']);
        if (!$customer->get_billing_country()) {
            $customer->set_billing_location($address['country'], $address['state'], $address['postal_code'], $address['city']);
        }
        $customer->set_calculated_shipping(true);
        $customer->save();
    }

    private static function chooseRate(string $rate_id): void
    {
        $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
        $chosen[0] = $rate_id;
        WC()->session->set('chosen_shipping_methods', $chosen);
    }

    /**
     * @param array<string,mixed> $address
     * @return array{country:string, state:string, postal_code:string, city:string, line1:string, line2:string}
     */
    private static function cleanAddress(array $address): array
    {
        $country = strtoupper(sanitize_text_field((string) ($address['country'] ?? '')));
        $state = sanitize_text_field((string) ($address['state'] ?? ''));
        return [
            'country' => $country,
            'state' => self::normalizeState($country, $state),
            'postal_code' => wc_format_postcode(sanitize_text_field((string) ($address['postal_code'] ?? '')), $country),
            'city' => sanitize_text_field((string) ($address['city'] ?? '')),
            'line1' => sanitize_text_field((string) ($address['line1'] ?? '')),
            'line2' => sanitize_text_field((string) ($address['line2'] ?? '')),
        ];
    }

    /** Wallets send state names ("California") or codes ("CA"); WooCommerce wants its own codes. */
    private static function normalizeState(string $country, string $state): string
    {
        if ($country === '' || $state === '') {
            return $state;
        }
        $states = WC()->countries->get_states($country);
        if (!is_array($states) || !$states) {
            return $state;
        }
        $upper = strtoupper($state);
        if (isset($states[$upper])) {
            return $upper;
        }
        foreach ($states as $code => $name) {
            if (strcasecmp(html_entity_decode((string) $name, ENT_QUOTES), $state) === 0 || strcasecmp(remove_accents(html_entity_decode((string) $name, ENT_QUOTES)), remove_accents($state)) === 0) {
                return (string) $code;
            }
        }
        // Some wallets prefix the country, e.g. "ES-M".
        if (strpos($upper, $country . '-') === 0 && isset($states[substr($upper, strlen($country) + 1)])) {
            return substr($upper, strlen($country) + 1);
        }
        return $state;
    }

    /** @return array{0:string, 1:string} */
    private static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['', ''];
        }
        $position = strrpos($name, ' ');
        if ($position === false) {
            return [$name, $name];
        }
        return [substr($name, 0, $position), substr($name, $position + 1)];
    }

    /**
     * Totals for the wallet sheet, in Stripe minor units.
     *
     * @return array<string,mixed>
     */
    public static function totals(): array
    {
        $cart = WC()->cart;
        $currency = get_woocommerce_currency();
        $total = (float) $cart->get_total('edit');
        $amount = $total > 0 ? Amount::toMinor($total, $currency) : 0;

        $rates = [];
        $chosen = (array) WC()->session->get('chosen_shipping_methods', []);
        $packages = WC()->shipping()->get_packages();
        if ($cart->needs_shipping() && $packages) {
            $package = reset($packages);
            foreach ((array) ($package['rates'] ?? []) as $rate) {
                if (!$rate instanceof \WC_Shipping_Rate) {
                    continue;
                }
                $cost = (float) $rate->get_cost() + array_sum(array_map('floatval', (array) $rate->get_taxes()));
                $entry = [
                    'id' => $rate->get_id(),
                    'displayName' => wp_strip_all_tags($rate->get_label()),
                    'amount' => Amount::toMinor(max(0, $cost), $currency),
                ];
                if (isset($chosen[0]) && $chosen[0] === $rate->get_id()) {
                    array_unshift($rates, $entry);
                } else {
                    $rates[] = $entry;
                }
            }
        }

        $line_items = [];
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $line = (float) ($item['line_total'] ?? 0) + (float) ($item['line_tax'] ?? 0);
            $line_items[] = [
                'name' => wp_strip_all_tags($product->get_name()) . ((int) $item['quantity'] > 1 ? ' × ' . (int) $item['quantity'] : ''),
                'amount' => Amount::toMinor(max(0, $line), $currency),
            ];
        }
        foreach ($cart->get_fees() as $fee) {
            $fee_total = (float) $fee->total + (float) $fee->tax;
            if ($fee_total < 0) {
                $line_items = [];
                break;
            }
            $line_items[] = ['name' => wp_strip_all_tags((string) $fee->name), 'amount' => Amount::toMinor($fee_total, $currency)];
        }
        if ($line_items && $cart->needs_shipping()) {
            $shipping = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
            $line_items[] = ['name' => __('Shipping', 'stackless-lite'), 'amount' => Amount::toMinor(max(0, $shipping), $currency)];
        }
        // Wallets reject line items that don't add up to the total, so only send exact sets.
        $sum = array_sum(array_column($line_items, 'amount'));
        if ($sum !== $amount || count($line_items) > 20) {
            $line_items = [];
        }

        return [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'needsShipping' => $cart->needs_shipping(),
            'shippingRates' => $rates,
            'lineItems' => $line_items,
            'requiresSave' => stackless_cart_requires_saved_payment_method(),
        ];
    }
}
