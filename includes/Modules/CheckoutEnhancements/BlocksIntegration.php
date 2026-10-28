<?php

namespace Stackless\Modules\CheckoutEnhancements;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined('ABSPATH') || exit;

/** Loads the block-checkout script (Place order label filter, free-order wording) only where the Checkout block renders. */
final class BlocksIntegration implements IntegrationInterface
{
    public const HANDLE = 'stackless-checkout_enhancements-blocks';

    public function get_name()
    {
        return 'stackless-checkout-enhancements';
    }

    public function initialize()
    {
        wp_register_script(
            self::HANDLE,
            STACKLESS_PLUGIN_URL . 'assets/checkout_enhancements/blocks.js',
            ['wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-hooks'],
            STACKLESS_VERSION,
            true
        );
    }

    public function get_script_handles()
    {
        return [self::HANDLE];
    }

    public function get_editor_script_handles()
    {
        return [];
    }

    public function get_script_data()
    {
        $free = Settings::freeButtonText();
        $zero = false;
        if ($free !== '' && function_exists('WC') && WC()->cart instanceof \WC_Cart && did_action('wp_loaded')) {
            $zero = LeanRules::zeroTotal(array_merge(CartFacts::fromCart(), CartFacts::valueFromCart()));
        }
        return [
            'button' => Settings::buttonText(),
            'freeButton' => $free,
            'zeroTotal' => $zero,
        ];
    }
}
