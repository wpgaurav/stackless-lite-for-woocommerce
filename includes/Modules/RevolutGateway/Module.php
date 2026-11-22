<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    public function id(): string
    {
        return Keys::MODULE_ID;
    }

    public function title(): string
    {
        return __('Revolut', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take cards, Revolut Pay and the other methods switched on in your Revolut merchant account on Revolut’s hosted page, save cards for subscriptions and renew them automatically. Refunds, payment updates and disputes sync from Revolut.', 'stackless-lite');
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
        return array_merge(parent::optionNames(), [Settings::OPTION]);
    }

    public function settingsFields(): array
    {
        return [
            [
                'id' => 'stackless_revolut_gateway_about',
                'type' => 'title',
                'title' => __('Revolut', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: %s: link to the gateway settings */
                    __('Keys, test mode and checkout options live with the other payment methods: %s. Saving there checks the key and sets the webhook up.', 'stackless-lite'),
                    '<a href="' . esc_url(Settings::settingsUrl()) . '">' . esc_html__('WooCommerce → Settings → Payments → Revolut', 'stackless-lite') . '</a>'
                ),
            ],
            ['id' => 'stackless_revolut_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_' . Pages::ACTION, [Pages::class, 'route']);
        add_action(Jobs::HOOK, [Jobs::class, 'run']);
        add_filter('woocommerce_cancel_unpaid_order', [$this, 'keepProcessingOrder'], 10, 2);
        add_filter('stackless_billing_health_sources', [$this, 'healthSource']);
        add_filter('stackless_payment_alerts_gateways', [$this, 'alertRoute']);
        add_action('admin_init', [$this, 'privacyPolicy']);
        Tokens::registerHooks();
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

    /**
     * "Hold stock" must not cancel an order while Revolut is still processing its payment.
     *
     * @param mixed $cancel
     * @param mixed $order
     */
    public function keepProcessingOrder($cancel, $order = null): bool
    {
        if ($cancel && $order instanceof \WC_Order && $order->get_payment_method() === Keys::GATEWAY_ID && $order->get_meta(Keys::WAITING) === 'yes') {
            return false;
        }
        return (bool) $cancel;
    }

    /**
     * @param mixed $sources
     * @return array<string,mixed>
     */
    public function healthSource($sources): array
    {
        $sources = is_array($sources) ? $sources : [];
        $sources[Keys::MODULE_ID] = [Health::class, 'report'];
        return $sources;
    }

    /**
     * @param mixed $routes
     * @return array<string,mixed>
     */
    public function alertRoute($routes): array
    {
        $routes = is_array($routes) ? $routes : [];
        $routes['revolut'] = Health::route();
        return $routes;
    }

    /** Suggested text for the store's privacy policy. */
    public function privacyPolicy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Stackless: Revolut', 'stackless-lite'),
            wp_kses_post('<p>' . __('When you pay with Revolut, we send Revolut your name, email address, shipping address, and the items and amount of the order. Card details are entered on Revolut’s page or checkout window and never reach our servers. For subscriptions, Revolut saves the card so we can charge renewals; we keep its brand, last four digits and expiry date.', 'stackless-lite') . '</p>')
        );
    }
}
