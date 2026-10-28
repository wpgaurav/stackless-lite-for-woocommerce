<?php

namespace Stackless\Modules\CheckoutEnhancements;

defined('ABSPATH') || exit;

/**
 * Checkout Enhancements: consent checkboxes with stored proof, a shorter checkout for
 * virtual-only, free and digital carts, a free-order button, renamed checkout fields and
 * text, and a trust block under payment.
 *
 * Block checkout: consents are Additional Checkout Fields (location "order") with hidden
 * rules evaluated by WooCommerce; hidden fields and wording changes go through WooCommerce's
 * address locale and i18n filters, except the country, which WooCommerce keeps visible in the
 * locale: it is hidden with a body class while the customer and order get the worked-out
 * country from the Store API request; the free-order label is a placeOrderButtonLabel filter.
 * Classic checkout: the same consents and text through template hooks, checkout field
 * filters, a hidden country input, and the posted checkout data.
 */
final class Module extends \Stackless\Core\Module
{
    public const STORE_NAMESPACE = 'stackless_checkout_enhancements';
    public const FIELD_PREFIX = 'stackless/consent_';
    public const META_CONSENTS = '_stackless_checkout_enhancements_consents';
    public const META_NOTED = '_stackless_checkout_enhancements_noted';
    public const HANDLE = 'stackless-checkout_enhancements';

    /** @var array<int, bool> orders whose consent table already printed on this request */
    private $printed = [];

    public function id(): string
    {
        return 'checkout_enhancements';
    }

