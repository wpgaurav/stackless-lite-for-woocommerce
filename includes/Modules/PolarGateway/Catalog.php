<?php

namespace Stackless\Modules\PolarGateway;

defined('ABSPATH') || exit;

/**
 * Maps WooCommerce purchases onto Polar products.
 *
 * A Polar checkout sells exactly one product and takes its price per checkout
 * (an ad-hoc price), so WooCommerce stays the source of truth for amounts.
 * Each product or variation can be linked to a Polar product by hand (or with
 * "Create in Polar"); otherwise one private Polar product per variation and
 * billing schedule is created on first use and remembered per environment,
 * plus one shared product for multi-item carts.
 */
final class Catalog
{
    public const OPTION = 'stackless_polar_gateway_products';
    public const META_PREFIX = '_stackless_polar_product_';
    public const NONCE = 'stackless-polar-gateway-catalog';

    /** Billing choices offered by the "Create in Polar" helper. */
    public const BILLING_CHOICES = ['once', 'day:1', 'week:1', 'month:1', 'month:3', 'month:6', 'year:1'];

    /** Map key: "{mode}|{once|interval:count}|{v<variation>|p<product>|cart|t<hash>}". */
    public static function key(array $item, ?array $recurring, string $mode): string
    {
        $billing = $recurring ? $recurring[0] . ':' . (int) $recurring[1] : 'once';
        if (!empty($item['variation_id'])) {
            $target = 'v' . (int) $item['variation_id'];
        } elseif (!empty($item['product_id'])) {
            $target = 'p' . (int) $item['product_id'];
        } elseif (!empty($item['cart'])) {
            $target = 'cart';
        } else {
            $target = 't' . substr(md5((string) ($item['title'] ?? '')), 0, 12);
        }
        return $mode . '|' . $billing . '|' . $target;
    }

    /** One order item becomes its own product; several share a store product. */
    public static function itemForOrder(\WC_Order $order, ?\WC_Order $subscription = null): array
    {
        $source = $subscription ?: $order;
        $items = array_values(array_filter($source->get_items(), static function ($item) {
            return $item instanceof \WC_Order_Item_Product;
        }));
        if (count($items) === 1) {
            /** @var \WC_Order_Item_Product $line */
            $line = $items[0];
            return [
                'title' => (string) $line->get_name(),
                'product_id' => (int) $line->get_product_id(),
                'variation_id' => (int) $line->get_variation_id(),
            ];
        }
        $store = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return [
            /* translators: %s: store name */
            'title' => sprintf(__('%s order', 'stackless-lite'), $store),
            'product_id' => 0,
            'variation_id' => 0,
            'cart' => true,
        ];
    }

    /** Product map of one gateway: each copy is its own Polar organization. */
    public static function option(Gateway $gateway): string
    {
        return self::OPTION . $gateway->suffix();
    }

    private static function map(Gateway $gateway): array
    {
        $map = get_option(self::option($gateway), []);
        return is_array($map) ? $map : [];
    }

    public static function forget(Gateway $gateway, array $item, ?array $recurring, string $mode): void
    {
        $map = self::map($gateway);
        unset($map[self::key($item, $recurring, $mode)]);
        update_option(self::option($gateway), $map, false);
    }

