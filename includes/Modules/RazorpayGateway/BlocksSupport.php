<?php

namespace Stackless\Modules\RazorpayGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Razorpay in the Checkout block. Place order goes to the order's payment page, where
 * Razorpay Checkout opens; a subscription cart first asks which renewal method to set up.
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
        return $gateway->enabled === 'yes' && $gateway->api()->hasKeys();
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-razorpay_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/razorpay_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        $requiresSave = !is_admin() && function_exists('WC') && WC()->cart ? stackless_cart_requires_saved_payment_method() : false;
        $methods = [];
        if ($requiresSave) {
            foreach ($gateway->eligibleMethods() as $method) {
                $methods[] = ['id' => $method, 'label' => Mandate::label($method)];
            }
        }
        return [
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'testmode' => $gateway->mode() === 'test',
            'currencies' => Money::currencies(),
            'requiresSave' => $requiresSave,
            'methods' => $methods,
            'field' => Gateway::FIELD_METHOD,
            // Saved Razorpay tokens debit a day later, so they are never offered at checkout.
            'supports' => array_values(array_diff(array_filter($gateway->supports, [$gateway, 'supports']), ['tokenization'])),
            'i18n' => [
                'testmode' => __('Test mode: no real payment will be taken.', 'stackless-lite'),
                'method' => __('Pay renewals with', 'stackless-lite'),
                'rupees' => __('Subscriptions renew in Indian rupees.', 'stackless-lite'),
            ],
        ];
    }
}
