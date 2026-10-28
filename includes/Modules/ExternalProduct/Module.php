<?php

namespace Stackless\Modules\ExternalProduct;

defined('ABSPATH') || exit;

/**
 * External Product extras on top of WooCommerce's External/Affiliate product type:
 * rel (nofollow, sponsored, noopener) and target controls, optional price hiding,
 * outbound click counts, and a hard server-side guard that keeps external products
 * out of the cart (add-to-cart links, AJAX, forms and the Store API).
 */
final class Module extends \Stackless\Core\Module
{
    public const META_NOFOLLOW = '_stackless_external_product_nofollow';
    public const META_SPONSORED = '_stackless_external_product_sponsored';
    public const META_TARGET = '_stackless_external_product_target';
    public const META_HIDE_PRICE = '_stackless_external_product_hide_price';
    public const FIELD_RESET = 'stackless_external_product_reset_clicks';
    private const HANDLE = 'stackless-external_product';
    private const COLUMN = 'stackless_external_clicks';

    /** @var bool */
    private $scriptQueued = false;

    public function id(): string
    {
        return 'external_product';
    }

    public function title(): string
    {
        return __('External Product Extras', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Control rel and target on External/Affiliate product links, hide their prices, count outbound clicks, and make sure they can never be added to the cart.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function install(): void
    {
        Clicks::install();
    }

    public function settingsFields(): array
    {
        $p = 'stackless_external_product_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('External product links', 'stackless-lite'), 'desc' => __('Store defaults for every External/Affiliate product. Each product can override them under Product data → General.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'nofollow', 'title' => __('Link attributes', 'stackless-lite'), 'desc' => __('Add rel="nofollow"', 'stackless-lite'), 'default' => 'yes', 'checkboxgroup' => 'start'],
            ['type' => 'checkbox', 'id' => $p . 'sponsored', 'desc' => __('Add rel="sponsored" (recommended for paid and affiliate links)', 'stackless-lite'), 'default' => 'yes', 'checkboxgroup' => 'end'],
            ['type' => 'select', 'id' => $p . 'target', 'title' => __('Open links', 'stackless-lite'), 'default' => '_blank', 'class' => 'wc-enhanced-select', 'options' => [
                '_blank' => __('In a new tab (adds rel="noopener")', 'stackless-lite'),
                '_self' => __('In the same tab', 'stackless-lite'),
            ]],
            ['type' => 'checkbox', 'id' => $p . 'hide_price', 'title' => __('Prices', 'stackless-lite'), 'desc' => __('Hide prices on external products (useful when the seller\'s price changes often)', 'stackless-lite'), 'default' => 'no'],
            ['type' => 'checkbox', 'id' => $p . 'tracking', 'title' => __('Click tracking', 'stackless-lite'), 'desc' => __('Count clicks on external product buttons and show them in the product list', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        // Cart guard.
        add_filter('woocommerce_is_purchasable', [$this, 'filterPurchasable'], PHP_INT_MAX, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAddToCart'], 1, 4);
        add_action('woocommerce_store_api_validate_add_to_cart', [$this, 'storeApiValidate'], 1, 1);
        add_action('woocommerce_store_api_validate_cart_item', [$this, 'storeApiValidate'], 1, 1);
        add_action('woocommerce_check_cart_items', [$this, 'removeFromCart'], 1);

        // Links.
        add_filter('woocommerce_loop_add_to_cart_args', [$this, 'filterLoopArgs'], 30, 2);
        add_action('init', [$this, 'replaceSingleButton'], 20);

        // Prices.
        add_filter('woocommerce_get_price_html', [$this, 'filterPriceHtml'], 100, 2);
        add_filter('woocommerce_structured_data_product', [$this, 'filterStructuredData'], 100, 2);

        // Tracking.
        if ($this->trackingEnabled()) {
            add_action('rest_api_init', [$this, 'registerRoutes']);
            add_filter('manage_edit-product_columns', [$this, 'addColumn'], 20);
            add_action('manage_product_posts_custom_column', [$this, 'renderColumn'], 10, 2);
            add_action('admin_head-edit.php', [$this, 'columnStyle']);
        }

        // Product editor.
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderProductFields'], 20);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductFields']);
    }

    private function trackingEnabled(): bool
    {
        return $this->option('tracking', 'yes') === 'yes';
    }

    private function isExternal($product): bool
    {
        return $product instanceof \WC_Product && $product->is_type('external');
    }

    /* ---------------------------------------------------------------------
     * Resolved per-product settings
     * ------------------------------------------------------------------- */

    public function target(\WC_Product $product): string
    {
        return Link::target((string) $product->get_meta(self::META_TARGET), (string) $this->option('target', '_blank'));
    }

    public function rel(\WC_Product $product): string
    {
        return Link::rel(
            Link::flag((string) $product->get_meta(self::META_NOFOLLOW), $this->option('nofollow', 'yes') === 'yes'),
            Link::flag((string) $product->get_meta(self::META_SPONSORED), $this->option('sponsored', 'yes') === 'yes'),
            $this->target($product)
        );
    }

    public function hidesPrice(\WC_Product $product): bool
    {
        return Link::flag((string) $product->get_meta(self::META_HIDE_PRICE), $this->option('hide_price', 'no') === 'yes');
    }

    /* ---------------------------------------------------------------------
     * Cart guard
     * ------------------------------------------------------------------- */

    /**
     * WooCommerce already returns false for external products; this keeps it false even if
     * another plugin flips the filter.
     *
     * @param bool        $purchasable
     * @param \WC_Product $product
     * @return bool
     */
    public function filterPurchasable($purchasable, $product)
    {
        return $this->isExternal($product) ? false : $purchasable;
    }

    /**
     * @param bool $passed
     * @param int  $productId
     * @param int  $quantity
     * @param int  $variationId
     * @return bool
     */
    public function validateAddToCart($passed, $productId, $quantity = 1, $variationId = 0)
    {
        if (!$this->isExternal(wc_get_product((int) $productId))) {
            return $passed;
        }
        wc_add_notice(esc_html__('This product is sold on another website and cannot be added to the cart.', 'stackless-lite'), 'error');
        return false;
    }

    /** @param \WC_Product $product */
    public function storeApiValidate($product): void
    {
        if (!$this->isExternal($product)) {
            return;
        }
        $message = __('This product is sold on another website and cannot be added to the cart.', 'stackless-lite');
        if (class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('stackless_external_product_not_purchasable', esc_html($message), 400);
        }
        throw new \Exception(esc_html($message));
    }

    public function removeFromCart(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($this->isExternal($item['data'] ?? null)) {
                WC()->cart->remove_cart_item($key);
                wc_add_notice(esc_html__('An external product was removed from your cart. It can only be bought on the seller\'s website.', 'stackless-lite'), 'notice');
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Links
     * ------------------------------------------------------------------- */

    /**
     * Classic loop buttons and the Product Button block both pass through this filter.
     *
     * @param array       $args
     * @param \WC_Product $product
     * @return array
     */
    public function filterLoopArgs($args, $product)
    {
        if (!is_array($args) || !$this->isExternal($product)) {
            return $args;
        }
        $attributes = isset($args['attributes']) && is_array($args['attributes']) ? $args['attributes'] : [];
        $rel = $this->rel($product);
        if ($rel !== '') {
            $attributes['rel'] = $rel;
        } else {
            unset($attributes['rel']);
        }
        $attributes['target'] = $this->target($product);
        if ($this->trackingEnabled()) {
            $attributes['data-stackless-external'] = (string) $product->get_id();
            $this->queueScript();
        }
        $args['attributes'] = $attributes;
        return $args;
    }

    /** Swap WooCommerce's GET form for a real link, so rel and target apply on product pages. */
    public function replaceSingleButton(): void
    {
        if (remove_action('woocommerce_external_add_to_cart', 'woocommerce_external_add_to_cart', 30)) {
            add_action('woocommerce_external_add_to_cart', [$this, 'renderSingleButton'], 30);
        }
    }

    public function renderSingleButton(): void
    {
        global $product;
        if (!$product instanceof \WC_Product_External) {
            return;
        }
        $url = (string) $product->add_to_cart_url();
        if (!Link::validUrl($url)) {
            return;
        }
        $rel = $this->rel($product);
        $tracking = $this->trackingEnabled();
        if ($tracking) {
            $this->queueScript();
        }
        $themeClass = function_exists('wc_wp_theme_get_element_class_name') ? (string) wc_wp_theme_get_element_class_name('button') : '';

        do_action('woocommerce_before_add_to_cart_form'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired the way WooCommerce does.
        echo '<div class="cart stackless-external-product">';
        do_action('woocommerce_before_add_to_cart_button'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired the way WooCommerce does.
        printf(
            '<a href="%1$s" class="single_add_to_cart_button button alt%2$s" target="%3$s"%4$s%5$s>%6$s</a>',
            esc_url($url),
            esc_attr($themeClass !== '' ? ' ' . $themeClass : ''),
            esc_attr($this->target($product)),
            $rel !== '' ? ' rel="' . esc_attr($rel) . '"' : '',
            $tracking ? ' data-stackless-external="' . esc_attr((string) $product->get_id()) . '"' : '',
            esc_html($product->single_add_to_cart_text())
        );
        do_action('woocommerce_after_add_to_cart_button'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired the way WooCommerce does.
        echo '</div>';
        do_action('woocommerce_after_add_to_cart_form'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired the way WooCommerce does.
    }

    private function queueScript(): void
    {
        if ($this->scriptQueued) {
            return;
        }
        $this->scriptQueued = true;
        wp_enqueue_script(self::HANDLE . '-tracking', STACKLESS_PLUGIN_URL . 'assets/external_product/tracking.js', [], STACKLESS_VERSION, true);
        wp_add_inline_script(self::HANDLE . '-tracking', 'window.stacklessExternalProduct=' . wp_json_encode(['endpoint' => esc_url_raw(rest_url('stackless/v1/external-click/'))]) . ';', 'before');
    }

    /* ---------------------------------------------------------------------
     * Prices
     * ------------------------------------------------------------------- */

    /**
     * @param string      $html
     * @param \WC_Product $product
     * @return string
     */
    public function filterPriceHtml($html, $product)
    {
        return $this->isExternal($product) && $this->hidesPrice($product) ? '' : $html;
    }

    /**
     * @param array       $markup
     * @param \WC_Product $product
     * @return array
     */
    public function filterStructuredData($markup, $product)
    {
        if (is_array($markup) && $this->isExternal($product) && $this->hidesPrice($product)) {
            unset($markup['offers']);
        }
        return $markup;
    }

    /* ---------------------------------------------------------------------
     * Click tracking
     * ------------------------------------------------------------------- */

    public function registerRoutes(): void
    {
        register_rest_route('stackless/v1', '/external-click/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'restClick'],
            'permission_callback' => [$this, 'restClickPermission'],
            'args' => [
                'id' => ['required' => true, 'validate_callback' => static function ($value) {
                    return is_numeric($value) && (int) $value > 0;
                }],
            ],
        ]);
    }

    /**
     * Only published external products with tracking on can be counted.
     *
     * @return bool|\WP_Error
     */
    public function restClickPermission(\WP_REST_Request $request)
    {
        $product = wc_get_product((int) $request->get_param('id'));
        if (!$this->isExternal($product) || !$product instanceof \WC_Product || $product->get_status() !== 'publish') {
            return new \WP_Error('stackless_external_product_not_found', __('Product not found.', 'stackless-lite'), ['status' => 404]);
        }
        return true;
    }

    public function restClick(\WP_REST_Request $request): \WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        // One click per visitor per product every 10 minutes keeps refreshes and bots from inflating counts.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
        $key = 'stackless_xclk_' . substr(hash_hmac('sha256', $ip . '|' . $productId, wp_salt('nonce')), 0, 32);
        if (get_transient($key)) {
            return new \WP_REST_Response(['counted' => false], 200);
        }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        Clicks::increment($productId);
        return new \WP_REST_Response(['counted' => true], 200);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function addColumn($columns)
    {
        if (!is_array($columns)) {
            return $columns;
        }
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'price') {
                $out[self::COLUMN] = __('Outbound clicks', 'stackless-lite');
            }
        }
        if (!isset($out[self::COLUMN])) {
            $out[self::COLUMN] = __('Outbound clicks', 'stackless-lite');
        }
        return $out;
    }

    /** WooCommerce lays the products table out with fixed widths; without one, this column collapses to nothing. */
    public function columnStyle(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'edit-product') {
            echo '<style>.wp-list-table .column-' . esc_attr(self::COLUMN) . '{width:7em}</style>';
        }
    }

    /**
     * @param string $column
     * @param int    $postId
     */
    public function renderColumn($column, $postId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        static $counts = null;
        if ($counts === null) {
            global $wp_query;
            $ids = [];
            if ($wp_query instanceof \WP_Query) {
                foreach ((array) $wp_query->posts as $post) {
                    $ids[] = is_object($post) ? (int) $post->ID : (int) $post;
                }
            }
            $counts = Clicks::many($ids);
        }
        $product = wc_get_product((int) $postId);
        if (!$this->isExternal($product)) {
            echo '<span aria-hidden="true">&ndash;</span>';
            return;
        }
        echo esc_html(number_format_i18n($counts[(int) $postId] ?? 0));
    }

    /* ---------------------------------------------------------------------
     * Product editor
     * ------------------------------------------------------------------- */

    public function renderProductFields(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : null;
        $inherit = __('Store default', 'stackless-lite');
        echo '<div class="options_group show_if_external stackless-external-product-fields">';
        woocommerce_wp_select([
            'id' => self::META_NOFOLLOW,
            'value' => $product ? (string) $product->get_meta(self::META_NOFOLLOW) : '',
            'label' => __('rel="nofollow"', 'stackless-lite'),
            'options' => ['' => $inherit, 'yes' => __('Add', 'stackless-lite'), 'no' => __('Do not add', 'stackless-lite')],
        ]);
        woocommerce_wp_select([
            'id' => self::META_SPONSORED,
            'value' => $product ? (string) $product->get_meta(self::META_SPONSORED) : '',
            'label' => __('rel="sponsored"', 'stackless-lite'),
            'options' => ['' => $inherit, 'yes' => __('Add', 'stackless-lite'), 'no' => __('Do not add', 'stackless-lite')],
        ]);
        woocommerce_wp_select([
            'id' => self::META_TARGET,
            'value' => $product ? (string) $product->get_meta(self::META_TARGET) : '',
            'label' => __('Open link', 'stackless-lite'),
            'options' => ['' => $inherit, '_blank' => __('In a new tab', 'stackless-lite'), '_self' => __('In the same tab', 'stackless-lite')],
        ]);
        woocommerce_wp_select([
            'id' => self::META_HIDE_PRICE,
            'value' => $product ? (string) $product->get_meta(self::META_HIDE_PRICE) : '',
            'label' => __('Price display', 'stackless-lite'),
            'options' => ['' => $inherit, 'yes' => __('Hide price', 'stackless-lite'), 'no' => __('Show price', 'stackless-lite')],
        ]);
        if ($this->trackingEnabled() && $product && $product->get_id()) {
            $stats = Clicks::get($product->get_id());
            $last = $stats['last'] !== '' ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($stats['last'] . ' UTC')) : '';
            echo '<p class="form-field"><label>' . esc_html__('Outbound clicks', 'stackless-lite') . '</label><strong>' . esc_html(number_format_i18n($stats['clicks'])) . '</strong>';
            if ($last) {
                /* translators: %s: date and time of the last click */
                echo ' <span class="description">' . esc_html(sprintf(__('Last click %s', 'stackless-lite'), $last)) . '</span>';
            }
            echo '</p>';
            if ($stats['clicks'] > 0) {
                woocommerce_wp_checkbox([
                    'id' => self::FIELD_RESET,
                    'value' => 'no',
                    'cbvalue' => 'yes',
                    'label' => __('Reset clicks', 'stackless-lite'),
                    'description' => __('Set the click count back to zero when you update the product.', 'stackless-lite'),
                ]);
            }
        }
        echo '</div>';
    }

    public function saveProductFields(\WC_Product $product): void
    {
        // Nonce and capability are checked by WooCommerce before this action runs.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $allowed = [
            self::META_NOFOLLOW => ['yes', 'no'],
            self::META_SPONSORED => ['yes', 'no'],
            self::META_TARGET => ['_blank', '_self'],
            self::META_HIDE_PRICE => ['yes', 'no'],
        ];
        foreach ($allowed as $key => $values) {
            $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
            if (in_array($value, $values, true)) {
                $product->update_meta_data($key, $value);
            } else {
                $product->delete_meta_data($key);
            }
        }
        if (!empty($_POST[self::FIELD_RESET]) && $product->get_id() && current_user_can('edit_product', $product->get_id())) {
            Clicks::reset($product->get_id());
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }
}
