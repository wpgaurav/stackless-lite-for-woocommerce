<?php

namespace Stackless\Admin;

defined('ABSPATH') || exit;

/** Local Tabler icons (assets/icons/*.svg), inlined so they inherit currentColor. */
final class Icons
{
    /** @var array<string,string> */
    private static $cache = [];

    /** Module id => icon. Modules not listed fall back to their group icon. */
    public const MODULES = [
        'checkout_enhancements' => 'checkbox',
        'extra_enhancements' => 'wand',
        'buy_links' => 'link',
        'pay_what_you_like' => 'pig-money',
        'free_price_text' => 'tag',
        'sold_count' => 'flame',
        'stock_urgency' => 'alert-triangle',
        'quantity_limits' => 'adjustments-horizontal',
        'hide_price_until_login' => 'eye-off',
        'custom_cart_text' => 'cursor-text',
        'external_product' => 'external-link',
        'product_enhancements' => 'list-details',
        'wishlist' => 'heart',
        'back_in_stock' => 'bell-ringing',
        'saved_carts' => 'device-floppy',
        'service_products' => 'tools',
        'woocommerce_optimizer' => 'rocket',
    ];

    public const GROUPS = [
        'subscriptions' => 'repeat',
        'payments' => 'credit-card',
        'checkout' => 'shopping-cart-plus',
        'sales' => 'receipt',
        'currency' => 'world',
        'access' => 'shield-lock',
        'catalog' => 'tag-starred',
        'store' => 'building-store',
        'admin' => 'settings',
    ];

    public static function forModule(string $id, string $group): string
    {
        return self::MODULES[$id] ?? (self::GROUPS[$group] ?? 'sparkles');
    }

    /** Inline SVG markup, decorative (aria-hidden). */
    public static function svg(string $name, int $size = 20, string $class = ''): string
    {
        $name = sanitize_key($name);
        if (!isset(self::$cache[$name])) {
            $file = STACKLESS_PLUGIN_PATH . 'assets/icons/' . $name . '.svg';
            self::$cache[$name] = is_readable($file) ? (string) file_get_contents($file) : '';
        }
        if (self::$cache[$name] === '') {
            return '';
        }
        $attrs = sprintf(' width="%1$d" height="%1$d" aria-hidden="true" focusable="false" class="stackless-icon %2$s"', $size, esc_attr($class));
        return (string) preg_replace('/<svg\b/', '<svg' . $attrs, self::$cache[$name], 1);
    }

    /** Base64 data URI for add_menu_page(); WordPress recolors its fill to match the admin scheme. */
    public static function menuDataUri(): string
    {
        $file = STACKLESS_PLUGIN_PATH . 'assets/icons/menu.svg';
        $svg = is_readable($file) ? (string) file_get_contents($file) : '';
        return $svg === '' ? 'dashicons-screenoptions' : 'data:image/svg+xml;base64,' . base64_encode($svg); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
    }
}
