<?php

namespace Stackless\Modules\QuantityLimits;

defined('ABSPATH') || exit;

/**
 * Min/Max Quantity: minimum, maximum and step per variation, per product and store-wide.
 *
 * Limits feed WooCommerce's own quantity filters, so quantity inputs, the Store API
 * (Cart/Checkout blocks, product blocks) and core stock caps all agree. Server-side checks
 * cover classic add to cart, AJAX add to cart, cart updates, cart/checkout validation and
 * Store API cart items.
 */
final class Module extends \Stackless\Core\Module
{
    public const META_MIN = '_stackless_quantity_limits_min';
    public const META_MAX = '_stackless_quantity_limits_max';
    public const META_STEP = '_stackless_quantity_limits_step';
    private const HANDLE = 'stackless-quantity_limits';

    /** @var array<int, array{min: int, max: int, step: int}> */
    private $cache = [];

    /** @var int Depth of Store API routes currently being dispatched (covers internal rest_do_request calls). */
    private $storeApiDepth = 0;

    public function id(): string
    {
        return 'quantity_limits';
    }

    public function title(): string
    {
        return __('Min/Max Quantity', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Set minimum, maximum and step quantities store-wide, per product and per variation. Enforced on the classic cart and the Cart/Checkout blocks.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_quantity_limits_';
        return [
            ['type' => 'title', 'id' => $p . 'defaults', 'title' => __('Store defaults', 'stackless-lite'), 'desc' => __('Products and variations can override each value. Variation values win over product values, which win over these.', 'stackless-lite')],
            ['type' => 'number', 'id' => $p . 'default_min', 'title' => __('Minimum quantity', 'stackless-lite'), 'default' => '1', 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'number', 'id' => $p . 'default_max', 'title' => __('Maximum quantity', 'stackless-lite'), 'default' => '0', 'desc' => __('0 means no limit. Stock levels still cap it.', 'stackless-lite'), 'custom_attributes' => ['min' => 0, 'step' => 1]],
            ['type' => 'number', 'id' => $p . 'default_step', 'title' => __('Sold in multiples of', 'stackless-lite'), 'default' => '1', 'custom_attributes' => ['min' => 1, 'step' => 1]],
            ['type' => 'sectionend', 'id' => $p . 'defaults'],

            ['type' => 'title', 'id' => $p . 'display', 'title' => __('Shopper messages', 'stackless-lite'), 'desc' => __('Placeholders: {product}, {min}, {max}, {step}, {quantity}.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'show_note', 'title' => __('Limits note', 'stackless-lite'), 'desc' => __('Show the limits next to the quantity box on product pages', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'min_note', 'title' => __('Minimum note', 'stackless-lite'), 'default' => __('Minimum {min}', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'text', 'id' => $p . 'max_note', 'title' => __('Maximum note', 'stackless-lite'), 'default' => __('Maximum {max}', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'text', 'id' => $p . 'step_note', 'title' => __('Step note', 'stackless-lite'), 'default' => __('Sold in packs of {step}', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'text', 'id' => $p . 'min_error', 'title' => __('Below minimum error', 'stackless-lite'), 'default' => __('You need at least {min} of "{product}" in your cart.', 'stackless-lite'), 'css' => 'min-width:400px'],
            ['type' => 'text', 'id' => $p . 'max_error', 'title' => __('Above maximum error', 'stackless-lite'), 'default' => __('You can buy at most {max} of "{product}".', 'stackless-lite'), 'css' => 'min-width:400px'],
            ['type' => 'text', 'id' => $p . 'step_error', 'title' => __('Wrong multiple error', 'stackless-lite'), 'default' => __('"{product}" is sold in multiples of {step}.', 'stackless-lite'), 'css' => 'min-width:400px'],
            ['type' => 'sectionend', 'id' => $p . 'display'],
        ];
    }

    public function boot(): void
    {
        // Core quantity filters: classic inputs, wc_get_quantity_input_args() and the Store API all read them.
        add_filter('woocommerce_quantity_input_min', [$this, 'filterMin'], 20, 2);
        add_filter('woocommerce_quantity_input_max', [$this, 'filterMax'], 20, 2);
        add_filter('woocommerce_quantity_input_step', [$this, 'filterStep'], 20, 2);

        // Store API (blocks) explicit limits, for products and cart items.
        add_filter('woocommerce_store_api_product_quantity_minimum', [$this, 'storeApiMinimum'], 20, 2);
        add_filter('woocommerce_store_api_product_quantity_maximum', [$this, 'storeApiMaximum'], 20, 2);
        add_filter('woocommerce_store_api_product_quantity_multiple_of', [$this, 'storeApiMultipleOf'], 20, 2);
        add_action('woocommerce_store_api_validate_cart_item', [$this, 'storeApiValidateCartItem'], 20, 2);
        add_filter('rest_request_before_callbacks', [$this, 'enterRoute'], 10, 3);
        add_filter('rest_request_after_callbacks', [$this, 'leaveRoute'], 10, 3);

        // Classic validation.
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAddToCart'], 20, 5);
        add_filter('woocommerce_update_cart_validation', [$this, 'validateCartUpdate'], 20, 4);
        add_action('woocommerce_check_cart_items', [$this, 'validateCart']);

        // Loop "Add to cart" sends the minimum, so AJAX add to cart passes.
        add_filter('woocommerce_loop_add_to_cart_args', [$this, 'filterLoopArgs'], 20, 2);
        add_filter('woocommerce_add_to_cart_quantity', [$this, 'filterDefaultAddQuantity'], 20, 3);

        // Variation data and single product note.
        add_filter('woocommerce_available_variation', [$this, 'filterVariationData'], 20, 3);
        add_action('woocommerce_after_add_to_cart_quantity', [$this, 'renderNote']);

        // Product editor.
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'renderProductFields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductFields']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'renderVariationFields'], 20, 3);
        add_action('woocommerce_admin_process_variation_object', [$this, 'saveVariationFields'], 20, 2);
    }

