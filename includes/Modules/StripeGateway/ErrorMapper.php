<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Turns Stripe error codes into messages that are safe to show a shopper, and classifies
 * errors for retry decisions. Fraud-related decline codes deliberately map to a generic
 * "declined" message so the store never tells a card tester why a card failed.
 */
final class ErrorMapper
{
    /** Decline codes that must never be revealed to the customer. */
    private const SENSITIVE_DECLINES = [
        'fraudulent', 'lost_card', 'stolen_card', 'pickup_card', 'restricted_card',
        'merchant_blacklist', 'security_violation', 'revocation_of_authorization',
        'revocation_of_all_authorizations', 'stop_payment_order', 'transaction_not_allowed',
    ];

    /** Error codes that mean "the bank wants the customer present". */
    private const AUTH_CODES = ['authentication_required', 'card_authentication_required'];

    /**
     * @param string $code         Stripe error code (error.code), e.g. card_declined.
     * @param string $decline_code Stripe decline code (error.decline_code), e.g. insufficient_funds.
     * @param string $fallback     Stripe's own message; shown only for card errors, which Stripe documents as customer-safe.
     * @param string $type         Stripe error type (card_error, invalid_request_error, api_error, ...).
     */
    public static function message(string $code, string $decline_code = '', string $fallback = '', string $type = ''): string
    {
        if ($decline_code !== '') {
            $message = self::declineMessage($decline_code);
            if ($message !== null) {
                return $message;
            }
        }

        switch ($code) {
            case 'card_declined':
                return __('Your card was declined. Try another card or payment method.', 'stackless-lite');
            case 'expired_card':
                return __('Your card has expired. Use a different card.', 'stackless-lite');
            case 'incorrect_cvc':
            case 'invalid_cvc':
                return __("Your card's security code is incorrect.", 'stackless-lite');
            case 'incorrect_number':
            case 'invalid_number':
                return __('Your card number is incorrect.', 'stackless-lite');
            case 'invalid_expiry_month':
            case 'invalid_expiry_year':
                return __("Your card's expiry date is invalid.", 'stackless-lite');
            case 'incorrect_zip':
            case 'postal_code_invalid':
                return __("Your card's postal code is incorrect.", 'stackless-lite');
            case 'processing_error':
                return __('An error occurred while processing your card. Try again in a moment.', 'stackless-lite');
            case 'authentication_required':
            case 'card_authentication_required':
                return __('Your bank needs you to confirm this payment. Pay the order from your account to authenticate it.', 'stackless-lite');
            case 'payment_intent_authentication_failure':
            case 'setup_intent_authentication_failure':
                return __('We could not verify your payment method. Try again or choose another payment method.', 'stackless-lite');
            case 'amount_too_small':
                return __('The order total is below the minimum amount that can be charged in this currency.', 'stackless-lite');
            case 'amount_too_large':
                return __('The order total is above the maximum amount that can be charged in one payment.', 'stackless-lite');
            case 'currency_not_supported':
                return __('Your payment method does not support this currency.', 'stackless-lite');
            case 'payment_method_unactivated':
            case 'payment_method_not_available':
            case 'payment_method_provider_decline':
                return __('This payment method is not available right now. Choose another payment method.', 'stackless-lite');
            case 'payment_intent_payment_attempt_expired':
            case 'payment_intent_payment_attempt_failed':
                return __('The payment attempt expired or failed. Try again.', 'stackless-lite');
            case 'rate_limit':
            case 'lock_timeout':
                return __('The payment service is busy. Try again in a moment.', 'stackless-lite');
            case 'api_connection_error':
                return __('We could not reach the payment service. Check your connection and try again.', 'stackless-lite');
        }

        if ($type === 'card_error' && $fallback !== '') {
            return $fallback;
        }

        return self::generic();
    }

    public static function generic(): string
    {
        return __('We could not process your payment. Try again or use a different payment method.', 'stackless-lite');
    }

    /** Message for an exception from StripeClient. */
    public static function fromException(StripeApiException $e): string
    {
        return self::message($e->getStripeCode(), $e->getDeclineCode(), $e->getMessage(), $e->getStripeType());
    }

    /** Message from a PaymentIntent/SetupIntent last_payment_error / last_setup_error array. */
    public static function fromIntentError(array $error): string
    {
        return self::message(
            (string) ($error['code'] ?? ''),
            (string) ($error['decline_code'] ?? ''),
            (string) ($error['message'] ?? ''),
            (string) ($error['type'] ?? '')
        );
    }

    public static function isAuthenticationRequired(string $code, string $decline_code = '', string $intent_status = ''): bool
    {
        return in_array($code, self::AUTH_CODES, true)
            || in_array($decline_code, self::AUTH_CODES, true)
            || $intent_status === 'requires_action';
    }

    /**
     * True when the outcome is unknown or transient and the same request (same idempotency
     * key) may be repeated: network failure, rate limit, lock timeout, Stripe 5xx.
     */
    public static function isRetryable(int $http_status, string $type = '', string $code = ''): bool
    {
        if ($http_status === 0 || $type === 'api_connection_error') {
            return true;
        }
        if ($http_status === 429 || $code === 'rate_limit' || $code === 'lock_timeout') {
            return true;
        }
        if ($http_status === 409 && $code !== 'idempotency_key_in_use' && $code !== 'idempotency_error') {
            return true;
        }
        return $http_status >= 500;
    }

    /** True for errors that mean the card or bank refused: no point retrying the same request. */
    public static function isCardFailure(string $type, string $code): bool
    {
        return $type === 'card_error' || in_array($code, ['card_declined', 'expired_card', 'incorrect_cvc', 'authentication_required'], true);
    }

    private static function declineMessage(string $decline_code): ?string
    {
        if (in_array($decline_code, self::SENSITIVE_DECLINES, true)) {
            return __('Your card was declined. Try another card or payment method.', 'stackless-lite');
        }
        switch ($decline_code) {
            case 'insufficient_funds':
                return __('Your card has insufficient funds.', 'stackless-lite');
            case 'expired_card':
                return __('Your card has expired. Use a different card.', 'stackless-lite');
            case 'incorrect_cvc':
            case 'invalid_cvc':
                return __("Your card's security code is incorrect.", 'stackless-lite');
            case 'incorrect_number':
            case 'invalid_number':
                return __('Your card number is incorrect.', 'stackless-lite');
            case 'incorrect_zip':
                return __("Your card's postal code is incorrect.", 'stackless-lite');
            case 'card_velocity_exceeded':
            case 'withdrawal_count_limit_exceeded':
                return __('Your card has reached its spending limit. Try another card or contact your bank.', 'stackless-lite');
            case 'currency_not_supported':
                return __('Your card does not support this currency.', 'stackless-lite');
            case 'card_not_supported':
                return __('Your card does not support this type of purchase.', 'stackless-lite');
            case 'authentication_required':
                return __('Your bank needs you to confirm this payment. Pay the order from your account to authenticate it.', 'stackless-lite');
            case 'processing_error':
            case 'try_again_later':
            case 'issuer_not_available':
                return __('Your bank could not be reached. Try again in a moment.', 'stackless-lite');
            case 'generic_decline':
            case 'do_not_honor':
            case 'do_not_try_again':
            case 'call_issuer':
            case 'not_permitted':
                return __('Your card was declined. Try another card or payment method.', 'stackless-lite');
        }
        return null;
    }
}
