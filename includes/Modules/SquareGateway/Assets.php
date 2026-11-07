<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Scripts for the classic checkout, the order-pay page and My Account → Add payment method.
 * Square's Web Payments SDK is loaded by common.js on demand (sandbox or production URL from
 * the field's settings), so nothing loads until the Square form is on the page.
 */
final class Assets
{
    public const COMMON = 'stackless-square_gateway-common';
    public const CLASSIC = 'stackless-square_gateway-classic';
    public const STYLE = 'stackless-square_gateway';

    public static function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register(): void
    {
        if (!wp_script_is(self::COMMON, 'registered')) {
            wp_register_script(self::COMMON, STACKLESS_PLUGIN_URL . 'assets/square_gateway/common.js', [], STACKLESS_VERSION, true);
        }
        if (!wp_script_is(self::CLASSIC, 'registered')) {
            wp_register_script(self::CLASSIC, STACKLESS_PLUGIN_URL . 'assets/square_gateway/classic.js', ['jquery', self::COMMON], STACKLESS_VERSION, true);
        }
        if (!wp_style_is(self::STYLE, 'registered')) {
            wp_register_style(self::STYLE, STACKLESS_PLUGIN_URL . 'assets/square_gateway/square.css', [], STACKLESS_VERSION);
        }
    }

    public static function enqueue(): void
    {
        $page = (function_exists('is_checkout') && is_checkout()) || (function_exists('is_add_payment_method_page') && is_add_payment_method_page());
        if (!$page) {
            return;
        }
        $gateway = Gateway::instance();
        if ($gateway->enabled !== 'yes' || $gateway->appId() === '') {
            return;
        }
        self::register();
        wp_enqueue_style(self::STYLE);
        // The Checkout block loads its own script through BlocksSupport.
        if (function_exists('has_block') && has_block('woocommerce/checkout') && !(function_exists('is_checkout_pay_page') && is_checkout_pay_page())) {
            return;
        }
        wp_enqueue_script(self::CLASSIC);
    }
}
