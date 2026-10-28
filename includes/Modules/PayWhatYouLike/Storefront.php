<?php

namespace Stackless\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * What buyers see on the classic storefront: the amount field on product pages (simple products
 * and variations), the "pay what you like" line under prices, an amount field under each line in
 * the classic cart, and the "Name your price" box on the classic checkout. The Cart and Checkout
 * blocks get the same box from blocks.js (see BlocksIntegration).
 */
final class Storefront
{
    public const STYLE = 'stackless-pay_what_you_like';
    public const SCRIPT = 'stackless-pay_what_you_like-front';

    /** @var ProductSettings */
    private $settings;

    /** @var Cart */
    private $cart;

    /** @var array{heading: string, note: string, product_field: bool, product_note: bool} */
    private $options;

    /** @var bool */
    private $checkoutRendered = false;

    /** @param array{heading: string, note: string, product_field: bool, product_note: bool} $options */
    public function __construct(ProductSettings $settings, Cart $cart, array $options)
    {
        $this->settings = $settings;
        $this->cart = $cart;
        $this->options = $options;
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        if ($this->options['product_field']) {
            add_action('woocommerce_before_add_to_cart_button', [$this, 'productField'], 15);
            add_filter('woocommerce_available_variation', [$this, 'variationData'], 20, 3);
        }
        if ($this->options['product_note']) {
            add_filter('woocommerce_get_price_html', [$this, 'priceNote'], 60, 2);
        }
        add_action('woocommerce_after_cart_item_name', [$this, 'cartField'], 20, 2);
        add_action('woocommerce_checkout_before_order_review', [$this, 'checkoutBox'], 5);
    }

    /* ---------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------- */

