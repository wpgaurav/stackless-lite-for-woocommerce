<?php

namespace Stackless\Modules\FreePriceText;

defined('ABSPATH') || exit;

/**
 * Free Price Text: shows custom text ("Free") wherever a zero price would print.
 *
 * Covers product loops, single product pages, variation prices, product blocks (they render
 * get_price_html()), the classic cart, mini-cart and checkout review, order line items, and
 * the Cart/Checkout/Mini-Cart blocks through a Store API flag plus a cart item class.
 */
final class Module extends \Stackless\Core\Module
{
    public const META_LABEL = '_stackless_free_price_text_label';
    public const STORE_NAMESPACE = 'stackless_free_price_text';
    private const HANDLE = 'stackless-free_price_text';

    /** @var bool */
    private $blockAssetsQueued = false;

    public function id(): string
    {
        return 'free_price_text';
    }

    public function title(): string
    {
        return __('Free Price Text', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Show custom text such as "Free" instead of a zero price on product pages, shop grids, the cart and checkout.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_free_price_text_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Free price text', 'stackless-lite'), 'desc' => __('Products with a price of exactly zero show this text instead. Products with no price at all are left alone, because WooCommerce cannot sell them.', 'stackless-lite')],
            ['type' => 'text', 'id' => $p . 'text', 'title' => __('Text', 'stackless-lite'), 'default' => __('Free', 'stackless-lite'), 'desc_tip' => __('Shown in place of the zero price. Each product can override it under Product data → General.', 'stackless-lite'), 'css' => 'min-width:260px'],
            ['type' => 'checkbox', 'id' => $p . 'ranges', 'title' => __('Price ranges', 'stackless-lite'), 'desc' => __('Use the text at the low end of a range, for example "Free – $19"', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'show_regular', 'title' => __('Free on sale', 'stackless-lite'), 'desc' => __('Keep the regular price struck through when a sale makes the product free', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'cart', 'title' => __('Cart and checkout', 'stackless-lite'), 'desc' => __('Also show the text for free items in the cart, mini-cart, checkout and order details', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_get_price_html', [$this, 'filterPriceHtml'], 50, 2);

        if ($this->option('cart', 'yes') === 'yes') {
            add_filter('woocommerce_cart_item_price', [$this, 'filterCartItemPrice'], 50, 2);
            add_filter('woocommerce_cart_item_subtotal', [$this, 'filterCartItemSubtotal'], 50, 2);
            add_filter('woocommerce_order_formatted_line_subtotal', [$this, 'filterOrderLineSubtotal'], 50, 2);

            if (did_action('woocommerce_blocks_loaded')) {
                $this->registerStoreApiData();
            } else {
                add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApiData']);
            }
            add_filter('render_block_woocommerce/cart', [$this, 'queueBlockAssets']);
            add_filter('render_block_woocommerce/checkout', [$this, 'queueBlockAssets']);
            add_filter('render_block_woocommerce/mini-cart', [$this, 'queueBlockAssets']);
        }

        add_action('woocommerce_product_options_general_product_data', [$this, 'renderProductField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductField']);
    }

    /* ---------------------------------------------------------------------
     * Text
     * ------------------------------------------------------------------- */

    public function text(?\WC_Product $product = null): string
    {
        $text = '';
        if ($product) {
            $owner = $product->is_type('variation') && $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
            if ($owner instanceof \WC_Product) {
                $text = trim((string) $owner->get_meta(self::META_LABEL));
            }
        }
        if ($text === '') {
            $text = trim((string) $this->option('text', __('Free', 'stackless-lite')));
        }
        if ($text === '') {
            $text = __('Free', 'stackless-lite');
        }
        /**
         * Filters the text shown for a zero price.
         *
         * @param string           $text
         * @param \WC_Product|null $product
         */
        return (string) apply_filters('stackless_free_price_text_text', $text, $product);
    }

    public function html(?\WC_Product $product = null): string
    {
        return '<span class="stackless-free-price">' . esc_html($this->text($product)) . '</span>';
    }

    /* ---------------------------------------------------------------------
     * Product prices
     * ------------------------------------------------------------------- */

    /**
     * @param string      $html
     * @param \WC_Product $product
     * @return string
     */
    public function filterPriceHtml($html, $product)
    {
        if (!$product instanceof \WC_Product || stackless_is_subscription_product($product)) {
            return $html;
        }

        if ($product->is_type('variable') && $product instanceof \WC_Product_Variable) {
            $prices = $product->get_variation_prices(true);
            return $this->rangeHtml((array) ($prices['price'] ?? []), (string) $html, $product);
        }

        if ($product->is_type('grouped')) {
            $prices = [];
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product($childId);
                if ($child instanceof \WC_Product && $child->is_visible() && $child->get_price() !== '') {
                    $prices[] = wc_get_price_to_display($child);
                }
            }
            return $this->rangeHtml($prices, (string) $html, $product);
        }

        $price = $product->get_price();
        if (!Price::isZero($price)) {
            return $html;
        }

        $regular = $product->get_regular_price();
        if ($product->is_on_sale() && $this->option('show_regular', 'yes') === 'yes' && is_numeric($regular) && !Price::isZero($regular)) {
            return wc_format_sale_price((string) wc_get_price_to_display($product, ['price' => $regular]), $this->html($product)) . $product->get_price_suffix();
        }

        return $this->html($product);
    }

    /** @param array<int|string, mixed> $prices */
    private function rangeHtml(array $prices, string $html, \WC_Product $product): string
    {
        $state = Price::rangeState($prices);
        if ($state === 'all') {
            return $this->html($product);
        }
        if ($state === 'from' && $this->option('ranges', 'yes') === 'yes') {
            /* translators: 1: free text (e.g. "Free"), 2: highest price */
            return sprintf(_x('%1$s &ndash; %2$s', 'Free price range', 'stackless-lite'), $this->html($product), wc_price(Price::max($prices))) . $product->get_price_suffix();
        }
        return $html;
    }

    /* ---------------------------------------------------------------------
     * Cart, mini-cart, checkout, orders (classic)
     * ------------------------------------------------------------------- */

    /**
     * @param string $html
     * @param array  $cartItem
     * @return string
     */
    public function filterCartItemPrice($html, $cartItem)
    {
        $product = is_array($cartItem) ? ($cartItem['data'] ?? null) : null;
        if ($product instanceof \WC_Product && Price::isZero($product->get_price()) && !stackless_is_subscription_product($product)) {
            return $this->html($product);
        }
        return $html;
    }

    /**
     * @param string $html
     * @param array  $cartItem
     * @return string
     */
    public function filterCartItemSubtotal($html, $cartItem)
    {
        if (!is_array($cartItem) || !isset($cartItem['line_subtotal'])) {
            return $this->filterCartItemPrice($html, $cartItem);
        }
        $product = $cartItem['data'] ?? null;
        if ($product instanceof \WC_Product && Price::isZero($cartItem['line_subtotal']) && Price::isZero($cartItem['line_subtotal_tax'] ?? 0) && !stackless_is_subscription_product($product)) {
            return $this->html($product);
        }
        return $html;
    }

    /**
     * @param string          $subtotal
     * @param \WC_Order_Item  $item
     * @return string
     */
    public function filterOrderLineSubtotal($subtotal, $item)
    {
        if (!$item instanceof \WC_Order_Item_Product) {
            return $subtotal;
        }
        if (Price::isZero($item->get_subtotal()) && Price::isZero($item->get_subtotal_tax())) {
            $product = $item->get_product();
            return $this->html($product instanceof \WC_Product ? $product : null);
        }
        return $subtotal;
    }

    /* ---------------------------------------------------------------------
     * Cart / Checkout / Mini-Cart blocks
     * ------------------------------------------------------------------- */

    public function registerStoreApiData(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'cart-item',
            'namespace' => self::STORE_NAMESPACE,
            'data_callback' => function ($cartItem) {
                $product = is_array($cartItem) ? ($cartItem['data'] ?? null) : null;
                $free = $product instanceof \WC_Product && Price::isZero($product->get_price()) && !stackless_is_subscription_product($product);
                return ['free' => $free];
            },
            'schema_callback' => function () {
                return [
                    'free' => [
                        'description' => __('Whether the item is free and should show the free price text.', 'stackless-lite'),
                        'type' => 'boolean',
                        'context' => ['view', 'edit'],
                        'readonly' => true,
                    ],
                ];
            },
        ]);
    }

    /**
     * @param string $content
     * @return string
     */
    public function queueBlockAssets($content)
    {
        if ($this->blockAssetsQueued) {
            return $content;
        }
        $this->blockAssetsQueued = true;
        wp_enqueue_script(self::HANDLE . '-blocks', STACKLESS_PLUGIN_URL . 'assets/free_price_text/blocks.js', ['wc-blocks-checkout'], STACKLESS_VERSION, true);
        wp_register_style(self::HANDLE, false, [], STACKLESS_VERSION);
        wp_enqueue_style(self::HANDLE);
        $label = (string) wp_json_encode($this->text());
        wp_add_inline_style(self::HANDLE, ':root{--stackless-free-price-text:' . $label . '}'
            . '.stackless-free-price-item .wc-block-components-product-price>*{display:none!important}'
            . '.stackless-free-price-item .wc-block-components-product-price::before{content:var(--stackless-free-price-text);font-weight:600}');
        return $content;
    }

    /* ---------------------------------------------------------------------
     * Product editor
     * ------------------------------------------------------------------- */

    public function renderProductField(): void
    {
        global $product_object;
        $value = $product_object instanceof \WC_Product ? (string) $product_object->get_meta(self::META_LABEL) : '';
        echo '<div class="options_group">';
        woocommerce_wp_text_input([
            'id' => self::META_LABEL,
            'value' => $value,
            'label' => __('Free price text', 'stackless-lite'),
            'placeholder' => $this->option('text', __('Free', 'stackless-lite')),
            'desc_tip' => true,
            'description' => __('Shown instead of the price when this product costs nothing. Leave blank to use the store setting.', 'stackless-lite'),
        ]);
        echo '</div>';
    }

    public function saveProductField(\WC_Product $product): void
    {
        // WooCommerce verified the product edit nonce and capability before this action.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST[self::META_LABEL])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = sanitize_text_field(wp_unslash((string) $_POST[self::META_LABEL]));
        if ($value === '') {
            $product->delete_meta_data(self::META_LABEL);
        } else {
            $product->update_meta_data(self::META_LABEL, mb_substr($value, 0, 60));
        }
    }
}
