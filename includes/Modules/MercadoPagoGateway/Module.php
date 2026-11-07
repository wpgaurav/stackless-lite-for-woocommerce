<?php

namespace Stackless\Modules\MercadoPagoGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'mercadopago_gateway';
    }

    public function title(): string
    {
        return __('Mercado Pago', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Mercado Pago Checkout Pro for stores in Argentina, Brazil, Chile, Colombia, Mexico, Peru and Uruguay: cards, Pix, boleto, OXXO, Mercado Pago balance and local methods, in the account’s own currency. Each payment is read back from Mercado Pago before the order is marked paid, cash and bank payments hold the order until they clear, refunds go back through Mercado Pago, and signed webhooks bring in refunds and chargebacks.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'payments';
    }

    public function install(): void
    {
        WebhookLog::install();
    }

    public function optionNames(): array
    {
        // The gateway's settings, and the account country that decides its currency at checkout.
        return array_merge(parent::optionNames(), [
            'woocommerce_' . Gateway::ID . '_settings',
            'stackless_mercadopago_gateway_account_live',
            'stackless_mercadopago_gateway_account_test',
        ]);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_mercadopago_gateway_about',
                'type' => 'title',
                'title' => __('Mercado Pago', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('Access tokens, test mode and the webhook secret live with the other payment methods: %1$s. Webhook URL for Mercado Pago: %2$s', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Mercado Pago', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(Gateway::webhookUrl()) . '</code>'
                ),
            ],
            ['id' => 'stackless_mercadopago_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_stackless_mercadopago_return', [Checkout::class, 'handleReturn']);
        add_filter('stackless_payment_alerts_gateways', [Health::class, 'alertRoute']);
        add_filter('stackless_billing_health_sources', [Health::class, 'register']);
    }

    /** @param array $gateways */
    public function registerGateway($gateways)
    {
        $gateways[] = Gateway::instance();
        return $gateways;
    }

    public function registerBlocks($registry): void
    {
        if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            return;
        }
        $registry->register(new BlocksSupport(Gateway::instance()));
    }
}
