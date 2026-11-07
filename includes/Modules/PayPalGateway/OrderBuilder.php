<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Builds Orders v2 and Vault v3 request bodies from WooCommerce carts and orders.
 *
 * Totals always come from WooCommerce's own calculation on the server; nothing
 * the browser sends affects an amount.
 */
class OrderBuilder
{
    public const REFERENCE = 'default';

    /** @var Gateway */
    private $gateway;

    public function __construct(Gateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /** Order body for the current cart (checkout page, before the WooCommerce order exists). */
    public function fromCart(\WC_Cart $cart, bool $vault, string $funding, bool $withShipping = true): array
    {
        $currency = strtoupper(get_woocommerce_currency());
        $total = (float) $cart->get_total('edit');

        $fees = $this->splitFees(array_map(static function ($fee) {
            return (float) $fee->total;
        }, $cart->get_fees()));

        $unit = [
            'reference_id' => self::REFERENCE,
            'description' => $this->description(),
            'amount' => $this->amount($total, $currency, [
                'item_total' => (float) $cart->get_subtotal() + $fees['positive'],
                'shipping' => (float) $cart->get_shipping_total(),
                'tax_total' => (float) $cart->get_total_tax(),
                'discount' => (float) $cart->get_discount_total() + $fees['negative'],
            ]),
        ];

        $shippingPreference = 'NO_SHIPPING';
        if ($cart->needs_shipping()) {
            $shipping = $withShipping && WC()->customer ? $this->shippingFromCustomer(WC()->customer) : null;
            if ($shipping) {
                $unit['shipping'] = $shipping;
                $shippingPreference = 'SET_PROVIDED_ADDRESS';
            } else {
                $shippingPreference = 'GET_FROM_FILE';
            }
        }

        $checkoutUrl = wc_get_checkout_url();
        return $this->finish([
            'intent' => $vault ? 'CAPTURE' : strtoupper($this->gateway->intent()),
            'purchase_units' => [$unit],
        ], $funding, $vault, $shippingPreference, $checkoutUrl, $checkoutUrl, ['context' => 'cart']);
    }

    /**
     * Order body for an existing WooCommerce order (order-pay page, redirect fallback).
     *
     * @param string $returnUrl Where PayPal sends the buyer after approval (redirect flow only).
     */
    public function fromOrder(\WC_Order $order, bool $vault, string $funding, string $returnUrl = '', bool $withShipping = true): array
    {
        $unit = $this->purchaseUnit($order, $withShipping);
        $shippingPreference = 'NO_SHIPPING';
        if ($order->needs_shipping_address()) {
            $shippingPreference = isset($unit['shipping']) ? 'SET_PROVIDED_ADDRESS' : 'GET_FROM_FILE';
        }

        $cancel = $order->get_checkout_payment_url();
        return $this->finish([
            'intent' => $vault ? 'CAPTURE' : strtoupper($this->gateway->intent()),
            'purchase_units' => [$unit],
        ], $funding, $vault, $shippingPreference, $returnUrl !== '' ? $returnUrl : $cancel, $cancel, ['context' => 'order', 'order' => $order]);
    }

    /**
     * Merchant-initiated renewal charge against a vaulted PayPal account or card.
     *
     * @param string $type paypal|card
     */
    public function renewal(\WC_Order $renewal, string $vaultId, string $type = 'paypal'): array
    {
        $unit = $this->purchaseUnit($renewal, true);
        /* translators: %s: site name */
        $unit['description'] = Amount::truncate(sprintf(__('Subscription renewal – %s', 'stackless-lite'), $this->gateway->brandName()), 127);

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => self::vaultSource($vaultId, $type, 'MERCHANT', 'RECURRING'),
        ];
        return (array) apply_filters('stackless_paypal_gateway_renewal_args', $body, $renewal);
    }

