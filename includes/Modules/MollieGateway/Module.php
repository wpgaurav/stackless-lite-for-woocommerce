<?php

namespace Stackless\Modules\MollieGateway;

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
        return __('Mollie', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take cards, iDEAL, Bancontact, PayPal, Apple Pay and every other method switched on in Mollie on Mollie’s hosted checkout, save cards, PayPal and SEPA Direct Debit mandates, and renew subscriptions automatically. Refunds and payment updates sync from Mollie.', 'stackless-lite');
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
        return array_merge(parent::optionNames(), ['woocommerce_' . Gateway::ID . '_settings']);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_mollie_gateway_about',
                'type' => 'title',
                'title' => __('Mollie', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: %s: link to the gateway settings */
                    __('API keys, methods and checkout options live with the other payment methods: %s. Mollie reports every payment to this site by itself, so there is no webhook to add.', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Mollie', 'stackless-lite') . '</a>'
                ),
            ],
            ['id' => 'stackless_mollie_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_' . Checkout::RETURN_ACTION, [Checkout::class, 'handleReturn']);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);
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
