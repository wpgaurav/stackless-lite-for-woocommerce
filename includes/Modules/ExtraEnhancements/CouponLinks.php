<?php

namespace Stackless\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Coupon links: any store link with ?coupon=CODE remembers the code for a day and
 * applies it, with WooCommerce's own coupon rules, as soon as the visitor has a cart
 * (classic add to cart, AJAX, or the Cart and Checkout blocks' Store API).
 *
 * Buy Links and On-page Checkout carry their own coupon parameter; their links are
 * left to them so a code is never applied twice. Inside an embedded checkout (On-page or
 * Modal Checkout) the cart is the frame's own, so a remembered code waits for the store cart.
 */
final class CouponLinks
{
    public const COOKIE = 'stackless_coupon';
    public const PARAM = 'coupon';

    public function register(): void
    {
        add_action('init', [$this, 'capture'], 20);
        add_action('template_redirect', [$this, 'apply'], 5);
        add_action('woocommerce_add_to_cart', [$this, 'apply'], 20, 0);
    }

    public static function sanitize($code): string
    {
        $code = is_string($code) ? trim(sanitize_text_field(wp_unslash($code))) : '';
        return preg_match('/^[\p{L}\p{N}_.@\-]{1,50}$/u', $code) ? $code : '';
    }

    /** Links that apply their own coupon. */
    private static function handledElsewhere(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
        return isset($_GET['stackless-buy']) || isset($_GET['stackless-checkout-frame']);
    }

    public function capture(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public link parameter, applied with WooCommerce's coupon checks.
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || empty($_GET[self::PARAM]) || self::handledElsewhere()) {
            return;
        }
        $code = self::sanitize($_GET[self::PARAM]);
        // phpcs:enable
        if ($code === '') {
            return;
        }
        $_COOKIE[self::COOKIE] = $code;
        self::cookie($code, time() + DAY_IN_SECONDS);
    }

    private static function cookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') && constant('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function forget(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::cookie('', time() - HOUR_IN_SECONDS);
    }

    /** A request of an embedded checkout, whose cart is not the visitor's store cart. */
    public static function inFrame(): bool
    {
        return apply_filters('stackless_on_page_checkout_frame', null) !== null;
    }

    public static function pending(): string
    {
        return self::sanitize($_COOKIE[self::COOKIE] ?? '');
    }

    public function apply(): void
    {
        $code = self::pending();
        if ($code === '' || !function_exists('WC') || !WC()->cart || is_admin() || self::inFrame()) {
            return;
        }
        // No cart yet: keep the code until the visitor adds something.
        if (WC()->cart->is_empty()) {
            return;
        }
        if (!WC()->cart->has_discount(wc_format_coupon_code($code))) {
            // WooCommerce checks the coupon's own rules and shows its notice; a refused code is dropped, not retried.
            WC()->cart->apply_coupon($code);
        }
        self::forget();
    }
}
