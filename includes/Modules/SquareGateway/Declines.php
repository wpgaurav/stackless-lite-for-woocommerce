<?php

namespace Stackless\Modules\SquareGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Square payment error codes → Subscriptions decline categories and buyer-facing messages.
 */
final class Declines
{
    private const CODES = [
        // Generic issuer declines may pass on a later try.
        'CARD_DECLINED' => SubscriptionData::DECLINE_SOFT,
        'GENERIC_DECLINE' => SubscriptionData::DECLINE_SOFT,
        // The card will not work again.
        'INVALID_ACCOUNT' => SubscriptionData::DECLINE_HARD,
        'ACCOUNT_UNUSABLE' => SubscriptionData::DECLINE_HARD,
        'CARD_NOT_SUPPORTED' => SubscriptionData::DECLINE_HARD,
        'UNSUPPORTED_CARD_BRAND' => SubscriptionData::DECLINE_HARD,
        'CARDHOLDER_INSUFFICIENT_PERMISSIONS' => SubscriptionData::DECLINE_HARD,
        'CARD_EXPIRED' => SubscriptionData::DECLINE_HARD,
        'INVALID_EXPIRATION' => SubscriptionData::DECLINE_HARD,
        'INVALID_EXPIRATION_YEAR' => SubscriptionData::DECLINE_HARD,
        'INVALID_EXPIRATION_DATE' => SubscriptionData::DECLINE_HARD,
        'BAD_EXPIRATION' => SubscriptionData::DECLINE_HARD,
        'EXPIRATION_FAILURE' => SubscriptionData::DECLINE_HARD,
        'PAN_FAILURE' => SubscriptionData::DECLINE_HARD,
        'INVALID_CARD' => SubscriptionData::DECLINE_HARD,
        'INVALID_CARD_DATA' => SubscriptionData::DECLINE_HARD,
        'CVV_FAILURE' => SubscriptionData::DECLINE_HARD,
        'VERIFY_CVV_FAILURE' => SubscriptionData::DECLINE_HARD,
        'ADDRESS_VERIFICATION_FAILURE' => SubscriptionData::DECLINE_HARD,
        'VERIFY_AVS_FAILURE' => SubscriptionData::DECLINE_HARD,
        'INVALID_POSTAL_CODE' => SubscriptionData::DECLINE_HARD,
        'CUSTOMER_NOT_FOUND' => SubscriptionData::DECLINE_HARD,
        'CARD_TOKEN_EXPIRED' => SubscriptionData::DECLINE_HARD,
        'SOURCE_EXPIRED' => SubscriptionData::DECLINE_HARD,
        // Funds and limits.
        'INSUFFICIENT_FUNDS' => SubscriptionData::DECLINE_FUNDS,
        'TRANSACTION_LIMIT' => SubscriptionData::DECLINE_FUNDS,
        'PAYMENT_LIMIT_EXCEEDED' => SubscriptionData::DECLINE_FUNDS,
        'AMOUNT_TOO_HIGH' => SubscriptionData::DECLINE_FUNDS,
        // The cardholder has to step in.
        'CARD_DECLINED_VERIFICATION_REQUIRED' => SubscriptionData::DECLINE_AUTH,
        'CARD_DECLINED_CALL_ISSUER' => SubscriptionData::DECLINE_AUTH,
        'VOICE_FAILURE' => SubscriptionData::DECLINE_AUTH,
        'INVALID_PIN' => SubscriptionData::DECLINE_AUTH,
        'MISSING_PIN' => SubscriptionData::DECLINE_AUTH,
        'ALLOWABLE_PIN_TRIES_EXCEEDED' => SubscriptionData::DECLINE_AUTH,
        // Square or the network.
        'TEMPORARY_ERROR' => SubscriptionData::DECLINE_NETWORK,
        'INTERNAL_SERVER_ERROR' => SubscriptionData::DECLINE_NETWORK,
        'SERVICE_UNAVAILABLE' => SubscriptionData::DECLINE_NETWORK,
        'GATEWAY_TIMEOUT' => SubscriptionData::DECLINE_NETWORK,
        'BAD_GATEWAY' => SubscriptionData::DECLINE_NETWORK,
        'REQUEST_TIMEOUT' => SubscriptionData::DECLINE_NETWORK,
        'RATE_LIMITED' => SubscriptionData::DECLINE_NETWORK,
    ];