    /* ---------------------------------------------------------------------
     * Limits
     * ------------------------------------------------------------------- */

    /** @return array{min: int, max: int, step: int} */
    public function limits(\WC_Product $product): array
    {
        $id = $product->get_id();
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $levels = [];
        if ($product->is_type('variation')) {
            $levels[] = $this->productLevel($product);
            $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : null;
            if ($parent instanceof \WC_Product) {
                $levels[] = $this->productLevel($parent);
            }
        } else {
            $levels[] = $this->productLevel($product);
        }
        $levels[] = [
            'min' => Rules::parse($this->option('default_min', 1)),
            'max' => Rules::parse($this->option('default_max', 0)),
            'step' => Rules::parse($this->option('default_step', 1)),
        ];
        $limits = Rules::resolve($levels);
        if ($product->is_sold_individually()) {
            $limits = ['min' => 1, 'max' => 1, 'step' => 1];
        }
        /**
         * Filters the resolved quantity limits for a product or variation.
         *
         * @param array{min: int, max: int, step: int} $limits max 0 = no limit
         * @param \WC_Product                          $product
         */
        $limits = (array) apply_filters('stackless_quantity_limits_limits', $limits, $product);
        $limits = Rules::normalize((int) ($limits['min'] ?? 1), (int) ($limits['max'] ?? 0), (int) ($limits['step'] ?? 1));
        return $this->cache[$id] = $limits;
    }

    /** @return array{min: int|null, max: int|null, step: int|null} */
    private function productLevel(\WC_Product $product): array
    {
        return [
            'min' => Rules::parse($product->get_meta(self::META_MIN)),
            'max' => Rules::parse($product->get_meta(self::META_MAX)),
            'step' => Rules::parse($product->get_meta(self::META_STEP)),
        ];
    }

    /** Products we never limit: grouped containers and external links have no quantity of their own. */
    private function applies(\WC_Product $product): bool
    {
        return !$product->is_type('grouped') && !$product->is_type('external');
    }

    /**
     * @param int|float   $value
     * @param \WC_Product $product
     * @return int|float
     */
    public function filterMin($value, $product = null)
    {
        if (!$product instanceof \WC_Product || !$this->applies($product)) {
            return $value;
        }
        return $this->limits($product)['min'];
    }

    /**
     * @param int|float   $value
     * @param \WC_Product $product
     * @return int|float
     */
    public function filterMax($value, $product = null)
    {
        if (!$product instanceof \WC_Product || !$this->applies($product)) {
            return $value;
        }
        return Rules::combineMax($this->limits($product)['max'], $value);
    }

    /**
     * @param int|float   $value
     * @param \WC_Product $product
     * @return int|float
     */
    public function filterStep($value, $product = null)
    {
        if (!$product instanceof \WC_Product || !$this->applies($product)) {
            return $value;
        }
        return $this->limits($product)['step'];
    }

    /**
     * @param mixed       $value
     * @param \WC_Product $product
     * @return mixed
     */
    public function storeApiMinimum($value, $product)
    {
        return $product instanceof \WC_Product && $this->applies($product) ? max((float) $value, $this->limits($product)['min']) : $value;
    }

