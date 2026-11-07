<?php

namespace Stackless\Modules\MollieGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps a failed Mollie payment to a Subscriptions decline category (SubscriptionData::DECLINE_*)
 * and the provider code kept for notes: details.failureReason for cards, the SEPA reason code
 * (details.bankReasonCode) for direct debits, and the payment status otherwise.
 */
final class Declines
{
    /** Card failure reasons (Mollie extra payment parameters). */
    private const CARD = [
        'authentication_abandoned' => SubscriptionData::DECLINE_AUTH,
        'authentication_failed' => SubscriptionData::DECLINE_AUTH,
        'authentication_required' => SubscriptionData::DECLINE_AUTH,
        'authentication_unavailable_acs' => SubscriptionData::DECLINE_SOFT,
        'card_declined' => SubscriptionData::DECLINE_SOFT,
        'card_expired' => SubscriptionData::DECLINE_HARD,
        'inactive_card' => SubscriptionData::DECLINE_HARD,
        'insufficient_funds' => SubscriptionData::DECLINE_FUNDS,
        'invalid_cvv' => SubscriptionData::DECLINE_HARD,
        'invalid_card_holder_name' => SubscriptionData::DECLINE_HARD,
        'invalid_card_number' => SubscriptionData::DECLINE_HARD,
        'invalid_card_type' => SubscriptionData::DECLINE_HARD,
        'possible_fraud' => SubscriptionData::DECLINE_HARD,
        'refused_by_issuer' => SubscriptionData::DECLINE_SOFT,
        'unknown_reason' => SubscriptionData::DECLINE_SOFT,
    ];

    /**
     * SEPA (EPC) reason codes. Mollie cancels subscriptions at once on AC01, AC04, AC06 and
     * MD07 and after three failures on MD01, MD06, MS02, MS03 and SL01; AM04 is the EPC code
     * for insufficient funds.
     */
    private const SEPA = [
        'AC01' => SubscriptionData::DECLINE_HARD, // incorrect account number
        'AC04' => SubscriptionData::DECLINE_HARD, // account closed
        'AC06' => SubscriptionData::DECLINE_HARD, // account blocked
        'AC13' => SubscriptionData::DECLINE_HARD, // debtor account is a consumer account
        'AG01' => SubscriptionData::DECLINE_HARD, // transaction forbidden on this account
        'MD01' => SubscriptionData::DECLINE_HARD, // no valid mandate
        'MD07' => SubscriptionData::DECLINE_HARD, // debtor deceased
        'AM04' => SubscriptionData::DECLINE_FUNDS, // insufficient funds
        'AM05' => SubscriptionData::DECLINE_SOFT, // duplicate
        'MD06' => SubscriptionData::DECLINE_SOFT, // refund requested by the debtor
        'MS02' => SubscriptionData::DECLINE_SOFT, // refused by the debtor
        'MS03' => SubscriptionData::DECLINE_SOFT, // reason not specified
        'SL01' => SubscriptionData::DECLINE_SOFT, // specific service of the debtor bank
        'FF05' => SubscriptionData::DECLINE_HARD, // direct debit type incorrect
    ];

    /**
     * @param array $payment A Mollie payment object.
     * @return array{category:string, code:string}
     */
    public static function fromPayment(array $payment): array
    {
        $details = is_array($payment['details'] ?? null) ? $payment['details'] : [];
        $reason = strtolower((string) ($details['failureReason'] ?? ''));
        if ($reason !== '') {
            return ['category' => self::CARD[$reason] ?? SubscriptionData::DECLINE_SOFT, 'code' => $reason];
        }
        $bank = strtoupper((string) ($details['bankReasonCode'] ?? ''));
        if ($bank !== '') {
            return ['category' => self::SEPA[$bank] ?? SubscriptionData::DECLINE_SOFT, 'code' => $bank];
        }
        $status = (string) ($payment['status'] ?? '');
        if ($status === 'expired' || $status === 'canceled') {
            // Nobody completed the payment in time (a renewal never expires; a first payment does).
            return ['category' => SubscriptionData::DECLINE_SOFT, 'code' => $status];
        }
        return ['category' => SubscriptionData::DECLINE_SOFT, 'code' => $status !== '' ? $status : 'failed'];
    }

    /**
     * A Mollie API error on creating a recurring payment: no valid mandate or a rejected
     * request will not pass later; no answer or a Mollie-side error might.
     *
     * @return array{category:string, code:string}
     */
    public static function fromError(\WP_Error $error): array
    {
        $status = Api::status($error);
        if (Api::isTransient($error)) {
            return ['category' => SubscriptionData::DECLINE_NETWORK, 'code' => $status > 0 ? 'http_' . $status : 'unreachable'];
        }
        return ['category' => SubscriptionData::DECLINE_HARD, 'code' => 'http_' . $status];
    }

    /** Category for a code read back from META_DECLINE_CODE (reports and tests). */
    public static function categoryFor(string $code): string
    {
        $lower = strtolower($code);
        if (isset(self::CARD[$lower])) {
            return self::CARD[$lower];
        }
        $upper = strtoupper($code);
        return self::SEPA[$upper] ?? SubscriptionData::DECLINE_SOFT;
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