    public function title(): string
    {
        return __('Checkout Enhancements', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Consent checkboxes with stored proof, a shorter checkout for free and digital orders, a "Get it for free" button, your own checkout wording, and a trust block under the payment methods.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'checkout';
    }

    public function settingsFields(): array
    {
        return (new Admin())->fields();
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
        }

        add_action('woocommerce_init', [$this, 'registerBlockFields']);
        if (did_action('woocommerce_blocks_loaded')) {
            $this->registerStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApi']);
        }
        add_action('woocommerce_blocks_checkout_block_registration', [$this, 'registerBlocksIntegration']);
        add_filter('woocommerce_admin_shipping_fields', [$this, 'hideConsentFieldsInAdmin'], 20);

        // Consents on the classic checkout.
        add_action('woocommerce_review_order_before_submit', [$this, 'renderClassicConsents'], 20);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassicConsents'], 20, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'persistClassicConsents'], 20, 2);
        add_action('woocommerce_checkout_order_created', [$this, 'noteConsents'], 20);

        // Consents on the block checkout (values were stored by WooCommerce as additional fields).
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'persistBlockConsents'], 20, 2);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'noteConsents'], 20);

        // Where the stored proof is shown.
        add_action('woocommerce_order_details_after_customer_details', [$this, 'renderCustomerConsents'], 30);
        add_action('woocommerce_thankyou', [$this, 'renderThankYouConsents'], 30);
        add_filter('woocommerce_email_order_meta_fields', [$this, 'emailFields'], 20, 3);

        // Shorter checkout for virtual-only, free and digital carts, and renamed fields.
        add_filter('woocommerce_checkout_fields', [$this, 'classicCheckoutFields'], 20);
        add_filter('woocommerce_form_field_hidden', [$this, 'hiddenCountryInput'], 20, 4);
        add_filter('woocommerce_checkout_posted_data', [$this, 'postedCountry'], 20);
        add_action('woocommerce_store_api_cart_update_customer_from_request', [$this, 'fillCustomerCountry'], 20);
        add_action('woocommerce_store_api_checkout_update_customer_from_request', [$this, 'fillCustomerCountry'], 20);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'fillOrderCountry'], 5);
        add_action('template_redirect', [$this, 'primeCountry'], 6);
        add_filter('woocommerce_coupons_enabled', [$this, 'couponsEnabled'], 20);
        add_filter('body_class', [$this, 'bodyClass'], 20);
        add_filter('woocommerce_default_address_fields', [$this, 'defaultAddressFields'], 20);
        add_filter('woocommerce_get_country_locale_default', [$this, 'defaultLocale'], 20);
        add_filter('woocommerce_get_country_locale', [$this, 'countryLocale'], 20);
        add_action('woocommerce_cart_loaded_from_session', [$this, 'resetLocale'], 20);
        add_filter('woocommerce_order_button_text', [$this, 'buttonText'], 20);
        add_action('template_redirect', [$this, 'maybeTranslateCheckout'], 5);

        add_action('wp_enqueue_scripts', [$this, 'assets'], 20);
        add_action('woocommerce_review_order_after_payment', [$this, 'renderClassicTrust'], 20);
        add_action('wp_footer', [$this, 'trustTemplate'], 5);
    }

    /* ---------------------------------------------------------------------
     * Block checkout: Additional Checkout Fields and Store API data.
     * ------------------------------------------------------------------ */

    public function registerBlockFields(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }
        foreach (Settings::consents() as $consent) {
            $field = [
                'id' => self::FIELD_PREFIX . $consent['id'],
                'label' => html_entity_decode(wp_strip_all_tags($consent['label']), ENT_QUOTES, 'UTF-8'),
                'location' => 'order',
                'type' => 'checkbox',
                'required' => (bool) $consent['required'],
                'show_in_order_confirmation' => false,
            ];
            if ($consent['required']) {
                $field['error_message'] = __('Please tick this box to continue.', 'stackless-lite');
            }
            $rule = ConsentRules::hiddenRule($consent, self::STORE_NAMESPACE, $this->productMatchIds($consent));
            if ($rule) {
                $field['hidden'] = $rule;
                if ($consent['required']) {
                    // A rule, not true: see ConsentRules::requiredRule().
                    $field['required'] = ConsentRules::requiredRule($rule);
                }
            }
            try {
                woocommerce_register_additional_checkout_field($field);
            } catch (\Throwable $e) {
                $this->log('Consent field "' . $consent['id'] . '" was not registered: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /** @return int[] */
    private function productMatchIds(array $consent): array
    {
        if (($consent['scope'] ?? '') !== 'product' || empty($consent['product_id'])) {
            return [];
        }
        $ids = [(int) $consent['product_id']];
        $product = wc_get_product((int) $consent['product_id']);
        if ($product instanceof \WC_Product && $product->is_type('variable')) {
            $ids = array_merge($ids, array_map('intval', $product->get_children()));
        }
        return $ids;
    }

    public function registerStoreApi(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace' => self::STORE_NAMESPACE,
            'data_callback' => function () {
                $facts = $this->valueFacts();
                $lean = $this->lean($facts);
                return [
                    'has_digital' => (bool) $facts['has_digital'],
                    'needs_shipping' => (bool) $facts['needs_shipping'],
                    'virtual_only' => CartFacts::virtualOnly($facts),
                    'zero_value' => $lean['zero_value'],
                    'zero_total' => $lean['zero_total'],
                    'free_digital' => $lean['free_digital'],
                    'hide_country' => $lean['hide_country'],
                ];
            },
            'schema_callback' => function () {
                return [
                    'has_digital' => ['description' => __('Cart has a virtual or downloadable item.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                    'needs_shipping' => ['description' => __('Something in the cart ships.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                    'virtual_only' => ['description' => __('Nothing in the cart ships.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                    'zero_value' => ['description' => __('No item price, no fees and no subscription to bill later.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                    'zero_total' => ['description' => __('Nothing to pay at checkout, after coupons, and no subscription to bill later.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                    'free_digital' => ['description' => __('The shorter checkout for free digital products applies.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                    'hide_country' => ['description' => __('The country field is hidden and a saved country is used.', 'stackless-lite'), 'type' => 'boolean', 'readonly' => true],
                ];
            },
            'schema_type' => ARRAY_A,
        ]);
    }

    public function registerBlocksIntegration($registry): void
    {
        if (is_object($registry) && method_exists($registry, 'register') && !$registry->is_registered('stackless-checkout-enhancements')) {
            $registry->register(new BlocksIntegration());
        }
    }

    /** Consent answers are proof; they are shown read-only in the consent box, not as editable admin fields. */
    public function hideConsentFieldsInAdmin($fields)
    {
        if (!is_array($fields)) {
            return $fields;
        }
        foreach (array_keys($fields) as $key) {
            if (strpos((string) $key, self::FIELD_PREFIX) === 0) {
                unset($fields[$key]);
            }
        }
        return $fields;
    }

    /* ---------------------------------------------------------------------
     * Consents.
     * ------------------------------------------------------------------ */

    public function renderClassicConsents(): void
    {
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromCart());
        if (!$consents) {
            return;
        }
        stackless_get_template('checkout_enhancements/consents.php', ['consents' => $consents, 'allowed' => Settings::allowedLabelHtml()]);
    }

    /** @return array<string, bool> */
    private function postedAnswers(): array
    {
        // Nonce: WooCommerce verified woocommerce-process-checkout-nonce before these checkout hooks run.
        $posted = isset($_POST['stackless_consent']) && is_array($_POST['stackless_consent']) ? map_deep(wp_unslash($_POST['stackless_consent']), 'sanitize_text_field') : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $answers = [];
        foreach ($posted as $id => $value) {
            $answers[sanitize_key((string) $id)] = ConsentRules::isTicked(is_scalar($value) ? (string) $value : '');
        }
        return $answers;
    }

    public function validateClassicConsents($data, $errors): void
    {
        if (!$errors instanceof \WP_Error) {
            return;
        }
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromCart());
        $byId = [];
        foreach ($consents as $consent) {
            $byId[$consent['id']] = $consent;
        }
        foreach (ConsentRules::missing($consents, $this->postedAnswers()) as $id) {
            /* translators: %s: the consent wording the customer must accept */
            $errors->add('stackless_consent_' . $id, sprintf(__('Please confirm: %s', 'stackless-lite'), wp_strip_all_tags($byId[$id]['label'])), ['id' => 'stackless_consent_' . $id]);
        }
    }

    public function persistClassicConsents($order, $data): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromCart());
        $this->store($order, $consents, $this->postedAnswers());
    }

    public function persistBlockConsents($order, $request): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $consents = ConsentRules::applicable(Settings::consents(), CartFacts::fromOrder($order));
        $answers = [];
        foreach ($consents as $consent) {
            $answers[$consent['id']] = ConsentRules::isTicked($order->get_meta('_wc_other/' . self::FIELD_PREFIX . $consent['id'], true));
        }
        $this->store($order, $consents, $answers);
    }

    private function store(\WC_Order $order, array $consents, array $answers): void
    {
        if (!$consents) {
            $order->delete_meta_data(self::META_CONSENTS);
            return;
        }
        $records = ConsentRules::records($consents, $answers, gmdate('Y-m-d H:i:s'));
        $order->update_meta_data(self::META_CONSENTS, $records);
        /**
         * Fires when consent answers are recorded on an order, before it is saved.
         *
         * @param \WC_Order $order
         * @param array[]   $records id, type, label, version, required, accepted, at (UTC)
         */
        do_action('stackless_checkout_enhancements_consents_recorded', $order, $records);
    }

    /** One order note per distinct answer set, so a retried payment does not repeat it. */
    public function noteConsents($order): void
    {
        if (!$order instanceof \WC_Order || !$order->get_id()) {
            return;
        }
        $records = $this->records($order);
        if (!$records) {
            return;
        }
        $hash = md5((string) wp_json_encode(array_map(static function ($record) {
            unset($record['at']);
            return $record;
        }, $records)));
        if ($order->get_meta(self::META_NOTED, true) === $hash) {
            return;
        }
        $lines = array_map(static function ($record) {
            /* translators: 1: Accepted or Not accepted, 2: consent wording, 3: wording version */
            return sprintf(__('%1$s: %2$s (wording v%3$d)', 'stackless-lite'), $record['accepted'] ? __('Accepted', 'stackless-lite') : __('Not accepted', 'stackless-lite'), wp_strip_all_tags($record['label']), (int) $record['version']);
        }, $records);
        $order->add_order_note(__('Checkout consents', 'stackless-lite') . "\n" . implode("\n", $lines));
        $order->update_meta_data(self::META_NOTED, $hash);
        $order->save();
    }

    /** @return array[] */
    public function records(\WC_Order $order): array
    {
        $records = $order->get_meta(self::META_CONSENTS, true);
        return is_array($records) ? array_values(array_filter($records, static function ($record) {
            return is_array($record) && !empty($record['label']);
        })) : [];
    }

    public function renderCustomerConsents($order): void
    {
        if (!$order instanceof \WC_Order || !Settings::flag('show_account', true) || isset($this->printed[$order->get_id()])) {
            return;
        }
        $records = $this->records($order);
        if (!$records) {
            return;
        }
        $this->printed[$order->get_id()] = true;
        stackless_get_template('checkout_enhancements/order-consents.php', ['order' => $order, 'records' => $records, 'heading' => __('Checkout consents', 'stackless-lite')]);
    }

    /** The block order confirmation page skips the classic order details template, so print here too. */
    public function renderThankYouConsents($orderId): void
    {
        $order = wc_get_order((int) $orderId);
        if ($order instanceof \WC_Order) {
            $this->renderCustomerConsents($order);
        }
    }

    public function emailFields($fields, $sentToAdmin, $order)
    {
        if (!is_array($fields) || !$order instanceof \WC_Order || !Settings::flag('show_emails', true)) {
            return $fields;
        }
        foreach ($this->records($order) as $index => $record) {
            $fields['stackless_consent_' . $index] = [
                'label' => wp_strip_all_tags($record['label']),
                'value' => $this->answerText($record),
            ];
        }
        return $fields;
    }

    public function answerText(array $record): string
    {
        $time = strtotime((string) $record['at'] . ' UTC');
        $when = $time ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time) : '';
        $answer = !empty($record['accepted']) ? __('Accepted', 'stackless-lite') : __('Not accepted', 'stackless-lite');
        /* translators: 1: Accepted or Not accepted, 2: date and time, 3: wording version */
        return sprintf(__('%1$s on %2$s (wording v%3$d)', 'stackless-lite'), $answer, (string) $when, (int) $record['version']);
    }

    /* ---------------------------------------------------------------------
     * Shorter checkout for virtual-only, free and digital carts, and renamed fields.
     * ------------------------------------------------------------------ */

    /**
     * Where the address fields are being built or checked: 'classic' (checkout page render or its
     * AJAX), 'block' (Checkout block page render or a Store API request), or '' anywhere else,
     * including My Account, order-pay and the Store API pay-for-order route.
     */
    private function checkoutContext(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing checks.
        $ajax = isset($_GET['wc-ajax']) ? sanitize_key(wp_unslash($_GET['wc-ajax'])) : '';
        if (in_array($ajax, ['update_order_review', 'checkout'], true)) {
            return 'classic';
        }
        $route = isset($_GET['rest_route']) ? sanitize_text_field(wp_unslash($_GET['rest_route'])) : '';
        // phpcs:enable
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $path = $route . ' ' . $uri;
        if (strpos($path, '/wc/store/') !== false) {
            // Paying for an existing order checks that order's own address, not the cart's.
            return preg_match('#/wc/store/(v\d+/)?checkout/\d+#', $path) ? '' : 'block';
        }
        if (!did_action('wp') || !function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
            return '';
        }
        return $this->isBlockCheckout() ? 'block' : 'classic';
    }

    private function onCheckoutForm(): bool
    {
        return $this->checkoutContext() !== '';
    }

    private function cartReady(): bool
    {
        return function_exists('WC') && WC()->cart instanceof \WC_Cart && did_action('wp_loaded');
    }

    /** Cart facts plus what the cart is worth. Read fresh: Store API requests change the cart mid-request. */
    private function valueFacts(): array
    {
        return array_merge(CartFacts::fromCart(), CartFacts::valueFromCart());
    }

    /**
     * What the shorter checkout does for the current cart.
     *
     * @return array{free_digital: bool, zero_value: bool, zero_total: bool, hide_country: bool, country: string, fields: string[]}
     */
    private function lean(?array $facts = null): array
    {
        $facts = $facts ?? $this->valueFacts();
        $settings = Settings::lean();
        $free = LeanRules::freeDigital($facts, $settings['free']);
        $hideCountry = LeanRules::hideCountry($facts, $settings['free'], $settings['country'], function_exists('wc_tax_enabled') && wc_tax_enabled());
        $country = $hideCountry ? $this->hiddenCountry() : '';
        return [
            'free_digital' => $free,
            'zero_value' => LeanRules::zeroValue($facts),
            'zero_total' => LeanRules::zeroTotal($facts),
            'hide_country' => $hideCountry && $country !== '',
            'country' => $country,
            'fields' => LeanRules::hiddenFields($free, $hideCountry, $country !== ''),
        ];
    }

    /** @return string[] */
    private function allowedCountries(): array
    {
        return function_exists('WC') && WC()->countries instanceof \WC_Countries ? array_map('strval', array_keys(WC()->countries->get_allowed_countries())) : [];
    }

    /**
     * The country saved on an order whose country field is hidden. Order: a country the buyer
     * entered on this checkout, a signed-in customer's profile country, Cloudflare's visitor
     * country (XX and T1 ignored), WooCommerce's own session value, then the store base country.
     * WooCommerce cannot tell a country a guest typed from its "Default customer location" seed,
     * so a guest's session value counts as that default and ranks after Cloudflare; a signed-in
     * customer's session value is loaded from their profile and ranks with it. Only countries the
     * store sells to count.
     *
     * @param string $entered A country posted from this checkout, when there is one.
     */
    private function hiddenCountry(string $entered = ''): string
    {
        $signedIn = is_user_logged_in();
        $country = LeanRules::pickCountry(LeanRules::countryCandidates([
            'entered' => $entered,
            'profile' => $signedIn ? (string) get_user_meta(get_current_user_id(), 'billing_country', true) : '',
            'session' => WC()->customer instanceof \WC_Customer ? WC()->customer->get_billing_country() : '',
            'cloudflare' => isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])) : '',
            'base' => WC()->countries instanceof \WC_Countries ? WC()->countries->get_base_country() : '',
        ], $signedIn), $this->allowedCountries());
        /**
         * Filters the country saved on an order whose country field the shorter checkout hides.
         * Return '' to show the country field instead.
         *
         * @param string        $country Two-letter code, or '' when none is known.
         * @param \WC_Cart|null $cart
         */
        return LeanRules::pickCountry([(string) apply_filters('stackless_checkout_enhancements_hidden_country', $country, WC()->cart)], $this->allowedCountries());
    }

    /**
     * Core address fields to drop for the current cart, in a checkout context.
     *
     * @return string[]
     */
    private function hiddenFields(?array $facts = null): array
    {
        if (!$this->onCheckoutForm() || !$this->cartReady()) {
            return [];
        }
        $facts = $facts ?? $this->valueFacts();
        $hide = Settings::virtualHide() && CartFacts::virtualOnly($facts) ? Settings::virtualHide() : [];
        if (Settings::leanEnabled()) {
            $hide = array_merge($hide, $this->lean($facts)['fields']);
        }
        return array_values(array_unique($hide));
    }

    /**
     * Fields the address locale marks hidden. The country never goes in: WooCommerce (11.2+) forces
     * every locale's country back to visible and required, since it is the key the locale is looked
     * up by, and on the classic checkout its address script would empty the hidden country input.
     * The Checkout block hides the country with the stackless-ce-hide-country body class instead,
     * while the field keeps the worked-out country as its value.
     *
     * @return string[]
     */
    private function localeHiddenFields(): array
    {
        return array_values(array_diff($this->hiddenFields(), ['country']));
    }

    public function resetLocale(): void
    {
        if ((Settings::virtualHide() || Settings::renamedFields() || Settings::leanEnabled()) && WC()->countries instanceof \WC_Countries) {
            WC()->countries->locale = [];
        }
    }

    public function classicCheckoutFields($fields)
    {
        if (!is_array($fields)) {
            return $fields;
        }
        $hide = $this->hiddenFields();
        foreach ($hide as $key) {
            if ($key === 'country') {
                continue;
            }
            unset($fields['billing']['billing_' . $key], $fields['shipping']['shipping_' . $key]);
        }
        if (in_array('country', $hide, true) && isset($fields['billing']['billing_country'])) {
            // A hidden input keeps #billing_country, so WooCommerce's checkout script still sends the
            // country with every order review refresh, and the order is saved with it.
            $lean = $this->lean();
            $fields['billing']['billing_country'] = [
                'type' => 'hidden',
                'label' => '',
                'required' => false,
                'default' => $lean['country'],
                'priority' => $fields['billing']['billing_country']['priority'] ?? 40,
                'stackless_lean_country' => $lean['country'],
            ];
            unset($fields['shipping']['shipping_country']);
        }
        foreach (Settings::renamedFields() as $key => $label) {
            foreach (['billing', 'shipping'] as $group) {
                if (isset($fields[$group][$group . '_' . $key]) && empty($fields[$group][$group . '_' . $key]['stackless_lean_country'])) {
                    $fields[$group][$group . '_' . $key]['label'] = $label;
                }
            }
        }
        return $fields;
    }

    /**
     * The hidden country prints as a bare input: no label and no #billing_country_field row,
     * which the address script would otherwise show or empty when the country changes.
     */
    public function hiddenCountryInput($field, $key, $args, $value)
    {
        if (!is_array($args) || empty($args['stackless_lean_country'])) {
            return $field;
        }
        // The field's own value is WooCommerce's session country, which for guests is only the
        // default location; the country worked out in hiddenCountry() already ranks it.
        $country = LeanRules::pickCountry([(string) $args['stackless_lean_country']], $this->allowedCountries());
        return '<input type="hidden" class="input-hidden country_to_state" name="' . esc_attr((string) $key) . '" id="' . esc_attr((string) $key) . '" value="' . esc_attr($country) . '" data-stackless-lean-country />';
    }

    /** Classic checkout: the order always gets a country, even when the field was hidden or removed by site code. */
    public function postedCountry($data)
    {
        if (!is_array($data) || !Settings::leanEnabled() || !$this->cartReady()) {
            return $data;
        }
        $lean = $this->lean();
        if (!$lean['hide_country']) {
            return $data;
        }
        // The hidden input carries the country this checkout showed, so a valid posted one ranks first.
        $posted = isset($data['billing_country']) && is_scalar($data['billing_country']) ? (string) $data['billing_country'] : '';
        $data['billing_country'] = LeanRules::pickCountry([$this->hiddenCountry($posted)], $this->allowedCountries());
        if ($data['billing_country'] === '') {
            $data['billing_country'] = $lean['country'];
        }
        if ($data['billing_country'] !== strtoupper($posted)) {
            $data['billing_state'] = '';
        }
        return $data;
    }

    /**
     * Checkout block: the hidden country is sent empty or as WooCommerce's session value, never as
     * something the buyer chose, so the customer gets the worked-out country before WooCommerce
     * copies the address to the order and checks it against the allowed countries.
     */
    public function fillCustomerCountry($customer): void
    {
        if (!$customer instanceof \WC_Customer || !Settings::leanEnabled() || !$this->cartReady()) {
            return;
        }
        $lean = $this->lean();
        if ($lean['hide_country'] && $customer->get_billing_country() !== $lean['country']) {
            $customer->set_billing_country($lean['country']);
            $customer->set_billing_state('');
        }
    }

    /** Belt and braces for the block checkout: a draft order never keeps an empty billing country. */
    public function fillOrderCountry($order): void
    {
        if (!$order instanceof \WC_Order || $order->get_billing_country() !== '' || !Settings::leanEnabled() || !$this->cartReady()) {
            return;
        }
        $lean = $this->lean();
        if ($lean['hide_country']) {
            $order->set_billing_country($lean['country']);
        }
    }

    /**
     * Checkout block page: the hidden country field shows the customer's country, and the form looks
     * up which fields to hide by it, so the customer gets the worked-out country before the block renders.
     */
    public function primeCountry(): void
    {
        if (!Settings::leanEnabled() || $this->checkoutContext() !== 'block' || !$this->cartReady() || !WC()->customer instanceof \WC_Customer) {
            return;
        }
        $this->fillCustomerCountry(WC()->customer);
    }

    /** Zero-value carts hide the coupon form on the checkout page (classic form and Checkout block). */
    public function couponsEnabled($enabled)
    {
        if (!$enabled || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !Settings::lean()['coupons'] || !$this->cartReady()) {
            return $enabled;
        }
        if (!did_action('wp') || !function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
            return $enabled;
        }
        return LeanRules::zeroValue($this->valueFacts()) ? false : $enabled;
    }

    /** Body classes the checkout stylesheet uses: the currency selector on free digital carts, the country on the Checkout block. */
    public function bodyClass($classes)
    {
        if (!is_array($classes) || !Settings::leanEnabled() || !$this->onCheckoutPage() || is_wc_endpoint_url() || !$this->cartReady()) {
            return $classes;
        }
        $lean = $this->lean();
        if ($lean['free_digital']) {
            $classes[] = 'stackless-ce-free-digital';
        }
        // Checkout block only: the classic checkout already prints the country as a hidden input.
        if ($lean['hide_country'] && $this->isBlockCheckout()) {
            $classes[] = 'stackless-ce-hide-country';
        }
        return $classes;
    }

    public function defaultAddressFields($fields)
    {
        if (!is_array($fields)) {
            return $fields;
        }
        foreach (Settings::renamedFields() as $key => $label) {
            if (isset($fields[$key])) {
                $fields[$key]['label'] = $label;
            }
        }
        return $fields;
    }

    /** @param string[] $hidden */
    private function applyLocale(array $entry, array $hidden): array
    {
        foreach (Settings::renamedFields() as $key => $label) {
            if ($key !== 'email') {
                $entry[$key] = array_merge(isset($entry[$key]) && is_array($entry[$key]) ? $entry[$key] : [], ['label' => $label]);
            }
        }
        foreach ($hidden as $key) {
            $entry[$key] = array_merge(isset($entry[$key]) && is_array($entry[$key]) ? $entry[$key] : [], ['required' => false, 'hidden' => true]);
        }
        return $entry;
    }

    public function defaultLocale($locale)
    {
        return is_array($locale) ? $this->applyLocale($locale, $this->localeHiddenFields()) : $locale;
    }

    /** Every country gets the override, including countries WooCommerce has no locale entry for. */
    public function countryLocale($locale)
    {
        if (!is_array($locale)) {
            return $locale;
        }
        $hidden = $this->localeHiddenFields();
        if (!Settings::renamedFields() && !$hidden) {
            return $locale;
        }
        $countries = array_keys(array_merge(WC()->countries->get_allowed_countries(), WC()->countries->get_shipping_countries()));
        foreach (array_unique(array_merge(array_keys($locale), $countries)) as $code) {
            $locale[$code] = $this->applyLocale(isset($locale[$code]) && is_array($locale[$code]) ? $locale[$code] : [], $hidden);
        }
        return $locale;
    }

    /** Classic checkout: the free-order wording when there is nothing to pay, otherwise the merchant's own label, if any. */
    public function buttonText($text)
    {
        $free = Settings::freeButtonText();
        $zero = $free !== '' && $this->onCheckoutForm() && $this->cartReady() && LeanRules::zeroTotal($this->valueFacts());
        return LeanRules::buttonText(is_string($text) ? $text : '', Settings::buttonText(), $free, $zero);
    }

    /** Checkout headings and the block checkout's email label are WooCommerce strings, renamed on the checkout page only. */
    public function maybeTranslateCheckout(): void
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url()) {
            return;
        }
        // The address locale may have been built before the cart and query were known.
        $this->resetLocale();
        $map = Settings::textMap();
        $fields = Settings::renamedFields();
        if (isset($fields['email'])) {
            $map['Email address'] = $fields['email'];
        }
        if (!$map) {
            return;
        }
        add_filter('gettext_woocommerce', static function ($translation, $text) use ($map) {
            return $map[$text] ?? $translation;
        }, 20, 2);
    }

    /* ---------------------------------------------------------------------
     * Assets and the trust block.
     * ------------------------------------------------------------------ */

    private function onCheckoutPage(): bool
    {
        return function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received');
    }

    private function isBlockCheckout(): bool
    {
        return function_exists('has_block') && has_block('woocommerce/checkout');
    }

    public function assets(): void
    {
        if (!$this->onCheckoutPage()) {
            return;
        }
        $url = STACKLESS_PLUGIN_URL . 'assets/checkout_enhancements/';
        wp_enqueue_style(self::HANDLE, $url . 'checkout.css', [], STACKLESS_VERSION);
        wp_enqueue_script(self::HANDLE, $url . 'checkout.js', [], STACKLESS_VERSION, true);
        wp_localize_script(self::HANDLE, 'stacklessCheckoutEnhancements', [
            'required' => __('Please tick this box to continue.', 'stackless-lite'),
        ]);

        // Block checkout strings are evaluated when its script loads, so the i18n filter must exist first.
        $map = Settings::textMap();
        if ($map && $this->isBlockCheckout()) {
            wp_enqueue_script(self::HANDLE . '-text', $url . 'text.js', ['wp-hooks', 'wp-i18n'], STACKLESS_VERSION, false);
            wp_add_inline_script(self::HANDLE . '-text', 'window.stacklessCheckoutText = ' . wp_json_encode($map) . ';', 'before');
        }
    }

    private function trustHtml(): string
    {
        $trust = Settings::trust();
        if (!$trust['enabled'] || ($trust['heading'] === '' && !$trust['lines'] && !$trust['image_id'] && !$trust['policy_page'])) {
            return '';
        }
        ob_start();
        stackless_get_template('checkout_enhancements/trust.php', ['trust' => $trust]);
        return (string) ob_get_clean();
    }

    public function renderClassicTrust(): void
    {
        echo $this->trustHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
    }

    /** The block checkout is drawn in the browser, so the trust block waits in a template and checkout.js places it under payment. */
    public function trustTemplate(): void
    {
        if (!$this->onCheckoutPage() || !$this->isBlockCheckout()) {
            return;
        }
        $html = $this->trustHtml();
        if ($html !== '') {
            echo '<template id="stackless-ce-trust-template">' . $html . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
        }
    }
}