    /**
     * @param mixed       $value
     * @param \WC_Product $product
     * @return mixed
     */
    public function storeApiMaximum($value, $product)
    {
        if (!$product instanceof \WC_Product || !$this->applies($product)) {
            return $value;
        }
        $max = $this->limits($product)['max'];
        return $max > 0 && (!is_numeric($value) || (float) $value <= 0 || (float) $value > $max) ? $max : $value;
    }

    /**
     * @param mixed       $value
     * @param \WC_Product $product
     * @return mixed
     */
    public function storeApiMultipleOf($value, $product)
    {
        return $product instanceof \WC_Product && $this->applies($product) ? $this->limits($product)['step'] : $value;
    }

    /* ---------------------------------------------------------------------
     * Validation
     * ------------------------------------------------------------------- */

    private function isStoreApiRoute($request): bool
    {
        return $request instanceof \WP_REST_Request && strpos((string) $request->get_route(), '/wc/store') === 0;
    }

    /**
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public function enterRoute($response, $handler, $request)
    {
        if ($this->isStoreApiRoute($request)) {
            $this->storeApiDepth++;
        }
        return $response;
    }

    /**
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public function leaveRoute($response, $handler, $request)
    {
        if ($this->isStoreApiRoute($request) && $this->storeApiDepth > 0) {
            $this->storeApiDepth--;
        }
        return $response;
    }

    private function inStoreApi(): bool
    {
        return $this->storeApiDepth > 0 || (function_exists('WC') && WC()->is_store_api_request());
    }

    /** Error message for a quantity, or '' when it is valid. */
    public function error(\WC_Product $product, int $quantity): string
    {
        if (!$this->applies($product)) {
            return '';
        }
        $limits = $this->limits($product);
        $rule = Rules::check($quantity, $limits);
        if ($rule === '') {
            return '';
        }
        $defaults = [
            'min' => __('You need at least {min} of "{product}" in your cart.', 'stackless-lite'),
            'max' => __('You can buy at most {max} of "{product}".', 'stackless-lite'),
            'step' => __('"{product}" is sold in multiples of {step}.', 'stackless-lite'),
        ];
        $template = (string) $this->option($rule . '_error', $defaults[$rule]);
        if (trim($template) === '') {
            $template = $defaults[$rule];
        }
        return Rules::message($template, [
            'product' => wp_strip_all_tags($product->get_name()),
            'min' => $limits['min'],
            'max' => $limits['max'],
            'step' => $limits['step'],
            'quantity' => $quantity,
        ]);
    }

