<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/**
 * A saved card as the payment flows see it: a merchant-triggered PaymentConsent, bound to the
 * store customer, the Airwallex customer, the mode and the Airwallex account it was saved in.
 */
final class SavedMethod
{
    /** @var int */
    public $id = 0;
    /** @var int WordPress user id, 0 for a guest purchase */
    public $user_id = 0;
    /** @var string PaymentConsent id */
    public $consent = '';
    /** @var string Airwallex customer id */
    public $customer = '';
    /** @var string PaymentMethod id */
    public $method = '';
    /** @var string test|live */
    public $mode = 'test';
    /** @var string Airwallex account id */
    public $account = '';
    /** @var string */
    public $label = '';

    public static function fromToken(\WC_Payment_Token $token): self
    {
        $saved = new self();
        $saved->id = (int) $token->get_id();
        $saved->user_id = (int) $token->get_user_id();
        $saved->consent = (string) $token->get_token();
        $saved->customer = (string) $token->get_meta(Tokens::META_CUSTOMER);
        $saved->method = (string) $token->get_meta(Tokens::META_PAYMENT_METHOD);
        $saved->mode = (string) $token->get_meta(Tokens::META_MODE) === 'live' ? 'live' : 'test';
        $saved->account = (string) $token->get_meta(Tokens::META_ACCOUNT);
        $saved->label = wp_strip_all_tags((string) $token->get_display_name());
        return $saved;
    }

    public function usable(): bool
    {
        return $this->consent !== '' && $this->customer !== '' && $this->method !== '' && $this->account !== '';
    }

    public function displayName(): string
    {
        return $this->label !== '' ? $this->label : __('Saved card', 'stackless-lite');
    }
}
