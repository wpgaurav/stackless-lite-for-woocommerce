<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/**
 * A vaulted card as this store keeps it: bound to one user, one mode and one Braintree merchant.
 */
final class Card
{
    /** @var int Store token id (SubscriptionData::META_TOKEN_ID). */
    public $id = 0;
    /** @var int WordPress user, 0 for a guest purchase. */
    public $user = 0;
    /** @var string Global payment method id. */
    public $remote = '';
    /** @var string Legacy id (payment method token) for the gateway API. */
    public $legacy = '';
    /** @var string Braintree customer id. */
    public $customer = '';
    /** @var string live|test */
    public $mode = 'test';
    /** @var string Braintree merchant. */
    public $account = '';
    /** @var string */
    public $brand = 'card';
    /** @var string */
    public $last4 = '';
    /** @var string Two digits. */
    public $month = '';
    /** @var string Four digits. */
    public $year = '';
    /** @var bool */
    public $default = false;

    private const BRANDS = [
        'VISA' => 'visa',
        'MASTERCARD' => 'mastercard',
        'AMERICAN_EXPRESS' => 'amex',
        'DISCOVER' => 'discover',
        'DINERS' => 'diners',
        'JCB' => 'jcb',
        'UNION_PAY' => 'unionpay',
        'ELO' => 'elo',
        'INTERNATIONAL_MAESTRO' => 'maestro',
        'UK_MAESTRO' => 'maestro',
    ];

    /**
     * From a vaulted PaymentMethod.
     *
     * @param mixed $method
     */
    public static function fromPaymentMethod($method, int $user, string $mode, string $account): ?self
    {
        if (!is_array($method) || ($method['usage'] ?? '') !== 'MULTI_USE' || empty($method['id'])) {
            return null;
        }
        $details = is_array($method['details'] ?? null) ? $method['details'] : [];
        $customer = (string) ($method['customer']['id'] ?? '');
        if ($customer === '' || ($details['__typename'] ?? '') !== 'CreditCardDetails') {
            return null;
        }
        $card = new self();
        $card->user = max(0, $user);
        $card->remote = (string) $method['id'];
        $card->legacy = (string) ($method['legacyId'] ?? '');
        $card->customer = $customer;
        $card->mode = $mode === 'live' ? 'live' : 'test';
        $card->account = $account;
        $card->brand = self::brand((string) ($details['brandCode'] ?? ''));
        $card->last4 = substr((string) preg_replace('/\D/', '', (string) ($details['last4'] ?? '')), -4);
        $month = (int) ($details['expirationMonth'] ?? 0);
        $card->month = $month >= 1 && $month <= 12 ? str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '';
        $year = (int) ($details['expirationYear'] ?? 0);
        $card->year = $year >= 2000 && $year <= 2999 ? (string) $year : '';
        return $card;
    }

    public static function brand(string $code): string
    {
        return self::BRANDS[strtoupper($code)] ?? 'card';
    }

    /**
     * Whether CreditCardDetails describe this card. The expiry is left out: the account updater
     * changes it at Braintree without telling the store.
     *
     * @param array<string,mixed> $details
     */
    public function sameCard(array $details): bool
    {
        return (string) ($details['last4'] ?? '') === $this->last4 && self::brand((string) ($details['brandCode'] ?? '')) === $this->brand;
    }

    public function brandLabel(): string
    {
        $labels = [
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
            'elo' => 'Elo',
            'maestro' => 'Maestro',
        ];
        return $labels[$this->brand] ?? __('Card', 'stackless-lite');
    }

    /** "Visa ending in 1111" */
    public function label(): string
    {
        /* translators: 1: card brand, 2: last four digits */
        return sprintf(__('%1$s ending in %2$s', 'stackless-lite'), $this->brandLabel(), $this->last4);
    }
}