    public static function categoryFor(string $code): string
    {
        return self::CODES[strtoupper(trim($code))] ?? SubscriptionData::DECLINE_SOFT;
    }

    /** @return array{category:string, code:string} */
    public static function fromError(\WP_Error $error): array
    {
        $code = Api::code($error);
        if ($code === '') {
            $status = Api::status($error);
            $code = $status > 0 ? 'HTTP_' . $status : 'UNREACHABLE';
            return ['category' => Api::isTransient($error) ? SubscriptionData::DECLINE_NETWORK : SubscriptionData::DECLINE_SOFT, 'code' => $code];
        }
        return ['category' => self::categoryFor($code), 'code' => $code];
    }

    /** @return array{category:string, code:string} A FAILED payment object (from a webhook). */
    public static function fromPayment(array $payment): array
    {
        $errors = $payment['card_details']['errors'] ?? [];
        $code = is_array($errors) && is_array($errors[0] ?? null) ? (string) ($errors[0]['code'] ?? '') : '';
        return ['category' => $code !== '' ? self::categoryFor($code) : SubscriptionData::DECLINE_SOFT, 'code' => $code !== '' ? $code : (string) ($payment['status'] ?? 'FAILED')];
    }

    /** What the buyer reads when a card payment at checkout is declined. */
    public static function message(string $code): string
    {
        switch (strtoupper($code)) {
            case 'INSUFFICIENT_FUNDS':
                return __('Your card has insufficient funds.', 'stackless-lite');
            case 'CVV_FAILURE':
            case 'VERIFY_CVV_FAILURE':
                return __('The security code (CVV) was not accepted. Check it and try again.', 'stackless-lite');
            case 'ADDRESS_VERIFICATION_FAILURE':
            case 'VERIFY_AVS_FAILURE':
            case 'INVALID_POSTAL_CODE':
                return __('The postal code does not match the card. Check it and try again.', 'stackless-lite');
            case 'CARD_EXPIRED':
            case 'INVALID_EXPIRATION':
            case 'INVALID_EXPIRATION_YEAR':
            case 'INVALID_EXPIRATION_DATE':
            case 'BAD_EXPIRATION':
            case 'EXPIRATION_FAILURE':
                return __('The card has expired or the expiry date is wrong.', 'stackless-lite');
            case 'CARD_DECLINED_VERIFICATION_REQUIRED':
                return __('Your bank needs you to verify this payment. Enter your card details again to continue.', 'stackless-lite');
            case 'CARD_DECLINED_CALL_ISSUER':
                return __('Your bank declined the payment. Call your bank or use another card.', 'stackless-lite');
            case 'TEMPORARY_ERROR':
            case 'INTERNAL_SERVER_ERROR':
            case 'SERVICE_UNAVAILABLE':
                return __('Square could not process the payment just now. Please try again.', 'stackless-lite');
            case 'CARD_TOKEN_EXPIRED':
            case 'CARD_TOKEN_USED':
            case 'SOURCE_USED':
            case 'SOURCE_EXPIRED':
                return __('The card details expired. Enter them again.', 'stackless-lite');
            default:
                return __('Your card was declined. Try another card.', 'stackless-lite');
        }
    }

    /** Write the decline details on an order (call before update_status('failed')). */
    public static function record(\WC_Order $order, array $decline): void
    {
        $category = in_array($decline['category'] ?? '', SubscriptionData::DECLINES, true) ? (string) $decline['category'] : SubscriptionData::DECLINE_SOFT;
        $order->update_meta_data(SubscriptionData::META_DECLINE, $category);
        $order->update_meta_data(SubscriptionData::META_DECLINE_CODE, substr(sanitize_text_field((string) ($decline['code'] ?? '')), 0, 64));
        $order->save();
    }
}
