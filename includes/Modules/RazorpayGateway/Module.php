<?php

namespace Stackless\Modules\RazorpayGateway;

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
        return __('Razorpay', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take UPI, cards, netbanking and wallets with Razorpay Checkout, and renew subscriptions automatically through card e-mandates, UPI Autopay and eNACH. Renewal debits start early to cover the bank’s pre-debit notice, and settle, refund and sync through Razorpay’s signed webhook.', 'stackless-lite');
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
                'id' => 'stackless_razorpay_gateway_about',
                'type' => 'title',
                'title' => __('Razorpay', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('API keys, the webhook secret and renewal options live with the other payment methods: %1$s. Webhook URL for Razorpay: %2$s', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Razorpay', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(rest_url('stackless/v1/razorpay/webhook')) . '</code>'
                ),
            ],
            ['id' => 'stackless_razorpay_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_receipt_' . Gateway::ID, [Checkout::class, 'receipt']);
        add_action('woocommerce_api_' . Checkout::RETURN_ACTION, [Sync::class, 'handleReturn']);
        add_action('woocommerce_api_' . Checkout::AUTHORIZE_ACTION, [Checkout::class, 'authorizePage']);
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
