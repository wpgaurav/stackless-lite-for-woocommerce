<?php

namespace Stackless\Admin;

defined('ABSPATH') || exit;

/**
 * Links each module and screen to its page in the Stackless for WooCommerce documentation.
 * Unmapped keys fall back to the documentation home.
 */
final class Documentation
{
    public const DOCS = 'https://gauravtiwari.org/docs/stackless-for-woocommerce';
    public const CHANGELOG = 'https://gauravtiwari.org/changelogs/stackless-for-woocommerce/';

    /** Module id (or screen key) => section/page. */
    private const PAGES = [
        'dashboard' => 'getting-started/dashboard-and-modules',
        'stripe_gateway' => 'payments/stripe',
        'paypal_gateway' => 'payments/paypal',
        'polar_gateway' => 'payments/polar',
        'dodo_gateway' => 'payments/dodo-payments',
        'razorpay_gateway' => 'payments/razorpay',
        'mollie_gateway' => 'payments/mollie',
        'square_gateway' => 'payments/square',
        'paystack_gateway' => 'payments/paystack',
        'flutterwave_gateway' => 'payments/flutterwave',
        'mercadopago_gateway' => 'payments/mercado-pago',
        'creem_gateway' => 'payments/creem',
        'gateway_tools' => 'payments/payment-gateway-tools',
        'checkout_enhancements' => 'checkout-and-sales/checkout-enhancements',
        'extra_enhancements' => 'checkout-and-sales/extra-enhancements',
        'buy_links' => 'checkout-and-sales/buy-links',
        'pay_what_you_like' => 'checkout-and-sales/pay-what-you-like',
        'free_price_text' => 'catalog/free-price-text',
        'sold_count' => 'catalog/sold-count',
        'stock_urgency' => 'catalog/stock-urgency',
        'quantity_limits' => 'catalog/quantity-limits',
        'hide_price_until_login' => 'catalog/hide-price-until-login',
        'custom_cart_text' => 'catalog/custom-add-to-cart-text',
        'external_product' => 'catalog/external-products',
        'product_enhancements' => 'catalog/product-details',
        'wishlist' => 'store-tools/wishlist',
        'back_in_stock' => 'store-tools/back-in-stock',
        'saved_carts' => 'store-tools/saved-carts',
        'service_products' => 'store-tools/service-products',
        'woocommerce_optimizer' => 'store-tools/woocommerce-optimizer',
        'troubleshooting' => 'developer-reference/troubleshooting',
    ];

    /** Module id => its step-by-step guide, linked from the product edit page. */
    private const GUIDES = [
        'service_products' => 'guides/sell-productized-service',
        'polar_gateway' => 'guides/sell-digital-products-without-sales-tax',
    ];

    public static function url(string $key = ''): string
    {
        $page = self::PAGES[$key] ?? '';
        $url = self::DOCS . '/' . ($page === '' ? '' : $page . '/');
        return (string) apply_filters('stackless_documentation_url', $url, $key);
    }

    /**
     * An escaped link that opens the page in a new tab.
     *
     * @param string $class Extra CSS classes.
     */
    public static function link(string $key, string $label = '', string $class = ''): string
    {
        return self::anchor(self::url($key), $label !== '' ? $label : __('Documentation', 'stackless-lite'), $class);
    }

    /**
     * Help for a feature set on the product edit page: its docs page, any further pages, and
     * the step-by-step guide when the docs have one. Escaped HTML.
     *
     * @param array<string,string> $more Further docs pages: key => link text.
     */
    public static function helpLinks(string $key, string $label = '', array $more = []): string
    {
        $links = [self::link($key, $label !== '' ? $label : __('How this works', 'stackless-lite'))];
        foreach ($more as $moreKey => $moreLabel) {
            $links[] = self::link((string) $moreKey, $moreLabel);
        }
        if (isset(self::GUIDES[$key])) {
            $url = (string) apply_filters('stackless_documentation_url', self::DOCS . '/' . self::GUIDES[$key] . '/', $key . '_guide');
            $links[] = self::anchor($url, __('Step-by-step guide', 'stackless-lite'));
        }
        return implode('<span class="stackless-docs-sep" aria-hidden="true">·</span>', $links);
    }

    /**
     * helpLinks() as a row of a WooCommerce product data panel.
     *
     * @param array<string,string> $more Further docs pages: key => link text.
     */
    public static function helpField(string $key, string $label = '', array $more = []): string
    {
        return '<p class="form-field stackless-docs-field">' . self::helpLinks($key, $label, $more) . '</p>';
    }

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'productScreen'], 20);
    }

    /**
     * On the product edit screen: styles the help rows, and shows a row marked
     * data-stackless-show-if="<checkbox id>" only while that product option is ticked.
     */
    public static function productScreen(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') {
            return;
        }
        if (wp_style_is('woocommerce_admin_styles', 'enqueued')) {
            wp_add_inline_style('woocommerce_admin_styles', '.stackless-docs-field .stackless-docs-link{text-decoration:none;white-space:nowrap}.stackless-docs-field .stackless-docs-link:is(:hover,:focus){text-decoration:underline}.stackless-docs-field .stackless-docs-link::after{content:"\\f504";font:normal 14px/1 dashicons;vertical-align:-2px;margin-left:2px}.stackless-docs-field .stackless-docs-sep{color:#8c8f94;margin:0 8px}');
        }
        if (wp_script_is('wc-admin-product-meta-boxes', 'enqueued')) {
            wp_add_inline_script('wc-admin-product-meta-boxes', 'jQuery(function($){$("[data-stackless-show-if]").each(function(){var row=$(this),box=$("#"+row.attr("data-stackless-show-if")),sync=function(){row.toggle(box.is(":checked"));};box.on("change",sync);sync();});});');
        }
    }

    private static function anchor(string $url, string $label, string $class = ''): string
    {
        return sprintf(
            '<a href="%s" class="stackless-docs-link%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
            esc_url($url),
            $class !== '' ? ' ' . esc_attr($class) : '',
            esc_html($label),
            esc_html__('(opens in a new tab)', 'stackless-lite')
        );
    }
}
