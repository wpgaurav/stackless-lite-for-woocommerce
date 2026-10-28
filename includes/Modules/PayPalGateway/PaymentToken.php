<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * A PayPal Vault v3 payment token (the buyer's PayPal wallet, saved for merchant-initiated charges).
 *
 * The token value is the PayPal vault id. Stored in WooCommerce's payment tokens
 * table with type "Stackless_PayPal"; WooCommerce resolves the class through the
 * woocommerce_payment_token_class filter (see Module::boot()).
 */
class PaymentToken extends \WC_Payment_Token
{
    public const TYPE = 'Stackless_PayPal';

    /** @var string */
    protected $type = self::TYPE;

    /** @var array */
    protected $extra_data = [
        'email' => '',
        'payer_id' => '',
        'customer_id' => '',
        'environment' => '',
    ];

    /** woocommerce_payment_token_class callback. */
    public static function filterClass($class, $type)
    {
        return $type === self::TYPE ? self::class : $class;
    }

    public function get_display_name($deprecated = '')
    {
        $email = $this->get_email();
        if ($email !== '') {
            /* translators: %s: PayPal account email */
            return sprintf(__('PayPal (%s)', 'stackless-lite'), $email);
        }
        return __('PayPal', 'stackless-lite');
    }

    protected function get_hook_prefix()
    {
        return 'stackless_paypal_gateway_token_get_';
    }

    public function validate()
    {
        return parent::validate() && $this->get_token('edit') !== '';
    }

    public function get_email($context = 'view'): string
    {
        return (string) $this->get_prop('email', $context);
    }

    public function set_email($email): void
    {
        $this->set_prop('email', sanitize_email((string) $email));
    }

    public function get_payer_id($context = 'view'): string
    {
        return (string) $this->get_prop('payer_id', $context);
    }

    public function set_payer_id($id): void
    {
        $this->set_prop('payer_id', sanitize_text_field((string) $id));
    }

    public function get_customer_id($context = 'view'): string
    {
        return (string) $this->get_prop('customer_id', $context);
    }

    public function set_customer_id($id): void
    {
        $this->set_prop('customer_id', sanitize_text_field((string) $id));
    }

    /** live|sandbox: a vault id only works in the environment that created it. */
    public function get_environment($context = 'view'): string
    {
        return (string) $this->get_prop('environment', $context);
    }

    public function set_environment($environment): void
    {
        $this->set_prop('environment', $environment === 'sandbox' ? 'sandbox' : 'live');
    }

    /** My Account → Payment methods row. */
    public static function listItem($item, $token)
    {
        if ($token instanceof self) {
            $item['method']['brand'] = 'stackless_paypal';
            $item['method']['last4'] = '';
        }
        return $item;
    }

    /** woocommerce_credit_card_type_labels: label for the brand set in listItem(). */
    public static function brandLabel($labels)
    {
        $labels = (array) $labels;
        $labels['stackless paypal'] = __('PayPal', 'stackless-lite');
        return $labels;
    }
}
