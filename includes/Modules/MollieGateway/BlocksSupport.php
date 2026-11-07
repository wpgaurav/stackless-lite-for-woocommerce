<?php

namespace Stackless\Modules\MollieGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Mollie in the Checkout block: a redirect method with an optional method picker and the
 * customer's saved mandates.
 */
final class BlocksSupport extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Gateway::ID;

    public function initialize()
    {
        $this->settings = (array) get_option('woocommerce_' . Gateway::ID . '_settings', []);
    }

    public function is_active()
    {
        $gateway = Gateway::instance();
        return $gateway->enabled === 'yes' && $gateway->apiKey() !== '';
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-mollie_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/mollie_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        $requiresSave = function_exists('WC') && WC()->cart ? stackless_cart_requires_saved_payment_method() : false;
        $methods = [];
        if (!is_admin() && function_exists('WC') && WC()->cart) {
            foreach ($gateway->pickerMethods() as $id => $label) {
                $methods[] = ['id' => $id, 'label' => $label];
            }
        }
        return [
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'testmode' => $gateway->mode() === 'test',
            'currencies' => $gateway->mode() === 'test' ? ['EUR'] : Money::currencies(),
            'methods' => $methods,
            'field' => Gateway::FIELD_METHOD,
            'showSavedCards' => $gateway->savedMethodsEnabled() || $requiresSave,
            'showSaveOption' => $gateway->savedMethodsEnabled() && !$requiresSave,
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
            'i18n' => [
                'testmode' => __('Test mode: no real payment will be taken.', 'stackless-lite'),
                'method' => __('Payment method', 'stackless-lite'),
                'any' => __('Choose on the next page', 'stackless-lite'),
            ],
        ];
    }
}
