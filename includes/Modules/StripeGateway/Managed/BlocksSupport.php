<?php

namespace Stackless\Modules\StripeGateway\Managed;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Stackless\Modules\StripeGateway\Amount;
use Stackless\Modules\StripeGateway\Settings;

defined('ABSPATH') || exit;

/**
 * Stripe Managed Payments in the Checkout block: a redirect method, so the block needs a
 * label, a description and cart eligibility (digital goods, one subscription schedule),
 * which it reads from the Store API cart extension stackless_stripe_managed.
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
        return ($this->settings['enabled'] ?? 'no') === 'yes' && Settings::isConfigured();
    }

    public function get_payment_method_script_handles()
    {
        $handle = 'stackless-stripe_gateway-managed-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/stripe_gateway/managed-blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-data', 'wp-html-entities'], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        return [
            'gatewayId' => Gateway::ID,
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'testMode' => Settings::isTestMode(),
            'currencies' => Amount::SUPPORTED,
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
            'i18n' => [
                'testMode' => __('Test mode: no real payment will be taken.', 'stackless-lite'),
            ],
        ];
    }
}
