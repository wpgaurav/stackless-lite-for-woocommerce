<?php

namespace Stackless\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Per-product checkout options, set in Product data → Checkout: which payment methods
 * may sell the product, and a link buyers go to after paying (any page on this site or
 * another website) instead of the order-received page. Variations follow their product.
 *
 * A cart with several restricted products offers only the methods they all allow.
 * Renewals always keep the order-received page.
 */
final class ProductCheckout
{
    public const METHODS_META = '_stackless_extra_enhancements_methods';
    public const THANK_YOU_META = '_stackless_extra_enhancements_thank_you_url';

    /** @var string[] Hosts of after-purchase links used in this request, for wp_safe_redirect(). */
    private static $hosts = [];

    public function register(): void
    {
        add_filter('woocommerce_available_payment_gateways', [$this, 'filterAvailable'], 150);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassic'], 30, 2);
        add_action('woocommerce_checkout_validate_order_before_payment', [$this, 'validateStoreApi'], 30, 2);
        add_filter('woocommerce_get_return_url', [$this, 'returnUrl'], 20, 2);
        add_filter('allowed_redirect_hosts', [$this, 'allowedHosts']);
        if (is_admin()) {
            add_filter('woocommerce_product_data_tabs', [$this, 'tab']);
            add_action('woocommerce_product_data_panels', [$this, 'panel']);
            add_action('woocommerce_admin_process_product_object', [$this, 'save']);
        }
    }

    /* ---- Storage ----------------------------------------------------------------------- */

    /** The product that holds the settings: a variation's parent. */
    private static function owner(int $productId): ?\WC_Product
    {
        $product = $productId ? wc_get_product($productId) : null;
        if ($product instanceof \WC_Product && $product->get_parent_id()) {
            $product = wc_get_product($product->get_parent_id());
        }
        return $product instanceof \WC_Product ? $product : null;
    }

    /** @return string[] */
    public static function allowedMethods(int $productId): array
    {
        $product = self::owner($productId);
        $saved = $product ? $product->get_meta(self::METHODS_META) : [];
        return is_array($saved) ? array_values(array_filter(array_map('sanitize_key', $saved))) : [];
    }

    public static function thankYouUrl(int $productId): string
    {
        $product = self::owner($productId);
        return $product ? esc_url_raw((string) $product->get_meta(self::THANK_YOU_META)) : '';
    }

    /**
     * Methods every restricted product allows, or null when no product restricts them.
     *
     * @param int[] $productIds
     * @param callable(int):string[]|null $lookup
     * @return string[]|null
     */
    public static function intersect(array $productIds, ?callable $lookup = null): ?array
    {
        $lookup = $lookup ?: [self::class, 'allowedMethods'];
        $allowed = null;
        foreach (array_unique(array_map('intval', $productIds)) as $id) {
            $routes = $id ? (array) $lookup($id) : [];
            if (!$routes) {
                continue;
            }
            $allowed = $allowed === null ? array_values($routes) : array_values(array_intersect($allowed, $routes));
        }
        return $allowed;
    }

