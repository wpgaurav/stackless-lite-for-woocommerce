<?php

namespace Stackless\Modules\RevolutGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Revolut in the Checkout block: a redirect method, to the hosted page or to the page with
 * Revolut's checkout widget.
 */
final class BlocksSupport extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Keys::GATEWAY_ID;

    public function initialize()
    {
        $this->settings = (array) get_option(Settings::OPTION, []);
    }

    public function is_active()
    {
        return Platform::gatewayEnabled() && Settings::secretKey() !== '';
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-revolut_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/revolut_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => Settings::get('title', __('Card or Revolut Pay', 'stackless-lite')),
            'description' => wp_kses_post(Settings::get('description', __('You’ll finish paying on Revolut’s secure checkout.', 'stackless-lite'))),
            'testmode' => Settings::mode() === 'test',
            'currencies' => Money::CURRENCIES,
            'requiresSave' => function_exists('WC') && WC()->cart ? stackless_cart_requires_saved_payment_method() : false,
            'supports' => ['products', 'refunds', 'stackless_subscriptions'],
            'i18n' => [
                'testmode' => __('Test mode: no real payment will be taken.', 'stackless-lite'),
                'save' => __('You enter your card in Revolut’s window on the next page. It is saved with Revolut for future payments.', 'stackless-lite'),
            ],
        ];
    }
}
