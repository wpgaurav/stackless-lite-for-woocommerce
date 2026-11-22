<?php

namespace Stackless\Modules\BraintreeGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Braintree outcomes as Subscriptions decline categories (SubscriptionData::DECLINE_*), the code
 * kept for notes, and what the shopper is told.
 */
final class Declines
{
    private const FUNDS = ['2001', '2002', '2003'];
    /** 2099 cardholder authentication required, 2101 additional authorization required. */
    private const AUTH = ['2099', '2101'];
    /** Hard for this charge, but the merchant's setup rather than the card: worth another try. */
    private const SETUP = ['2027', '2028', '2030', '2045', '2055', '2056', '2064'];
    /** The codes Braintree's authorization response reference types "Soft" (2109 and above are all soft). */
    private const SOFT = ['2000', '2016', '2025', '2026', '2034', '2035', '2038', '2040', '2042', '2046', '2048', '2057', '2062', '2078', '2080', '2092', '2102', '2103', '2104', '2105', '2106'];
    private const REJECTIONS = [
        'THREE_D_SECURE' => SubscriptionData::DECLINE_AUTH,
        'FRAUD' => SubscriptionData::DECLINE_HARD,
        'PAYMENT_METHOD_BLOCKED' => SubscriptionData::DECLINE_HARD,
        'EXCESSIVE_RETRY' => SubscriptionData::DECLINE_HARD,
    ];
    /** Validation errors that mean this card can never be charged. */
    private const DEAD_CARD = ['91518', '91577'];

