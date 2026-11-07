<?php

namespace Stackless\Modules\FlutterwaveGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'flutterwave_gateway';
    }

    public function title(): string
    {
        return __('Flutterwave', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Flutterwave for stores selling across Africa and beyond: cards, bank transfer, mobile money and USSD on Flutterwave’s hosted checkout, in naira, cedis, shillings, rand, CFA francs, dollars, euros and pounds. Each payment is verified with Flutterwave before the order is marked paid, refunds go back through Flutterwave, and webhooks bring in payments and refunds made in Flutterwave.', 'stackless-lite');
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
        // The gateway's settings, read by the gateway and the blocks integration on every checkout.
        return array_merge(parent::optionNames(), ['woocommerce_' . Gateway::ID . '_settings']);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_flutterwave_gateway_about',
                'type' => 'title',
                'title' => __('Flutterwave', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('Keys, test mode and the webhook secret hash live with the other payment methods: %1$s. Webhook URL for Flutterwave: %2$s', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Flutterwave', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(Gateway::webhookUrl()) . '</code>'
                ),
            ],
            ['id' => 'stackless_flutterwave_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_stackless_flutterwave_return', [Checkout::class, 'handleReturn']);
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
