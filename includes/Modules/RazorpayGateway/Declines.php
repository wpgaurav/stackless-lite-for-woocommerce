<?php

namespace Stackless\Modules\RazorpayGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps Razorpay failures (a payment's error_reason, or an API error's reason) to Subscriptions
 * decline categories (SubscriptionData::DECLINE_*).
 */
final class Declines
{
    private const REASONS = [
        // Not enough money or over a limit: retry later in the cycle.
        'insufficient_funds' => SubscriptionData::DECLINE_FUNDS,
        'adequate_funds_not_available_blocked' => SubscriptionData::DECLINE_FUNDS,
        'transaction_limit_exceeded' => SubscriptionData::DECLINE_FUNDS,
        'transaction_daily_limit_exceeded' => SubscriptionData::DECLINE_FUNDS,
        'transaction_daily_count_exceeded' => SubscriptionData::DECLINE_FUNDS,
        'credit_limit_exceeded' => SubscriptionData::DECLINE_FUNDS,
        'funds_blocked_by_mandate' => SubscriptionData::DECLINE_FUNDS,
        // The customer has to act: approve again, or set up a mandate that covers the amount.
        'amount_does_not_match_mandate_amount' => SubscriptionData::DECLINE_AUTH,
        'amount_exceeds_mandate' => SubscriptionData::DECLINE_AUTH,
        'authentication_failed' => SubscriptionData::DECLINE_AUTH,
        'incorrect_otp' => SubscriptionData::DECLINE_AUTH,
        'otp_expired' => SubscriptionData::DECLINE_AUTH,
        'otp_attempts_exceeded' => SubscriptionData::DECLINE_AUTH,
        'incorrect_pin' => SubscriptionData::DECLINE_AUTH,
        'pin_attempts_exceeded' => SubscriptionData::DECLINE_AUTH,
        'incorrect_cvv' => SubscriptionData::DECLINE_AUTH,
        'card_not_enrolled' => SubscriptionData::DECLINE_AUTH,
        'payment_cancelled' => SubscriptionData::DECLINE_AUTH,
        'pre_debit_notification_not_sent' => SubscriptionData::DECLINE_SOFT,
        'mandate_paused' => SubscriptionData::DECLINE_AUTH,
        // The method will never work again.
        'card_declined' => SubscriptionData::DECLINE_HARD,
        'card_expired' => SubscriptionData::DECLINE_HARD,
        'card_number_invalid' => SubscriptionData::DECLINE_HARD,
        'card_type_invalid' => SubscriptionData::DECLINE_HARD,
        'debit_declined' => SubscriptionData::DECLINE_HARD,
        'debit_instrument_blocked' => SubscriptionData::DECLINE_HARD,
        'debit_instrument_inactive' => SubscriptionData::DECLINE_HARD,
        'bank_account_invalid' => SubscriptionData::DECLINE_HARD,
        'payment_risk_check_failed' => SubscriptionData::DECLINE_HARD,
        'compliance_violation' => SubscriptionData::DECLINE_HARD,
        'international_transaction_not_allowed' => SubscriptionData::DECLINE_HARD,
        'recurring_payment_not_enabled' => SubscriptionData::DECLINE_HARD,
        'upi_autopay_not_supported_on_psp' => SubscriptionData::DECLINE_HARD,
        'mandate_creation_declined' => SubscriptionData::DECLINE_HARD,
        'mandate_cancelled' => SubscriptionData::DECLINE_HARD,
        'mandate_rejected' => SubscriptionData::DECLINE_HARD,
        'invalid_vpa' => SubscriptionData::DECLINE_HARD,
        'no_mandate' => SubscriptionData::DECLINE_HARD,
        // Provider or bank trouble.
        'bank_technical_error' => SubscriptionData::DECLINE_NETWORK,
        'issuer_technical_error' => SubscriptionData::DECLINE_NETWORK,
        'gateway_technical_error' => SubscriptionData::DECLINE_NETWORK,
        'bank_not_available' => SubscriptionData::DECLINE_NETWORK,
        'psp_not_available' => SubscriptionData::DECLINE_NETWORK,
        'upi_app_technical_error' => SubscriptionData::DECLINE_NETWORK,
        'server_error' => SubscriptionData::DECLINE_NETWORK,
        'invalid_response_from_gateway' => SubscriptionData::DECLINE_NETWORK,
        'request_timed_out' => SubscriptionData::DECLINE_NETWORK,
        'payment_timed_out' => SubscriptionData::DECLINE_SOFT,
        'payment_session_expired' => SubscriptionData::DECLINE_SOFT,
        'bank_cutoff_in_progress' => SubscriptionData::DECLINE_NETWORK,
        'payment_declined_due_to_high_traffic' => SubscriptionData::DECLINE_NETWORK,
        // Generic.
        'payment_declined' => SubscriptionData::DECLINE_SOFT,
        'payment_failed' => SubscriptionData::DECLINE_SOFT,
        'payment_pending' => SubscriptionData::DECLINE_SOFT,
    ];

    public static function categoryFor(string $reason): string
    {
        return self::REASONS[strtolower(trim($reason))] ?? SubscriptionData::DECLINE_SOFT;
    }

    /**
     * @param array $payment A Razorpay payment entity.
     * @return array{category:string, code:string}
     */
    public static function fromPayment(array $payment): array
    {
        $reason = strtolower((string) ($payment['error_reason'] ?? ''));
        if ($reason === '') {
            $reason = strtolower((string) ($payment['error_code'] ?? '')) ?: 'payment_failed';
        }
        $category = self::categoryFor($reason);
        if (!isset(self::REASONS[$reason]) && in_array((string) ($payment['error_source'] ?? ''), ['internal', 'gateway', 'network'], true)) {
            $category = SubscriptionData::DECLINE_NETWORK;
        }
        return ['category' => $category, 'code' => $reason];
    }

    /** @return array{category:string, code:string} */
    public static function fromError(\WP_Error $error): array
    {
        if (Api::isTransient($error)) {
            $status = Api::status($error);
            return ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => $status > 0 ? 'http_' . $status : 'unreachable'];
        }
        $reason = Api::reason($error);
        if ($reason !== '') {
            return ['category' => self::categoryFor($reason), 'code' => strtolower($reason)];
        }
        return ['category' => SubscriptionData::DECLINE_SOFT, 'code' => 'http_' . Api::status($error)];
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
