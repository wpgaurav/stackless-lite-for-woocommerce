<?php

namespace Stackless\Modules\CreemGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'creem_gateway';
    }

    public function title(): string
    {
        return __('Creem', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Creem as a merchant of record for software and digital products: hosted checkout with sales tax and VAT handled for you, one-time orders in US dollars or euros, and single-product subscriptions with free trials that Creem renews. Payments, renewals, failed renewals, refunds and cancellations sync both ways through signed webhooks, which are set up for you.', 'stackless-lite');
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
        // The copy list and every copy's settings, read by the gateways and the blocks integration.
        $names = [Copies::OPTION];
        foreach (Copies::ids() as $id) {
            $names[] = 'woocommerce_' . $id . '_settings';
        }
        return array_merge(parent::optionNames(), $names);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_creem_gateway_about',
                'type' => 'title',
                'title' => __('Creem', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('API keys, webhook secrets and tax settings live with the other payment methods: %1$s. Webhook URL for Creem: %2$s', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Creem', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(rest_url('stackless/v1/creem/webhook')) . '</code>'
                ),
            ],
            ['id' => 'stackless_creem_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateways']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        // woocommerce_blocks_loaded fires at plugins_loaded:10, before modules boot (20).
        if (did_action('woocommerce_blocks_loaded')) {
            $this->extendStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'extendStoreApi']);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_stackless_creem_return', [Checkout::class, 'handleReturn']);
        // Payment Gateway Tools adds, renames and removes copies of this gateway.
        add_filter('stackless_gateway_copy_families', [Copies::class, 'declare']);
        add_action('stackless_gateway_copy_removed', [Copies::class, 'removed']);
        add_filter('stackless_payment_alerts_gateways', [Health::class, 'alertRoute']);
        add_filter('stackless_billing_health_sources', [Health::class, 'register']);
    }

    /** @param array $gateways */
    public function registerGateways($gateways)
    {
        foreach (Gateway::all() as $gateway) {
            $gateways[] = $gateway;
        }
        return $gateways;
    }

    public function registerBlocks($registry): void
    {
        if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            return;
        }
        foreach (Gateway::all() as $gateway) {
            $registry->register(new BlocksSupport($gateway));
        }
    }

    /** Tells the Checkout block whether the cart fits Creem (one subscription, nothing else). */
    public function extendStoreApi(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'cart',
            'namespace' => 'stackless_creem_gateway',
            'schema_type' => ARRAY_A,
            'data_callback' => static function () {
                $reason = Eligibility::cartReason();
                return ['eligible' => $reason === Eligibility::OK, 'reason' => $reason];
            },
            'schema_callback' => static function () {
                return [
                    'eligible' => [
                        'description' => __('Whether Creem can take this cart.', 'stackless-lite'),
                        'type' => 'boolean',
                        'context' => ['view', 'edit'],
                        'readonly' => true,
                    ],
                    'reason' => [
                        'description' => __('Why Creem cannot take this cart, when it cannot.', 'stackless-lite'),
                        'type' => 'string',
                        'context' => ['view', 'edit'],
                        'readonly' => true,
                    ],
                ];
            },
        ]);
    }
}
