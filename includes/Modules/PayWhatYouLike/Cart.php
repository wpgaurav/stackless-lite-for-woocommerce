<?php

namespace Stackless\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Chosen amounts in the cart.
 *
 * A line carries cart item data stackless_pay_what_you_like = {amount, currency}: the amount the
 * buyer chose, as a decimal string in the currency it was chosen in. The line's unit price is set
 * on the server from that value and the product's current limits on every totals calculation,
 * never from a client-sent price. Without a chosen amount the line costs the default (suggested
 * amount or the product's price).
 *
 * Multi-currency: limits are worked out in the buyer's currency (the product's own displayed price
 * as the minimum, the merchant's base-currency amounts converted). The price given to set_price() is
 * the chosen amount turned back into the base currency at the same effective rate, unrounded, so
 * MultiCurrency's single runtime conversion lands exactly on the amount the buyer chose. Switching
 * currency puts the line back on the new currency's default.
 */
final class Cart
{
    /** Cart item data key, and the request field for amounts keyed by cart item key. */
    public const KEY = 'stackless_pay_what_you_like';
    /** Amount field on the product page (and Store API add-to-cart param). */
    public const PRODUCT_FIELD = 'stackless_pay_what_you_like_amount';
    /** Which product the posted product-page amount belongs to. */
    public const PRODUCT_MARKER = 'stackless_pay_what_you_like_product';
    public const ORDER_META = '_stackless_pay_what_you_like';
    public const NOTED_META = '_stackless_pay_what_you_like_noted';
    /** ?stackless_price=25 on a cart, checkout or buy link sets the amount once, inside the limits. */
    public const LINK_PARAM = 'stackless_price';
    /** Order bump lines are priced by the bump; they never take a chosen amount. */
    private const BUMP_KEY = 'stackless_bump_id';

    /** @var ProductSettings */
    private $settings;

    /** @var array<string, array<string, mixed>|null> */
    private $boundsMemo = [];

    /** @var bool */
    private $linkApplied = false;

    public function __construct(ProductSettings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        // Adding to the cart: classic form, Store API, order again, programmatic.
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAdd'], 20, 6);
        add_filter('woocommerce_add_cart_item_data', [$this, 'cartItemData'], 20, 4);
        add_filter('woocommerce_store_api_add_to_cart_data', [$this, 'storeApiAddData'], 10, 2);

        // Prices: before Fields & Paid Add-ons (30), which adds its charges on top of the price it finds.
        add_action('woocommerce_before_calculate_totals', [$this, 'applyPrices'], 20);
        // Mini carts can print line prices before totals run on a request.
        add_action('woocommerce_cart_loaded_from_session', [$this, 'applyPrices'], 20);
        add_action('woocommerce_check_cart_items', [$this, 'checkCartItems'], 20);

        // Changing amounts: classic cart, classic checkout (refresh and Place order).
        add_filter('woocommerce_update_cart_action_cart_updated', [$this, 'classicCartUpdate'], 20);
        add_action('woocommerce_checkout_update_order_review', [$this, 'orderReviewUpdate'], 20);
        add_action('woocommerce_checkout_process', [$this, 'checkoutProcess'], 20);

        // Orders.
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'orderLineItem'], 20, 4);
        add_action('woocommerce_checkout_order_created', [$this, 'noteOrder'], 20);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'noteOrder'], 20);
        add_filter('woocommerce_order_again_cart_item_data', [$this, 'orderAgainData'], 20, 3);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hiddenItemMeta']);

        // Links.
        add_action('stackless_buy_links_processed', [$this, 'buyLinkProcessed'], 20, 2);
        add_action('template_redirect', [$this, 'linkAmount'], 20);
    }

    /* ---------------------------------------------------------------------
     * Limits
     * ------------------------------------------------------------------- */

    /**
     * Limits for a product in the buyer's currency, or null when it has a fixed price.
     *
     * @return array{min: int, max: int, default: int, presets: int[], decimals: int, currency: string, custom_min: bool}|null
     */
    public function bounds(\WC_Product $product): ?array
    {
        $settings = $this->settings->settingsFor($product);
        if (!$settings) {
            return null;
        }
        $currency = stackless_current_currency();
        $memo = $product->get_id() . '|' . $currency;
        if (array_key_exists($memo, $this->boundsMemo)) {
            return $this->boundsMemo[$memo];
        }
        $decimals = max(0, (int) wc_get_price_decimals());
        // The saved price in the buyer's currency (price book or conversion), not a runtime set_price().
        $fresh = $product->get_id() ? wc_get_product($product->get_id()) : $product;
        $price = $fresh instanceof \WC_Product ? $fresh->get_price() : '';
        $priceMinor = is_numeric($price) ? Amounts::toMinor((string) wc_format_decimal((string) $price), $decimals) : null;
        if ($priceMinor === null) {
            return $this->boundsMemo[$memo] = null;
        }
        $presets = [];
        foreach ($settings['presets'] as $preset) {
            $minor = $this->converted($preset, $decimals);
            if ($minor !== null) {
                $presets[] = $minor;
            }
        }
        $bounds = Amounts::bounds(
            $priceMinor,
            $settings['min'] !== '' ? $this->converted($settings['min'], $decimals) : null,
            $settings['suggested'] !== '' ? $this->converted($settings['suggested'], $decimals) : null,
            $settings['max'] !== '' ? $this->converted($settings['max'], $decimals) : null,
            $presets,
            Amounts::hardMax($decimals)
        );
        return $this->boundsMemo[$memo] = $bounds + ['decimals' => $decimals, 'currency' => $currency, 'custom_min' => $settings['min'] !== ''];
    }

    /** A base-currency setting in minor units of the buyer's currency. */
    private function converted(string $base, int $decimals): ?int
    {
        $value = stackless_convert_amount($base);
        return Amounts::toMinor(is_numeric($value) ? (string) wc_format_decimal((string) $value) : $base, $decimals);
    }

    public function flush(): void
    {
        $this->boundsMemo = [];
        $this->settings->flush();
    }

    /**
     * State of one cart line, or null when it does not take a chosen amount.
     *
     * amount: the chosen amount (or the default when none was chosen), priced: the amount used for
     * the price (the chosen amount kept inside the current limits), chosen: whether the buyer chose,
     * error: "too_low"/"too_high" when a chosen amount no longer fits the limits.
     *
     * @param array<string, mixed> $item
     * @return array{amount: int, priced: int, chosen: bool, error: string, min: int, max: int, default: int, presets: int[], decimals: int, currency: string, custom_min: bool}|null
     */
    public function lineState(array $item): ?array
    {
        if (!empty($item[self::BUMP_KEY]) || !isset($item['data']) || !$item['data'] instanceof \WC_Product) {
            return null;
        }
        $bounds = $this->bounds($item['data']);
        if (!$bounds) {
            return null;
        }
        $stored = $item[self::KEY] ?? null;
        $chosen = null;
        if (is_array($stored) && ($stored['currency'] ?? '') === $bounds['currency'] && isset($stored['amount']) && is_scalar($stored['amount'])) {
            $chosen = Amounts::toMinor((string) $stored['amount'], $bounds['decimals']);
        }
        $amount = $chosen === null ? $bounds['default'] : $chosen;
        return [
            'amount' => $amount,
            'priced' => Amounts::clamp($amount, $bounds),
            'chosen' => $chosen !== null,
            'error' => $chosen === null ? '' : Amounts::check($chosen, $bounds),
        ] + $bounds;
    }

    /** Plain-text price ("€12.50") for labels, notices and the Store API. */
    public static function label(int $minor, int $decimals): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) Amounts::fromMinor($minor, $decimals), ['decimals' => $decimals])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array{min: int, max: int, decimals: int} $state
     */
    public static function boundsMessage(string $code, array $state, string $name): string
    {
        if ($code === 'too_low') {
            /* translators: 1: minimum price, 2: product name */
            return sprintf(__('Enter at least %1$s for %2$s.', 'stackless-lite'), self::label($state['min'], $state['decimals']), $name);
        }
        /* translators: 1: maximum price, 2: product name */
        return sprintf(__('Enter at most %1$s for %2$s.', 'stackless-lite'), self::label($state['max'], $state['decimals']), $name);
    }

    public static function invalidMessage(string $name): string
    {
        /* translators: %s: product name */
        return sprintf(__('Enter an amount such as 12.50 for %s, without a currency symbol.', 'stackless-lite'), $name);
    }

    /**
     * Help text under an amount field.
     *
     * @param array{min: int, max: int, decimals: int} $state
     */
    public static function helpText(array $state): string
    {
        $hasMax = $state['max'] < Amounts::hardMax($state['decimals']);
        if ($state['min'] <= 0) {
            return $hasMax
                /* translators: %s: maximum price */
                ? sprintf(__('Enter 0 to get it free, or any amount up to %s.', 'stackless-lite'), self::label($state['max'], $state['decimals']))
                : __('Enter 0 to get it free, or any amount you like.', 'stackless-lite');
        }
        return $hasMax
            /* translators: 1: minimum price, 2: maximum price */
            ? sprintf(__('Minimum %1$s, maximum %2$s', 'stackless-lite'), self::label($state['min'], $state['decimals']), self::label($state['max'], $state['decimals']))
            /* translators: %s: minimum price */
            : sprintf(__('Minimum %s', 'stackless-lite'), self::label($state['min'], $state['decimals']));
    }

    /** Effective rate from the base currency to $currency (1 for the base currency). */
    private function rate(string $currency): float
    {
        if ($currency === stackless_base_currency()) {
            return 1.0;
        }
        return (float) stackless_currency_rate($currency);
    }

    /* ---------------------------------------------------------------------
     * Adding to the cart
     * ------------------------------------------------------------------- */

    /**
     * Amount requested for a product being added: cart item data first (Store API, order again,
     * code), then the classic product form when it belongs to this product. Null when none.
     *
     * @param array<string, mixed>|null $data
     */
    private function requestedAmount(int $productId, ?array $data): ?string
    {
        if (is_array($data) && array_key_exists(self::KEY, $data)) {
            $value = is_array($data[self::KEY]) ? ($data[self::KEY]['amount'] ?? null) : $data[self::KEY];
            return is_scalar($value) && trim((string) $value) !== '' ? sanitize_text_field((string) $value) : null;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart has no nonce; the value is validated.
        if (!isset($_POST[self::PRODUCT_MARKER], $_POST[self::PRODUCT_FIELD]) || absint($_POST[self::PRODUCT_MARKER]) !== $productId || !is_scalar($_POST[self::PRODUCT_FIELD])) {
            return null;
        }
        $value = sanitize_text_field(wp_unslash((string) $_POST[self::PRODUCT_FIELD]));
        // phpcs:enable
        return $value;
    }

    /**
     * @param bool $passed
     * @param int|string $product_id
     * @param int|string $quantity
     * @param int|string $variation_id
     * @param array<string, mixed> $variations
     * @param array<string, mixed>|null $cart_item_data present for Store API and order again
     */
    public function validateAdd($passed, $product_id = 0, $quantity = 1, $variation_id = 0, $variations = [], $cart_item_data = null): bool
    {
        if (!$passed) {
            return false;
        }
        $product = wc_get_product((int) $variation_id ?: (int) $product_id);
        $bounds = $product instanceof \WC_Product ? $this->bounds($product) : null;
        if (!$product instanceof \WC_Product || !$bounds) {
            return true;
        }
        $raw = $this->requestedAmount((int) $product_id, is_array($cart_item_data) ? $cart_item_data : null);
        if ($raw === null) {
            // A product-page form that was left empty.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of the posted form.
            if (!is_array($cart_item_data) && isset($_POST[self::PRODUCT_MARKER]) && absint($_POST[self::PRODUCT_MARKER]) === (int) $product_id) {
                wc_add_notice(self::invalidMessage($product->get_name()), 'error');
                return false;
            }
            return true;
        }
        $parsed = Amounts::parse($raw, $bounds['decimals'], wc_get_price_decimal_separator());
        if ($parsed === null) {
            wc_add_notice(self::invalidMessage($product->get_name()), 'error');
            return false;
        }
        $error = Amounts::check((int) Amounts::toMinor($parsed, $bounds['decimals']), $bounds);
        if ($error !== '') {
            wc_add_notice(self::boundsMessage($error, $bounds, $product->get_name()), 'error');
            return false;
        }
        return true;
    }

    /**
     * Keeps only a readable amount and stamps it with the buyer's currency. Out-of-range amounts
     * that skipped validation (code paths that call add_to_cart() directly) are priced inside the
     * limits and stop checkout until corrected.
     *
     * @param array<string, mixed> $data
     * @param int|string $product_id
     * @param int|string $variation_id
     * @param int|string $quantity
     * @return array<string, mixed>
     */
    public function cartItemData($data, $product_id, $variation_id = 0, $quantity = 1): array
    {
        $data = (array) $data;
        $raw = $this->requestedAmount((int) $product_id, $data);
        unset($data[self::KEY]);
        $product = wc_get_product((int) $variation_id ?: (int) $product_id);
        $bounds = $product instanceof \WC_Product ? $this->bounds($product) : null;
        if (!$bounds || $raw === null) {
            return $data;
        }
        $parsed = Amounts::parse($raw, $bounds['decimals'], wc_get_price_decimal_separator());
        if ($parsed !== null) {
            // Part of the cart item hash: the same product at different amounts makes separate lines.
            $data[self::KEY] = ['amount' => $parsed, 'currency' => $bounds['currency']];
        }
        return $data;
    }

    /**
     * Store API add item: accept {"stackless_pay_what_you_like_amount": "12.50"} alongside the core params.
     *
     * @param array<string, mixed> $data
     * @param \WP_REST_Request<array<string, mixed>>|mixed $request
     * @return array<string, mixed>
     */
    public function storeApiAddData($data, $request): array
    {
        $data = (array) $data;
        if ($request instanceof \WP_REST_Request) {
            $raw = $request->get_param(self::PRODUCT_FIELD);
            if (is_scalar($raw) && trim((string) $raw) !== '') {
                $data['cart_item_data'] = (array) ($data['cart_item_data'] ?? []);
                $data['cart_item_data'][self::KEY] = ['amount' => sanitize_text_field((string) $raw)];
            }
        }
        return $data;
    }

    /* ---------------------------------------------------------------------
     * Prices
     * ------------------------------------------------------------------- */

    /** @param \WC_Cart|mixed $cart */
    public function applyPrices($cart): void
    {
        if (!$cart instanceof \WC_Cart || (is_admin() && !wp_doing_ajax())) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            $state = $this->lineState((array) $item);
            if (!$state) {
                continue;
            }
            // Base-currency runtime price; MultiCurrency converts it once (see Amounts::toBase()).
            $item['data']->set_price(Amounts::toBase(Amounts::fromMinor($state['priced'], $state['decimals']), $this->rate($state['currency'])));
        }
    }

    public function checkCartItems(): void
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart instanceof \WC_Cart) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            $state = $this->lineState((array) $item);
            if ($state && $state['error'] !== '') {
                wc_add_notice(self::boundsMessage($state['error'], $state, $item['data']->get_name()), 'error');
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Changing amounts
     * ------------------------------------------------------------------- */

    /**
     * Set the chosen amount of one cart line from typed text. Returns '' on success or a message
     * for the buyer. The caller recalculates totals.
     *
     * @param mixed $raw
     */
    public function setAmount(string $key, $raw, bool $clampToLimits = false): string
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        $item = $cart instanceof \WC_Cart ? $cart->get_cart_item($key) : [];
        if (!$cart instanceof \WC_Cart || !$item) {
            return __('That cart item no longer exists.', 'stackless-lite');
        }
        $state = $this->lineState($item);
        $name = $item['data'] instanceof \WC_Product ? $item['data']->get_name() : __('this product', 'stackless-lite');
        if (!$state) {
            /* translators: %s: product name */
            return sprintf(__('%s has a fixed price.', 'stackless-lite'), $name);
        }
        $parsed = is_scalar($raw) ? Amounts::parse((string) $raw, $state['decimals'], $clampToLimits ? '.' : wc_get_price_decimal_separator()) : null;
        if ($parsed === null) {
            return self::invalidMessage($name);
        }
        $minor = (int) Amounts::toMinor($parsed, $state['decimals']);
        if ($clampToLimits) {
            $minor = Amounts::clamp($minor, $state);
        }
        $error = Amounts::check($minor, $state);
        if ($error !== '') {
            return self::boundsMessage($error, $state, $name);
        }
        if ($minor === $state['amount'] && $state['error'] === '') {
            return '';
        }
        $contents = $cart->get_cart_contents();
        $contents[$key][self::KEY] = ['amount' => Amounts::fromMinor($minor, $state['decimals']), 'currency' => $state['currency']];
        // A fresh product object, so modules that remember the price they found on the old one start again.
        $fresh = wc_get_product(!empty($item['variation_id']) ? (int) $item['variation_id'] : (int) $item['product_id']);
        if ($fresh instanceof \WC_Product) {
            $contents[$key]['data'] = $fresh;
        }
        $cart->set_cart_contents($contents);
        return '';
    }

    /**
     * Apply amounts posted as stackless_pay_what_you_like[cart item key] = "12.50". Problems
     * become error notices. True when an amount changed.
     *
     * @param mixed $posted
     */
    public function applyPosted($posted): bool
    {
        if (!is_array($posted)) {
            return false;
        }
        $cart = function_exists('WC') ? WC()->cart : null;
        if (!$cart instanceof \WC_Cart) {
            return false;
        }
        $changed = false;
        foreach ($posted as $key => $raw) {
            $key = is_string($key) ? sanitize_key($key) : '';
            if ($key === '' || !is_scalar($raw)) {
                continue;
            }
            $before = $cart->get_cart_item($key)[self::KEY] ?? null;
            $message = $this->setAmount($key, sanitize_text_field((string) $raw));
            if ($message !== '') {
                wc_add_notice($message, 'error');
                continue;
            }
            if (($cart->get_cart_item($key)[self::KEY] ?? null) !== $before) {
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Classic cart "Update cart".
     *
     * @param mixed $updated
     */
    public function classicCartUpdate($updated): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification -- checked here as well as by WooCommerce.
        $nonce = isset($_REQUEST['woocommerce-cart-nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['woocommerce-cart-nonce'])) : '';
        if (!isset($_POST[self::KEY]) || !wp_verify_nonce($nonce, 'woocommerce-cart')) {
            return (bool) $updated;
        }
        $posted = map_deep(wp_unslash($_POST[self::KEY]), 'sanitize_text_field');
        // phpcs:enable
        return $this->applyPosted($posted) || (bool) $updated;
    }

    /**
     * Classic checkout refresh: the serialized form arrives as post_data (nonce checked by WooCommerce).
     *
     * @param mixed $postData
     */
    public function orderReviewUpdate($postData): void
    {
        if (!is_string($postData) || $postData === '' || strpos($postData, self::KEY) === false) {
            return;
        }
        $fields = [];
        parse_str($postData, $fields);
        if (isset($fields[self::KEY])) {
            $this->applyPosted($fields[self::KEY]);
        }
    }

    /** Classic Place order: amounts typed without the script running arrive with the form. */
    public function checkoutProcess(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        if (isset($_POST[self::KEY])) {
            $this->applyPosted(wp_unslash($_POST[self::KEY])); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value in applyPosted().
        }
    }

    /* ---------------------------------------------------------------------
     * Links
     * ------------------------------------------------------------------- */

    /**
     * @param mixed $added
     * @param mixed $params
     */
    public function buyLinkProcessed($added, $params = []): void
    {
        if ((int) $added > 0) {
            $this->applyLinkAmount();
        }
    }

    public function linkAmount(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link; the amount is kept inside the product's limits.
        if (!isset($_GET[self::LINK_PARAM]) || is_admin() || !(is_cart() || is_checkout())) {
            return;
        }
        $this->applyLinkAmount();
        wp_safe_redirect(remove_query_arg(self::LINK_PARAM));
        exit;
    }

    /** One amount fits one product: it goes to the first line that takes a chosen amount. */
    private function applyLinkAmount(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link; the amount is kept inside the product's limits.
        $raw = isset($_GET[self::LINK_PARAM]) && is_scalar($_GET[self::LINK_PARAM]) ? sanitize_text_field(wp_unslash((string) $_GET[self::LINK_PARAM])) : '';
        $cart = function_exists('WC') ? WC()->cart : null;
        if ($this->linkApplied || $raw === '' || !$cart instanceof \WC_Cart) {
            return;
        }
        $this->linkApplied = true;
        foreach ($cart->get_cart() as $key => $item) {
            if ($this->lineState((array) $item)) {
                if ($this->setAmount((string) $key, $raw, true) === '') {
                    $cart->calculate_totals();
                }
                return;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Orders
     * ------------------------------------------------------------------- */

    /**
     * @param \WC_Order_Item_Product|mixed $item
     * @param string $key
     * @param array<string, mixed> $values
     * @param \WC_Order|mixed $order
     */
    public function orderLineItem($item, $key, $values, $order): void
    {
        if (!$item instanceof \WC_Order_Item_Product) {
            return;
        }
        $state = $this->lineState((array) $values);
        if (!$state) {
            return;
        }
        $item->update_meta_data(self::ORDER_META, [
            'amount' => Amounts::fromMinor($state['priced'], $state['decimals']),
            'minimum' => Amounts::fromMinor($state['min'], $state['decimals']),
            'currency' => $state['currency'],
            'chosen' => $state['chosen'] ? 'yes' : 'no',
        ]);
    }

    /** One order note listing each chosen amount and its minimum. @param \WC_Order|mixed $order */
    public function noteOrder($order): void
    {
        if (!$order instanceof \WC_Order || $order->get_meta(self::NOTED_META)) {
            return;
        }
        $lines = [];
        foreach ($order->get_items() as $item) {
            $meta = $item->get_meta(self::ORDER_META, true);
            if (!is_array($meta) || !isset($meta['amount'], $meta['minimum'])) {
                continue;
            }
            $args = ['currency' => (string) ($meta['currency'] ?? $order->get_currency())];
            $lines[] = sprintf(
                /* translators: 1: product name, 2: amount paid per item, 3: minimum price */
                __('%1$s: %2$s (minimum %3$s)', 'stackless-lite'),
                $item->get_name(),
                html_entity_decode(wp_strip_all_tags(wc_price((float) $meta['amount'], $args)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                html_entity_decode(wp_strip_all_tags(wc_price((float) $meta['minimum'], $args)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }
        if (!$lines) {
            return;
        }
        $order->add_order_note(__('Pay what you like', 'stackless-lite') . "\n" . implode("\n", $lines));
        $order->update_meta_data(self::NOTED_META, '1');
        $order->save();
    }

    /**
     * Order again keeps the amount when the visitor is shopping in the same currency.
     *
     * @param array<string, mixed> $data
     * @param \WC_Order_Item_Product|mixed $item
     * @return array<string, mixed>
     */
    public function orderAgainData($data, $item, $order = null): array
    {
        $data = (array) $data;
        if (!$item instanceof \WC_Order_Item_Product) {
            return $data;
        }
        $meta = $item->get_meta(self::ORDER_META, true);
        if (is_array($meta) && ($meta['chosen'] ?? '') === 'yes' && ($meta['currency'] ?? '') === stackless_current_currency() && isset($meta['amount'])) {
            $data[self::KEY] = ['amount' => (string) $meta['amount']];
        }
        return $data;
    }

    /** @param string[] $keys */
    public function hiddenItemMeta($keys): array
    {
        $keys = (array) $keys;
        $keys[] = self::ORDER_META;
        return $keys;
    }

    /* ---------------------------------------------------------------------
     * Store API
     * ------------------------------------------------------------------- */

    /**
     * extensions["stackless-pay-what-you-like"] on each cart item. Amounts are decimal strings
     * with "." in the cart currency.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function storeApiItemData($item): array
    {
        $item = (array) $item;
        $state = $this->lineState($item);
        if (!$state) {
            return ['enabled' => false];
        }
        $d = $state['decimals'];
        $name = $item['data']->get_name();
        return [
            'enabled' => true,
            'amount' => Amounts::fromMinor($state['amount'], $d),
            'chosen' => $state['chosen'],
            'min' => Amounts::fromMinor($state['min'], $d),
            'max' => Amounts::fromMinor($state['max'], $d),
            'default' => Amounts::fromMinor($state['default'], $d),
            'min_label' => self::label($state['min'], $d),
            'max_label' => self::label($state['max'], $d),
            'presets' => array_map(static function ($preset) use ($d) {
                return ['amount' => Amounts::fromMinor($preset, $d), 'label' => self::label($preset, $d)];
            }, $state['presets']),
            'help' => self::helpText($state),
            'currency' => $state['currency'],
            'symbol' => html_entity_decode(get_woocommerce_currency_symbol($state['currency']), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'decimals' => $d,
            'error' => $state['error'] !== '' ? self::boundsMessage($state['error'], $state, $name) : '',
        ];
    }

    /** @return array<string, mixed> */
    public static function storeApiItemSchema(): array
    {
        $string = ['type' => 'string', 'context' => ['view', 'edit'], 'readonly' => true];
        return [
            'enabled' => ['description' => __('Whether the buyer chooses what to pay for this item.', 'stackless-lite'), 'type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'amount' => ['description' => __('Amount per item, chosen or default, in the cart currency.', 'stackless-lite')] + $string,
            'chosen' => ['type' => 'boolean', 'context' => ['view', 'edit'], 'readonly' => true],
            'min' => $string,
            'max' => $string,
            'default' => $string,
            'min_label' => $string,
            'max_label' => $string,
            'presets' => [
                'type' => 'array',
                'context' => ['view', 'edit'],
                'readonly' => true,
                'items' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'string'], 'label' => ['type' => 'string']]],
            ],
            'help' => $string,
            'currency' => $string,
            'symbol' => $string,
            'decimals' => ['type' => 'integer', 'context' => ['view', 'edit'], 'readonly' => true],
            'error' => $string,
        ];
    }
}
