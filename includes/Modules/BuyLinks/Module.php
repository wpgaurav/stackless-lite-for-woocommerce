<?php

namespace Stackless\Modules\BuyLinks;

defined('ABSPATH') || exit;

/**
 * Buy Links: shareable direct-checkout URLs (?stackless-buy=…), an optional "Buy now" button,
 * signed links that cannot be edited, and a link generator on the product screen.
 * Links add normal cart items, so subscription products and every checkout type just work.
 */
final class Module extends \Stackless\Core\Module
{
    public const META_BUY_NOW = '_stackless_buy_links_buy_now';
    public const BUY_NOW_PARAM = 'stackless-buy-now';
    public const HANDLE = 'stackless-buy_links';

    public function id(): string
    {
        return 'buy_links';
    }

    public function title(): string
    {
        return __('Buy Links', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Shareable links that fill the cart and open checkout, with coupons, multiple products and tamper-proof signatures, plus an optional Buy now button.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'sales';
    }

    public function install(): void
    {
        Links::secret();
    }

    public function settingsFields(): array
    {
        $example = home_url('/?' . Links::PARAM . '=123');
        return [
            [
                'title' => __('Buy links', 'stackless-lite'),
                'type' => 'title',
                'id' => 'stackless_buy_links_section',
                'desc' => sprintf(
                    /* translators: 1: example URL, 2: example parameters */
                    __('Any page URL with %1$s adds that product and opens checkout. Use product:variation:quantity for more control, commas for several products, and %2$s to apply a coupon. Generate ready links from the Buy link box on each product.', 'stackless-lite'),
                    '<code>' . esc_html($example) . '</code>',
                    '<code>&amp;coupon=CODE</code>'
                ),
            ],
            ['title' => __('Empty the cart first', 'stackless-lite'), 'desc' => __('Replace the cart with the link\'s products (a link can override this with clear=0 or clear=1)', 'stackless-lite'), 'type' => 'checkbox', 'id' => 'stackless_buy_links_clear', 'default' => 'yes'],
            ['title' => __('Send customers to', 'stackless-lite'), 'type' => 'select', 'id' => 'stackless_buy_links_redirect', 'default' => 'checkout', 'options' => ['checkout' => __('Checkout', 'stackless-lite'), 'cart' => __('Cart', 'stackless-lite')]],
            ['title' => __('Signed links', 'stackless-lite'), 'type' => 'select', 'id' => 'stackless_buy_links_signing', 'default' => 'off', 'options' => [
                'off' => __('Optional: unsigned links work, signed links are checked', 'stackless-lite'),
                'required' => __('Required: only signed links work, so nobody can change the quantity or coupon', 'stackless-lite'),
            ], 'desc_tip' => __('A signature covers the products, quantities, coupons, cart option, destination and expiry date.', 'stackless-lite')],
            ['title' => __('Reset signing key', 'stackless-lite'), 'desc' => __('Make a new key when you save. Every signed link made so far stops working.', 'stackless-lite'), 'type' => 'checkbox', 'id' => 'stackless_buy_links_rotate', 'default' => 'no'],
            ['type' => 'sectionend', 'id' => 'stackless_buy_links_section'],

            ['title' => __('Buy now button', 'stackless-lite'), 'type' => 'title', 'id' => 'stackless_buy_links_buy_now_section', 'desc' => __('A second button beside Add to cart that goes straight to checkout. Each product can override this in its Buy link box.', 'stackless-lite')],
            ['title' => __('Show Buy now', 'stackless-lite'), 'desc' => __('On product pages', 'stackless-lite'), 'type' => 'checkbox', 'id' => 'stackless_buy_links_buy_now', 'default' => 'no'],
            ['title' => __('In shop grids', 'stackless-lite'), 'desc' => __('Also on simple products in shop and category grids', 'stackless-lite'), 'type' => 'checkbox', 'id' => 'stackless_buy_links_buy_now_loops', 'default' => 'no'],
            ['title' => __('Button text', 'stackless-lite'), 'type' => 'text', 'id' => 'stackless_buy_links_buy_now_text', 'default' => __('Buy now', 'stackless-lite'), 'custom_attributes' => ['maxlength' => 40]],
            ['title' => __('Buy only this product', 'stackless-lite'), 'desc' => __('Buy now checks out just this product and leaves nothing else in the cart', 'stackless-lite'), 'type' => 'checkbox', 'id' => 'stackless_buy_links_buy_now_clear', 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => 'stackless_buy_links_buy_now_section'],
        ];
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
            add_action('stackless_settings_saved_buy_links', [$this, 'maybeRotate']);
        }
        add_action('wp_loaded', [$this, 'handleLink'], 30);
        add_action('woocommerce_after_add_to_cart_button', [$this, 'singleButton'], 20);
        add_action('woocommerce_add_to_cart', [$this, 'buyNowOnly'], 20, 4);
        add_filter('woocommerce_add_to_cart_redirect', [$this, 'buyNowRedirect'], 20, 2);
        add_action('woocommerce_after_shop_loop_item', [$this, 'loopButton'], 15);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function maybeRotate(): void
    {
        if (get_option('stackless_buy_links_rotate') === 'yes') {
            Links::rotate();
            update_option('stackless_buy_links_rotate', 'no');
            $this->log('Signing key reset from settings by user #' . get_current_user_id() . '.');
        }
    }

    /* ---------------------------------------------------------------------
     * Link handler.
     * ------------------------------------------------------------------ */

    public function handleLink(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public links; tampering is stopped by the optional HMAC signature.
        if (!isset($_GET[Links::PARAM]) || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!function_exists('WC') || !WC()->cart || !WC()->session) {
            return;
        }
        $raw = sanitize_text_field(wp_unslash((string) $_GET[Links::PARAM]));
        $couponRaw = isset($_GET['coupon']) ? sanitize_text_field(wp_unslash((string) $_GET['coupon'])) : '';
        $clearParam = isset($_GET['clear']) ? sanitize_key(wp_unslash((string) $_GET['clear'])) : '';
        $to = isset($_GET['to']) ? sanitize_key(wp_unslash((string) $_GET['to'])) : '';
        $exp = isset($_GET['exp']) ? absint(wp_unslash($_GET['exp'])) : 0;
        $sig = isset($_GET['sig']) ? strtolower(sanitize_key(wp_unslash((string) $_GET['sig']))) : '';
        $attributes = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && strpos($key, 'attribute_') === 0 && is_scalar($value)) {
                $attributes[sanitize_title(wp_unslash($key))] = sanitize_text_field(wp_unslash((string) $value));
            }
        }
        $utm = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && strpos($key, 'utm_') === 0 && is_scalar($value)) {
                $utm[sanitize_key($key)] = rawurlencode(sanitize_text_field(wp_unslash((string) $value)));
            }
        }
        // phpcs:enable

        wc_nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        $params = ['items' => $raw, 'coupon' => $couponRaw, 'clear' => in_array($clearParam, ['0', '1'], true) ? $clearParam : '', 'to' => in_array($to, ['checkout', 'cart'], true) ? $to : '', 'exp' => $exp];
        if ($sig !== '' || Links::signingRequired()) {
            if (!LinkParser::verify($params, $sig, Links::secret())) {
                $this->fail(__('This buy link is not valid. Please ask the store for a new one.', 'stackless-lite'), 'signature');
                return;
            }
            if (LinkParser::expired($exp, time())) {
                $this->fail(__('This buy link has expired. Please ask the store for a new one.', 'stackless-lite'), 'expired');
                return;
            }
        }

        $parsed = LinkParser::parse($raw);
        if (!$parsed['items']) {
            $this->fail(__('This buy link does not include any products.', 'stackless-lite'), 'empty');
            return;
        }

        $session = WC()->session;
        if ($session instanceof \WC_Session_Handler && !$session->has_session()) {
            $session->set_customer_session_cookie(true);
        }
        $clear = $params['clear'] !== '' ? $params['clear'] === '1' : get_option('stackless_buy_links_clear', 'yes') === 'yes';
        if ($clear) {
            WC()->cart->empty_cart();
        }

        $added = 0;
        foreach ($parsed['items'] as $item) {
            $resolved = LinkParser::resolve($item, static function ($id) {
                $product = wc_get_product($id);
                return $product instanceof \WC_Product && $product->is_type('variable');
            });
            if ($this->addItem($resolved, $attributes)) {
                $added++;
            }
        }
        if ($parsed['errors']) {
            wc_add_notice(__('Some products in this link could not be read and were skipped.', 'stackless-lite'), 'notice');
        }

        if ($added) {
            foreach (LinkParser::coupons($couponRaw) as $code) {
                $code = wc_format_coupon_code($code);
                if (!WC()->cart->has_discount($code)) {
                    WC()->cart->apply_coupon($code);
                }
            }
        }

        /**
         * Fires after a buy link filled the cart, before the redirect.
         *
         * @param int   $added  Number of products added.
         * @param array $params items, coupon, clear, to, exp
         */
        do_action('stackless_buy_links_processed', $added, $params);

        $destination = $params['to'] !== '' ? $params['to'] : (string) get_option('stackless_buy_links_redirect', 'checkout');
        $url = $added && $destination !== 'cart' && !WC()->cart->is_empty() ? wc_get_checkout_url() : wc_get_cart_url();
        if ($utm) {
            $url = add_query_arg($utm, $url);
        }
        wp_safe_redirect(apply_filters('stackless_buy_links_redirect', $url, $added, $params));
        exit;
    }

    private function fail(string $message, string $reason): void
    {
        wc_add_notice($message, 'error');
        $this->log('Buy link rejected: ' . $reason . '.', 'notice');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    /** @param array{product: int, variation: int, qty: int} $item */
    private function addItem(array $item, array $requested): bool
    {
        $product = wc_get_product($item['variation'] ?: $item['product']);
        if (!$product instanceof \WC_Product || $product->get_status() === 'trash' || $product->is_type(['grouped', 'external'])) {
            /* translators: %d: product ID */
            wc_add_notice(sprintf(__('Product #%d from this link is not available.', 'stackless-lite'), $item['variation'] ?: $item['product']), 'error');
            return false;
        }
        if ($product->is_type('variable')) {
            /* translators: %s: product name */
            wc_add_notice(sprintf(__('Choose the options for "%s" to add it to your cart.', 'stackless-lite'), $product->get_name()), 'notice');
            return false;
        }
        $productId = $product->get_id();
        $variationId = 0;
        $variation = [];
        if ($product instanceof \WC_Product_Variation) {
            $productId = $product->get_parent_id();
            $variationId = $product->get_id();
            if ($item['variation'] && $item['product'] !== $productId && $item['product'] !== $variationId) {
                /* translators: %d: product ID */
                wc_add_notice(sprintf(__('Product #%d from this link is not available.', 'stackless-lite'), $item['variation']), 'error');
                return false;
            }
            foreach ($product->get_variation_attributes() as $name => $value) {
                $variation[$name] = $value !== '' ? $value : ($requested[$name] ?? '');
                if ($variation[$name] === '') {
                    /* translators: %s: product name */
                    wc_add_notice(sprintf(__('Choose the options for "%s" to add it to your cart.', 'stackless-lite'), $product->get_name()), 'notice');
                    return false;
                }
            }
        }
        $qty = $product->is_sold_individually() ? 1 : $item['qty'];
        if (!apply_filters('woocommerce_add_to_cart_validation', true, $productId, $qty, $variationId, $variation)) {
            return false;
        }
        try {
            return (bool) WC()->cart->add_to_cart($productId, $qty, $variationId, $variation);
        } catch (\Exception $e) {
            if ($e->getMessage() !== '') {
                wc_add_notice($e->getMessage(), 'error');
            }
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * Buy now button.
     * ------------------------------------------------------------------ */

    private function buyNowEnabled(\WC_Product $product): bool
    {
        $override = $product->get_meta(self::META_BUY_NOW, true);
        if ($override === 'yes' || $override === 'no') {
            return $override === 'yes';
        }
        return get_option('stackless_buy_links_buy_now', 'no') === 'yes';
    }

    private function buttonText(): string
    {
        $text = get_option('stackless_buy_links_buy_now_text', '');
        return is_string($text) && trim($text) !== '' ? sanitize_text_field($text) : __('Buy now', 'stackless-lite');
    }

    public function singleButton(): void
    {
        global $product;
        if (!$product instanceof \WC_Product || !$this->buyNowEnabled($product) || !$product->is_purchasable() || !$product->is_in_stock()) {
            return;
        }
        $type = $product->get_type();
        if (!in_array($type, ['simple', 'variable'], true) && !apply_filters('stackless_buy_links_buy_now_supports', false, $product)) {
            return;
        }
        $action = add_query_arg(self::BUY_NOW_PARAM, '1', apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- core hook.
        $classes = ['button', 'alt', 'stackless-buy-now'];
        if (function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button')) {
            $classes[] = wc_wp_theme_get_element_class_name('button');
        }
        if ($type === 'variable') {
            // WooCommerce's variation script enables every .single_add_to_cart_button once a variation is chosen.
            $classes = array_merge($classes, ['single_add_to_cart_button', 'disabled', 'wc-variation-selection-needed']);
            printf('<button type="submit" class="%1$s" formaction="%2$s">%3$s</button>', esc_attr(implode(' ', $classes)), esc_url($action), esc_html($this->buttonText()));
            return;
        }
        printf('<button type="submit" name="add-to-cart" value="%1$d" class="%2$s" formaction="%3$s">%4$s</button>', (int) $product->get_id(), esc_attr(implode(' ', $classes)), esc_url($action), esc_html($this->buttonText()));
    }

    private function isBuyNowRequest(): bool
    {
        return !empty($_GET[self::BUY_NOW_PARAM]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same trust level as WooCommerce's add-to-cart form.
    }

    /** Buy now checks out exactly what was chosen. */
    public function buyNowOnly($key, $productId, $quantity, $variationId): void
    {
        static $running = false;
        if ($running || !$this->isBuyNowRequest() || get_option('stackless_buy_links_buy_now_clear', 'yes') !== 'yes' || !WC()->cart) {
            return;
        }
        $running = true;
        foreach (array_keys(WC()->cart->get_cart()) as $other) {
            if ($other !== $key) {
                WC()->cart->remove_cart_item($other);
            }
        }
        $line = WC()->cart->get_cart_item($key);
        if ($line && (float) $line['quantity'] !== (float) $quantity) {
            WC()->cart->set_quantity($key, (int) $quantity, false);
        }
        $running = false;
    }

    public function buyNowRedirect($url, $product = null)
    {
        return $this->isBuyNowRequest() ? wc_get_checkout_url() : $url;
    }

    public function loopButton(): void
    {
        global $product;
        if (get_option('stackless_buy_links_buy_now_loops', 'no') !== 'yes' || !$product instanceof \WC_Product || !$product->is_type('simple') || !$this->buyNowEnabled($product) || !$product->is_purchasable() || !$product->is_in_stock()) {
            return;
        }
        $clear = get_option('stackless_buy_links_buy_now_clear', 'yes') === 'yes' ? '1' : '0';
        $url = Links::url([['product' => $product->get_id(), 'variation' => 0, 'qty' => 1]], ['clear' => $clear, 'to' => 'checkout']);
        printf(
            '<a href="%1$s" class="button stackless-buy-now stackless-buy-now--loop" rel="nofollow">%2$s<span class="screen-reader-text">: %3$s</span></a>',
            esc_url($url),
            esc_html($this->buttonText()),
            esc_html($product->get_name())
        );
    }

    public function assets(): void
    {
        $loops = get_option('stackless_buy_links_buy_now_loops', 'no') === 'yes' && (is_shop() || is_product_taxonomy());
        if (is_product() || $loops) {
            wp_enqueue_style(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/buy_links/buy-now.css', [], STACKLESS_VERSION);
        }
    }
}
