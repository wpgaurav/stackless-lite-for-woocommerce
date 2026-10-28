<?php

namespace Stackless\Modules\ExtraEnhancements;

use Stackless\Core\Module as BaseModule;

defined('ABSPATH') || exit;

/**
 * Small store improvements that don't need a module of their own, each with its own
 * switch: payment failure alerts, per-product payment methods and after-purchase
 * links, coupon links, internal test emails, a checkout spam guard, and a hidden
 * payment section when the only method is a hosted checkout.
 */
final class Module extends BaseModule
{
    public function id(): string
    {
        return 'extra_enhancements';
    }

    public function title(): string
    {
        return __('Extra Enhancements', 'stackless-lite');
    }

    public function description(): string
    {
        if (!self::hasAlerts()) {
            return __('Small fixes with their own switches: payment methods and an after-purchase link per product, ?coupon= links that stick, internal test emails that skip accounts and integrations, a checkout guard against throwaway emails and card testing, and no empty payment section when the only method is a hosted checkout.', 'stackless-lite');
        }
        return __('Small fixes with their own switches: email alerts for failed live payments, payment methods and an after-purchase link per product, ?coupon= links that stick, internal test emails that skip accounts and integrations, a checkout guard against throwaway emails and card testing, and no empty payment section when the only method is a hosted checkout.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'checkout';
    }

    /** Builds without payment gateways (Stackless Lite) leave out the payment alerts. */
    private static function hasAlerts(): bool
    {
        return class_exists(PaymentAlerts::class);
    }

    public function settingsFields(): array
    {
        $p = 'stackless_extra_enhancements_';
        return array_merge(self::hasAlerts() ? self::alertFields($p) : [], self::otherFields($p));
    }

    private static function alertFields(string $p): array
    {
        return [
            ['type' => 'title', 'id' => $p . 'alerts_section', 'title' => __('Payment failure alerts', 'stackless-lite'), 'desc' => __('Emails you when a live payment fails or a payment provider’s API refuses a live request (refunds, checkouts, renewals). Test and sandbox activity never alerts. The email has no keys, card or customer details. Repeats of the same failure are held back for 30 minutes.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'payment_alerts', 'title' => __('Alerts', 'stackless-lite'), 'desc' => __('Email me about failed live payments', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'text', 'id' => $p . 'alert_email', 'title' => __('Send alerts to', 'stackless-lite'), 'default' => '', 'placeholder' => (string) get_option('admin_email'), 'desc' => __('Leave empty to use the site admin email.', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'multiselect', 'id' => $p . 'alert_gateways', 'title' => __('Watch these gateways', 'stackless-lite'), 'default' => array_keys(PaymentAlerts::GATEWAYS), 'class' => 'wc-enhanced-select', 'options' => array_map(static function ($gateway) {
                return $gateway['label'];
            }, PaymentAlerts::GATEWAYS), 'desc' => __('Clear the list to watch none.', 'stackless-lite')],
            ['type' => 'sectionend', 'id' => $p . 'alerts_section'],
        ];
    }

    private static function otherFields(string $p): array
    {
        return [
            ['type' => 'title', 'id' => $p . 'checkout_section', 'title' => __('Checkout', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'product_checkout', 'title' => __('Per-product checkout', 'stackless-lite'), 'desc' => __('Choose payment methods and an after-purchase link on each product (Product data → Checkout)', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'single_payment_hide', 'title' => __('Hosted-only payment', 'stackless-lite'), 'desc' => __('Hide the payment section when the only payment method is a hosted checkout (Polar, Paddle, Dodo Payments)', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'coupon_links', 'title' => __('Coupon links', 'stackless-lite'), 'desc' => __('Any store link with ?coupon=CODE keeps the code for a day and applies it once the visitor has a cart', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'checkout_section'],

            ['type' => 'title', 'id' => $p . 'internal_section', 'title' => __('Internal and test emails', 'stackless-lite'), 'desc' => __('Orders from these addresses or domains are recorded as usual but don’t create a customer account and don’t run integrations (Stackless FluentCRM sync and WooCommerce webhooks). Use it for your own test purchases.', 'stackless-lite')],
            ['type' => 'textarea', 'id' => $p . 'internal_emails', 'title' => __('Emails and domains', 'stackless-lite'), 'default' => '', 'placeholder' => "you@example.com\n@yourcompany.com", 'desc' => __('One per line. @example.com or example.com means the whole domain.', 'stackless-lite'), 'css' => 'min-width:300px;height:90px'],
            ['type' => 'checkbox', 'id' => $p . 'internal_skip_accounts', 'title' => __('Accounts', 'stackless-lite'), 'desc' => __('Don’t create a customer account', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'checkbox', 'id' => $p . 'internal_skip_integrations', 'title' => __('Integrations', 'stackless-lite'), 'desc' => __('Don’t run integrations', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'sectionend', 'id' => $p . 'internal_section'],

            ['type' => 'title', 'id' => $p . 'guard_section', 'title' => __('Checkout guard', 'stackless-lite'), 'desc' => __('Refuses throwaway email addresses and slows down a connection that places too many orders in an hour (card testing). Store managers are never limited.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'guard_disposable', 'title' => __('Throwaway emails', 'stackless-lite'), 'desc' => __('Refuse well-known throwaway inboxes (Mailinator, Guerrilla Mail, YOPmail and others)', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'textarea', 'id' => $p . 'guard_domains', 'title' => __('Also refuse', 'stackless-lite'), 'default' => '', 'placeholder' => 'example-spam.com', 'desc' => __('More domains, one per line.', 'stackless-lite'), 'css' => 'min-width:300px;height:70px'],
            ['type' => 'number', 'id' => $p . 'guard_hourly_limit', 'title' => __('Orders per hour', 'stackless-lite'), 'default' => '10', 'desc' => __('Most order attempts one connection may make in an hour. 0 turns the limit off.', 'stackless-lite'), 'css' => 'width:90px', 'custom_attributes' => ['min' => '0', 'step' => '1']],
            ['type' => 'sectionend', 'id' => $p . 'guard_section'],
        ];
    }

    private function on(string $key): bool
    {
        return $this->option($key, 'yes') === 'yes';
    }

    public function boot(): void
    {
        if (self::hasAlerts() && $this->on('payment_alerts')) {
            PaymentAlerts::init((string) $this->option('alert_email', ''), PaymentAlerts::selectedRoutes($this->option('alert_gateways', null)));
        }
        if ($this->on('product_checkout')) {
            (new ProductCheckout())->register();
        }
        if ($this->on('single_payment_hide')) {
            (new SinglePaymentMethod())->register();
        }
        if ($this->on('coupon_links')) {
            (new CouponLinks())->register();
        }
        $internal = InternalEmails::parse((string) $this->option('internal_emails', ''));
        if ($internal) {
            (new InternalEmails($internal, $this->on('internal_skip_accounts'), $this->on('internal_skip_integrations')))->register();
        }
        $guard = new CheckoutGuard($this->on('guard_disposable'), CheckoutGuard::parseDomains((string) $this->option('guard_domains', '')), (int) $this->option('guard_hourly_limit', 10));
        $guard->register();
    }
}
