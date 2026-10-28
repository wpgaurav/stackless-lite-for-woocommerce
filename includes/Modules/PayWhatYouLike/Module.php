<?php

namespace Stackless\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Pay What You Like: buyers choose what to pay for a product, at or above a minimum (the product's
 * price unless the product sets one). The chosen amount becomes the cart line's real unit price on
 * the server, so coupons, tax, order items and reports all see it.
 *
 * Buyers choose on the product page, under each line of the classic cart, in a "Name your price"
 * box on the classic checkout and on the Cart and Checkout blocks (Store API), or through a link
 * (?stackless_price=25 on a cart, checkout or buy link). Subscription products keep their set price.
 */
final class Module extends \Stackless\Core\Module
{
    /** Store API namespace (cart item extension data and cart update callback). */
    public const STORE_NAMESPACE = 'stackless-pay-what-you-like';

    /** @var ProductSettings|null */
    private $productSettings;

    /** @var Cart|null */
    private $cart;

    public function id(): string
    {
        return 'pay_what_you_like';
    }

    public function title(): string
    {
        return __('Pay What You Like', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Let buyers choose what to pay for a product, with a minimum, a suggested amount, quick amounts and an optional maximum. Works on product pages, block and classic cart and checkout, and buy links.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'sales';
    }

    public function conflicts(): array
    {
        return [
            'woocommerce-name-your-price/woocommerce-name-your-price.php' => 'Name Your Price',
        ];
    }

    public function settingsFields(): array
    {
        $p = 'stackless_pay_what_you_like_';
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Pay what you like', 'stackless-lite'), 'desc' => __('Turn it on per product in the "Pay what you like" product data tab. Each product\'s price is its minimum unless you set a lower one, so a price of 0 makes it "free, or pay what you like". Subscription products keep their set price.', 'stackless-lite')],
            ['type' => 'text', 'id' => $p . 'heading', 'title' => __('Heading', 'stackless-lite'), 'default' => __('Name your price', 'stackless-lite'), 'desc_tip' => __('Label of the amount field on product pages and heading of the box on the cart and checkout.', 'stackless-lite'), 'css' => 'min-width:260px'],
            ['type' => 'text', 'id' => $p . 'note', 'title' => __('Line under the heading', 'stackless-lite'), 'default' => '', 'desc_tip' => __('Optional, for example "Every amount helps keep this project going."', 'stackless-lite'), 'css' => 'min-width:360px'],
            ['type' => 'checkbox', 'id' => $p . 'product_field', 'title' => __('Product pages', 'stackless-lite'), 'desc' => __('Show the amount field above Add to cart. When off, buyers choose their amount in the cart and at checkout.', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'product_note', 'title' => __('Price line', 'stackless-lite'), 'desc' => __('Show "Pay what you like: this price or more" (or "Free, or pay what you like") under prices', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'section'],
        ];
    }

    /** @return array{heading: string, note: string, product_field: bool, product_note: bool} */
    public function options(): array
    {
        $heading = trim((string) $this->option('heading', ''));
        return [
            'heading' => $heading !== '' ? sanitize_text_field($heading) : __('Name your price', 'stackless-lite'),
            'note' => sanitize_text_field(trim((string) $this->option('note', ''))),
            'product_field' => $this->option('product_field', 'yes') !== 'no',
            'product_note' => $this->option('product_note', 'yes') !== 'no',
        ];
    }

    public function boot(): void
    {
        $this->productSettings = new ProductSettings();
        $this->productSettings->register();
        $this->cart = new Cart($this->productSettings);
        $this->cart->register();
        // The default heading is translated, so the options are read once translations load (init).
        add_action('init', function () {
            if ($this->productSettings && $this->cart) {
                (new Storefront($this->productSettings, $this->cart, $this->options()))->register();
            }
        }, 5);

        // Blocks usually load before modules boot (plugins_loaded 20); register now if so.
        if (did_action('woocommerce_blocks_loaded')) {
            $this->registerStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'registerStoreApi']);
        }
        add_action('woocommerce_blocks_cart_block_registration', [$this, 'registerBlocksIntegration']);
        add_action('woocommerce_blocks_checkout_block_registration', [$this, 'registerBlocksIntegration']);
        add_filter('render_block_woocommerce/cart', [$this, 'blockStyle']);
        add_filter('render_block_woocommerce/checkout', [$this, 'blockStyle']);
    }

    /** The cart service (staging scripts and integrations). */
    public function cart(): ?Cart
    {
        return $this->cart;
    }

    public function productSettings(): ?ProductSettings
    {
        return $this->productSettings;
    }

    public function registerStoreApi(): void
    {
        $cart = $this->cart;
        if (!$cart || !function_exists('woocommerce_store_api_register_endpoint_data') || !class_exists(\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::class)) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
            'namespace' => self::STORE_NAMESPACE,
            'data_callback' => function ($cartItem) use ($cart) {
                return $cart->storeApiItemData((array) $cartItem);
            },
            'schema_callback' => function () {
                return Cart::storeApiItemSchema();
            },
            'schema_type' => ARRAY_A,
        ]);
        if (function_exists('woocommerce_store_api_register_update_callback')) {
            woocommerce_store_api_register_update_callback([
                'namespace' => self::STORE_NAMESPACE,
                'callback' => function ($data) use ($cart) {
                    $data = is_array($data) ? $data : [];
                    $key = isset($data['key']) && is_scalar($data['key']) ? sanitize_key((string) $data['key']) : '';
                    $amount = isset($data['amount']) && is_scalar($data['amount']) ? sanitize_text_field((string) $data['amount']) : '';
                    if ($key === '') {
                        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('stackless_pay_what_you_like_item', esc_html__('That cart item no longer exists.', 'stackless-lite'), 400);
                    }
                    $message = $cart->setAmount($key, $amount);
                    if ($message !== '') {
                        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('stackless_pay_what_you_like_amount', esc_html($message), 400);
                    }
                },
            ]);
        }
    }

    /** @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry|mixed $registry */
    public function registerBlocksIntegration($registry): void
    {
        if (is_object($registry) && method_exists($registry, 'register') && interface_exists(\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface::class)) {
            $registry->register(new BlocksIntegration($this->options()));
        }
    }

    /**
     * The box on the Cart and Checkout blocks shares the classic stylesheet.
     *
     * @param mixed $content
     * @return mixed
     */
    public function blockStyle($content)
    {
        wp_enqueue_style(Storefront::STYLE, STACKLESS_PLUGIN_URL . 'assets/pay_what_you_like/front.css', [], STACKLESS_VERSION);
        return $content;
    }
}
