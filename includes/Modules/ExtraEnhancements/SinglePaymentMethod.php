<?php

namespace Stackless\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Hides the payment section when the only payment method is a hosted checkout that
 * collects nothing on the page (Polar, Paddle, Dodo Payments and their copies). The
 * method stays selected, so Place order still goes to it.
 *
 * Classic checkout: a style printed next to Place order, inside the #payment fragment
 * WooCommerce re-renders on every order review refresh, so it follows address and
 * currency changes. Checkout block: a small script watches the block's own list of
 * available methods, since it changes with the address without a page load.
 */
final class SinglePaymentMethod
{
    /** Gateway families with nothing to fill in on the checkout page. */
    public const FAMILIES = ['stackless_polar', 'stackless_paddle', 'stackless_dodo'];

    public const HANDLE = 'stackless-extra_enhancements-hosted';

    public function register(): void
    {
        // Inside #payment: woocommerce_review_order_before_payment runs only on the first, non-AJAX render.
        add_action('woocommerce_review_order_before_submit', [$this, 'classic'], 99);
        add_filter('render_block_woocommerce/checkout', [$this, 'block']);
    }

    /** @return string[] */
    public static function families(): array
    {
        return array_values(array_filter(array_map('strval', (array) apply_filters('stackless_extra_enhancements_hosted_gateways', self::FAMILIES))));
    }

    /** Whether a gateway id is a hosted gateway or a copy of one ("stackless_dodo_copy2"). */
    public static function isHosted(string $id, ?array $families = null): bool
    {
        foreach ($families ?? self::families() as $family) {
            if ($id === $family || preg_match('/^' . preg_quote($family, '/') . '_copy\d+$/', $id)) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $available Ids of the methods the checkout offers. */
    public static function hide(array $available, ?array $families = null): bool
    {
        $available = array_values($available);
        return count($available) === 1 && self::isHosted((string) $available[0], $families);
    }

    public function classic(): void
    {
        if (!function_exists('WC') || !WC()->cart || !WC()->cart->needs_payment() || !WC()->payment_gateways()) {
            return;
        }
        $available = array_map('strval', array_keys(WC()->payment_gateways()->get_available_payment_gateways()));
        if (self::hide($available)) {
            echo '<style id="stackless-hosted-only">#payment .wc_payment_methods{display:none!important}</style>';
        }
    }

    /** @param string $content */
    public function block($content)
    {
        wp_enqueue_script(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/extra_enhancements/hosted-only.js', ['wp-data'], STACKLESS_VERSION, true);
        wp_add_inline_script(self::HANDLE, 'window.stacklessHostedGateways=' . wp_json_encode(self::families()) . ';', 'before');
        return $content;
    }
}
