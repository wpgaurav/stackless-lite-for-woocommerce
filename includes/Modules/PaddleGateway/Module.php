<?php

namespace Stackless\Modules\PaddleGateway;

use Stackless\Contracts\SubscriptionData;
use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return 'paddle_gateway';
    }

    public function title(): string
    {
        return __('Paddle', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Paddle Billing as a merchant of record: an overlay checkout with sales tax and VAT handled for you, one-time orders, pay links, and subscriptions (with signup fees, free trials and one-time items in the same cart) that Paddle renews. Payments, renewals, refunds and cancellations sync both ways through signed webhooks.', 'stackless-lite');
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
                'id' => 'stackless_paddle_gateway_about',
                'type' => 'title',
                'title' => __('Paddle', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: 1: link to the gateway settings, 2: webhook URL */
                    __('API keys, client-side tokens, webhook secrets and tax behavior live with the other payment methods: %1$s. Webhook URL for Paddle: %2$s', 'stackless-lite'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID)) . '">' . esc_html__('WooCommerce → Settings → Payments → Paddle', 'stackless-lite') . '</a>',
                    '<code>' . esc_html(rest_url('stackless/v1/paddle/webhook')) . '</code>'
                ),
            ],
            ['id' => 'stackless_paddle_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        // woocommerce_blocks_loaded fires at plugins_loaded:10, before modules boot (20).
        if (did_action('woocommerce_blocks_loaded')) {
            $this->extendStoreApi();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'extendStoreApi']);
        }
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_stackless_paddle_pay', [$this, 'handlePayPage']);
        add_action('woocommerce_api_stackless_paddle_return', [$this, 'handleReturn']);
        add_action('woocommerce_api_stackless_paddle_update_payment', [$this, 'handleUpdatePayment']);
        add_filter('stackless_subscriptions_customer_actions', [$this, 'customerActions'], 10, 2);
        // Payment Gateway Tools adds, renames and removes copies of this gateway.
        add_filter('stackless_gateway_copy_families', [Copies::class, 'declare']);
        add_action('stackless_gateway_copy_removed', [Copies::class, 'removed']);
    }

    /** @param array $gateways */
    public function registerGateway($gateways)
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

    /** Tells the Checkout block whether the cart fits Paddle (one subscription schedule at most). */
    public function extendStoreApi(): void
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'cart',
            'namespace' => 'stackless_paddle_gateway',
            'schema_type' => ARRAY_A,
            'data_callback' => static function () {
                $reason = Eligibility::cartReason();
                return ['eligible' => $reason === Eligibility::OK, 'reason' => $reason];
            },
            'schema_callback' => static function () {
                return [
                    'eligible' => [
                        'description' => __('Whether Paddle can take this cart.', 'stackless-lite'),
                        'type' => 'boolean',
                        'context' => ['view', 'edit'],
                        'readonly' => true,
                    ],
                    'reason' => [
                        'description' => __('Why Paddle cannot take this cart, when it cannot.', 'stackless-lite'),
                        'type' => 'string',
                        'context' => ['view', 'edit'],
                        'readonly' => true,
                    ],
                ];
            },
        ]);
    }

    /**
     * One pay, return and update route serves Paddle and every copy: the order (or
     * subscription) in the request says which one. Checkout re-checks the key or nonce.
     */
    private static function requestGateway(): Gateway
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- lookup only; Checkout authenticates the request.
        $id = isset($_GET['order_id']) ? absint($_GET['order_id']) : (isset($_GET['subscription_id']) ? absint($_GET['subscription_id']) : 0);
        // phpcs:enable
        $order = $id ? wc_get_order($id) : false;
        $gateway = $order instanceof \WC_Order ? Gateway::forOrder($order) : null;
        return $gateway ?: Gateway::instance();
    }

    public function handlePayPage(): void
    {
        (new Checkout(self::requestGateway()))->renderPayPage();
    }

    public function handleReturn(): void
    {
        (new Checkout(self::requestGateway()))->handleReturn();
    }

    public function handleUpdatePayment(): void
    {
        (new Checkout(self::requestGateway()))->handleUpdatePayment();
    }

    /**
     * Paddle keeps the card for subscriptions it bills, so "update payment method"
     * opens Paddle's own update checkout instead of the store's form.
     *
     * @param array $actions
     * @param mixed $subscription
     * @return array
     */
    public function customerActions($actions, $subscription)
    {
        if (!$subscription instanceof \WC_Order || !Copies::belongs((string) $subscription->get_payment_method())) {
            return $actions;
        }
        if ((string) $subscription->get_meta(SubscriptionData::META_GATEWAY_SUBSCRIPTION) === '') {
            return $actions;
        }
        $open = [SubscriptionData::STATUS_ACTIVE, SubscriptionData::STATUS_TRIAL, SubscriptionData::STATUS_ON_HOLD, SubscriptionData::STATUS_PENDING_CANCEL];
        if (!in_array($subscription->get_status(), $open, true)) {
            return $actions;
        }
        $actions['paddle_update_payment'] = [
            'label' => __('Update payment method', 'stackless-lite'),
            'url' => Checkout::updatePaymentUrl($subscription),
            'class' => 'button',
        ];
        return $actions;
    }
}
