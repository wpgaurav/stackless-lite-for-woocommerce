<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * The server's check of what the browser posted as a payment method nonce.
 *
 * It must be a single-use card: paymentMethodId also takes vaulted ids, so without this check a
 * shopper who learned someone's saved-card id could post it and charge that card. When the
 * store requires 3-D Secure, the result is read from Braintree too, never from the browser.
 * Braintree itself refuses a nonce verified for another amount (91585) or merchant account
 * (91584).
 */
final class ThreeDSecure
{
    public const REQUIRED = 'required';
    public const STRICT = 'strict';
    public const OFF = 'off';

    /**
     * Statuses Braintree lets through when 3-D Secure is required, less the ones a shopper
     * could ask for by editing the page to dodge the challenge (data only, exemptions: this
     * module never requests them).
     */
    private const PASSES = [
        'AUTHENTICATE_SUCCESSFUL',
        'AUTHENTICATE_ATTEMPT_SUCCESSFUL',
        'AUTHENTICATION_UNAVAILABLE',
        'LOOKUP_BYPASSED',
        'LOOKUP_ERROR',
        'LOOKUP_FAILED_ACS_ERROR',
        'LOOKUP_NOT_ENROLLED',
        'MPI_SERVER_ERROR',
        'SKIPPED_DUE_TO_RULE',
        'UNSUPPORTED_ACCOUNT_TYPE',
        'UNSUPPORTED_CARD',
        'UNSUPPORTED_THREE_D_SECURE_VERSION',
    ];

    /** Liability can only have moved with one of these. */
    private const SHIFTS = ['AUTHENTICATE_SUCCESSFUL', 'AUTHENTICATE_ATTEMPT_SUCCESSFUL'];

    /**
     * @param mixed     $method The PaymentMethod node for the posted nonce.
     * @param Card|null $saved  The saved card the nonce was made from.
     * @return array{category:string, code:string}|null null when the card may be charged
     */
    public static function refusal($method, string $policy, ?Card $saved = null): ?array
    {
        $details = is_array($method) && is_array($method['details'] ?? null) ? $method['details'] : [];
        if (!is_array($method) || ($method['usage'] ?? '') !== 'SINGLE_USE' || ($details['__typename'] ?? '') !== 'CreditCardDetails') {
            return ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'invalid_payment_method'];
        }
        if ($saved && !$saved->sameCard($details)) {
            return ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'card_unavailable'];
        }
        if ($policy === self::OFF) {
            return null;
        }
        $authentication = $details['threeDSecure']['authentication'] ?? null;
        $status = is_array($authentication) ? strtoupper((string) ($authentication['authenticationStatus'] ?? '')) : '';
        if ($status === '') {
            return ['category' => SubscriptionData::DECLINE_AUTH, 'code' => 'three_d_secure_missing'];
        }
        $passes = $policy === self::STRICT
            ? ($authentication['liabilityShifted'] ?? false) === true && in_array($status, self::SHIFTS, true)
            : in_array($status, self::PASSES, true);
        return $passes ? null : ['category' => SubscriptionData::DECLINE_AUTH, 'code' => strtolower($status)];
    }
}
