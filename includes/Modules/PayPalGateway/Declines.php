<?php

namespace Stackless\Modules\PayPalGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Decline categories (Contracts\SubscriptionData::DECLINE_*) for failed PayPal charges (2.0.0).
 *
 * Card charges carry the issuer's answer in the capture's processor_response
 * (response_code, payment_advice_code); PayPal-wallet and API failures carry an issue
 * name. Both are mapped here, and written on the renewal or switch order BEFORE it turns
 * failed, because Dunning reacts to that status change and reads them then.
 */
final class Declines
{
    /** processor_response.response_code values the issuer will never approve for this card. */
    private const HARD_CODES = [
        '0390', // ACCOUNT_NOT_FOUND
        '0R00', // CANCELLED_PAYMENT
        '1330', // INVALID_ACCOUNT
        '1335', // INVALID_ACCOUNT_RECURRING
        '1352', // RESTRICTED_OR_INACTIVE_ACCOUNT
        '1380', // INVALID_EXPIRATION
        '5140', // CARD_CLOSED
        '5150', // PICKUP_CARD_SPECIAL_CONDITIONS
        '5160', // UNAUTHORIZED_USER
        '5180', // INVALID_OR_RESTRICTED_CARD
        '5400', // EXPIRED_CARD
        '5700', // TRANSACTION_NOT_PERMITTED
        '5930', // CARD_NOT_ACTIVATED
        '5950', // DECLINED_DUE_TO_UPDATED_ACCOUNT
        '6300', // ACCOUNT_NOT_ON_FILE
        '7600', // INVALID_ACCOUNT_NUMBER
        '7710', // INVALID_CREDIT_CARD_NUMBER
        '9500', // SUSPECTED_FRAUD
        '9510', // SECURITY_VIOLATION
        '9520', // LOST_OR_STOLEN
        '9540', // REFUSED_CARD
        'PP06', // ACCOUNT_CLOSED
        'PPAB', // ACCOUNT_BLOCKED_BY_ISSUER
        'PPCT', // CARD_TYPE_UNSUPPORTED
        'PPEF', // EXPIRED_FUNDING_INSTRUMENT
        'PPEX', // EXPIRY_DATE
        'PPFI', // INVALID_FUNDING_INSTRUMENT
        'PPFR', // RESTRICTED_FUNDING_INSTRUMENT
        'PPRN', // REATTEMPT_NOT_PERMITTED
    ];

    /** Not enough money or over a limit: retry later. */
    private const FUNDS_CODES = [
        '5120', // INSUFFICIENT_FUNDS
        '5710', // TX_ATTEMPTS_EXCEED_LIMIT
        'PPAX', // AMOUNT_EXCEEDED
        'PPEL', // EXCEEDS_FREQUENCY_LIMIT
        'PPNL', // EXCEEDS_NETWORK_FREQUENCY_LIMIT
    ];

    /** The issuer wants the cardholder present (3-D Secure). */
    private const AUTH_CODES = [
        '5650', // DECLINED_SCA_REQUIRED
        'PPD3', // SECURE_ERROR_3DS
    ];

    /** PayPal or the network failed on its side. */
    private const NETWORK_CODES = [
        '0800', // BAD_RESPONSE_REVERSAL_REQUIRED
        '0960', // SYSTEM_MALFUNCTION
        'PPER', // INTERNAL_SYSTEM_ERROR
        'PPNT', // NETWORK_ERROR
    ];

    /** payment_advice_code (Mastercard Merchant Advice Codes) that settle the question on their own. */
    private const ADVICE = [
        '01' => SubscriptionData::DECLINE_HARD, // New account information available.
        '02' => SubscriptionData::DECLINE_FUNDS, // Over limit or insufficient funds; retry later.
        '03' => SubscriptionData::DECLINE_HARD, // Account closed as fraudulent.
        '21' => SubscriptionData::DECLINE_HARD, // Cardholder stopped recurring payments.
    ];

