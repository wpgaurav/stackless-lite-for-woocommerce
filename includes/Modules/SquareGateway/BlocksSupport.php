<?php

namespace Stackless\Modules\SquareGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Square in the Checkout block: the card form, Google Pay and Apple Pay, and saved cards.
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
        return $gateway->enabled === 'yes' && $gateway->appId() !== '' && $gateway->accessToken() !== '';
    }

    public function get_payment_method_script_handles()
    {
        Assets::register();
        wp_enqueue_style(Assets::STYLE);
        $handle = 'stackless-square_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/square_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', Assets::COMMON], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        $data = is_admin() ? [] : $gateway->scriptData('checkout');
        return array_merge($data, [
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'testmode' => $gateway->mode() === 'test',
            'currencies' => $gateway->supported_currencies(),
            'showSavedCards' => $gateway->savedCardsEnabled() || !empty($data['requiresSave']),
            'showSaveOption' => $gateway->savedCardsEnabled() && empty($data['requiresSave']),
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
        ]);
    }
}
