<?php

namespace Stackless\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Product Enhancements: a product details table (specifications) with presets for physical
 * products, downloads, books (ISBN-10/13), software and services. Values auto-fill from
 * WooCommerce data (brand, GTIN, weight, dimensions, downloads), can differ per variation,
 * show as a tab, inside Additional information, or below the summary, and feed the
 * Product structured data.
 */
final class Module extends \Stackless\Core\Module
{
    private const HANDLE = 'stackless-product_enhancements';

    /** @var array<int, bool> */
    private $rendered = [];

    /** @var array<int, array> */
    private $rowsCache = [];

    public function id(): string
    {
        return 'product_enhancements';
    }

    public function title(): string
    {
        return __('Product Details Table', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Add a specifications table to products with presets for physical goods, downloads, books, software and services. Values fill in from WooCommerce data and feed product schema.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_product_enhancements_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Product details table', 'stackless-lite'), 'desc' => __('Fill in details per product under Product data → Details. The shortcode [stackless_product_details] places the table anywhere; add id="123" outside product pages.', 'stackless-lite')],
            ['type' => 'select', 'id' => $p . 'placement', 'title' => __('Placement', 'stackless-lite'), 'default' => 'tab', 'class' => 'wc-enhanced-select', 'options' => [
                'tab' => __('Its own product tab', 'stackless-lite'),
                'additional' => __('Inside the Additional information tab', 'stackless-lite'),
                'below_summary' => __('Below the product summary', 'stackless-lite'),
                'none' => __('Only where I place the shortcode', 'stackless-lite'),
            ]],
            ['type' => 'text', 'id' => $p . 'tab_title', 'title' => __('Tab title', 'stackless-lite'), 'default' => __('Details', 'stackless-lite'), 'css' => 'min-width:260px'],
            ['type' => 'text', 'id' => $p . 'heading', 'title' => __('Table heading', 'stackless-lite'), 'default' => __('Specifications', 'stackless-lite'), 'desc' => __('Leave blank for no heading.', 'stackless-lite'), 'css' => 'min-width:260px'],
            ['type' => 'checkbox', 'id' => $p . 'schema', 'title' => __('Structured data', 'stackless-lite'), 'desc' => __('Add details to the product schema (brand, GTIN, MPN, condition, weight, dimensions, other properties)', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    public function boot(): void
    {
        if (is_admin()) {
            (new Admin())->register();
        }

        $placement = (string) $this->option('placement', 'tab');
        if ($placement === 'tab' || $placement === 'additional') {
            add_filter('woocommerce_product_tabs', [$this, 'filterTabs'], 20);
        } elseif ($placement === 'below_summary') {
            add_action('woocommerce_after_single_product_summary', [$this, 'renderBelowSummary'], 5);
            add_filter('render_block_woocommerce/product-details', [$this, 'prependToDetailsBlock'], 20, 3);
        }

        (new Agent())->register();
        add_shortcode('stackless_product_details', [$this, 'shortcode']);
        add_filter('woocommerce_available_variation', [$this, 'filterVariationData'], 20, 3);
        if ($this->option('schema', 'yes') === 'yes') {
            add_filter('woocommerce_structured_data_product', [$this, 'filterStructuredData'], 30, 2);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(\WC_Product $product): array
    {
        $id = $product->get_id();
        if (!isset($this->rowsCache[$id])) {
            $this->rowsCache[$id] = Repository::rows($product);
        }
        return $this->rowsCache[$id];
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    public function render(\WC_Product $product, ?string $heading = null): string
    {
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent instanceof \WC_Product) {
                return '';
            }
            $product = $parent;
        }
        if ($product->get_status() !== 'publish' && !current_user_can('read_product', $product->get_id())) {
            return '';
        }
        $rows = $this->rows($product);
        if (!$rows) {
            return '';
        }
        $this->rendered[$product->get_id()] = true;
        wp_enqueue_style(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/product_enhancements/details.css', [], STACKLESS_VERSION);

        $heading = $heading ?? (string) $this->option('heading', __('Specifications', 'stackless-lite'));
        $hasVariations = false;
        $html = '<section class="stackless-product-details" data-stackless-product-details="' . esc_attr((string) $product->get_id()) . '">';
        if (trim($heading) !== '') {
            $html .= '<h2 class="stackless-product-details__title">' . esc_html($heading) . '</h2>';
        }
        $html .= '<table class="stackless-product-details__table woocommerce-product-attributes shop_attributes"><tbody>';
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            $varies = !empty($row['variations']);
            $hasVariations = $hasVariations || $varies;
            $html .= sprintf(
                '<tr class="stackless-product-details__row"%s%s><th scope="row">%s</th><td%s>%s</td></tr>',
                $varies ? ' data-stackless-detail-row="' . esc_attr((string) $row['key']) . '"' : '',
                $value === '' ? ' hidden' : '',
                esc_html((string) $row['label']),
                $varies ? ' data-stackless-detail="' . esc_attr((string) $row['key']) . '" data-stackless-default="' . esc_attr($this->plain($row, $value)) . '"' : '',
                $this->formatValue((string) $row['type'], $value)
            );
        }
        $html .= '</tbody></table></section>';
        if ($hasVariations) {
            wp_enqueue_script(self::HANDLE . '-variations', STACKLESS_PLUGIN_URL . 'assets/product_enhancements/variations.js', ['jquery'], STACKLESS_VERSION, true);
        }
        return $html;
    }

    /** Escaped HTML for one value. */
    private function formatValue(string $type, string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($type === 'url') {
            $label = (string) preg_replace('#^https?://(www\.)?#', '', untrailingslashit($value));
            return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
        }
        if ($type === 'date') {
            $time = strtotime($value);
            return esc_html($time ? (string) wp_date((string) get_option('date_format'), $time) : $value);
        }
        return esc_html($value);
    }

    /**
     * Plain text for the per-variation switcher (it sets textContent, never HTML).
     *
     * @param array<string, mixed> $row
     */
    private function plain(array $row, string $value): string
    {
        if ($value !== '' && ($row['type'] ?? '') === 'date') {
            $time = strtotime($value);
            return $time ? (string) wp_date((string) get_option('date_format'), $time) : $value;
        }
        return $value;
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function filterTabs($tabs)
    {
        global $product;
        if (!is_array($tabs) || !$product instanceof \WC_Product || !$this->rows($product)) {
            return $tabs;
        }
        $current = $product;
        if ($this->option('placement', 'tab') === 'additional') {
            if (isset($tabs['additional_information']['callback'])) {
                $original = $tabs['additional_information']['callback'];
                $tabs['additional_information']['callback'] = function ($key = '', $tab = []) use ($original, $current) {
                    if (is_callable($original)) {
                        call_user_func($original, $key, $tab);
                    }
                    echo $this->render($current); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
                };
            } else {
                $tabs['additional_information'] = [
                    'title' => __('Additional information', 'stackless-lite'),
                    'priority' => 20,
                    'callback' => function () use ($current) {
                        echo $this->render($current); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
                    },
                ];
            }
            return $tabs;
        }
        $title = trim((string) $this->option('tab_title', __('Details', 'stackless-lite')));
        $tabs['stackless_details'] = [
            'title' => $title !== '' ? $title : __('Details', 'stackless-lite'),
            'priority' => 15,
            'callback' => function () use ($current) {
                echo $this->render($current); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
            },
        ];
        return $tabs;
    }

    public function renderBelowSummary(): void
    {
        global $product;
        if ($product instanceof \WC_Product && empty($this->rendered[$product->get_id()])) {
            echo $this->render($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
        }
    }

    /**
     * Block themes: the Product Details block holds the tabs, so the table goes just above it.
     *
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function prependToDetailsBlock($content, $block, $instance = null)
    {
        $id = $instance instanceof \WP_Block ? (int) ($instance->context['postId'] ?? 0) : 0;
        if (!$id) {
            $id = (int) get_the_ID();
        }
        $product = $id ? wc_get_product($id) : null;
        if (!$product instanceof \WC_Product || !empty($this->rendered[$product->get_id()])) {
            return $content;
        }
        return $this->render($product) . $content;
    }

    /**
     * [stackless_product_details id="123" title="Specs"]
     *
     * @param array|string $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['id' => 0, 'title' => null], is_array($atts) ? $atts : [], 'stackless_product_details');
        $id = absint($atts['id']) ?: (int) get_the_ID();
        $product = $id ? wc_get_product($id) : null;
        if (!$product instanceof \WC_Product || !empty($this->rendered[$product->get_id()])) {
            return '';
        }
        return $this->render($product, $atts['title'] === null ? null : sanitize_text_field((string) $atts['title']));
    }

    /**
     * Per-variation values for the switcher script.
     *
     * @param array                      $data
     * @param \WC_Product_Variable       $parent
     * @param \WC_Product_Variation|null $variation
     * @return array
     */
    public function filterVariationData($data, $parent, $variation)
    {
        if (!is_array($data) || !$parent instanceof \WC_Product || !$variation instanceof \WC_Product) {
            return $data;
        }
        $values = [];
        foreach ($this->rows($parent) as $row) {
            if (!empty($row['variations'][$variation->get_id()])) {
                $values[(string) $row['key']] = $this->plain($row, (string) $row['variations'][$variation->get_id()]);
            }
        }
        if ($values) {
            $data['stackless_details'] = $values;
        }
        return $data;
    }

    /**
     * @param array       $markup
     * @param \WC_Product $product
     * @return array
     */
    public function filterStructuredData($markup, $product)
    {
        if (!is_array($markup) || !$product instanceof \WC_Product) {
            return $markup;
        }
        return Schema::apply($markup, $this->rows($product), Fields::all());
    }
}
