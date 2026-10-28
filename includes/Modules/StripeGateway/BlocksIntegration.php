<?php

namespace Stackless\Modules\StripeGateway;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * Cart/Checkout block integration: registers the Payment Element method (with saved tokens)
 * and the Express Checkout Element as an express payment method.
 */
final class BlocksIntegration extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Settings::GATEWAY_ID;

    public function initialize()
    {
        $this->settings = (array) get_option('woocommerce_' . Settings::GATEWAY_ID . '_settings', []);
    }

    public function is_active()
    {
        return ($this->settings['enabled'] ?? 'no') === 'yes' && Settings::isConfigured();
    }

    public function get_payment_method_script_handles()
    {
        Assets::register();
        wp_enqueue_style(Assets::STYLE);
        return [Assets::BLOCKS];
    }

    public function get_supported_features()
    {
        $gateway = Module::gateway();
        $features = array_values(array_filter($gateway->supports, [$gateway, 'supports']));
        return (array) apply_filters('__experimental_woocommerce_blocks_payment_gateway_features_list', $features, $this->get_name()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired the way WooCommerce does.
    }

    public function get_payment_method_data()
    {
        $requires_save = WC()->cart ? stackless_cart_requires_saved_payment_method() : false;
        $express_cart = Settings::expressEnabledOn('cart') && function_exists('is_cart') && is_cart();
        $express_checkout = Settings::expressEnabledOn('checkout') && function_exists('is_checkout') && is_checkout();

        return [
            'title' => Settings::title(),
            'description' => Module::gateway()->get_description(),
            'testMode' => Settings::isTestMode(),
            'supports' => $this->get_supported_features(),
            'showSavedCards' => Settings::savedCardsEnabled() || $requires_save,
            'showSaveOption' => Settings::savedCardsEnabled() && !$requires_save,
            'requiresSave' => $requires_save,
            'captureMethod' => ($requires_save || Settings::captureNow()) ? 'automatic' : 'manual',
            'supportedCurrencies' => Amount::SUPPORTED,
            'minimums' => Amount::MINIMUMS,
            'express' => [
                'enabled' => ($express_cart || $express_checkout) && Express::accountRequirementsMet(),
                'context' => $express_cart ? 'cart' : 'checkout',
            ],
            'icons' => [],
        ];
    }
}
