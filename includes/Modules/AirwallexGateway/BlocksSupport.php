<?php

namespace Stackless\Modules\AirwallexGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/** Airwallex in the Checkout block: a redirect method. */
final class BlocksSupport extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Settings::GATEWAY_ID;

    public function initialize()
    {
        $this->settings = (array) get_option(Settings::OPTION, []);
    }

    public function is_active()
    {
        return Settings::enabled() && Settings::configured();
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-airwallex_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/airwallex_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        $requiresSave = function_exists('WC') && WC()->cart ? stackless_cart_requires_saved_payment_method() : false;
        return [
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'testmode' => Settings::mode() === 'test',
            'currencies' => Money::currencies(),
            'requiresSave' => $requiresSave,
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
            'i18n' => [
                'testmode' => __('Test mode: no real payment will be taken.', 'stackless-lite'),
                'save' => __('Your card is saved with Airwallex and charged automatically for renewals.', 'stackless-lite'),
            ],
        ];
    }
}