    /** Orders v2 / Vault issue names. */
    private const HARD_ISSUES = [
        'PAYER_CANNOT_PAY', 'PAYEE_BLOCKED_TRANSACTION', 'PAYMENT_SOURCE_CANNOT_BE_USED',
        'PAYMENT_SOURCE_INFO_CANNOT_BE_VERIFIED', 'CARD_EXPIRED', 'CARD_CLOSED', 'INVALID_RESOURCE_ID',
        'RESOURCE_NOT_FOUND', 'TOKEN_NOT_FOUND', 'INVALID_PAYMENT_TOKEN', 'PAYMENT_TOKEN_NOT_FOUND',
        'AGREEMENT_ALREADY_CANCELLED', 'BILLING_AGREEMENT_NOT_FOUND', 'COMPLIANCE_VIOLATION',
    ];

    /**
     * @param string $responseCode processor_response.response_code
     * @param string $adviceCode   processor_response.payment_advice_code
     * @param string $issue        Orders v2 error issue (details[0].issue) or name
     * @param int    $httpStatus   HTTP status of a failed API call; 0 = PayPal unreachable; -1 = none
     */
    public static function category(string $responseCode, string $adviceCode = '', string $issue = '', int $httpStatus = -1): string
    {
        if ($httpStatus === 0 || $httpStatus === 429 || $httpStatus >= 500) {
            return SubscriptionData::DECLINE_NETWORK;
        }
        $responseCode = strtoupper(trim($responseCode));
        if (isset(self::ADVICE[$adviceCode])) {
            return self::ADVICE[$adviceCode];
        }
        if (in_array($responseCode, self::AUTH_CODES, true)) {
            return SubscriptionData::DECLINE_AUTH;
        }
        if (in_array($responseCode, self::FUNDS_CODES, true)) {
            return SubscriptionData::DECLINE_FUNDS;
        }
        if (in_array($responseCode, self::HARD_CODES, true)) {
            return SubscriptionData::DECLINE_HARD;
        }
        if (in_array($responseCode, self::NETWORK_CODES, true)) {
            return SubscriptionData::DECLINE_NETWORK;
        }
        $issue = strtoupper(trim($issue));
        if ($issue === 'PAYER_ACTION_REQUIRED') {
            return SubscriptionData::DECLINE_AUTH;
        }
        if (in_array($issue, self::HARD_ISSUES, true)) {
            return SubscriptionData::DECLINE_HARD;
        }
        return SubscriptionData::DECLINE_SOFT;
    }

    /** The code worth recording: the processor code, else the issue, else the fallback. */
    public static function code(string $responseCode, string $issue, string $fallback = 'declined'): string
    {
        $code = $responseCode !== '' ? $responseCode : $issue;
        return $code !== '' ? $code : $fallback;
    }

    /** Category and code from a capture or authorization (its processor_response). @return array{0:string,1:string} */
    public static function fromPayment(array $payment): array
    {
        $response = (array) ($payment['processor_response'] ?? []);
        $code = (string) ($response['response_code'] ?? '');
        $advice = (string) ($response['payment_advice_code'] ?? '');
        $reason = (string) ($payment['status_details']['reason'] ?? '');
        return [self::category($code, $advice, $reason), self::code($code, $reason)];
    }

    /** Category and code from a failed API call. @return array{0:string,1:string} */
    public static function fromError(\WP_Error $error): array
    {
        $data = (array) $error->get_error_data();
        $issue = (string) ($data['issue'] ?? '');
        $status = (int) ($data['status'] ?? -1);
        $fallback = $status === 0 ? 'paypal_unreachable' : ($status > 0 ? 'http_' . $status : 'declined');
        return [self::category('', '', $issue, $status), self::code('', $issue, $fallback)];
    }

    /** Writes both meta keys on the order; the caller saves. */
    public static function write(\WC_Order $order, string $category, string $code): void
    {
        if (!in_array($category, SubscriptionData::DECLINES, true)) {
            $category = SubscriptionData::DECLINE_SOFT;
        }
        $order->update_meta_data(SubscriptionData::META_DECLINE, $category);
        $order->update_meta_data(SubscriptionData::META_DECLINE_CODE, substr(preg_replace('/[^A-Za-z0-9_-]/', '', $code), 0, 64));
    }
}
