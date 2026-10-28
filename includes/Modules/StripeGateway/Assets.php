<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Script and style registration. Stripe.js always loads from js.stripe.com (required for PCI
 * SAQ-A); our scripts are plain ES2017 with no build step.
 */
final class Assets
{
    public const STRIPE_JS = 'stackless-stripe_gateway-stripejs';
    public const COMMON = 'stackless-stripe_gateway-common';
    public const CLASSIC = 'stackless-stripe_gateway-classic';
    public const EXPRESS = 'stackless-stripe_gateway-express';
    public const BLOCKS = 'stackless-stripe_gateway-blocks';
    public const STYLE = 'stackless-stripe_gateway-style';

    /** @var bool */
    private static $registered = false;

    public static function registerHooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFrontend'], 20);
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        $base = STACKLESS_PLUGIN_URL . 'assets/stripe_gateway/';
        $version = defined('STACKLESS_VERSION') ? STACKLESS_VERSION : '0';

        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe.js must never be versioned or self-hosted.
        wp_register_script(self::STRIPE_JS, 'https://js.stripe.com/v3/', [], null, true);
        wp_register_script(self::COMMON, $base . 'common.js', [self::STRIPE_JS], $version, true);
        wp_localize_script(self::COMMON, 'stacklessStripeConfig', self::config());
        wp_register_script(self::CLASSIC, $base . 'classic.js', ['jquery', self::COMMON], $version, true);
        wp_register_script(self::EXPRESS, $base . 'express.js', [self::COMMON], $version, true);
        wp_register_script(self::BLOCKS, $base . 'blocks.js', [self::COMMON, self::EXPRESS, 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-html-entities'], $version, true);
        wp_register_style(self::STYLE, $base . 'stripe.css', [], $version);
    }

    public static function enqueueFrontend(): void
    {
        if (!self::gatewayReady()) {
            return;
        }
        $is_pay = function_exists('is_checkout_pay_page') && is_checkout_pay_page();
        $is_add = function_exists('is_add_payment_method_page') && is_add_payment_method_page();
        $is_classic_checkout = is_checkout() && !$is_pay && !has_block('woocommerce/checkout');
        $is_account = function_exists('is_account_page') && is_account_page();

        if ($is_pay || $is_add || $is_classic_checkout || ($is_account && apply_filters('stackless_stripe_gateway_load_on_account_page', false))) {
            self::register();
            wp_enqueue_script(self::CLASSIC);
            wp_enqueue_style(self::STYLE);
        }
    }

    public static function enqueueExpress(): void
    {
        self::register();
        wp_enqueue_script(self::EXPRESS);
        wp_enqueue_style(self::STYLE);
    }

    public static function gatewayReady(): bool
    {
        return Module::gateway()->enabled === 'yes' && Settings::isConfigured();
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        $countries = WC()->countries ? array_keys(WC()->countries->get_shipping_countries()) : [];
        return [
            'gatewayId' => Settings::GATEWAY_ID,
            'publishableKey' => Settings::publishableKey(),
            'testMode' => Settings::isTestMode(),
            'ajaxUrl' => \WC_AJAX::get_endpoint('%%endpoint%%'),
            'appearance' => Settings::appearance(),
            'zeroDecimal' => Amount::ZERO_DECIMAL,
            'threeDecimal' => Amount::THREE_DECIMAL,
            'wholeUnits' => Amount::WHOLE_UNITS_TWO_DECIMAL,
            'expressNonce' => wp_create_nonce(Express::NONCE),
            'buttonType' => Settings::expressButtonType(),
            'requirePhone' => get_option('woocommerce_checkout_phone_field', 'required') === 'required',
            'allowedCountries' => array_values(array_map('strval', $countries)),
            'storeName' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'i18n' => [
                'genericError' => ErrorMapper::generic(),
                'enterDetails' => __('Enter your payment details to continue.', 'stackless-lite'),
                'chooseOptions' => __('Choose product options before paying.', 'stackless-lite'),
                'shippingPending' => __('Calculated from your address', 'stackless-lite'),
                'noShipping' => __('We cannot ship to this address.', 'stackless-lite'),
                'savedForRenewals' => __('Your payment details will be saved securely by Stripe and used for future renewals.', 'stackless-lite'),
                'processing' => __('Processing payment…', 'stackless-lite'),
                'testCard' => __('Test mode: use card 4242 4242 4242 4242 with any future expiry date and any CVC.', 'stackless-lite'),
                'totalChanged' => __('The total changed after taxes or shipping were added. Check the new total and try again.', 'stackless-lite'),
            ],
        ];
    }
}