    /**
     * Charge outside the renewal schedule against a saved PayPal account or card
     * (Contracts\SavedMethodCharger, e.g. a post-purchase offer).
     *
     * @param string $type paypal|card
     * @param bool $onSession The buyer is on the page and may approve or authenticate.
     */
    public function savedCharge(\WC_Order $order, string $vaultId, string $type, bool $onSession, string $returnUrl): array
    {
        $unit = $this->purchaseUnit($order, true);
        $source = self::vaultSource($vaultId, $type, $onSession ? 'CUSTOMER' : 'MERCHANT', 'UNSCHEDULED');
        if ($onSession) {
            $context = ['return_url' => $returnUrl, 'cancel_url' => $order->get_checkout_payment_url()];
            if ($type === 'card') {
                $source['card']['attributes'] = ['verification' => ['method' => $this->gateway->threeDSecureMethod()]];
                $source['card']['experience_context'] = $context;
            } else {
                $source['paypal']['experience_context'] = array_merge(['brand_name' => $this->gateway->brandName(), 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW'], $context);
            }
        }
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => $source,
        ];
        return (array) apply_filters('stackless_paypal_gateway_saved_charge_args', $body, $order, $onSession);
    }

    /**
     * payment_source for a vault id. Cards carry the card-network stored-credential fields;
     * PayPal wallets take payment_initiator and usage only.
     */
    public static function vaultSource(string $vaultId, string $type, string $initiator, string $paymentType): array
    {
        if ($type === 'card') {
            return ['card' => [
                'vault_id' => $vaultId,
                'stored_credential' => [
                    'payment_initiator' => $initiator,
                    'payment_type' => $paymentType,
                    'usage' => 'SUBSEQUENT',
                ],
            ]];
        }
        return ['paypal' => [
            'vault_id' => $vaultId,
            'stored_credential' => [
                'payment_initiator' => $initiator,
                'usage' => 'SUBSEQUENT',
            ],
        ]];
    }

    /**
     * Order paid with a Fastlane single-use card token: PayPal charges it on create, so the
     * order is captured in one call (or asks for 3-D Secure).
     */
    public function fromSingleUseToken(\WC_Order $order, string $token, bool $vault, string $returnUrl): array
    {
        $unit = $this->purchaseUnit($order, true);
        $card = [
            'single_use_token' => $token,
            'attributes' => ['verification' => ['method' => $this->gateway->threeDSecureMethod()]],
            'experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $order->get_checkout_payment_url()],
        ];
        if ($vault) {
            $card['attributes']['vault'] = ['store_in_vault' => 'ON_SUCCESS'];
        }
        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => ['card' => $card],
        ];
        return (array) apply_filters('stackless_paypal_gateway_order_args', $body, ['context' => 'fastlane', 'order' => $order]);
    }

