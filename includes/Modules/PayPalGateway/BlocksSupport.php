<?php

namespace Stackless\Modules\PayPalGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Registers the PayPal buttons as a payment method in the Checkout block.
 * Loaded only from the woocommerce_blocks_payment_method_type_registration hook.
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
        return $gateway->enabled === 'yes' && $gateway->api()->hasCredentials();
    }

    public function get_payment_method_script_handles()
    {
        Module::registerScripts();
        return ['stackless-paypal_gateway-blocks'];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        return array_merge(Module::scriptData($gateway), [
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
        ]);
    }
}
