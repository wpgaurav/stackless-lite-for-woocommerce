<?php

namespace Stackless\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Product editor: a "Details" tab in Product data and per-variation values.
 */
final class Admin
{
    public const FIELD = 'stackless_product_enhancements';
    public const FIELD_VARIATION = 'stackless_product_enhancements_variation';
    private const HANDLE = 'stackless-product_enhancements-admin';

    public function register(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'tab']);
        add_action('woocommerce_product_data_panels', [$this, 'panel']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'variationFields'], 30, 3);
        add_action('woocommerce_admin_process_variation_object', [$this, 'saveVariation'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function tab($tabs)
    {
        if (!is_array($tabs)) {
            return $tabs;
        }
        $tabs['stackless_details'] = [
            'label' => __('Details', 'stackless-lite'),
            'target' => 'stackless_product_details_panel',
            'class' => ['hide_if_grouped'],
            'priority' => 75,
        ];
        return $tabs;
    }

    public function assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') {
            return;
        }
        wp_enqueue_script(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/product_enhancements/admin.js', ['jquery'], STACKLESS_VERSION, true);
        wp_enqueue_style(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/product_enhancements/admin.css', [], STACKLESS_VERSION);
    }

    public function panel(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : new \WC_Product_Simple();
        $data = Repository::get($product);
        $fields = Fields::all();
        $presets = Fields::presets();
        $auto = $product->get_id() ? AutoFill::values($product) : [];

        $membership = [];
        foreach ($presets as $presetKey => $preset) {
            foreach ($preset['fields'] as $fieldKey) {
                $membership[$fieldKey][] = $presetKey;
            }
        }

        echo '<div id="stackless_product_details_panel" class="panel woocommerce_options_panel hidden stackless-pe-panel">';
        echo '<p class="form-field stackless-docs-field">' . \Stackless\Admin\Documentation::link('product_enhancements', __('How this works', 'stackless-lite')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => 'stackless_pe_preset',
            'name' => self::FIELD . '[preset]',
            'value' => $data['preset'],
            'label' => __('Product type', 'stackless-lite'),
            'options' => array_map(static function ($preset) {
                return (string) $preset['label'];
            }, $presets),
            'desc_tip' => true,
            'description' => __('Picks which details to show. Grey values come from WooCommerce data and fill in automatically when you leave a box empty.', 'stackless-lite'),
        ]);
        woocommerce_wp_checkbox([
            'id' => 'stackless_pe_show',
            'name' => self::FIELD . '[show]',
            'value' => $data['show'] ? 'yes' : 'no',
            'cbvalue' => 'yes',
            'label' => __('Details table', 'stackless-lite'),
            'description' => __('Show the details table on the product page', 'stackless-lite'),
        ]);
        echo '</div>';

        echo '<div class="options_group stackless-pe-fields">';
        foreach ($membership as $key => $inPresets) {
            if (!isset($fields[$key])) {
                continue;
            }
            $field = $fields[$key];
            $id = 'stackless_pe_' . $key;
            $placeholder = (string) ($auto[(string) ($field['auto'] ?? '')] ?? ($field['default'] ?? ''));
            $value = $data['values'][$key] ?? '';
            printf('<p class="form-field stackless-pe-field" data-presets="%s">', esc_attr(implode(' ', $inPresets)));
            printf('<label for="%s">%s</label>', esc_attr($id), esc_html((string) $field['label']));
            $this->input($id, self::FIELD . '[values][' . $key . ']', $field, $value, $placeholder);
            printf(
                '<label class="stackless-pe-hide"><input type="checkbox" name="%s[hidden][]" value="%s" %s> %s</label>',
                esc_attr(self::FIELD),
                esc_attr($key),
                checked(in_array($key, $data['hidden'], true), true, false),
                esc_html__('Hide', 'stackless-lite')
            );
            if (!empty($field['help'])) {
                echo wc_help_tip((string) $field['help']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes.
            }
            echo '</p>';
        }
        echo '</div>';

        echo '<div class="options_group">';
        woocommerce_wp_textarea_input([
            'id' => 'stackless_pe_custom',
            'name' => self::FIELD . '[custom]',
            'value' => Repository::customText($data['custom']),
            'label' => __('Extra details', 'stackless-lite'),
            'placeholder' => __("Care | Machine wash cold\nCertified | OEKO-TEX", 'stackless-lite'),
            'rows' => 4,
            'desc_tip' => true,
            /* translators: %d: maximum number of rows */
            'description' => sprintf(__('One per line as "Name | Value". Up to %d rows, shown after the fields above.', 'stackless-lite'), Repository::MAX_CUSTOM),
        ]);
        echo '<input type="hidden" name="' . esc_attr(self::FIELD) . '[present]" value="1">';
        echo '</div>';
        echo '</div>';
    }

    /** @param array<string, mixed> $field */
    private function input(string $id, string $name, array $field, string $value, string $placeholder): void
    {
        $type = (string) $field['type'];
        if ($type === 'select') {
            printf('<select id="%s" name="%s" class="select short">', esc_attr($id), esc_attr($name));
            printf('<option value="">%s</option>', esc_html($placeholder !== '' ? $placeholder : __('Not set', 'stackless-lite')));
            foreach ((array) ($field['options'] ?? []) as $option) {
                printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr((string) $option), selected($value, (string) $option, false));
            }
            echo '</select>';
            return;
        }
        $html = ['number' => 'number', 'date' => 'date', 'url' => 'url'][$type] ?? 'text';
        printf(
            '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="short"%s>',
            esc_attr($html),
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            esc_attr($placeholder),
            $html === 'number' ? ' min="0" step="1"' : ''
        );
    }

    public function save(\WC_Product $product): void
    {
        // WooCommerce verified the product nonce and edit capability before this action.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized in Repository::validate().
        if (!is_array($raw) || empty($raw['present'])) {
            return;
        }
        [$data, $errors] = Repository::validate($product, $raw);
        $existing = Repository::get($product);
        if (!$existing['saved'] && !$data['show'] && !$data['values'] && !$data['custom'] && !$data['hidden']) {
            return;
        }
        $product->update_meta_data(Repository::META, $data);
        AutoFill::flush($product->get_id());
        foreach (array_unique($errors) as $error) {
            /* translators: %s: validation message */
            \WC_Admin_Meta_Boxes::add_error(sprintf(__('Product details: %s', 'stackless-lite'), $error));
        }
    }

    /**
     * @param int      $loop
     * @param array    $variationData
     * @param \WP_Post $variationPost
     */
    public function variationFields($loop, $variationData, $variationPost): void
    {
        $variation = wc_get_product($variationPost->ID);
        $parent = $variation instanceof \WC_Product ? wc_get_product($variation->get_parent_id()) : null;
        if (!$variation instanceof \WC_Product || !$parent instanceof \WC_Product) {
            return;
        }
        $data = Repository::get($parent);
        $presets = Fields::presets();
        $keys = $presets[$data['preset']]['fields'] ?? [];
        if (!$keys) {
            return;
        }
        $fields = Fields::all();
        $values = Repository::variationValues($variation);
        $auto = AutoFill::values($variation);
        echo '<div class="stackless-pe-variation"><p class="form-row form-row-full"><strong>' . esc_html__('Product details for this variation', 'stackless-lite') . '</strong> ';
        echo wc_help_tip(__('Leave blank to use the product\'s value. The field list follows the product type set in the Details tab; update the product after changing it.', 'stackless-lite')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes.
        echo '</p>';
        $i = 0;
        foreach ($keys as $key) {
            if (!isset($fields[$key]) || in_array($key, $data['hidden'], true)) {
                continue;
            }
            $field = $fields[$key];
            $id = 'stackless_pe_v' . (int) $loop . '_' . $key;
            $placeholder = (string) ($data['values'][$key] ?? ($auto[(string) ($field['auto'] ?? '')] ?? ''));
            printf('<p class="form-row %s"><label for="%s">%s</label>', $i % 2 ? 'form-row-last' : 'form-row-first', esc_attr($id), esc_html((string) $field['label']));
            $this->input($id, self::FIELD_VARIATION . '[' . (int) $loop . '][' . $key . ']', $field, $values[$key] ?? '', $placeholder);
            echo '</p>';
            $i++;
        }
        echo '</div>';
    }

    /**
     * @param \WC_Product_Variation $variation
     * @param int                   $index
     */
    public function saveVariation($variation, $index): void
    {
        if (!$variation instanceof \WC_Product) {
            return;
        }
        // WooCommerce checks the save-variations nonce and edit_products capability before this action.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST[self::FIELD_VARIATION][$index]) || !is_array($_POST[self::FIELD_VARIATION][$index])) {
            return;
        }
        $parent = wc_get_product($variation->get_parent_id());
        if (!$parent instanceof \WC_Product) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Fields::clean().
        $input = (array) wp_unslash($_POST[self::FIELD_VARIATION][$index]);
        [$values, $errors] = Repository::validateVariation(Repository::get($parent)['preset'], $input);
        if ($values) {
            $variation->update_meta_data(Repository::META_VARIATION, $values);
        } else {
            $variation->delete_meta_data(Repository::META_VARIATION);
        }
        AutoFill::flush($variation->get_id());
        foreach (array_unique($errors) as $error) {
            /* translators: 1: variation ID, 2: validation message */
            \WC_Admin_Meta_Boxes::add_error(sprintf(__('Variation #%1$d details: %2$s', 'stackless-lite'), $variation->get_id(), $error));
        }
    }
}