    /** Quantity of a product/variation already in the cart, optionally skipping one line. */
    private function quantityInCart(int $productId, int $variationId, string $skipKey = ''): int
    {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }
        $total = 0;
        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($key === $skipKey) {
                continue;
            }
            $itemVariation = (int) ($item['variation_id'] ?? 0);
            if ($variationId ? $itemVariation === $variationId : ((int) ($item['product_id'] ?? 0) === $productId && !$itemVariation)) {
                $total += (int) ($item['quantity'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * @param bool  $passed
     * @param int   $productId
     * @param int   $quantity
     * @param int   $variationId
     * @param array $variations
     * @return bool
     */
    public function validateAddToCart($passed, $productId, $quantity, $variationId = 0, $variations = [])
    {
        // The Store API normalizes new items to valid quantities after this filter runs and
        // validates top-ups itself through the quantity limits above.
        if (!$passed || $this->inStoreApi()) {
            return $passed;
        }
        $product = wc_get_product($variationId ? (int) $variationId : (int) $productId);
        if (!$product instanceof \WC_Product) {
            return $passed;
        }
        $total = (int) wc_stock_amount($quantity) + $this->quantityInCart((int) $productId, (int) $variationId);
        $message = $this->error($product, $total);
        if ($message !== '') {
            wc_add_notice(esc_html($message), 'error');
            return false;
        }
        return $passed;
    }

    /**
     * @param bool   $passed
     * @param string $cartItemKey
     * @param array  $values
     * @param int    $quantity
     * @return bool
     */
    public function validateCartUpdate($passed, $cartItemKey, $values, $quantity)
    {
        $quantity = (int) wc_stock_amount($quantity);
        if (!$passed || $quantity <= 0 || !is_array($values)) {
            return $passed;
        }
        $product = $values['data'] ?? null;
        if (!$product instanceof \WC_Product) {
            return $passed;
        }
        $total = $quantity + $this->quantityInCart((int) ($values['product_id'] ?? 0), (int) ($values['variation_id'] ?? 0), (string) $cartItemKey);
        $message = $this->error($product, $total);
        if ($message !== '') {
            wc_add_notice(esc_html($message), 'error');
            return false;
        }
        return $passed;
    }

    /** Classic cart and checkout: block checkout while any line breaks its limits. */
    public function validateCart(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $seen = [];
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $variationId = (int) ($item['variation_id'] ?? 0);
            $key = $productId . ':' . $variationId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $message = $this->error($product, $this->quantityInCart($productId, $variationId));
            if ($message !== '') {
                wc_add_notice(esc_html($message), 'error');
            }
        }
    }

    /**
     * Store API: refuse a cart that breaks the limits (the Cart/Checkout blocks show the message).
     *
     * @param \WC_Product $product
     * @param array       $cartItem
     */
    public function storeApiValidateCartItem($product, $cartItem): void
    {
        if (!$product instanceof \WC_Product || !is_array($cartItem)) {
            return;
        }
        $total = $this->quantityInCart((int) ($cartItem['product_id'] ?? 0), (int) ($cartItem['variation_id'] ?? 0));
        $message = $this->error($product, $total > 0 ? $total : (int) ($cartItem['quantity'] ?? 0));
        if ($message === '') {
            return;
        }
        if (class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('stackless_quantity_limits_invalid', esc_html($message), 400);
        }
        throw new \Exception(esc_html($message));
    }

    /**
     * @param array       $args
     * @param \WC_Product $product
     * @return array
     */
    public function filterLoopArgs($args, $product)
    {
        if (is_array($args) && $product instanceof \WC_Product && $this->applies($product) && $product->is_type('simple')) {
            $args['quantity'] = $this->limits($product)['min'];
        }
        return $args;
    }

    /**
     * Default quantity for links like ?add-to-cart=ID without a quantity.
     *
     * @param int|float $quantity
     * @param int       $productId
     * @param int       $variationId
     * @return int|float
     */
    public function filterDefaultAddQuantity($quantity, $productId, $variationId = 0)
    {
        $product = wc_get_product((int) $variationId ?: (int) $productId);
        if ($product instanceof \WC_Product && $this->applies($product) && (float) $quantity < $this->limits($product)['min']) {
            return $this->limits($product)['min'];
        }
        return $quantity;
    }

    /* ---------------------------------------------------------------------
     * Product page
     * ------------------------------------------------------------------- */

    /** @param array{min: int, max: int, step: int} $limits */
    private function noteText(array $limits): string
    {
        if ($this->option('show_note', 'yes') !== 'yes') {
            return '';
        }
        $parts = [];
        $vars = ['min' => $limits['min'], 'max' => $limits['max'], 'step' => $limits['step']];
        if ($limits['min'] > 1) {
            $parts[] = Rules::message((string) $this->option('min_note', __('Minimum {min}', 'stackless-lite')), $vars);
        }
        if ($limits['max'] > 0) {
            $parts[] = Rules::message((string) $this->option('max_note', __('Maximum {max}', 'stackless-lite')), $vars);
        }
        if ($limits['step'] > 1) {
            $parts[] = Rules::message((string) $this->option('step_note', __('Sold in packs of {step}', 'stackless-lite')), $vars);
        }
        return implode(' · ', array_filter(array_map('trim', $parts)));
    }

    /**
     * @param array                      $data
     * @param \WC_Product_Variable       $parent
     * @param \WC_Product_Variation|null $variation
     * @return array
     */
    public function filterVariationData($data, $parent, $variation)
    {
        if (!is_array($data) || !$variation instanceof \WC_Product) {
            return $data;
        }
        $limits = $this->limits($variation);
        $data['stackless_qty_step'] = $limits['step'];
        $data['stackless_qty_note'] = $this->noteText($limits);
        return $data;
    }

    public function renderNote(): void
    {
        global $product;
        if (!$product instanceof \WC_Product || !$this->applies($product) || $product->is_sold_individually()) {
            return;
        }
        $note = $product->is_type('variable') ? '' : $this->noteText($this->limits($product));
        if ($note === '' && !$product->is_type('variable')) {
            return;
        }
        if ($product->is_type('variable')) {
            wp_enqueue_script(self::HANDLE . '-variations', STACKLESS_PLUGIN_URL . 'assets/quantity_limits/variations.js', ['jquery', 'wc-add-to-cart-variation'], STACKLESS_VERSION, true);
        }
        printf('<p class="stackless-qty-limits"%s>%s</p>', $note === '' ? ' hidden' : '', esc_html($note));
        wp_register_style(self::HANDLE, false, [], STACKLESS_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, '.stackless-qty-limits{flex-basis:100%;margin:.4em 0 .8em;font-size:.875em;opacity:.8}');
    }

    /* ---------------------------------------------------------------------
     * Product editor
     * ------------------------------------------------------------------- */

    public function renderProductFields(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : null;
        echo '<div class="options_group show_if_simple show_if_variable">';
        echo '<p class="form-field"><strong>' . esc_html__('Quantity limits', 'stackless-lite') . '</strong> ' . wc_help_tip(__('Leave blank to use the store default. Variations can override these.', 'stackless-lite')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip escapes.
        $fields = [
            self::META_MIN => [__('Minimum quantity', 'stackless-lite'), (string) $this->option('default_min', 1), 1],
            self::META_MAX => [__('Maximum quantity', 'stackless-lite'), (string) $this->option('default_max', 0), 0],
            self::META_STEP => [__('Sold in multiples of', 'stackless-lite'), (string) $this->option('default_step', 1), 1],
        ];
        foreach ($fields as $key => $field) {
            woocommerce_wp_text_input([
                'id' => $key,
                'value' => $product ? (string) $product->get_meta($key) : '',
                'label' => $field[0],
                'type' => 'number',
                'placeholder' => $field[1],
                'custom_attributes' => ['min' => $field[2], 'step' => 1],
                'desc_tip' => $key === self::META_MAX,
                'description' => $key === self::META_MAX ? __('0 means no limit.', 'stackless-lite') : '',
            ]);
        }
        echo '</div>';
    }

    public function saveProductFields(\WC_Product $product): void
    {
        foreach ([self::META_MIN, self::META_MAX, self::META_STEP] as $key) {
            // Nonce and capability are checked by WooCommerce before this action runs.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $raw = isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
            $this->storeValue($product, $key, $raw);
        }
        unset($this->cache[$product->get_id()]);
    }

    private function storeValue(\WC_Product $product, string $key, string $raw): void
    {
        $value = Rules::parse($raw);
        if ($value === null) {
            $product->delete_meta_data($key);
            return;
        }
        if ($key !== self::META_MAX) {
            $value = max(1, $value);
        }
        $product->update_meta_data($key, (string) $value);
    }

    /** Variation inputs use their own names so they never collide with the product fields in the same form. */
    private static function variationField(string $metaKey): string
    {
        return 'stackless_quantity_limits_variation' . str_replace('_stackless_quantity_limits', '', $metaKey);
    }

    /**
     * @param int      $loop
     * @param array    $variationData
     * @param \WP_Post $variationPost
     */
    public function renderVariationFields($loop, $variationData, $variationPost): void
    {
        $variation = wc_get_product($variationPost->ID);
        if (!$variation instanceof \WC_Product) {
            return;
        }
        $labels = [
            self::META_MIN => __('Minimum quantity', 'stackless-lite'),
            self::META_MAX => __('Maximum quantity', 'stackless-lite'),
            self::META_STEP => __('Sold in multiples of', 'stackless-lite'),
        ];
        $classes = [self::META_MIN => 'form-row-first', self::META_MAX => 'form-row-last', self::META_STEP => 'form-row-first'];
        echo '<div class="stackless-variation-qty-limits"><p class="form-row form-row-full"><strong>' . esc_html__('Quantity limits', 'stackless-lite') . '</strong></p>';
        foreach ($labels as $key => $label) {
            $field = self::variationField($key);
            woocommerce_wp_text_input([
                'id' => $field . '_' . (int) $loop,
                'name' => $field . '[' . (int) $loop . ']',
                'value' => (string) $variation->get_meta($key),
                'label' => $label,
                'type' => 'number',
                'placeholder' => __('Product default', 'stackless-lite'),
                'custom_attributes' => ['min' => $key === self::META_MAX ? 0 : 1, 'step' => 1],
                'wrapper_class' => 'form-row ' . $classes[$key],
            ]);
        }
        echo '</div>';
    }

    /**
     * @param \WC_Product_Variation $variation
     * @param int                   $index
     */
    public function saveVariationFields($variation, $index): void
    {
        if (!$variation instanceof \WC_Product) {
            return;
        }
        foreach ([self::META_MIN, self::META_MAX, self::META_STEP] as $key) {
            $field = self::variationField($key);
            // WooCommerce checks the save-variations nonce and edit_products capability before this action.
            $raw = isset($_POST[$field][$index]) ? sanitize_text_field(wp_unslash((string) $_POST[$field][$index])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $this->storeValue($variation, $key, $raw);
        }
        unset($this->cache[$variation->get_id()]);
    }
}