    /** Vault v3 setup token: save a PayPal account without charging it. */
    public function setupToken(string $returnUrl, string $cancelUrl): array
    {
        $body = [
            'payment_source' => [
                'paypal' => [
                    'description' => Amount::truncate(sprintf(
                        /* translators: %s: store name */
                        __('Future payments to %s', 'stackless-lite'),
                        $this->gateway->brandName()
                    ), 127),
                    'usage_type' => 'MERCHANT',
                    'customer_type' => 'CONSUMER',
                    'permit_multiple_payment_tokens' => true,
                    'experience_context' => [
                        'brand_name' => $this->gateway->brandName(),
                        'shipping_preference' => 'NO_SHIPPING',
                        'vault_instruction' => 'ON_PAYER_APPROVAL',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
        ];
        return (array) apply_filters('stackless_paypal_gateway_setup_token_args', $body);
    }

    /**
     * PATCH operations that make an approved PayPal order match the WooCommerce order
     * (cart totals can move between approval and Place order).
     */
    public function patchOperations(\WC_Order $order, array $paypalOrder): array
    {
        $unit = (array) ($paypalOrder['purchase_units'][0] ?? []);
        $reference = (string) ($unit['reference_id'] ?? self::REFERENCE);
        $path = "/purchase_units/@reference_id=='" . $reference . "'";
        $wanted = $this->purchaseUnit($order, false);

        $ops = [];
        $current = (array) ($unit['amount'] ?? []);
        $currentBreakdown = isset($current['breakdown']) ? (array) $current['breakdown'] : [];
        $wantedBreakdown = isset($wanted['amount']['breakdown']) ? (array) $wanted['amount']['breakdown'] : [];
        if (!Amount::matches($current, $order->get_total(), $order->get_currency()) || $this->normalize($currentBreakdown) !== $this->normalize($wantedBreakdown)) {
            $ops[] = ['op' => 'replace', 'path' => $path . '/amount', 'value' => $wanted['amount']];
        }
        if ((string) ($unit['custom_id'] ?? '') !== $wanted['custom_id']) {
            $ops[] = ['op' => 'add', 'path' => $path . '/custom_id', 'value' => $wanted['custom_id']];
        }
        if ((string) ($unit['invoice_id'] ?? '') !== $wanted['invoice_id']) {
            $ops[] = ['op' => 'add', 'path' => $path . '/invoice_id', 'value' => $wanted['invoice_id']];
        }
        return $ops;
    }

    /** One purchase unit for a WooCommerce order. */
    public function purchaseUnit(\WC_Order $order, bool $withShipping): array
    {
        $currency = $order->get_currency();
        $fees = $this->splitFees(array_map(static function ($fee) {
            return (float) $fee->get_total();
        }, $order->get_fees()));

        $unit = [
            'reference_id' => self::REFERENCE,
            'custom_id' => (string) $order->get_id(),
            'invoice_id' => Amount::truncate($this->gateway->invoicePrefix() . $order->get_order_number(), 127),
            'description' => $this->description(),
            'amount' => $this->amount((float) $order->get_total(), $currency, [
                'item_total' => (float) $order->get_subtotal() + $fees['positive'],
                'shipping' => (float) $order->get_shipping_total(),
                'tax_total' => (float) $order->get_total_tax(),
                'discount' => (float) $order->get_discount_total() + $fees['negative'],
            ]),
        ];

        if ($withShipping && $order->needs_shipping_address() && $order->has_shipping_address()) {
            $shipping = $this->shipping(
                trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                [
                    'address_1' => $order->get_shipping_address_1(),
                    'address_2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'postcode' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country(),
                ]
            );
            if ($shipping) {
                $unit['shipping'] = $shipping;
            }
        }
        return $unit;
    }

    private function amount(float $total, string $currency, array $parts): array
    {
        $amount = Amount::money($total, $currency);
        $breakdown = Amount::breakdown($parts, $total, $currency);
        if ($breakdown) {
            $amount['breakdown'] = $breakdown;
        }
        return $amount;
    }

    /** @param float[] $fees @return array{positive:float,negative:float} */
    private function splitFees(array $fees): array
    {
        $positive = 0.0;
        $negative = 0.0;
        foreach ($fees as $fee) {
            if ($fee >= 0) {
                $positive += $fee;
            } else {
                $negative += abs($fee);
            }
        }
        return ['positive' => $positive, 'negative' => $negative];
    }

    private function normalize(array $breakdown): array
    {
        $out = [];
        foreach ($breakdown as $key => $money) {
            $out[$key] = is_array($money) ? strtoupper((string) ($money['currency_code'] ?? '')) . ':' . (string) ($money['value'] ?? '') : '';
        }
        ksort($out);
        return $out;
    }

    private function description(): string
    {
        /* translators: %s: store name */
        return Amount::truncate(sprintf(__('Order from %s', 'stackless-lite'), $this->gateway->brandName()), 127);
    }

    private function shippingFromCustomer(\WC_Customer $customer): ?array
    {
        return $this->shipping(
            trim($customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name()),
            [
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city' => $customer->get_shipping_city(),
                'state' => $customer->get_shipping_state(),
                'postcode' => $customer->get_shipping_postcode(),
                'country' => $customer->get_shipping_country(),
            ]
        );
    }

    /** PayPal shipping object, or null when the address is incomplete. */
    public function shipping(string $name, array $address): ?array
    {
        $country = strtoupper((string) ($address['country'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $country) || trim((string) ($address['address_1'] ?? '')) === '' || trim((string) ($address['city'] ?? '')) === '') {
            return null;
        }
        $out = [
            'address' => array_filter([
                'address_line_1' => Amount::truncate((string) $address['address_1'], 300),
                'address_line_2' => Amount::truncate((string) ($address['address_2'] ?? ''), 300),
                'admin_area_2' => Amount::truncate((string) $address['city'], 120),
                'admin_area_1' => Amount::truncate((string) ($address['state'] ?? ''), 300),
                'postal_code' => Amount::truncate((string) ($address['postcode'] ?? ''), 60),
                'country_code' => $country,
            ], static function ($value) {
                return $value !== '';
            }),
        ];
        if ($name !== '') {
            $out['name'] = ['full_name' => Amount::truncate($name, 300)];
        }
        return $out;
    }

    /** Payment source, experience context and vault attributes. */
    private function finish(array $body, string $funding, bool $vault, string $shippingPreference, string $returnUrl, string $cancelUrl, array $context): array
    {
        $experience = [
            'brand_name' => $this->gateway->brandName(),
            'shipping_preference' => $shippingPreference,
            'user_action' => 'PAY_NOW',
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ];
        $locale = str_replace('_', '-', (string) get_locale());
        if (preg_match('/^[a-z]{2}-[A-Z]{2}$/', $locale)) {
            $experience['locale'] = $locale;
        }

        if ($funding === 'card') {
            // Advanced Card Fields: PayPal runs 3-D Secure in the card fields when it applies.
            $card = [
                'attributes' => ['verification' => ['method' => $this->gateway->threeDSecureMethod()]],
                'experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl],
            ];
            if ($vault) {
                $card['attributes']['vault'] = ['store_in_vault' => 'ON_SUCCESS'];
            }
            $body['payment_source'] = ['card' => $card];
            return (array) apply_filters('stackless_paypal_gateway_order_args', $body, $context);
        }

        if ($vault || in_array($funding, ['', 'paypal', 'paylater'], true)) {
            $source = ['experience_context' => $experience];
            if ($vault) {
                $source['attributes'] = [
                    'vault' => [
                        'store_in_vault' => 'ON_SUCCESS',
                        'usage_type' => 'MERCHANT',
                        'customer_type' => 'CONSUMER',
                        'permit_multiple_payment_tokens' => true,
                    ],
                ];
            }
            $body['payment_source'] = ['paypal' => $source];
        } elseif ($funding === 'venmo') {
            $body['payment_source'] = ['venmo' => ['experience_context' => [
                'brand_name' => $experience['brand_name'],
                'shipping_preference' => $shippingPreference,
            ]]];
        }
        // Other funding sources (guest_card: the buttons' own card form, local methods) carry no
        // payment source: the JS SDK collects them in PayPal's window.

        return (array) apply_filters('stackless_paypal_gateway_order_args', $body, $context);
    }
}