    /**
     * An unpaid transaction.
     *
     * @param array<string,mixed> $transaction
     * @return array{category:string, code:string}
     */
    public static function fromTransaction(array $transaction): array
    {
        $status = strtoupper((string) ($transaction['status'] ?? ''));
        $type = '';
        $reason = '';
        foreach ((array) ($transaction['statusHistory'] ?? []) as $event) {
            if (is_array($event)) {
                $type = $type !== '' ? $type : strtoupper((string) ($event['declineType'] ?? ''));
                $reason = $reason !== '' ? $reason : strtoupper((string) ($event['gatewayRejectionReason'] ?? ''));
            }
        }
        if ($status === 'GATEWAY_REJECTED') {
            return self::rejection($reason);
        }
        $code = (string) ($transaction['processorAuthorizationResponse']['legacyCode'] ?? '');
        if ($status === 'PROCESSOR_DECLINED') {
            return self::processor($code, $type);
        }
        if ($status === 'FAILED') {
            return ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => $code !== '' ? $code : 'failed'];
        }
        return ['category' => SubscriptionData::DECLINE_SOFT, 'code' => $status !== '' ? strtolower($status) : 'failed'];
    }

    /**
     * A failed card verification.
     *
     * @param array<string,mixed> $verification
     * @return array{category:string, code:string}
     */
    public static function fromVerification(array $verification): array
    {
        $status = strtoupper((string) ($verification['status'] ?? ''));
        if ($status === 'GATEWAY_REJECTED') {
            return self::rejection(strtoupper((string) ($verification['gatewayRejectionReason'] ?? '')));
        }
        if ($status === 'PROCESSOR_DECLINED') {
            return self::processor((string) ($verification['processorResponse']['legacyCode'] ?? ''));
        }
        return ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => $status !== '' ? strtolower($status) : 'verification_failed'];
    }

    /** @return array{category:string, code:string} */
    public static function fromError(\WP_Error $error): array
    {
        $class = Api::errorClass($error);
        $code = Api::code($error);
        if ($class === 'NOT_FOUND') {
            return ['category' => SubscriptionData::DECLINE_HARD, 'code' => $code !== '' ? $code : 'not_found'];
        }
        if ($class === 'VALIDATION') {
            return ['category' => in_array($code, self::DEAD_CARD, true) ? SubscriptionData::DECLINE_HARD : SubscriptionData::DECLINE_SOFT, 'code' => $code !== '' ? $code : 'validation'];
        }
        return ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => $code !== '' ? $code : ($class !== '' ? strtolower($class) : 'unreachable')];
    }

    /** @return array{category:string, code:string} */
    public static function processor(string $code, string $type = ''): array
    {
        $number = (int) $code;
        if (in_array($code, self::FUNDS, true)) {
            $category = SubscriptionData::DECLINE_FUNDS;
        } elseif (in_array($code, self::AUTH, true)) {
            $category = SubscriptionData::DECLINE_AUTH;
        } elseif ($number >= 3000) {
            $category = SubscriptionData::DECLINE_NETWORK;
        } elseif (in_array($code, self::SETUP, true)) {
            $category = SubscriptionData::DECLINE_SOFT;
        } elseif ($type === 'HARD' || $type === 'SOFT') {
            $category = $type === 'HARD' ? SubscriptionData::DECLINE_HARD : SubscriptionData::DECLINE_SOFT;
        } elseif ($number >= 2000 && $number <= 2108) {
            $category = in_array($code, self::SOFT, true) ? SubscriptionData::DECLINE_SOFT : SubscriptionData::DECLINE_HARD;
        } else {
            $category = SubscriptionData::DECLINE_SOFT;
        }
        return ['category' => $category, 'code' => $code !== '' ? $code : 'declined'];
    }

    /** @return array{category:string, code:string} */
    private static function rejection(string $reason): array
    {
        return ['category' => self::REJECTIONS[$reason] ?? SubscriptionData::DECLINE_SOFT, 'code' => $reason !== '' ? strtolower($reason) : 'gateway_rejected'];
    }

    /**
     * The meta to write before the order turns failed.
     *
     * @param array{category:string, code:string} $decline
     * @return array<string,string>
     */
    public static function meta(array $decline): array
    {
        $category = in_array($decline['category'], SubscriptionData::DECLINES, true) ? $decline['category'] : SubscriptionData::DECLINE_SOFT;
        return [
            SubscriptionData::META_DECLINE => $category,
            SubscriptionData::META_DECLINE_CODE => substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $decline['code']), 0, 64),
        ];
    }

    /**
     * What the shopper reads. Never the processor's own wording, which can describe fraud checks.
     *
     * @param array{category:string, code:string} $decline
     */
    public static function message(array $decline): string
    {
        switch ($decline['code']) {
            case '91585':
            case '91584':
                return __('The order total or currency changed after your card was verified. Check the total and pay again.', 'stackless-lite');
            case '91564':
            case '91565':
            case '91566':
                return __('Your card details expired or were already used. Enter your card again.', 'stackless-lite');
            case 'amount_precision':
                return __('This total cannot be charged exactly in this currency. Please contact the store.', 'stackless-lite');
            case 'no_merchant_account':
                return __('Braintree does not take payments in this currency here. Choose another payment method.', 'stackless-lite');
            case 'card_unavailable':
                return __('That saved card is not available. Choose another one.', 'stackless-lite');
            case 'card_required':
                return __('Enter your card details to continue.', 'stackless-lite');
            case 'cvv':
            case '2010':
                return __('The security code did not match. Check it and try again.', 'stackless-lite');
            case 'avs':
            case 'avs_and_cvv':
                return __('The billing address or security code did not match your card. Check both and try again.', 'stackless-lite');
        }
        switch ($decline['category']) {
            case SubscriptionData::DECLINE_FUNDS:
                return __('The card does not have enough funds or is over its limit. Try another card.', 'stackless-lite');
            case SubscriptionData::DECLINE_AUTH:
                return __('Your card could not be verified with your bank. Try again or use another card.', 'stackless-lite');
            case SubscriptionData::DECLINE_NETWORK:
                return __('The payment could not be processed right now, and your card was not charged. Try again in a moment.', 'stackless-lite');
        }
        return __('Your card was declined. Try another card or contact your bank.', 'stackless-lite');
    }
}
