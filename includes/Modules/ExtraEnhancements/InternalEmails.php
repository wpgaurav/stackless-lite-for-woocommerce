<?php

namespace Stackless\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Internal and test emails: orders from listed addresses or domains are recorded as
 * usual, but checkout doesn't create a customer account for them and integrations
 * don't run (WooCommerce webhooks, and Stackless modules that ask the
 * stackless_skip_integrations filter, such as the FluentCRM sync).
 */
final class InternalEmails
{
    public const META = '_stackless_internal_email';

    /** @var string[] */
    private $rules;

    /** @var bool */
    private $skipAccounts;

    /** @var bool */
    private $skipIntegrations;

    /** @param string[] $rules */
    public function __construct(array $rules, bool $skipAccounts, bool $skipIntegrations)
    {
        $this->rules = $rules;
        $this->skipAccounts = $skipAccounts;
        $this->skipIntegrations = $skipIntegrations;
    }

    /** One address or domain per line, comma or space. "@example.com" and "example.com" both mean the whole domain. @return string[] */
    public static function parse(string $value): array
    {
        $rules = [];
        foreach ((array) preg_split('/[\s,;]+/', strtolower($value)) as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if (strpos($rule, '@') > 0) {
                if (is_email($rule)) {
                    $rules[] = $rule;
                }
                continue;
            }
            $domain = ltrim($rule, '@');
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                $rules[] = '@' . $domain;
            }
        }
        return array_values(array_unique($rules));
    }

    public function matches(string $email): bool
    {
        $email = strtolower(trim($email));
        $at = strpos($email, '@');
        if ($email === '' || $at === false) {
            return false;
        }
        $domain = substr($email, $at);
        foreach ($this->rules as $rule) {
            if ($rule === $email || ($rule[0] === '@' && $rule === $domain)) {
                return true;
            }
        }
        return false;
    }

    public function register(): void
    {
        if ($this->skipAccounts) {
            add_filter('woocommerce_checkout_registration_required', [$this, 'noAccount'], 99);
            add_filter('woocommerce_checkout_registration_enabled', [$this, 'noAccount'], 99);
            add_filter('woocommerce_checkout_posted_data', [$this, 'postedData'], 99);
        }
        if ($this->skipIntegrations) {
            add_filter('stackless_skip_integrations', [$this, 'skip'], 10, 2);
            add_filter('woocommerce_webhook_should_deliver', [$this, 'webhook'], 20, 3);
        }
        add_action('woocommerce_checkout_order_processed', [$this, 'mark'], 5, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'mark'], 5, 1);
    }

    /** The email of the checkout being processed: posted (classic) or on the customer (Checkout block). */
    public static function checkoutEmail(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce; this only reads the address.
        if (isset($_POST['billing_email']) && is_string($_POST['billing_email'])) {
            return sanitize_email(wp_unslash($_POST['billing_email']));
        }
        return function_exists('WC') && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
    }

    /** @param mixed $value */
    public function noAccount($value)
    {
        return !is_user_logged_in() && $this->matches(self::checkoutEmail()) ? false : $value;
    }

    /** @param mixed $data */
    public function postedData($data)
    {
        if (is_array($data) && $this->matches((string) ($data['billing_email'] ?? ''))) {
            $data['createaccount'] = 0;
        }
        return $data;
    }

    /** @param mixed $skip */
    public function skip($skip, $email = ''): bool
    {
        return (bool) $skip || $this->matches((string) $email);
    }

    /**
     * @param mixed $deliver
     * @param mixed $webhook
     * @param mixed $arg Resource id: an order, subscription or customer.
     */
    public function webhook($deliver, $webhook, $arg)
    {
        if (!$deliver || !$webhook instanceof \WC_Webhook || !is_numeric($arg)) {
            return $deliver;
        }
        $resource = $webhook->get_resource();
        $email = '';
        if ($resource === 'customer') {
            $user = get_userdata((int) $arg);
            $email = $user ? (string) $user->user_email : '';
        } elseif (in_array($resource, ['order', 'subscription'], true)) {
            $order = wc_get_order((int) $arg);
            $email = $order instanceof \WC_Order ? (string) $order->get_billing_email() : '';
        }
        return $email !== '' && $this->matches($email) ? false : $deliver;
    }

    /** Tags the order and leaves a note so nobody wonders why nothing ran. @param mixed $order */
    public function mark($order, $posted = null, $orderObject = null): void
    {
        $order = $orderObject instanceof \WC_Order ? $orderObject : (is_numeric($order) ? wc_get_order((int) $order) : $order);
        if (!$order instanceof \WC_Order || !$this->matches((string) $order->get_billing_email())) {
            return;
        }
        $skipped = array_filter([
            $this->skipAccounts ? __('no account was created', 'stackless-lite') : '',
            $this->skipIntegrations ? __('integrations did not run', 'stackless-lite') : '',
        ]);
        $order->update_meta_data(self::META, 'yes');
        $order->save();
        if ($skipped) {
            /* translators: %s: what was skipped, e.g. "no account was created and integrations did not run" */
            $order->add_order_note(sprintf(__('Internal email: this address is on the Extra Enhancements internal list, so %s.', 'stackless-lite'), implode(__(' and ', 'stackless-lite'), $skipped)));
        }
    }
}
