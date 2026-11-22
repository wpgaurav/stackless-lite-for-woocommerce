<?php

namespace Stackless\Modules\BraintreeGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Braintree in the Checkout block: Hosted Fields, 3-D Secure (saved cards included) and saved
 * cards.
 */
final class BlocksSupport extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Gateway::ID;

    public function initialize()
    {
        $this->settings = (array) get_option(Gateway::OPTION, []);
    }

    public function is_active()
    {
        return Gateway::setting('enabled', 'no') === 'yes' && Config::hasKeys(Config::mode());
    }

    public function get_payment_method_script_handles()
    {
        Assets::register();
        wp_enqueue_style(Assets::STYLE);
        $handle = 'stackless-braintree_gateway-blocks';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, STACKLESS_PLUGIN_URL . 'assets/braintree_gateway/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', Assets::COMMON], STACKLESS_VERSION, true);
        }
        return [$handle];
    }

    public function get_payment_method_data()
    {
        $gateway = Gateway::instance();
        if (!is_admin()) {
            Connection::ensure(Config::mode());
        }
        $data = is_admin() ? [] : $gateway->scriptData('checkout');
        return array_merge($data, [
            'title' => $gateway->get_title(),
            'description' => wp_kses_post($gateway->get_description()),
            'testmode' => Config::mode() === 'test',
            'currencies' => $gateway->supported_currencies(),
            'showSavedCards' => Config::savedCards() || !empty($data['requiresSave']),
            'showSaveOption' => Config::savedCards() && empty($data['requiresSave']),
            'supports' => array_values(array_filter($gateway->supports, [$gateway, 'supports'])),
        ]);
    }
}
