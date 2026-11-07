<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Saved Razorpay token that is not a card (UPI Autopay or an eNACH bank mandate), shown by its
 * label. Card mandates use WC_Payment_Token_CC.
 */
class PaymentToken extends \WC_Payment_Token
{
    public const TYPE = 'STACKLESS_Razorpay';

    /** @var string */
    protected $type = self::TYPE;

    /** @var array<string,string> */
    protected $extra_data = [
        'label' => '',
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
        return 'woocommerce_payment_token_stackless_razorpay_get_';
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
}