    /** @return int[] */
    private static function cartProductIds(): array
    {
        $ids = [];
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $ids[] = (int) ($item['product_id'] ?? 0);
            }
        }
        return $ids;
    }

    /** @return int[] */
    private static function orderProductIds(\WC_Order $order): array
    {
        $ids = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $ids[] = (int) $item->get_product_id();
            }
        }
        return $ids;
    }

    /** Restriction for what is being paid: the order on the pay page, else the cart. @return string[]|null */
    private static function currentAllowed(): ?array
    {
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            $order = wc_get_order(absint(get_query_var('order-pay')));
            return $order instanceof \WC_Order ? self::intersect(self::orderProductIds($order)) : null;
        }
        return self::intersect(self::cartProductIds());
    }

    /* ---- Checkout ---------------------------------------------------------------------- */

    /** @param mixed $gateways */
    public function filterAvailable($gateways)
    {
        if (!is_array($gateways) || !$gateways || is_admin() && !wp_doing_ajax()) {
            return $gateways;
        }
        $allowed = self::currentAllowed();
        if ($allowed === null) {
            return $gateways;
        }
        // An empty list makes WooCommerce say no payment method can take the order.
        return array_intersect_key($gateways, array_flip($allowed));
    }

    private static function refusal(string $method, ?array $allowed): ?string
    {
        if ($allowed === null || $method === '' || in_array($method, $allowed, true)) {
            return null;
        }
        return __('The selected payment method is not available for a product in your cart. Choose another payment method.', 'stackless-lite');
    }

    /**
     * @param mixed $data
     * @param mixed $errors
     */
    public function validateClassic($data, $errors): void
    {
        if (!$errors instanceof \WP_Error || !is_array($data) || !function_exists('WC') || !WC()->cart || !WC()->cart->needs_payment()) {
            return;
        }
        $message = self::refusal(sanitize_key((string) ($data['payment_method'] ?? '')), self::intersect(self::cartProductIds()));
        if ($message !== null) {
            $errors->add('stackless_product_payment_method', $message);
        }
    }

    /**
     * @param mixed $order
     * @param mixed $errors
     */
    public function validateStoreApi($order, $errors): void
    {
        if (!$order instanceof \WC_Order || !$errors instanceof \WP_Error || !$order->needs_payment()) {
            return;
        }
        $message = self::refusal((string) $order->get_payment_method(), self::intersect(self::orderProductIds($order)));
        if ($message !== null) {
            $errors->add('stackless_product_payment_method', $message);
        }
    }

    /**
     * After paying, send buyers to the first product's own link. Only paid orders go there:
     * a payment still open (a hosted checkout the buyer left, BACS or cheque awaiting the
     * transfer) keeps the order-received page, which shows its state and payment details.
     * Renewals keep the receipt too.
     *
     * @param mixed $url
     * @param mixed $order
     */
    public function returnUrl($url, $order)
    {
        if (!$order instanceof \WC_Order || $order->get_type() !== 'shop_order' || !$order->is_paid() || stackless_is_renewal_order($order)) {
            return $url;
        }
        foreach (self::orderProductIds($order) as $id) {
            $link = self::thankYouUrl($id);
            if ($link !== '') {
                $host = strtolower((string) wp_parse_url($link, PHP_URL_HOST));
                if ($host !== '') {
                    self::$hosts[] = $host;
                }
                return $link;
            }
        }
        return $url;
    }

    /** @param mixed $hosts */
    public function allowedHosts($hosts)
    {
        return array_values(array_unique(array_merge((array) $hosts, self::$hosts)));
    }

    /* ---- Product editor ---------------------------------------------------------------- */

    /** @param mixed $tabs */
    public function tab($tabs)
    {
        $tabs = is_array($tabs) ? $tabs : [];
        $tabs['stackless_checkout'] = [
            'label' => __('Checkout', 'stackless-lite'),
            'target' => 'stackless_checkout_options',
            'class' => ['hide_if_grouped', 'hide_if_external'],
            'priority' => 75,
        ];
        return $tabs;
    }

    /** Gateways to offer: switched on, or already chosen for this product. @return array<string,array{title:string,enabled:bool}> */
    private static function gateways(array $chosen): array
    {
        $list = [];
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return $list;
        }
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            if (!$gateway instanceof \WC_Payment_Gateway) {
                continue;
            }
            $enabled = $gateway->enabled === 'yes';
            if ($enabled || in_array((string) $id, $chosen, true)) {
                $list[(string) $id] = ['title' => wp_strip_all_tags((string) ($gateway->get_method_title() ?: $gateway->get_title() ?: $id)), 'enabled' => $enabled];
            }
        }
        return $list;
    }

    public function panel(): void
    {
        global $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : null;
        $chosen = $product ? self::allowedMethods($product->get_id()) : [];
        echo '<div id="stackless_checkout_options" class="panel woocommerce_options_panel hidden"><div class="options_group">';
        echo '<fieldset class="form-field"><legend>' . esc_html__('Payment methods', 'stackless-lite') . '</legend><ul class="wc-radios">';
        foreach (self::gateways($chosen) as $id => $gateway) {
            echo '<li><label><input type="checkbox" name="stackless_checkout_methods[]" value="' . esc_attr($id) . '" ' . checked(in_array($id, $chosen, true), true, false) . '> ' . esc_html($gateway['title']) . ($gateway['enabled'] ? '' : ' <em>(' . esc_html__('off', 'stackless-lite') . ')</em>') . '</label></li>';
        }
        echo '</ul></fieldset>';
        echo '<p class="form-field description" style="margin-top:0">' . esc_html__('Leave all unticked to allow every payment method. If a cart has products with different choices, buyers see only the methods they all allow.', 'stackless-lite') . '</p>';
        woocommerce_wp_text_input([
            'id' => 'stackless_checkout_thank_you_url',
            'label' => __('After payment, send buyers to', 'stackless-lite'),
            'value' => $product ? (string) $product->get_meta(self::THANK_YOU_META) : '',
            'placeholder' => '/welcome/ or https://…',
            'desc_tip' => true,
            'description' => __('Any page on this site (/welcome/) or another website (https://…). Leave empty to show the order-received page. Renewals always show the order-received page.', 'stackless-lite'),
        ]);
        wp_nonce_field('stackless_checkout_options', 'stackless_checkout_options_nonce');
        echo '</div></div>';
    }

    /** "/welcome/" → this site's address; anything that is not a web address → ''. */
    public static function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = home_url($url);
        }
        $url = esc_url_raw($url, ['http', 'https']);
        return $url !== '' && wp_http_validate_url($url) ? $url : '';
    }

    /** @param mixed $product */
    public function save($product): void
    {
        if (!$product instanceof \WC_Product || !current_user_can('edit_products')
            || !isset($_POST['stackless_checkout_options_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['stackless_checkout_options_nonce'])), 'stackless_checkout_options')) {
            return;
        }
        $known = array_keys(self::gateways(self::allowedMethods($product->get_id())));
        $posted = isset($_POST['stackless_checkout_methods']) && is_array($_POST['stackless_checkout_methods']) ? array_map('sanitize_key', wp_unslash($_POST['stackless_checkout_methods'])) : [];
        $methods = array_values(array_intersect($posted, $known));
        $methods ? $product->update_meta_data(self::METHODS_META, $methods) : $product->delete_meta_data(self::METHODS_META);

        $raw = isset($_POST['stackless_checkout_thank_you_url']) ? sanitize_text_field(wp_unslash($_POST['stackless_checkout_thank_you_url'])) : '';
        $url = self::cleanUrl($raw);
        if ($raw !== '' && $url === '' && class_exists(\WC_Admin_Meta_Boxes::class)) {
            \WC_Admin_Meta_Boxes::add_error(__('After-payment link: enter a full web address starting with https://, or a path on this site starting with /.', 'stackless-lite'));
            return;
        }
        $url !== '' ? $product->update_meta_data(self::THANK_YOU_META, $url) : $product->delete_meta_data(self::THANK_YOU_META);
    }
}