    public function assets(): void
    {
        wp_register_style(self::STYLE, STACKLESS_PLUGIN_URL . 'assets/pay_what_you_like/front.css', [], STACKLESS_VERSION);
        wp_register_script(self::SCRIPT, STACKLESS_PLUGIN_URL . 'assets/pay_what_you_like/front.js', ['jquery'], STACKLESS_VERSION, true);
        wp_localize_script(self::SCRIPT, 'stacklessPayWhatYouLike', [
            'decimalSep' => wc_get_price_decimal_separator(),
            'i18n' => [
                /* translators: %s: minimum price */
                'tooLow' => __('Enter at least %s.', 'stackless-lite'),
                /* translators: %s: maximum price */
                'tooHigh' => __('Enter at most %s.', 'stackless-lite'),
                'invalid' => __('Enter an amount such as 12.50, without a currency symbol.', 'stackless-lite'),
            ],
        ]);

        $needed = false;
        if (is_product()) {
            $product = wc_get_product((int) get_queried_object_id());
            $needed = $product instanceof \WC_Product && $this->settings->settingsFor($product) !== null;
        } elseif ((is_cart() || is_checkout()) && !is_order_received_page() && function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                if ($this->cart->lineState((array) $item)) {
                    $needed = true;
                    break;
                }
            }
        }
        if ($needed) {
            $this->enqueue();
        }
    }

    private function enqueue(): void
    {
        wp_enqueue_style(self::STYLE);
        wp_enqueue_script(self::SCRIPT);
    }

    /* ---------------------------------------------------------------------
     * Fields
     * ------------------------------------------------------------------- */

    /**
     * Template arguments for one amount field.
     *
     * @param array{amount: int, min: int, max: int, presets: int[], decimals: int}|array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function fieldArgs(array $state, string $name, string $label, string $context): array
    {
        $d = (int) $state['decimals'];
        $sep = wc_get_price_decimal_separator();
        $show = static function (int $minor) use ($d, $sep): string {
            return str_replace('.', $sep, Amounts::fromMinor($minor, $d));
        };
        $amount = (int) ($state['amount'] ?? $state['default'] ?? 0);
        return [
            'id' => wp_unique_id('stackless-pwyl-'),
            'name' => $name,
            'label' => $label,
            'context' => $context,
            'value' => $show($amount),
            'min' => Amounts::fromMinor((int) $state['min'], $d),
            'max' => Amounts::fromMinor((int) $state['max'], $d),
            'decimals' => $d,
            'min_label' => Cart::label((int) $state['min'], $d),
            'max_label' => Cart::label((int) $state['max'], $d),
            'help' => Cart::helpText(['min' => (int) $state['min'], 'max' => (int) $state['max'], 'decimals' => $d]),
            'symbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'symbol_after' => in_array(get_option('woocommerce_currency_pos', 'left'), ['right', 'right_space'], true),
            'presets' => array_map(static function ($preset) use ($d, $show, $amount) {
                return ['value' => $show((int) $preset), 'label' => Cart::label((int) $preset, $d), 'pressed' => (int) $preset === $amount];
            }, (array) ($state['presets'] ?? [])),
        ];
    }

    public function productField(): void
    {
        global $product;
        if (!$product instanceof \WC_Product) {
            return;
        }
        $isVariable = $product->is_type('variable');
        if (!$isVariable && !$product->is_type('simple')) {
            return;
        }
        if ($this->settings->settingsFor($product) === null) {
            return;
        }
        $state = null;
        if (!$isVariable) {
            $bounds = $this->cart->bounds($product);
            if (!$bounds) {
                return;
            }
            $state = $bounds + ['amount' => $bounds['default']];
            // A refused add keeps what the buyer typed.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- redisplay only.
            if (isset($_POST[Cart::PRODUCT_MARKER], $_POST[Cart::PRODUCT_FIELD]) && absint($_POST[Cart::PRODUCT_MARKER]) === $product->get_id() && is_scalar($_POST[Cart::PRODUCT_FIELD])) {
                $parsed = Amounts::parse(sanitize_text_field(wp_unslash((string) $_POST[Cart::PRODUCT_FIELD])), $bounds['decimals'], wc_get_price_decimal_separator()); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ($parsed !== null) {
                    $state['amount'] = (int) Amounts::toMinor($parsed, $bounds['decimals']);
                }
            }
        }
        $this->enqueue();
        $args = $state ? self::fieldArgs($state, Cart::PRODUCT_FIELD, $this->options['heading'], 'product') : [
            'id' => wp_unique_id('stackless-pwyl-'),
            'name' => Cart::PRODUCT_FIELD,
            'label' => $this->options['heading'],
            'context' => 'product',
            'value' => '',
            'min' => '0',
            'max' => '0',
            'decimals' => wc_get_price_decimals(),
            'min_label' => '',
            'max_label' => '',
            'help' => '',
            'symbol' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'symbol_after' => in_array(get_option('woocommerce_currency_pos', 'left'), ['right', 'right_space'], true),
            'presets' => [],
        ];
        stackless_get_template('pay_what_you_like/amount-field.php', $args + [
            'note' => $this->options['note'],
            'product_id' => $product->get_id(),
            'variable' => $isVariable,
        ]);
    }

    /**
     * Each variation's limits for the product page script; false for fixed-price variations.
     *
     * @param array<string, mixed> $data
     * @param mixed $product
     * @param mixed $variation
     * @return array<string, mixed>
     */
    public function variationData($data, $product, $variation): array
    {
        $data = (array) $data;
        if (!$variation instanceof \WC_Product) {
            return $data;
        }
        $bounds = $this->cart->bounds($variation);
        if (!$bounds) {
            $data['stackless_pwyl'] = false;
            return $data;
        }
        $args = self::fieldArgs($bounds + ['amount' => $bounds['default']], Cart::PRODUCT_FIELD, '', 'product');
        $data['stackless_pwyl'] = [
            'value' => $args['value'],
            'min' => $args['min'],
            'max' => $args['max'],
            'decimals' => $args['decimals'],
            'minLabel' => $args['min_label'],
            'maxLabel' => $args['max_label'],
            'help' => $args['help'],
            'presets' => $args['presets'],
        ];
        return $data;
    }

    /**
     * "Pay what you like: this price or more" under prices.
     *
     * @param mixed $html
     * @param mixed $product
     */
    public function priceNote($html, $product): string
    {
        $html = (string) $html;
        if (!$product instanceof \WC_Product || (is_admin() && !wp_doing_ajax()) || $html === '') {
            return $html;
        }
        if (!$product->is_type(['simple', 'variable']) || $this->settings->settingsFor($product) === null) {
            return $html;
        }
        $bounds = $product->is_type('simple') ? $this->cart->bounds($product) : null;
        if ($bounds && $bounds['custom_min']) {
            $text = $bounds['min'] > 0
                /* translators: %s: minimum price */
                ? sprintf(__('Pay what you like, from %s', 'stackless-lite'), Cart::label($bounds['min'], $bounds['decimals']))
                : __('Free, or pay what you like', 'stackless-lite');
        } else {
            $price = $product instanceof \WC_Product_Variable ? $product->get_variation_price('min', false) : $product->get_price();
            $text = is_numeric($price) && (float) $price <= 0
                ? __('Free, or pay what you like', 'stackless-lite')
                : __('Pay what you like: this price or more', 'stackless-lite');
        }
        if (wp_style_is(self::STYLE, 'registered')) {
            wp_enqueue_style(self::STYLE);
        }
        return $html . ' <span class="stackless-pwyl-note">' . esc_html($text) . '</span>';
    }

    /**
     * Amount field under a line in the classic cart (inside the cart form, sent with Update cart).
     *
     * @param array<string, mixed> $item
     * @param string $key
     */
    public function cartField($item, $key): void
    {
        $state = $this->cart->lineState((array) $item);
        if (!$state || !is_string($key)) {
            return;
        }
        $this->enqueue();
        stackless_get_template('pay_what_you_like/amount-field.php', self::fieldArgs($state, Cart::KEY . '[' . $key . ']', __('Your price', 'stackless-lite'), 'cart') + [
            'note' => '',
            'product_id' => 0,
            'variable' => false,
            'error' => $state['error'] !== '' ? Cart::boundsMessage($state['error'], $state, $item['data']->get_name()) : '',
        ]);
    }

    /** "Name your price" box on the classic checkout, outside the refreshed order review. */
    public function checkoutBox(): void
    {
        if ($this->checkoutRendered || !function_exists('WC') || !WC()->cart) {
            return;
        }
        $fields = [];
        foreach (WC()->cart->get_cart() as $key => $item) {
            $state = $this->cart->lineState((array) $item);
            if (!$state) {
                continue;
            }
            $fields[] = self::fieldArgs($state, Cart::KEY . '[' . $key . ']', $item['data']->get_name(), 'checkout') + [
                'error' => $state['error'] !== '' ? Cart::boundsMessage($state['error'], $state, $item['data']->get_name()) : '',
            ];
        }
        if (!$fields) {
            return;
        }
        $this->checkoutRendered = true;
        $this->enqueue();
        stackless_get_template('pay_what_you_like/checkout-box.php', [
            'heading' => $this->options['heading'],
            'note' => $this->options['note'],
            'fields' => $fields,
        ]);
    }
}
