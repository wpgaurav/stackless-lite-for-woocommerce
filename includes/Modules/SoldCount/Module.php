<?php

namespace Stackless\Modules\SoldCount;

defined('ABSPATH') || exit;

/**
 * Sold Count: "X sold" badge from WooCommerce's total_sales counter.
 */
final class Module extends \Stackless\Core\Module
{
    public const META_HIDE = '_stackless_sold_count_hide';
    private const HANDLE = 'stackless-sold_count';

    /** @var bool */
    private $styled = false;

    public function id(): string
    {
        return 'sold_count';
    }

    public function title(): string
    {
        return __('Sold Count', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Show an "X sold" badge on products once they pass a sales threshold. Uses WooCommerce\'s own sales counter.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_sold_count_';
        $single = [
            'after_title' => __('After the title', 'stackless-lite'),
            'after_price' => __('After the price', 'stackless-lite'),
            'after_add_to_cart' => __('After the add to cart button', 'stackless-lite'),
            '' => __('Do not show on product pages', 'stackless-lite'),
        ];
        $archive = [
            'after_title' => __('After the title', 'stackless-lite'),
            'after_price' => __('After the price', 'stackless-lite'),
            'before_button' => __('Before the add to cart button', 'stackless-lite'),
            '' => __('Do not show in shop grids', 'stackless-lite'),
        ];
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Sold count badge', 'stackless-lite'), 'desc' => __('Counts come from completed and processing orders, as recorded by WooCommerce. Variations add up on their parent product.', 'stackless-lite')],
            ['type' => 'text', 'id' => $p . 'text', 'title' => __('Badge text', 'stackless-lite'), 'default' => __('{count} sold', 'stackless-lite'), 'desc' => __('{count} is replaced with the number sold.', 'stackless-lite'), 'css' => 'min-width:260px'],
            ['type' => 'number', 'id' => $p . 'count_multiplier', 'title' => __('Count multiplier', 'stackless-lite'), 'default' => '1', 'desc' => __('The badge shows the real count times this number, rounded up. For example, 12 sold × 6.32 = 75.84 shows as 76. Leave it at 1 to show the real count. A count that does not match real sales can mislead buyers and may break consumer-protection rules where you sell.', 'stackless-lite'), 'css' => 'width:90px', 'custom_attributes' => ['min' => '0.01', 'max' => (string) Badge::MAX_MULTIPLIER, 'step' => '0.01']],
            ['type' => 'number', 'id' => $p . 'threshold', 'title' => __('Show from', 'stackless-lite'), 'default' => '5', 'desc' => __('Hide the badge until it would show at least this many. Compared with the count the badge shows, after the multiplier.', 'stackless-lite'), 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'select', 'id' => $p . 'rounding', 'title' => __('Number style', 'stackless-lite'), 'default' => 'exact', 'class' => 'wc-enhanced-select', 'options' => [
                'exact' => __('Exact (1,268 sold)', 'stackless-lite'),
                'rounded' => __('Rounded down (1,200+ sold)', 'stackless-lite'),
            ]],
            ['type' => 'select', 'id' => $p . 'single_position', 'title' => __('Product pages', 'stackless-lite'), 'default' => 'after_price', 'class' => 'wc-enhanced-select', 'options' => $single],
            ['type' => 'select', 'id' => $p . 'archive_position', 'title' => __('Shop grids', 'stackless-lite'), 'default' => 'after_price', 'class' => 'wc-enhanced-select', 'options' => $archive, 'desc' => __('Applies to the shop, category pages, related products and product blocks.', 'stackless-lite')],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        (new Placement([$this, 'render'], (string) $this->option('single_position', 'after_price'), (string) $this->option('archive_position', 'after_price')))->register();

        add_filter('woocommerce_admin_settings_sanitize_option_stackless_sold_count_count_multiplier', [$this, 'sanitizeMultiplier'], 10, 3);
        add_action('woocommerce_product_options_advanced', [$this, 'renderProductField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductField']);
    }

    /** Saved as a plain decimal string, clamped to a positive number up to Badge::MAX_MULTIPLIER. */
    public function sanitizeMultiplier($value, $option, $raw): string
    {
        $raw = is_scalar($raw) ? str_replace(',', '.', trim((string) $raw)) : '';
        return (string) Badge::multiplier($raw === '' ? 1 : $raw);
    }

    public function count(\WC_Product $product): int
    {
        $owner = $product;
        if ($product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            $owner = $parent instanceof \WC_Product ? $parent : $product;
        }
        /**
         * Filters the sold count shown for a product.
         *
         * @param int         $count
         * @param \WC_Product $product
         */
        return (int) apply_filters('stackless_sold_count_count', (int) $owner->get_total_sales(), $owner);
    }

    /** The number the badge shows: the real count times the count multiplier, rounded up. */
    public function shownCount(\WC_Product $product): int
    {
        return Badge::multiply($this->count($product), Badge::multiplier($this->option('count_multiplier', 1)));
    }

    /** Badge HTML for a product, or '' when it should not show. */
    public function render(\WC_Product $product, string $context = 'single'): string
    {
        if ($product->get_meta(self::META_HIDE) === 'yes') {
            return '';
        }
        $count = $this->shownCount($product);
        if (!Badge::visible($count, (int) $this->option('threshold', 5))) {
            return '';
        }
        $formatted = Badge::formatCount($count, (string) $this->option('rounding', 'exact'), static function (int $n): string {
            return (string) number_format_i18n($n);
        });
        $text = Badge::text((string) $this->option('text', __('{count} sold', 'stackless-lite')), $formatted);
        $this->style();
        return sprintf(
            '<div class="stackless-sold-count stackless-sold-count--%1$s"><span class="stackless-sold-count__text">%2$s</span></div>',
            esc_attr($context === 'archive' ? 'archive' : 'single'),
            esc_html($text)
        );
    }

    private function style(): void
    {
        if ($this->styled) {
            return;
        }
        $this->styled = true;
        wp_register_style(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/sold_count/sold-count.css', [], STACKLESS_VERSION);
        wp_enqueue_style(self::HANDLE);
    }

    public function renderProductField(): void
    {
        global $product_object;
        $value = $product_object instanceof \WC_Product ? (string) $product_object->get_meta(self::META_HIDE) : '';
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => self::META_HIDE,
            'value' => $value === 'yes' ? 'yes' : 'no',
            'label' => __('Hide sold count', 'stackless-lite'),
            'description' => __('Do not show the "sold" badge for this product.', 'stackless-lite'),
        ]);
        echo '</div>';
    }

    public function saveProductField(\WC_Product $product): void
    {
        // Nonce and capability are checked by WooCommerce before this action runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!empty($_POST[self::META_HIDE])) {
            $product->update_meta_data(self::META_HIDE, 'yes');
        } else {
            $product->delete_meta_data(self::META_HIDE);
        }
    }
}
