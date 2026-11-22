<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * Scripts for classic checkout, the order-pay page and My Account → Add payment method. The
 * Braintree SDK is loaded by common.js, so nothing loads from Braintree until the form is shown.
 */
final class Assets
{
    public const COMMON = 'stackless-braintree_gateway-common';
    public const CLASSIC = 'stackless-braintree_gateway-classic';
    public const STYLE = 'stackless-braintree_gateway';

    public static function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register(): void
    {
        if (!wp_script_is(self::COMMON, 'registered')) {
            wp_register_script(self::COMMON, STACKLESS_PLUGIN_URL . 'assets/braintree_gateway/common.js', [], STACKLESS_VERSION, true);
        }
        if (!wp_script_is(self::CLASSIC, 'registered')) {
            wp_register_script(self::CLASSIC, STACKLESS_PLUGIN_URL . 'assets/braintree_gateway/classic.js', ['jquery', self::COMMON], STACKLESS_VERSION, true);
        }
        if (!wp_style_is(self::STYLE, 'registered')) {
            wp_register_style(self::STYLE, STACKLESS_PLUGIN_URL . 'assets/braintree_gateway/braintree.css', [], STACKLESS_VERSION);
        }
    }

    public static function enqueue(): void
    {
        $page = (function_exists('is_checkout') && is_checkout()) || (function_exists('is_add_payment_method_page') && is_add_payment_method_page());
        if (!$page || Gateway::setting('enabled', 'no') !== 'yes' || !Config::hasKeys(Config::mode())) {
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
