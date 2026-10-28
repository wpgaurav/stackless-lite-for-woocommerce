<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'paypal_gateway';
    }

    public function title(): string
    {
        return __('PayPal', 'stackless-lite');
    }

    public function description(): string
    {
        return __('PayPal Smart Buttons and Pay Later on the block and classic checkout, refunds, and saved PayPal accounts that renew subscriptions automatically. Uses PayPal’s REST APIs directly.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_paypal_gateway_about',
                'type' => 'title',
                'title' => __('PayPal', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('Credentials, button style and Pay Later live with the other payment methods: %1$s. Webhook URL for your PayPal apps: %2$s', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → PayPal', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(Gateway::webhookUrl()) . '</code>'
                ),
            ],
            ['id' => 'stackless_paypal_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_filter('woocommerce_payment_token_class', [PaymentToken::class, 'filterClass'], 10, 2);
        add_filter('woocommerce_payment_methods_list_item', [PaymentToken::class, 'listItem'], 20, 2);
        add_filter('woocommerce_credit_card_type_labels', [PaymentToken::class, 'brandLabel']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        // woocommerce_blocks_loaded fires at plugins_loaded:10, before modules boot (20).
        if (did_action('woocommerce_blocks_loaded')) {
            $this->extendStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'extendStoreApi']);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_stackless_paypal_return', [$this, 'handleReturn']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueClassic']);
        add_action('woocommerce_order_status_processing', [$this, 'captureOnStatus'], 5);
        add_action('woocommerce_order_status_completed', [$this, 'captureOnStatus'], 5);
        add_action('woocommerce_order_status_cancelled', [$this, 'voidOnCancel']);
        Ajax::register();
    }

    /** @param array $gateways */
    public function registerGateway($gateways)
    {
        $gateways[] = Gateway::instance();
        return $gateways;
    }

    public function registerBlocks($registry): void
    {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            $registry->register(new BlocksSupport());
        }
    }

    /** Tells the Checkout block when the cart needs a saved PayPal account (subscriptions). */
    public function extendStoreApi(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'cart',
            'namespace' => 'stackless_paypal_gateway',
            'schema_type' => ARRAY_A,
            'data_callback' => static function () {
                return ['requires_vault' => stackless_cart_requires_saved_payment_method()];
            },
            'schema_callback' => static function () {
                return [
                    'requires_vault' => [
                        'description' => __('Whether checkout must save the PayPal account for automatic renewals.', 'stackless-lite'),
                        'type' => 'boolean',
                        'context' => ['view', 'edit'],
                        'readonly' => true,
                    ],
                ];
            },
        ]);
    }

    public function handleReturn(): void
    {
        Gateway::instance()->payments()->handleReturn();
    }

    public function captureOnStatus($orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && $order->get_payment_method() === Gateway::ID) {
            Gateway::instance()->payments()->captureAuthorization($order);
        }
    }

    public function voidOnCancel($orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order && $order->get_payment_method() === Gateway::ID) {
            Gateway::instance()->payments()->voidAuthorization($order);
        }
    }

    /* ---- Assets ---------------------------------------------------------------- */

    public static function registerScripts(): void
    {
        if (wp_script_is('stackless-paypal_gateway-sdk', 'registered')) {
            return;
        }
        $base = STACKLESS_PLUGIN_URL . 'assets/paypal_gateway/';
        wp_register_style('stackless-paypal_gateway', $base . 'paypal.css', [], STACKLESS_VERSION);
        wp_register_script('stackless-paypal_gateway-sdk', $base . 'sdk.js', [], STACKLESS_VERSION, true);
        wp_register_script('stackless-paypal_gateway-classic', $base . 'classic.js', ['jquery', 'stackless-paypal_gateway-sdk'], STACKLESS_VERSION, true);
        wp_register_script('stackless-paypal_gateway-blocks', $base . 'blocks.js', ['stackless-paypal_gateway-sdk', 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-data', 'wp-i18n', 'wp-html-entities'], STACKLESS_VERSION, true);
        wp_set_script_translations('stackless-paypal_gateway-blocks', 'stackless-lite');
        // The block script runs inside the Checkout block, so it brings the stylesheet with it.
        add_action('wp_footer', static function () {
            if (wp_script_is('stackless-paypal_gateway-blocks', 'enqueued')) {
                wp_enqueue_style('stackless-paypal_gateway');
            }
        }, 1);
    }

    /** Settings the browser needs (no secrets: the client id is public by design). */
    public static function scriptData(Gateway $gateway): array
    {
        return [
            'gatewayId' => Gateway::ID,
            'clientId' => $gateway->clientId(),
            'sdkBase' => 'https://www.paypal.com/sdk/js',
            'sdkParams' => (array) apply_filters('stackless_paypal_gateway_sdk_params', [
                'components' => $gateway->payLaterMessaging() ? 'buttons,messages' : 'buttons',
                'enable-funding' => $gateway->payLaterMessaging() ? 'paylater' : '',
                'commit' => 'true',
            ]),
            // Extra SDK parameters used only when saving an account without a purchase.
            'sdkSetupParams' => (array) apply_filters('stackless_paypal_gateway_sdk_setup_params', []),
            'intent' => $gateway->intent(),
            'currencies' => Amount::currencies(),
            'style' => $gateway->buttonStyle(),
            'payLater' => $gateway->payLaterMessaging(),
            'ajax' => Ajax::urls(),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'i18n' => [
                'error' => __('PayPal could not start. Please try again or choose another payment method.', 'stackless-lite'),
                'approved' => __('PayPal approved. Place your order to finish.', 'stackless-lite'),
                'saving' => __('Your PayPal account will be saved for future payments.', 'stackless-lite'),
                'unavailable' => __('PayPal is not available for this currency.', 'stackless-lite'),
                'cancelled' => __('You closed PayPal before finishing. Use the button again when you are ready.', 'stackless-lite'),
            ],
        ];
    }

    /** Classic checkout, order-pay and My Account → Add payment method. */
    public function enqueueClassic(): void
    {
        if (!function_exists('is_checkout')) {
            return;
        }
        $orderPay = is_checkout_pay_page();
        $addMethod = is_add_payment_method_page();
        $checkout = is_checkout() && !$orderPay && !is_order_received_page();
        if (!$orderPay && !$addMethod && !$checkout) {
            return;
        }
        if ($checkout && !$orderPay && function_exists('has_block') && has_block('woocommerce/checkout', (int) wc_get_page_id('checkout'))) {
            return; // The Checkout block loads blocks.js through BlocksSupport.
        }
        $gateway = Gateway::instance();
        if ($gateway->enabled !== 'yes' || !$gateway->api()->hasCredentials()) {
            return;
        }
        self::registerScripts();
        wp_enqueue_style('stackless-paypal_gateway');
        wp_enqueue_script('stackless-paypal_gateway-classic');
        wp_localize_script('stackless-paypal_gateway-classic', 'stacklessPayPalData', self::scriptData($gateway));
    }
}
