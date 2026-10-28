<?php

namespace Stackless\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Per-product settings: a "Pay what you like" product data tab (simple and variable products)
 * and optional overrides on each variation. Amounts are stored in the store's base currency as
 * decimal strings; MultiCurrency converts them for other currencies at display time.
 *
 * Subscription products keep their set price, so they never take a chosen amount.
 */
final class ProductSettings
{
    public const META = '_stackless_pay_what_you_like';
    private const FIELD = 'stackless_pay_what_you_like';

    /** @var array<int, array<string, mixed>> */
    private $cache = [];

    public function register(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'tab']);
        add_action('woocommerce_product_data_panels', [$this, 'panel']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'variationFields'], 20, 3);
        add_action('woocommerce_admin_process_variation_object', [$this, 'saveVariation'], 20, 2);
    }

    private static function decimals(): int
    {
        return stackless_base_price_decimals();
    }

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------- */

    /**
     * Effective settings for a product or variation, or null when it does not take a chosen
     * amount (switched off, a subscription, or a product type that can't be bought directly).
     *
     * @param \WC_Product|int|null $product
     * @return array{enabled: bool, min: string, suggested: string, max: string, presets: string[]}|null
     */
    public function settingsFor($product): ?array
    {
        $product = is_numeric($product) ? wc_get_product((int) $product) : $product;
        if (!$product instanceof \WC_Product) {
            return null;
        }
        $id = (int) $product->get_id();
        if (!array_key_exists($id, $this->cache)) {
            $this->cache[$id] = $this->compute($product);
        }
        $settings = $this->cache[$id];
        /**
         * Filters the effective pay-what-you-like settings of a product (null = fixed price).
         *
         * @param array|null  $settings enabled, min, suggested, max, presets (base currency)
         * @param \WC_Product $product
         */
        $settings = apply_filters('stackless_pay_what_you_like_settings', $settings, $product);
        return is_array($settings) && !empty($settings['enabled']) ? Amounts::normalizeProduct($settings) : null;
    }

    /** @return array<string, mixed>|null */
    private function compute(\WC_Product $product): ?array
    {
        if (!$product->is_type(['simple', 'variation', 'variable'])) {
            return null;
        }
        if (stackless_is_subscription_product($product)) {
            return null;
        }
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent instanceof \WC_Product || stackless_is_subscription_product($parent)) {
                return null;
            }
            $settings = Amounts::resolve(Amounts::normalizeProduct($parent->get_meta(self::META, true)), Amounts::normalizeVariation($product->get_meta(self::META, true)));
        } else {
            $settings = Amounts::normalizeProduct($product->get_meta(self::META, true));
        }
        return $settings['enabled'] ? $settings : null;
    }

    public function flush(int $productId = 0): void
    {
        if ($productId) {
            unset($this->cache[$productId]);
        } else {
            $this->cache = [];
        }
    }

    /* ---------------------------------------------------------------------
     * Product editor
     * ------------------------------------------------------------------- */

    /** @param array<string, array<string, mixed>> $tabs */
    public function tab($tabs): array
    {
        $tabs = (array) $tabs;
        $tabs['stackless_pay_what_you_like'] = [
            'label' => __('Pay what you like', 'stackless-lite'),
            'target' => 'stackless_pay_what_you_like_data',
            'class' => ['show_if_simple', 'show_if_variable', 'hide_if_grouped', 'hide_if_external'],
            'priority' => 76,
        ];
        return $tabs;
    }

    public function panel(): void
    {
        global $post;
        $product = $post ? wc_get_product($post->ID) : null;
        $settings = Amounts::normalizeProduct($product ? $product->get_meta(self::META, true) : []);
        $symbol = get_woocommerce_currency_symbol(stackless_base_currency());

        echo '<div id="stackless_pay_what_you_like_data" class="panel woocommerce_options_panel hidden">';
        echo '<p class="form-field">' . \Stackless\Admin\Documentation::link('pay_what_you_like', __('How this works', 'stackless-lite')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => self::FIELD . '_enabled',
            'label' => __('Pay what you like', 'stackless-lite'),
            'description' => __('Let buyers choose what to pay', 'stackless-lite'),
            'value' => $settings['enabled'] ? 'yes' : 'no',
            'cbvalue' => 'yes',
        ]);
        echo '<p class="form-field"><span class="description">' . esc_html__('The product\'s price is the minimum unless you set one below, so a price of 0 makes it "free, or pay what you like". Subscription products keep their set price.', 'stackless-lite') . '</span></p>';
        echo '</div><div class="options_group">';
        $fields = [
            'min' => [__('Minimum', 'stackless-lite'), __('Leave empty to use the product\'s price as the minimum.', 'stackless-lite')],
            'suggested' => [__('Suggested amount', 'stackless-lite'), __('Filled in for the buyer. Leave empty to start at the product\'s price.', 'stackless-lite')],
            'max' => [__('Maximum', 'stackless-lite'), __('Leave empty to allow up to 1,000,000.', 'stackless-lite')],
        ];
        foreach ($fields as $key => $labels) {
            woocommerce_wp_text_input([
                'id' => self::FIELD . '_' . $key,
                /* translators: 1: field label, 2: currency symbol */
                'label' => sprintf(__('%1$s (%2$s)', 'stackless-lite'), $labels[0], $symbol),
                'value' => $settings[$key] !== '' ? wc_format_localized_price($settings[$key]) : '',
                'data_type' => 'price',
                'desc_tip' => true,
                'description' => $labels[1],
            ]);
        }
        echo '</div><div class="options_group">';
        for ($i = 0; $i < Amounts::MAX_PRESETS; $i++) {
            woocommerce_wp_text_input([
                'id' => self::FIELD . '_preset_' . $i,
                'name' => self::FIELD . '_presets[' . $i . ']',
                /* translators: 1: quick amount number (1-4), 2: currency symbol */
                'label' => sprintf(__('Quick amount %1$d (%2$s)', 'stackless-lite'), $i + 1, $symbol),
                'value' => isset($settings['presets'][$i]) ? wc_format_localized_price($settings['presets'][$i]) : '',
                'data_type' => 'price',
                'desc_tip' => true,
                'description' => __('One-tap amounts shown with the amount field, for example 0, 5 and 10. Amounts below a product\'s minimum are left out, so 0 only shows when the minimum is 0.', 'stackless-lite'),
            ]);
        }
        echo '<p class="form-field"><span class="description">' . esc_html(sprintf(
            /* translators: %s: store currency code */
            __('Amounts are in %s. Other currencies are converted at checkout. Variations can override the minimum, suggested amount and maximum, or opt out.', 'stackless-lite'),
            stackless_base_currency()
        )) . '</span></p>';
        echo '<input type="hidden" name="' . esc_attr(self::FIELD . '_present') . '" value="1">';
        echo '</div></div>';
    }

    public function save(\WC_Product $product): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product save nonce.
        if (empty($_POST[self::FIELD . '_present'])) {
            return;
        }
        $text = static function (string $key): string {
            return isset($_POST[$key]) && is_scalar($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
        };
        $presets = [];
        if (isset($_POST[self::FIELD . '_presets']) && is_array($_POST[self::FIELD . '_presets'])) {
            foreach (wp_unslash($_POST[self::FIELD . '_presets']) as $value) {
                if (is_scalar($value)) {
                    $presets[] = sanitize_text_field((string) $value);
                }
            }
        }
        $input = [
            'enabled' => $text(self::FIELD . '_enabled'),
            'min' => $text(self::FIELD . '_min'),
            'suggested' => $text(self::FIELD . '_suggested'),
            'max' => $text(self::FIELD . '_max'),
            'presets' => $presets,
        ];
        // phpcs:enable
        $result = Amounts::sanitizeProduct($input, self::decimals(), wc_get_price_decimal_separator());
        foreach ($result['errors'] as $code) {
            if (class_exists(\WC_Admin_Meta_Boxes::class)) {
                \WC_Admin_Meta_Boxes::add_error(self::errorMessage($code));
            }
        }
        if ($result['settings'] === Amounts::productDefaults()) {
            $product->delete_meta_data(self::META);
        } else {
            $product->update_meta_data(self::META, $result['settings']);
        }
        $this->flush();
    }

    /**
     * @param int|string $loop
     * @param array<string, mixed> $variationData
     * @param \WP_Post|mixed $variation
     */
    public function variationFields($loop, $variationData, $variation): void
    {
        $id = $variation instanceof \WP_Post ? (int) $variation->ID : 0;
        $product = $id ? wc_get_product($id) : null;
        $settings = Amounts::normalizeVariation($product ? $product->get_meta(self::META, true) : []);
        $loop = (int) $loop;
        $symbol = get_woocommerce_currency_symbol(stackless_base_currency());

        echo '<div class="stackless-pwyl-variation">';
        woocommerce_wp_checkbox([
            'id' => self::FIELD . '_var_off_' . $loop,
            'name' => self::FIELD . '_var[' . $loop . '][off]',
            'label' => __('Fixed price', 'stackless-lite'),
            'description' => __('No pay what you like for this variation (when the product has it on)', 'stackless-lite'),
            'value' => $settings['mode'] === 'off' ? 'yes' : 'no',
            'cbvalue' => 'yes',
            'wrapper_class' => 'form-row form-row-full',
        ]);
        $fields = [
            'min' => __('Pay what you like minimum', 'stackless-lite'),
            'suggested' => __('Suggested amount', 'stackless-lite'),
            'max' => __('Maximum', 'stackless-lite'),
        ];
        $n = 0;
        foreach ($fields as $key => $label) {
            woocommerce_wp_text_input([
                'id' => self::FIELD . '_var_' . $key . '_' . $loop,
                'name' => self::FIELD . '_var[' . $loop . '][' . $key . ']',
                /* translators: 1: field label, 2: currency symbol */
                'label' => sprintf(__('%1$s (%2$s)', 'stackless-lite'), $label, $symbol),
                'value' => $settings[$key] !== '' ? wc_format_localized_price($settings[$key]) : '',
                'data_type' => 'price',
                'placeholder' => __('Product setting', 'stackless-lite'),
                'wrapper_class' => 'form-row ' . ($n++ % 2 === 0 ? 'form-row-first' : 'form-row-last'),
                'desc_tip' => true,
                'description' => __('Leave empty to use the product\'s Pay what you like setting.', 'stackless-lite'),
            ]);
        }
        echo '</div>';
    }

    /** @param int|string $index */
    public function saveVariation(\WC_Product $variation, $index): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the save-variations nonce.
        $all = isset($_POST[self::FIELD . '_var']) && is_array($_POST[self::FIELD . '_var']) ? wp_unslash($_POST[self::FIELD . '_var']) : [];
        // phpcs:enable
        $row = is_array($all) && isset($all[$index]) && is_array($all[$index]) ? $all[$index] : null;
        if ($row === null) {
            return;
        }
        $input = [];
        foreach (['off', 'min', 'suggested', 'max'] as $key) {
            $input[$key] = isset($row[$key]) && is_scalar($row[$key]) ? sanitize_text_field((string) $row[$key]) : '';
        }
        $result = Amounts::sanitizeVariation($input, self::decimals(), wc_get_price_decimal_separator());
        foreach ($result['errors'] as $code) {
            if (class_exists(\WC_Admin_Meta_Boxes::class)) {
                \WC_Admin_Meta_Boxes::add_error(sprintf(
                    /* translators: 1: variation ID, 2: error message */
                    __('Variation #%1$d: %2$s', 'stackless-lite'),
                    (int) $variation->get_id(),
                    self::errorMessage($code)
                ));
            }
        }
        if ($result['settings'] === Amounts::variationDefaults()) {
            $variation->delete_meta_data(self::META);
        } else {
            $variation->update_meta_data(self::META, $result['settings']);
        }
        $this->flush();
    }

    public static function errorMessage(string $code): string
    {
        switch ($code) {
            case 'invalid_min':
                return __('Pay what you like: the minimum is not a valid amount and was not saved.', 'stackless-lite');
            case 'invalid_suggested':
                return __('Pay what you like: the suggested amount is not a valid amount and was not saved.', 'stackless-lite');
            case 'invalid_max':
                return __('Pay what you like: the maximum is not a valid amount and was not saved.', 'stackless-lite');
            case 'invalid_preset':
                return __('Pay what you like: a quick amount is not a valid amount and was left out.', 'stackless-lite');
            case 'too_many_presets':
                /* translators: %d: the number of quick amounts allowed */
                return sprintf(__('Pay what you like: add up to %d quick amounts.', 'stackless-lite'), Amounts::MAX_PRESETS);
            case 'min_above_max':
                return __('Pay what you like: the minimum is above the maximum, so the maximum was not saved.', 'stackless-lite');
            case 'suggested_above_max':
                return __('Pay what you like: the suggested amount can\'t be above the maximum and was not saved.', 'stackless-lite');
            case 'preset_above_max':
                return __('Pay what you like: quick amounts above the maximum were left out.', 'stackless-lite');
        }
        return __('Pay what you like: some settings could not be saved.', 'stackless-lite');
    }
}
