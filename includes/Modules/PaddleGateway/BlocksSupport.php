<?php

namespace Stackless\Modules\PaddleGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Paddle in the Checkout block: a redirect method, so the block only needs a
 * label, a short description and cart eligibility. One instance per gateway
 * (the original and each copy); they share one script.
 */
final class BlocksSupport extends AbstractPaymentMethodType
{
    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
        $this->name = $gateway->id;
    }

    public function initialize()
    {
        $this->settings = (array) get_option('woocommerce_' . $this->gateway->id . '_settings', []);
    }

    public function is_active()
    {
        $gateway = $this->gateway;
        return $gateway->enabled === 'yes' && $gateway->apiKey() !== '' && $gateway->clientToken() !== '';
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-paddle_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/paddle_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-data', 'wp-html-entities'], STACKLESS_VERSION, true);
            wp_add_inline_script($handle, 'window.stacklessPaddleGateways=' . wp_json_encode(Copies::ids()) . ';', 'before');
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = $this->gateway;
        return [
            'gatewayId' => $gateway->id,
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'sandbox' => $gateway->mode() === 'sandbox',
            'currencies' => Money::currencies(),
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
            'i18n' => [
                'sandbox' => __('Sandbox mode: no real payment will be taken.', 'stackless-lite'),
            ],
        ];
    }
}
