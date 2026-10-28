<?php

namespace Stackless\Modules\CustomCartText;

defined('ABSPATH') || exit;

/**
 * Custom Add to Cart Text: button labels per product type, per state and per product,
 * on shop grids and product pages. Product blocks and the Store API read the same
 * WooCommerce text methods, so they change too.
 */
final class Module extends \Stackless\Core\Module
{
    public const META_LOOP = '_stackless_custom_cart_text_loop';
    public const META_SINGLE = '_stackless_custom_cart_text_single';
    private const FIELD_VARIATION = 'stackless_custom_cart_text_variation';
    private const HANDLE = 'stackless-custom_cart_text';

    public function id(): string
    {
        return 'custom_cart_text';
    }

    public function title(): string
    {
        return __('Custom Add to Cart Text', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Rename the add to cart button per product type, for free, subscription and sold-out products, and per product or variation.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    /** @return array<string, string> */
    private function types(): array
    {
        return [
            'simple' => __('Simple products', 'stackless-lite'),
            'variable' => __('Variable products', 'stackless-lite'),
            'grouped' => __('Grouped products', 'stackless-lite'),
            'external' => __('External/Affiliate products', 'stackless-lite'),
            'subscription' => __('Subscription products', 'stackless-lite'),
            'free' => __('Free products', 'stackless-lite'),
            'out_of_stock' => __('Out of stock products', 'stackless-lite'),
        ];
    }

    public function settingsFields(): array
    {
        $p = 'stackless_custom_cart_text_';
        $fields = [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Button text', 'stackless-lite'), 'desc' => __('Leave a box blank to keep WooCommerce\'s text. Product-level text (Product data → Advanced) wins over these. Sold out wins over free and subscription, which win over the product type.', 'stackless-lite')],
        ];
        foreach ($this->types() as $key => $label) {
            $fields[] = ['type' => 'text', 'id' => $p . $key . '_loop', 'title' => $label, 'desc' => __('Shop grids and product blocks', 'stackless-lite'), 'placeholder' => $this->placeholder($key, 'loop'), 'css' => 'min-width:260px'];
            $fields[] = ['type' => 'text', 'id' => $p . $key . '_single', 'title' => '', 'desc' => __('Product page', 'stackless-lite'), 'placeholder' => $this->placeholder($key, 'single'), 'css' => 'min-width:260px'];
        }
        $fields[] = ['type' => 'sectionend', 'id' => $p . 'section'];
        return $fields;
    }

    private function placeholder(string $key, string $context): string
    {
        switch ($key) {
            case 'variable':
                return $context === 'loop' ? __('Select options', 'stackless-lite') : __('Add to cart', 'stackless-lite');
            case 'grouped':
                return $context === 'loop' ? __('View products', 'stackless-lite') : __('Add to cart', 'stackless-lite');
            case 'external':
                return __('Buy product', 'stackless-lite');
            case 'out_of_stock':
                return __('Read more', 'stackless-lite');
            default:
                return __('Add to cart', 'stackless-lite');
        }
    }

