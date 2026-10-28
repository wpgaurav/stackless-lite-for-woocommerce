<?php

namespace Stackless\Modules\StockUrgency;

defined('ABSPATH') || exit;

/**
 * Stock Urgency: "Only 3 left" labels for products that track stock.
 *
 * Products that do not manage stock never get an urgency label (the count is unknown).
 * Variations get their own label through the variation availability HTML, so the label
 * changes as the shopper picks options.
 */
final class Module extends \Stackless\Core\Module
{
    public const META_HIDE = '_stackless_stock_urgency_hide';
    public const META_LOW = '_stackless_stock_urgency_low';
    private const HANDLE = 'stackless-stock_urgency';

    /** @var bool */
    private $styled = false;

    public function id(): string
    {
        return 'stock_urgency';
    }

    public function title(): string
    {
        return __('Stock Urgency Labels', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Show "Only 3 left" style labels when stock runs low, with separate low and very-low thresholds and messages.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_stock_urgency_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Stock urgency labels', 'stackless-lite'), 'desc' => __('Labels only appear on products (and variations) with "Track stock quantity" turned on.', 'stackless-lite')],
            ['type' => 'number', 'id' => $p . 'low', 'title' => __('Low stock at', 'stackless-lite'), 'default' => '5', 'desc' => __('Show the low stock label at or below this many units.', 'stackless-lite'), 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'text', 'id' => $p . 'low_text', 'title' => __('Low stock text', 'stackless-lite'), 'default' => __('Only {count} left in stock', 'stackless-lite'), 'desc' => __('{count} is replaced with the units left.', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'number', 'id' => $p . 'very_low', 'title' => __('Very low stock at', 'stackless-lite'), 'default' => '2', 'desc' => __('Show the stronger label at or below this many units. Set 0 to skip it.', 'stackless-lite'), 'custom_attributes' => ['min' => 0, 'step' => 1]],
            ['type' => 'text', 'id' => $p . 'very_low_text', 'title' => __('Very low stock text', 'stackless-lite'), 'default' => __('Almost gone: only {count} left', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'checkbox', 'id' => $p . 'in_stock', 'title' => __('Plenty in stock', 'stackless-lite'), 'desc' => __('Show a label when stock is above the low threshold', 'stackless-lite'), 'default' => 'no'],
            ['type' => 'text', 'id' => $p . 'in_stock_text', 'title' => __('In stock text', 'stackless-lite'), 'default' => __('In stock', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'checkbox', 'id' => $p . 'skip_backorders', 'title' => __('Backorders', 'stackless-lite'), 'desc' => __('Skip products that allow backorders, since they never really run out', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'select', 'id' => $p . 'single_position', 'title' => __('Product pages', 'stackless-lite'), 'default' => 'stock', 'class' => 'wc-enhanced-select', 'options' => [
                'stock' => __('Replace WooCommerce\'s stock line (updates per variation)', 'stackless-lite'),
                'after_title' => __('After the title', 'stackless-lite'),
                'after_price' => __('After the price', 'stackless-lite'),
                'after_add_to_cart' => __('After the add to cart button', 'stackless-lite'),
                '' => __('Do not show on product pages', 'stackless-lite'),
            ]],
            ['type' => 'select', 'id' => $p . 'archive_position', 'title' => __('Shop grids', 'stackless-lite'), 'default' => 'after_price', 'class' => 'wc-enhanced-select', 'options' => [
                'after_title' => __('After the title', 'stackless-lite'),
                'after_price' => __('After the price', 'stackless-lite'),
                'before_button' => __('Before the add to cart button', 'stackless-lite'),
                '' => __('Do not show in shop grids', 'stackless-lite'),
            ]],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        $single = (string) $this->option('single_position', 'stock');
        (new Placement([$this, 'render'], $single === 'stock' ? '' : $single, (string) $this->option('archive_position', 'after_price')))->register();

        if ($single === 'stock') {
            add_filter('woocommerce_get_stock_html', [$this, 'filterStockHtml'], 20, 2);
            add_filter('render_block_woocommerce/product-stock-indicator', [$this, 'filterStockBlock'], 20, 3);
        }

        add_action('woocommerce_product_options_inventory_product_data', [$this, 'renderProductFields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductFields']);
    }

    /* ---------------------------------------------------------------------
     * Levels
     * ------------------------------------------------------------------- */

    /** Units left, or null when the product does not track stock (or backorders are skipped). */
    public function quantity(\WC_Product $product): ?int
    {
        $skipBackorders = $this->option('skip_backorders', 'yes') === 'yes';

        if ($product->is_type('variable') && !$product->managing_stock()) {
            $quantities = [];
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product($childId);
                if (!$child instanceof \WC_Product || $child->get_status() !== 'publish' || !$child->is_purchasable()) {
                    continue;
                }
                $quantities[] = $this->ownQuantity($child, $skipBackorders);
            }
            return Levels::sum($quantities);
        }

        return $this->ownQuantity($product, $skipBackorders);
    }

    private function ownQuantity(\WC_Product $product, bool $skipBackorders): ?int
    {
        if (!$product->managing_stock() || $product->get_stock_quantity() === null) {
            return null;
        }
        if ($skipBackorders && $product->backorders_allowed()) {
            return null;
        }
        return (int) $product->get_stock_quantity();
    }

    public function level(\WC_Product $product): string
    {
        $owner = $this->owner($product);
        if ($owner->get_meta(self::META_HIDE) === 'yes') {
            return Levels::NONE;
        }
        $low = (int) $this->option('low', 5);
        $override = $owner->get_meta(self::META_LOW);
        if ($override !== '' && is_numeric($override) && (int) $override > 0) {
            $low = (int) $override;
        }
        $level = Levels::level($this->quantity($product), $low, (int) $this->option('very_low', 2));
        if ($level === Levels::IN_STOCK && $this->option('in_stock', 'no') !== 'yes') {
            return Levels::NONE;
        }
        /**
         * Filters the urgency level for a product: none, in-stock, low, very-low or out.
         *
         * @param string      $level
         * @param \WC_Product $product
         */
        return (string) apply_filters('stackless_stock_urgency_level', $level, $product);
    }

    private function owner(\WC_Product $product): \WC_Product
    {
        if ($product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                return $parent;
            }
        }
        return $product;
    }

    /* ---------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------- */

    public function render(\WC_Product $product, string $context = 'single'): string
    {
        $level = $this->level($product);
        if (!in_array($level, [Levels::LOW, Levels::VERY_LOW, Levels::IN_STOCK], true)) {
            return '';
        }
        $quantity = (int) $this->quantity($product);
        $templates = [
            Levels::LOW => (string) $this->option('low_text', __('Only {count} left in stock', 'stackless-lite')),
            Levels::VERY_LOW => (string) $this->option('very_low_text', __('Almost gone: only {count} left', 'stackless-lite')),
            Levels::IN_STOCK => (string) $this->option('in_stock_text', __('In stock', 'stackless-lite')),
        ];
        $text = Levels::text($templates[$level], $quantity);
        if (trim($text) === '') {
            return '';
        }
        $this->style();
        return sprintf(
            '<div class="stackless-stock-urgency stackless-stock-urgency--%1$s stackless-stock-urgency--%2$s"><span class="stackless-stock-urgency__text">%3$s</span></div>',
            esc_attr($level),
            esc_attr($context === 'archive' ? 'archive' : 'single'),
            esc_html($text)
        );
    }

    /**
     * Replace WooCommerce's stock line when a label applies. Runs for simple products and,
     * through the variation JSON, for each variation.
     *
     * @param string      $html
     * @param \WC_Product $product
     * @return string
     */
    public function filterStockHtml($html, $product)
    {
        if (!$product instanceof \WC_Product) {
            return $html;
        }
        $badge = $this->render($product, 'single');
        return $badge !== '' ? $badge : $html;
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function filterStockBlock($content, $block, $instance = null)
    {
        $id = $instance instanceof \WP_Block ? (int) ($instance->context['postId'] ?? 0) : 0;
        $product = $id ? wc_get_product($id) : null;
        if (!$product instanceof \WC_Product || $product->is_type('variable')) {
            return $content;
        }
        $badge = $this->render($product, 'single');
        return $badge !== '' ? $badge : $content;
    }

    private function style(): void
    {
        if ($this->styled) {
            return;
        }
        $this->styled = true;
        wp_enqueue_style(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/stock_urgency/stock-urgency.css', [], STACKLESS_VERSION);
    }

    /* ---------------------------------------------------------------------
     * Product editor (Inventory tab)
     * ------------------------------------------------------------------- */

    public function renderProductFields(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : null;
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => self::META_HIDE,
            'value' => $product && $product->get_meta(self::META_HIDE) === 'yes' ? 'yes' : 'no',
            'label' => __('Hide urgency label', 'stackless-lite'),
            'description' => __('Never show a low stock label for this product.', 'stackless-lite'),
        ]);
        woocommerce_wp_text_input([
            'id' => self::META_LOW,
            'value' => $product ? (string) $product->get_meta(self::META_LOW) : '',
            'label' => __('Urgency threshold', 'stackless-lite'),
            'type' => 'number',
            'placeholder' => (string) $this->option('low', 5),
            'custom_attributes' => ['min' => 1, 'step' => 1],
            'desc_tip' => true,
            'description' => __('Show the low stock label at or below this many units. Leave blank to use the store setting.', 'stackless-lite'),
        ]);
        echo '</div>';
    }

    public function saveProductFields(\WC_Product $product): void
    {
        // Nonce and capability are checked by WooCommerce before this action runs.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (!empty($_POST[self::META_HIDE])) {
            $product->update_meta_data(self::META_HIDE, 'yes');
        } else {
            $product->delete_meta_data(self::META_HIDE);
        }
        $low = isset($_POST[self::META_LOW]) ? absint(wp_unslash($_POST[self::META_LOW])) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ($low > 0) {
            $product->update_meta_data(self::META_LOW, (string) $low);
        } else {
            $product->delete_meta_data(self::META_LOW);
        }
    }
}