    public static function forgetMode(Gateway $gateway, string $mode): void
    {
        $map = array_filter(self::map($gateway), static function ($key) use ($mode) {
            return strpos((string) $key, $mode . '|') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        update_option(self::option($gateway), $map, false);
    }

    /**
     * Polar product for a checkout: the linked one when it fits, else the auto-created one.
     *
     * @param array|null $defaultPrice ['currency' => lowercase, 'amount' => minor] when Polar also needs a default-currency price.
     * @return string|\WP_Error
     */
    public static function productId(Gateway $gateway, array $item, ?array $recurring, string $currency, int $minor, string $mode, bool $refresh = false, ?array $defaultPrice = null)
    {
        // Products linked by hand in the product editor belong to the original gateway's organization.
        if (!$refresh && $gateway->isOriginal()) {
            $linked = self::linkedProduct($item, $mode);
            if ($linked !== '' && self::linkedFits($gateway, $linked, $recurring, $mode)) {
                return $linked;
            }
        }

        $key = self::key($item, $recurring, $mode);
        $map = self::map($gateway);
        if (!$refresh && !empty($map[$key])) {
            return (string) $map[$key];
        }

        $product = self::create($gateway, $item, $recurring, $currency, $minor, $mode, $defaultPrice);
        if (is_wp_error($product)) {
            return $product;
        }
        $map[$key] = (string) $product['id'];
        update_option(self::option($gateway), $map, false);
        return (string) $product['id'];
    }

    /** Polar product id set by hand on the variation, else on its parent product. */
    public static function linkedProduct(array $item, string $mode): string
    {
        foreach ([(int) ($item['variation_id'] ?? 0), (int) ($item['product_id'] ?? 0)] as $id) {
            if (!$id) {
                continue;
            }
            $product = wc_get_product($id);
            if ($product instanceof \WC_Product) {
                $value = trim((string) $product->get_meta(self::META_PREFIX . $mode));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    /** A linked product must exist, be active, and bill on the same schedule. Cached for an hour. */
    private static function linkedFits(Gateway $gateway, string $productId, ?array $recurring, string $mode): bool
    {
        $cacheKey = 'stackless_polar_gateway_pv_' . md5($mode . '|' . $productId);
        $cached = get_transient($cacheKey);
        if (!is_array($cached)) {
            $remote = $gateway->api($mode)->request('GET', 'products/' . rawurlencode($productId));
            if (is_wp_error($remote)) {
                $gateway->log('Linked Polar product ' . $productId . ' could not be read: ' . $remote->get_error_message(), 'warning');
                return false;
            }
            $cached = [
                'archived' => !empty($remote['is_archived']),
                'interval' => (string) ($remote['recurring_interval'] ?? ''),
                'count' => max(1, (int) ($remote['recurring_interval_count'] ?? 1)),
            ];
            set_transient($cacheKey, $cached, HOUR_IN_SECONDS);
        }
        if ($cached['archived']) {
            return false;
        }
        if (!$recurring) {
            return $cached['interval'] === '';
        }
        $fits = $cached['interval'] === $recurring[0] && (int) $cached['count'] === (int) $recurring[1];
        if (!$fits) {
            $gateway->log('Linked Polar product ' . $productId . ' bills every ' . $cached['count'] . ' ' . $cached['interval'] . ', not ' . $recurring[1] . ' ' . $recurring[0] . '; using an automatic product.', 'warning');
        }
        return $fits;
    }

    /** @return array|\WP_Error */
    public static function create(Gateway $gateway, array $item, ?array $recurring, string $currency, int $minor, string $mode, ?array $defaultPrice = null)
    {
        $currency = strtolower($currency);
        $prices = [[
            'amount_type' => 'fixed',
            'price_amount' => max(0, $minor),
            'price_currency' => $currency,
        ]];
        // Polar requires a price in the organization's default currency. It is never
        // charged: every checkout supplies its own prices.
        if ($defaultPrice && $defaultPrice['currency'] !== $currency) {
            $prices[] = [
                'amount_type' => 'fixed',
                'price_amount' => max(0, (int) $defaultPrice['amount']),
                'price_currency' => $defaultPrice['currency'],
            ];
        }

        $body = [
            'name' => self::name((string) ($item['title'] ?? '')),
            'visibility' => 'private',
            'prices' => $prices,
            'metadata' => array_filter([
                'source' => 'stackless',
                'wc_product_id' => (int) ($item['product_id'] ?? 0),
                'wc_variation_id' => (int) ($item['variation_id'] ?? 0),
                'site' => self::siteHost(),
            ]),
        ];
        if ($recurring) {
            $body['recurring_interval'] = $recurring[0];
            $body['recurring_interval_count'] = (int) $recurring[1];
        }

        $body = (array) apply_filters('stackless_polar_gateway_product_args', $body, $item, $recurring, $mode);
        $product = $gateway->api($mode)->request('POST', 'products/', $body);
        if (!is_wp_error($product) && empty($product['id'])) {
            return new \WP_Error('stackless_polar_product', __('Polar did not return a product.', 'stackless-lite'));
        }
        if (!is_wp_error($product)) {
            $gateway->log('Created Polar product ' . $product['id'] . ' (' . self::key($item, $recurring, $mode) . ').');
        }
        return $product;
    }

    /** Polar product names must be 3 to 64 characters. */
    public static function name(string $title): string
    {
        $title = trim(wp_strip_all_tags(html_entity_decode($title, ENT_QUOTES, 'UTF-8')));
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($length < 3) {
            $title = trim($title . ' ' . __('purchase', 'stackless-lite'));
        }
        if (function_exists('mb_substr')) {
            return mb_strlen($title) > 64 ? rtrim(mb_substr($title, 0, 63)) . '…' : $title;
        }
        return strlen($title) > 64 ? rtrim(substr($title, 0, 61)) . '...' : $title;
    }

    public static function siteHost(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /** "month:3" → ['month', 3]; "once" → null. */
    public static function parseBilling(string $choice): ?array
    {
        if ($choice === 'once' || strpos($choice, ':') === false) {
            return null;
        }
        [$period, $count] = explode(':', $choice, 2);
        return EventMap::interval($period, (int) $count);
    }

    /* ---- Product editor ------------------------------------------------------------ */

    public static function registerAdmin(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderProductFields']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'saveProduct']);
        add_action('woocommerce_product_after_variable_attributes', [self::class, 'renderVariationFields'], 20, 3);
        add_action('woocommerce_admin_process_variation_object', [self::class, 'saveVariation'], 10, 2);
        add_action('wp_ajax_stackless_polar_create_product', [self::class, 'ajaxCreate']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$screen || $screen->post_type !== 'product') {
            return;
        }
        wp_enqueue_script('stackless-polar_gateway-admin', STACKLESS_PLUGIN_URL . 'assets/polar_gateway/admin.js', ['jquery'], STACKLESS_VERSION, true);
        wp_localize_script('stackless-polar_gateway-admin', 'stacklessPolarAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'i18n' => [
                'creating' => __('Creating in Polar…', 'stackless-lite'),
                'created' => __('Created. Save the product to keep the link.', 'stackless-lite'),
                'failed' => __('Polar could not create the product.', 'stackless-lite'),
                'saveFirst' => __('Save the product first so it has a price.', 'stackless-lite'),
            ],
        ]);
    }

    private static function billingOptions(): array
    {
        return [
            'once' => __('One-time', 'stackless-lite'),
            'day:1' => __('Daily', 'stackless-lite'),
            'week:1' => __('Weekly', 'stackless-lite'),
            'month:1' => __('Monthly', 'stackless-lite'),
            'month:3' => __('Every 3 months', 'stackless-lite'),
            'month:6' => __('Every 6 months', 'stackless-lite'),
            'year:1' => __('Yearly', 'stackless-lite'),
        ];
    }

    private static function fieldsHtml(\WC_Product $product, string $nameSuffix, string $wrapperClass): void
    {
        $id = $product->get_id();
        $default = stackless_is_subscription_product($product) ? 'month:1' : 'once';
        echo '<div class="stackless-polar-product ' . esc_attr($wrapperClass) . '" data-product-id="' . esc_attr((string) $id) . '">';
        foreach (['live' => __('Polar product (live)', 'stackless-lite'), 'sandbox' => __('Polar product (sandbox)', 'stackless-lite')] as $mode => $label) {
            echo '<p class="form-field form-row">';
            echo '<label for="stackless_polar_product_' . esc_attr($mode . '_' . $id) . '">' . esc_html($label) . '</label>';
            echo '<input type="text" class="short stackless-polar-product__id" data-mode="' . esc_attr($mode) . '" id="stackless_polar_product_' . esc_attr($mode . '_' . $id) . '" name="stackless_polar_product_' . esc_attr($mode) . esc_attr($nameSuffix) . '" value="' . esc_attr((string) $product->get_meta(self::META_PREFIX . $mode)) . '" placeholder="' . esc_attr__('Automatic', 'stackless-lite') . '" pattern="[0-9a-fA-F-]{36}" /> ';
            echo '<button type="button" class="button stackless-polar-product__create" data-mode="' . esc_attr($mode) . '">' . esc_html__('Create in Polar', 'stackless-lite') . '</button>';
            echo '</p>';
        }
        echo '<p class="form-field form-row"><label>' . esc_html__('Polar billing', 'stackless-lite') . '</label><select class="stackless-polar-product__billing">';
        foreach (self::billingOptions() as $value => $text) {
            echo '<option value="' . esc_attr($value) . '"' . selected($value, $default, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select> <span class="description stackless-polar-product__status"></span></p>';
        echo '<p class="form-field form-row description">' . esc_html__('Leave empty and Polar products are created automatically on first sale. A linked product must use the same billing schedule as this product; prices always come from WooCommerce.', 'stackless-lite') . '</p>';
        echo '</div>';
    }

    public static function renderProductFields(): void
    {
        global $product_object;
        if (!$product_object instanceof \WC_Product || $product_object->is_type('variable') || $product_object->is_type('grouped') || $product_object->is_type('external')) {
            return;
        }
        echo '<div class="options_group show_if_simple">';
        self::fieldsHtml($product_object, '', '');
        echo '</div>';
    }

    public static function renderVariationFields($loop, $variationData, $variation): void
    {
        $product = wc_get_product($variation instanceof \WP_Post ? $variation->ID : (int) $variation);
        if ($product instanceof \WC_Product) {
            self::fieldsHtml($product, '[' . (int) $loop . ']', 'form-row-full');
        }
    }

    private static function clean($value): string
    {
        $value = trim(sanitize_text_field((string) $value));
        return preg_match('/^[0-9a-fA-F-]{36}$/', $value) ? strtolower($value) : '';
    }

    public static function saveProduct($product): void
    {
        if (!$product instanceof \WC_Product || !current_user_can('edit_products')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product save nonce.
        foreach (['live', 'sandbox'] as $mode) {
            $field = 'stackless_polar_product_' . $mode;
            if (isset($_POST[$field]) && !is_array($_POST[$field])) {
                $product->update_meta_data(self::META_PREFIX . $mode, self::clean(sanitize_text_field(wp_unslash($_POST[$field]))));
            }
        }
        // phpcs:enable
    }

    public static function saveVariation($variation, $index): void
    {
        if (!$variation instanceof \WC_Product || !current_user_can('edit_products')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the variations save nonce.
        foreach (['live', 'sandbox'] as $mode) {
            $field = 'stackless_polar_product_' . $mode;
            if (isset($_POST[$field][$index])) {
                $variation->update_meta_data(self::META_PREFIX . $mode, self::clean(sanitize_text_field(wp_unslash($_POST[$field][$index]))));
            }
        }
        // phpcs:enable
    }

    /** "Create in Polar": a private Polar product at the current WooCommerce price. */
    public static function ajaxCreate(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('You are not allowed to edit products.', 'stackless-lite')], 403);
        }
        $productId = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $mode = isset($_POST['mode']) && $_POST['mode'] === 'sandbox' ? 'sandbox' : 'live';
        $billing = isset($_POST['billing']) ? sanitize_text_field(wp_unslash($_POST['billing'])) : 'once';
        if (!in_array($billing, self::BILLING_CHOICES, true)) {
            $billing = 'once';
        }

        $product = $productId ? wc_get_product($productId) : false;
        if (!$product instanceof \WC_Product || !current_user_can('edit_post', $product->get_parent_id() ?: $product->get_id())) {
            wp_send_json_error(['message' => __('Product not found.', 'stackless-lite')], 404);
        }
        $price = (float) $product->get_price('edit');
        if ($price <= 0) {
            wp_send_json_error(['message' => __('Give the product a price above zero first.', 'stackless-lite')], 400);
        }

        $gateway = Gateway::instance();
        if ($gateway->accessToken($mode) === '') {
            wp_send_json_error(['message' => __('Add the Polar access token for this environment first.', 'stackless-lite')], 400);
        }
        $currency = strtolower(stackless_base_currency());
        if (!Money::supported($currency)) {
            wp_send_json_error(['message' => __('Polar does not support the store currency.', 'stackless-lite')], 400);
        }
        $default = $gateway->defaultCurrency($mode);
        $defaultPrice = $default !== '' && $default !== $currency
            ? ['currency' => $default, 'amount' => Money::toMinor(Checkout::convert($price, $currency, $default, null), $default)]
            : null;

        $title = $product->get_name();
        $item = [
            'title' => $title,
            'product_id' => $product->get_parent_id() ?: $product->get_id(),
            'variation_id' => $product->get_parent_id() ? $product->get_id() : 0,
        ];
        $created = self::create($gateway, $item, self::parseBilling($billing), $currency, Money::toMinor($price, $currency), $mode, $defaultPrice);
        if (is_wp_error($created)) {
            wp_send_json_error(['message' => $created->get_error_message()], 400);
        }
        $product->update_meta_data(self::META_PREFIX . $mode, (string) $created['id']);
        $product->save();
        wp_send_json_success(['id' => (string) $created['id']]);
    }
}
