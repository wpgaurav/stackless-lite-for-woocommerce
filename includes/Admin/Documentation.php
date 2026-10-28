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

    /** Module id (or screen key) => section/page. */
    private const PAGES = [
        'dashboard' => 'getting-started/dashboard-and-modules',
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
        $label = $label !== '' ? $label : __('Documentation', 'stackless-lite');
        return sprintf(
            '<a href="%s" class="stackless-docs-link%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
            esc_url(self::url($key)),
            $class !== '' ? ' ' . esc_attr($class) : '',
            esc_html($label),
            esc_html__('(opens in a new tab)', 'stackless-lite')
        );
    }
}
