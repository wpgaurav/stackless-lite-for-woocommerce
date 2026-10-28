<?php

namespace Stackless\Modules\PayWhatYouLike;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined('ABSPATH') || exit;

/**
 * Loads the "Name your price" box on the Cart and Checkout blocks. Only instantiated from the
 * block registration actions, so the interface exists.
 */
final class BlocksIntegration implements IntegrationInterface
{
    public const HANDLE = 'stackless-pay_what_you_like-blocks';

    /** @var array{heading: string, note: string, product_field: bool, product_note: bool} */
    private $options;

    /** @param array{heading: string, note: string, product_field: bool, product_note: bool} $options */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function get_name()
    {
        return Module::STORE_NAMESPACE;
    }

    public function initialize()
    {
        wp_register_script(
            self::HANDLE,
            STACKLESS_PLUGIN_URL . 'assets/pay_what_you_like/blocks.js',
            ['wc-blocks-checkout', 'wc-blocks-data-store', 'wc-settings', 'wp-element', 'wp-plugins', 'wp-data'],
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
        return [
            'namespace' => Module::STORE_NAMESPACE,
            'heading' => $this->options['heading'],
            'note' => $this->options['note'],
            'decimalSep' => wc_get_price_decimal_separator(),
            'symbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'symbolAfter' => in_array(get_option('woocommerce_currency_pos', 'left'), ['right', 'right_space'], true),
            'i18n' => [
                /* translators: %s: minimum price */
                'tooLow' => __('Enter at least %s.', 'stackless-lite'),
                /* translators: %s: maximum price */
                'tooHigh' => __('Enter at most %s.', 'stackless-lite'),
                'invalid' => __('Enter an amount such as 12.50, without a currency symbol.', 'stackless-lite'),
                'saving' => __('Your total is being updated. Try again in a moment.', 'stackless-lite'),
                'error' => __('Your amount could not be saved. Please try again.', 'stackless-lite'),
                'quickAmounts' => __('Quick amounts', 'stackless-lite'),
            ],
        ];
    }
}
