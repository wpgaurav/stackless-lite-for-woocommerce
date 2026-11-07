<?php

namespace Stackless\Modules\SquareGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return Gateway::MODULE_ID;
    }

    public function title(): string
    {
        return __('Square', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take cards, Apple Pay and Google Pay with Square’s Web Payments SDK on classic and block checkout, save cards on file, and renew subscriptions automatically. Charges in your Square location’s currency; refunds and card updates sync through a signed webhook created for you.', 'stackless-lite');
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
        return array_merge(parent::optionNames(), [
            'woocommerce_' . Gateway::ID . '_settings',
            'stackless_square_gateway_location_live',
            'stackless_square_gateway_location_test',
            'stackless_square_gateway_webhook_url_live',
            'stackless_square_gateway_webhook_url_test',
        ]);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_square_gateway_about',
                'type' => 'title',
                'title' => __('Square', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('Application IDs, access tokens and wallets live with the other payment methods: %1$s. The webhook (%2$s) is created for you when you save there.', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Square', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(rest_url('stackless/v1/square/webhook')) . '</code>'
                ),
            ],
            ['id' => 'stackless_square_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action(Payments::RETRY_HOOK, [Payments::class, 'retry']);
        Assets::registerHooks();
        Tokens::registerHooks();
        Health::registerHooks();
    }

    /** @param array $gateways */
    public function registerGateway($gateways)
    {
        $gateways[] = Gateway::class;
        return $gateways;
    }

    public function registerBlocks($registry): void
    {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            $registry->register(new BlocksSupport());
        }
    }
}