    public function boot(): void
    {
        add_filter('woocommerce_product_add_to_cart_text', [$this, 'filterLoopText'], 20, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [$this, 'filterSingleText'], 20, 2);
        add_filter('woocommerce_available_variation', [$this, 'filterVariationData'], 20, 3);
        add_action('woocommerce_after_add_to_cart_button', [$this, 'maybeEnqueueVariationScript']);

        add_action('woocommerce_product_options_advanced', [$this, 'renderProductFields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductFields']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'renderVariationField'], 25, 3);
        add_action('woocommerce_admin_process_variation_object', [$this, 'saveVariationField'], 20, 2);
    }

    /** Resolved text for a product in "loop" or "single" context; $default is WooCommerce's own. */
    public function text(\WC_Product $product, string $context, string $default): string
    {
        $context = $context === 'single' ? 'single' : 'loop';
        $owner = $product;
        if ($product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            $owner = $parent instanceof \WC_Product ? $parent : $product;
        }

        $candidates = [];
        if ($context === 'single' && $product->is_type('variation')) {
            $candidates[] = (string) $product->get_meta(self::META_SINGLE);
        }
        $candidates[] = (string) $owner->get_meta($context === 'single' ? self::META_SINGLE : self::META_LOOP);

        // A per-product button text entered on an external product is the merchant's own choice.
        if ($owner instanceof \WC_Product_External && $owner->get_button_text() !== '') {
            return TextResolver::resolve($candidates, $default);
        }

        $price = $product->get_price();
        $free = $price !== '' && is_numeric($price) && (float) $price === 0.0 && !$owner->is_type('variable') && !$owner->is_type('grouped');
        $keys = TextResolver::keys($owner->get_type(), $product->is_in_stock(), $free, stackless_is_subscription_product($owner));
        foreach ($keys as $key) {
            $candidates[] = (string) $this->option($key . '_' . $context, '');
        }
        $text = TextResolver::resolve($candidates, $default);
        /**
         * Filters the add to cart text.
         *
         * @param string      $text
         * @param \WC_Product $product
         * @param string      $context loop|single
         */
        return (string) apply_filters('stackless_custom_cart_text_text', $text, $product, $context);
    }

    /**
     * @param string      $text
     * @param \WC_Product $product
     * @return string
     */
    public function filterLoopText($text, $product)
    {
        return $product instanceof \WC_Product ? $this->text($product, 'loop', (string) $text) : $text;
    }

    /**
     * @param string      $text
     * @param \WC_Product $product
     * @return string
     */
    public function filterSingleText($text, $product)
    {
        return $product instanceof \WC_Product ? $this->text($product, 'single', (string) $text) : $text;
    }

    /**
     * @param array                      $data
     * @param \WC_Product_Variable       $parent
     * @param \WC_Product_Variation|null $variation
     * @return array
     */
    public function filterVariationData($data, $parent, $variation)
    {
        if (!is_array($data) || !$variation instanceof \WC_Product || !$parent instanceof \WC_Product) {
            return $data;
        }
        $base = $parent->single_add_to_cart_text();
        $text = $this->text($variation, 'single', $base);
        if ($text !== $base) {
            $data['stackless_button_text'] = $text;
        }
        return $data;
    }

    public function maybeEnqueueVariationScript(): void
    {
        global $product;
        if ($product instanceof \WC_Product && $product->is_type('variable')) {
            wp_enqueue_script(self::HANDLE . '-variations', STACKLESS_PLUGIN_URL . 'assets/custom_cart_text/variations.js', ['jquery', 'wc-add-to-cart-variation'], STACKLESS_VERSION, true);
        }
    }

    /* ---------------------------------------------------------------------
     * Product editor
     * ------------------------------------------------------------------- */

    public function renderProductFields(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : null;
        echo '<div class="options_group">';
        woocommerce_wp_text_input([
            'id' => self::META_LOOP,
            'value' => $product ? (string) $product->get_meta(self::META_LOOP) : '',
            'label' => __('Button text in shop', 'stackless-lite'),
            'placeholder' => __('Store setting', 'stackless-lite'),
            'desc_tip' => true,
            'description' => __('Add to cart text on shop grids and product blocks. Leave blank to use the store setting.', 'stackless-lite'),
        ]);
        woocommerce_wp_text_input([
            'id' => self::META_SINGLE,
            'value' => $product ? (string) $product->get_meta(self::META_SINGLE) : '',
            'label' => __('Button text on product page', 'stackless-lite'),
            'placeholder' => __('Store setting', 'stackless-lite'),
            'desc_tip' => true,
            'description' => __('Add to cart text on this product\'s page. Variations can override it.', 'stackless-lite'),
        ]);
        echo '</div>';
    }

    public function saveProductFields(\WC_Product $product): void
    {
        foreach ([self::META_LOOP, self::META_SINGLE] as $key) {
            // Nonce and capability are checked by WooCommerce before this action runs.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
            if ($value === '') {
                $product->delete_meta_data($key);
            } else {
                $product->update_meta_data($key, mb_substr($value, 0, 60));
            }
        }
    }

    /**
     * @param int      $loop
     * @param array    $variationData
     * @param \WP_Post $variationPost
     */
    public function renderVariationField($loop, $variationData, $variationPost): void
    {
        $variation = wc_get_product($variationPost->ID);
        if (!$variation instanceof \WC_Product) {
            return;
        }
        woocommerce_wp_text_input([
            'id' => self::FIELD_VARIATION . '_' . (int) $loop,
            'name' => self::FIELD_VARIATION . '[' . (int) $loop . ']',
            'value' => (string) $variation->get_meta(self::META_SINGLE),
            'label' => __('Add to cart button text', 'stackless-lite'),
            'placeholder' => __('Product setting', 'stackless-lite'),
            'wrapper_class' => 'form-row form-row-full',
            'desc_tip' => true,
            'description' => __('Shown on the product page when this variation is selected.', 'stackless-lite'),
        ]);
    }

    /**
     * @param \WC_Product_Variation $variation
     * @param int                   $index
     */
    public function saveVariationField($variation, $index): void
    {
        if (!$variation instanceof \WC_Product) {
            return;
        }
        // WooCommerce checks the save-variations nonce and edit_products capability before this action.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = isset($_POST[self::FIELD_VARIATION][$index]) ? sanitize_text_field(wp_unslash((string) $_POST[self::FIELD_VARIATION][$index])) : '';
        if ($value === '') {
            $variation->delete_meta_data(self::META_SINGLE);
        } else {
            $variation->update_meta_data(self::META_SINGLE, mb_substr($value, 0, 60));
        }
    }
}
