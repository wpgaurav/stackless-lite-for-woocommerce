<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Stripe gateway module: our own Stripe integration for core WooCommerce (no Stripe plugin,
 * no SDK). Payment Element and Express Checkout Element on classic and block checkout,
 * saved methods, SCA, refunds, webhooks, and automatic renewals through
 * Contracts\SubscriptionGateway.
 */
final class Module extends \Stackless\Core\Module
{
    /** @var Gateway|null */
    private static $fallbackGateway;

    public function id(): string
    {
        return Settings::MODULE_ID;
    }

    public function title(): string
    {
        return __('Stripe', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take cards, Apple Pay, Google Pay, Link and local payment methods with Stripe, save cards, and renew subscriptions automatically.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function conflicts(): array
    {
        // The official Stripe plugin can run alongside: different gateway id, tokens and webhooks.
        return [];
    }

    public function install(): void
    {
        EventStore::install();
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocksIntegration']);
        add_action('rest_api_init', [Webhooks::class, 'register']);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);

        add_action('woocommerce_order_status_on-hold_to_processing', [$this, 'captureOnStatus']);
        add_action('woocommerce_order_status_on-hold_to_completed', [$this, 'captureOnStatus']);
        add_action('woocommerce_order_status_cancelled', [$this, 'voidOnCancel']);

        Tokens::registerHooks();
        Endpoints::registerHooks();
        Assets::registerHooks();
        add_action('init', [Express::class, 'registerHooks']);

        if (is_admin()) {
            Admin::registerHooks();
        }
    }

    /**
     * @param array<int,string|\WC_Payment_Gateway> $gateways
     * @return array<int,string|\WC_Payment_Gateway>
     */
    public function registerGateway($gateways)
    {
        $gateways[] = Gateway::class;
        return $gateways;
    }

    /** @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry */
    public function registerBlocksIntegration($registry): void
    {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            $registry->register(new BlocksIntegration());
        }
    }

