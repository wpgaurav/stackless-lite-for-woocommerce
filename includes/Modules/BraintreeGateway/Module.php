<?php

namespace Stackless\Modules\BraintreeGateway;

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
        return __('Braintree', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take cards with Braintree’s Hosted Fields and 3-D Secure on classic and block checkout, save cards, and renew subscriptions automatically. Charges each currency through its own merchant account; refunds, voids and disputes sync from Braintree.', 'stackless-lite');
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
            Gateway::OPTION,
            Connection::option('live'),
            Connection::option('test'),
        ]);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_braintree_gateway_about',
                'type' => 'title',
                'title' => __('Braintree', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('The Merchant ID, keys, merchant accounts and 3-D Secure live with the other payment methods: %1$s. Add the webhook (%2$s) in the Braintree Control Panel.', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Braintree', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(Config::webhookUrl()) . '</code>'
                ),
            ],
            ['id' => 'stackless_braintree_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action(Payments::CHECK_HOOK, [Payments::class, 'check']);
        add_action(Sync::SWEEP_HOOK, [Sync::class, 'sweep']);
        add_action('admin_init', [Sync::class, 'adminInit']);
        Checkout::registerHooks();
        Assets::registerHooks();
        Tokens::registerHooks();
        Guard::registerHooks();
        Health::registerHooks();
        Privacy::registerHooks();
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
