<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Saved Stripe payment method that is neither a card nor a bank debit (for example Link).
 * Card-like methods use WC_Payment_Token_CC and bank debits use WC_Payment_Token_ECheck.
 */
class PaymentToken extends \WC_Payment_Token
{
    public const TYPE = 'STACKLESS_Stripe';

    /** @var string */
    protected $type = self::TYPE;

    /** @var array<string,string> */
    protected $extra_data = [
        'label' => '',
        'method_type' => '',
    ];

    /**
     * @param string $deprecated Unused, matches the parent signature.
     */
    public function get_display_name($deprecated = '')
    {
        $label = $this->get_label();
        return $label !== '' ? $label : __('Saved payment method', 'stackless-lite');
    }

    protected function get_hook_prefix()
    {
        return 'woocommerce_payment_token_stackless_stripe_get_';
    }

    /** @param string $context */
    public function get_label($context = 'view'): string
    {
        return (string) $this->get_prop('label', $context);
    }

    public function set_label(string $label): void
    {
        $this->set_prop('label', $label);
    }

    /** @param string $context */
    public function get_method_type($context = 'view'): string
    {
        return (string) $this->get_prop('method_type', $context);
    }

    public function set_method_type(string $type): void
    {
        $this->set_prop('method_type', $type);
    }
}