    /** @param int $order_id */
    public function captureOnStatus($order_id): void
    {
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order && $order->get_payment_method() === Settings::GATEWAY_ID) {
            Payments::capture($order);
        }
    }

    /** @param int $order_id */
    public function voidOnCancel($order_id): void
    {
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order && $order->get_payment_method() === Settings::GATEWAY_ID) {
            Payments::voidAuthorization($order);
        }
    }

    /** The gateway instance WooCommerce built, or a standalone one outside a full WooCommerce request. */
    public static function gateway(): Gateway
    {
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways[Settings::GATEWAY_ID]) && $gateways[Settings::GATEWAY_ID] instanceof Gateway) {
                return $gateways[Settings::GATEWAY_ID];
            }
        }
        if (!self::$fallbackGateway) {
            self::$fallbackGateway = new Gateway();
        }
        return self::$fallbackGateway;
    }

    public function settingsFields(): array
    {
        $p = Settings::PREFIX;
        $webhook = esc_url(Settings::webhookUrl());
        return [
            [
                'type' => 'title',
                'id' => $p . 'keys_section',
                'title' => __('Stripe account', 'stackless-lite'),
                'desc' => \Stackless\Core\WebhookSetup::keyButtons([
                    __('Get test keys', 'stackless-lite') => 'https://dashboard.stripe.com/test/apikeys',
                    __('Get live keys', 'stackless-lite') => 'https://dashboard.stripe.com/apikeys',
                ]) . sprintf(
                    /* translators: %s: webhook URL */
                    __('Copy the publishable and secret keys into the fields below. Keys are checked with Stripe when you save, and Stripe\'s webhook is created for you when its signing secret is empty (it needs a key that may manage webhook endpoints). Webhook URL for both modes: <code>%s</code>', 'stackless-lite'),
                    $webhook
                ),
            ],
            ['type' => 'checkbox', 'id' => $p . 'testmode', 'title' => __('Test mode', 'stackless-lite'), 'desc' => __('Use test keys. Only Stripe test cards work; no real money moves.', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'test_publishable_key', 'title' => __('Test publishable key', 'stackless-lite'), 'placeholder' => 'pk_test_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'test_secret_key', 'title' => __('Test secret key', 'stackless-lite'), 'placeholder' => 'sk_test_… / rk_test_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'test_webhook_secret', 'title' => __('Test webhook signing secret', 'stackless-lite'), 'placeholder' => 'whsec_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'text', 'id' => $p . 'live_publishable_key', 'title' => __('Live publishable key', 'stackless-lite'), 'placeholder' => 'pk_live_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'live_secret_key', 'title' => __('Live secret key', 'stackless-lite'), 'placeholder' => 'sk_live_… / rk_live_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'password', 'id' => $p . 'live_webhook_secret', 'title' => __('Live webhook signing secret', 'stackless-lite'), 'placeholder' => 'whsec_…', 'css' => 'min-width:420px', 'default' => ''],
            ['type' => 'sectionend', 'id' => $p . 'keys_section'],

            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite')],
            ['type' => 'text', 'id' => $p . 'title', 'title' => __('Title', 'stackless-lite'), 'default' => __('Card and more', 'stackless-lite'), 'desc_tip' => __('Payment method name shoppers see at checkout.', 'stackless-lite')],
            ['type' => 'textarea', 'id' => $p . 'description', 'title' => __('Description', 'stackless-lite'), 'default' => __('Pay securely with your card, Apple Pay, Google Pay, Link or a local payment method.', 'stackless-lite')],
            [
                'type' => 'select',
                'id' => $p . 'capture',
                'title' => __('Capture', 'stackless-lite'),
                'default' => 'now',
                'options' => [
                    'now' => __('Charge immediately', 'stackless-lite'),
                    'authorize' => __('Authorize only, capture when the order moves to Processing or Completed', 'stackless-lite'),
                ],
                'desc' => __('Orders with subscriptions always charge immediately. Authorizations expire after 7 days.', 'stackless-lite'),
            ],
            ['type' => 'checkbox', 'id' => $p . 'saved_cards', 'title' => __('Saved payment methods', 'stackless-lite'), 'desc' => __('Let signed-in customers save and reuse payment methods. Subscription orders always save the method for renewals.', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'statement_descriptor_suffix', 'title' => __('Statement descriptor suffix', 'stackless-lite'), 'default' => '', 'custom_attributes' => ['maxlength' => 22], 'desc_tip' => __('Added after your account\'s shortened descriptor on card statements, e.g. an order prefix. Letters required; no < > \\ \' " * characters.', 'stackless-lite')],
            [
                'type' => 'select',
                'id' => $p . 'appearance',
                'title' => __('Payment form style', 'stackless-lite'),
                'default' => 'site',
                'options' => [
                    'site' => __('Match my theme (colors, font, light or dark)', 'stackless-lite'),
                    'stripe' => __('Stripe default', 'stackless-lite'),
                    'flat' => __('Flat', 'stackless-lite'),
                    'night' => __('Night (dark)', 'stackless-lite'),
                ],
            ],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            [
                'type' => 'title',
                'id' => $p . 'express_section',
                'title' => __('Express checkout', 'stackless-lite'),
                'desc' => __('Apple Pay, Google Pay and Link buttons. Apple Pay also needs your domain registered in Stripe → Settings → Payment method domains.', 'stackless-lite'),
            ],
            [
                'type' => 'multiselect',
                'id' => $p . 'express_locations',
                'title' => __('Show buttons on', 'stackless-lite'),
                'class' => 'wc-enhanced-select',
                'default' => ['product', 'cart', 'checkout'],
                'options' => [
                    'product' => __('Product pages', 'stackless-lite'),
                    'cart' => __('Cart', 'stackless-lite'),
                    'checkout' => __('Checkout', 'stackless-lite'),
                ],
            ],
            [
                'type' => 'select',
                'id' => $p . 'express_button_type',
                'title' => __('Button label', 'stackless-lite'),
                'default' => 'buy',
                'options' => [
                    'plain' => __('Logo only', 'stackless-lite'),
                    'buy' => __('Buy with', 'stackless-lite'),
                    'checkout' => __('Checkout with', 'stackless-lite'),
                    'order' => __('Order with', 'stackless-lite'),
                    'pay' => __('Pay with', 'stackless-lite'),
                ],
            ],
            ['type' => 'sectionend', 'id' => $p . 'express_section'],

            ['type' => 'title', 'id' => $p . 'advanced_section', 'title' => __('Advanced', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'debug', 'title' => __('Debug log', 'stackless-lite'), 'desc' => __('Log API calls and webhook results to WooCommerce → Status → Logs (stackless-stripe_gateway). Keys and card data are never logged. Errors are always logged.', 'stackless-lite'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => $p . 'advanced_section'],
        ];
    }
}
