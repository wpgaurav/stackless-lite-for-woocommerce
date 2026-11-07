<?php

namespace Stackless\Modules\MercadoPagoGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Mercado Pago in the Checkout block: a redirect method, so the block only needs a label,
 * a short description and the currencies it can take.
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
        return $this->gateway->enabled === 'yes' && $this->gateway->accessToken() !== '';
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-mercadopago_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/mercadopago_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], STACKLESS_VERSION, true);
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
            'testmode' => $gateway->mode() === 'test',
            'currencies' => Money::currencies(),
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
            'i18n' => [
                'testmode' => __('Test mode: no real payment will be taken.', 'stackless-lite'),
            ],
        ];
    }
}
