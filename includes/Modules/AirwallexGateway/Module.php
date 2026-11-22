<?php

namespace Stackless\Modules\AirwallexGateway;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

final class Module extends BaseModule
{
    /** A session lives an hour; a quarter more for the webhook. */
    private const SESSION_SECONDS = 4500;

    public function id(): string
    {
        return Settings::MODULE_ID;
    }

    public function title(): string
    {
        return __('Airwallex (beta)', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Take cards, wallets and local payment methods on Airwallex’s hosted payment page, save cards, and renew subscriptions automatically. Refunds, disputes and payment updates sync from Airwallex.', 'stackless-lite');
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
                'id' => 'stackless_airwallex_gateway_about',
                'type' => 'title',
                'title' => __('Airwallex (beta)', 'stackless-lite'),
                'desc' => sprintf(
                    /* translators: %s: link to the gateway settings */
                    __('API keys, the webhook and checkout options live with the other payment methods: %s.', 'stackless-lite'),
                    '<a href="' . esc_url(Settings::settingsUrl()) . '">' . esc_html__('WooCommerce → Settings → Payments → Airwallex', 'stackless-lite') . '</a>'
                ),
            ],
            ['id' => 'stackless_airwallex_gateway_about', 'type' => 'sectionend'],
        ];
    }

    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);
        add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'registerBlocks']);
        add_action('rest_api_init', [Webhook::class, 'register']);
        add_action('woocommerce_api_' . Returns::ACTION, [Returns::class, 'handle']);
        add_action(Renewals::RETRY_HOOK, [Renewals::class, 'retry']);
        add_filter('woocommerce_cancel_unpaid_order', [self::class, 'keepWhilePaying'], 20, 2);
        Tokens::registerHooks();
        Health::registerHooks();
        Privacy::registerHooks();
    }

    /** @param array $gateways */
    public function registerGateway($gateways)
    {
        $gateways[] = Gateway::class;
        return $gateways;
    }

    /**
     * "Hold stock" can be shorter than a Checkout Session's hour. An order whose hosted page may
     * still take the money is not cancelled for being unpaid until that hour has passed.
     *
     * @param mixed $cancel
     * @param mixed $order
     */
    public static function keepWhilePaying($cancel, $order = null): bool
    {
        if ($cancel && $order instanceof \WC_Order && $order->get_payment_method() === Settings::GATEWAY_ID && (string) $order->get_meta(Meta::SESSION) !== '') {
            $changed = $order->get_date_modified();
            return !$changed || $changed->getTimestamp() < time() - self::SESSION_SECONDS;
        }
        return (bool) $cancel;
    }

    public function registerBlocks($registry): void
    {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            $registry->register(new BlocksSupport());
        }
    }
}
