<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Maps a Revolut payment's decline_reason to a Subscriptions decline category
 * (SubscriptionData::DECLINE_*). Reasons not listed are soft: they may pass later.
 */
final class Declines
{
    private const REASONS = [
        '3ds_challenge_abandoned' => SubscriptionData::DECLINE_AUTH,
        '3ds_challenge_failed' => SubscriptionData::DECLINE_AUTH,
        '3ds_challenge_failed_manually' => SubscriptionData::DECLINE_AUTH,
        'authentication_required' => SubscriptionData::DECLINE_AUTH,
        'authentication_challenge' => SubscriptionData::DECLINE_AUTH,
        'card_not_enrolled_to_three_ds' => SubscriptionData::DECLINE_AUTH,
        'customer_challenge_abandoned' => SubscriptionData::DECLINE_AUTH,
        'customer_challenge_failed' => SubscriptionData::DECLINE_AUTH,
        'customer_verification_failed' => SubscriptionData::DECLINE_AUTH,
        'no_common_supported_three_ds_version' => SubscriptionData::DECLINE_AUTH,
        'pin_required' => SubscriptionData::DECLINE_AUTH,

        'customer_wallet_limits_exceeded' => SubscriptionData::DECLINE_FUNDS,
        'insufficient_funds' => SubscriptionData::DECLINE_FUNDS,
        'withdrawal_frequency_exceeded' => SubscriptionData::DECLINE_FUNDS,
        'withdrawal_limit_exceeded' => SubscriptionData::DECLINE_FUNDS,

        'issuer_not_available' => SubscriptionData::DECLINE_NETWORK,
        'technical_error' => SubscriptionData::DECLINE_NETWORK,

        'currency_not_supported' => SubscriptionData::DECLINE_HARD,
        'expired_card' => SubscriptionData::DECLINE_HARD,
        'invalid_account' => SubscriptionData::DECLINE_HARD,
        'invalid_card' => SubscriptionData::DECLINE_HARD,
        'invalid_expiry' => SubscriptionData::DECLINE_HARD,
        'law_violation' => SubscriptionData::DECLINE_HARD,
        'lost_card' => SubscriptionData::DECLINE_HARD,
        'no_such_issuer' => SubscriptionData::DECLINE_HARD,
        'pick_up_card' => SubscriptionData::DECLINE_HARD,
        'rejected_by_customer' => SubscriptionData::DECLINE_HARD,
        'restricted_card' => SubscriptionData::DECLINE_HARD,
        'security_violation' => SubscriptionData::DECLINE_HARD,
        'stolen_card' => SubscriptionData::DECLINE_HARD,
        'suspected_fraud' => SubscriptionData::DECLINE_HARD,
        'transaction_not_allowed_for_cardholder' => SubscriptionData::DECLINE_HARD,
        'unknown_card' => SubscriptionData::DECLINE_HARD,
    ];

    public static function categoryFor(string $reason): string
    {
        return self::REASONS[strtolower($reason)] ?? SubscriptionData::DECLINE_SOFT;
    }

    /**
     * The newest failure on a Revolut order.
     *
     * @param array<string,mixed> $remote
     * @return array{category:string, code:string}
     */
    public static function fromOrder(array $remote): array
    {
        $code = '';
        $newest = '';
        foreach (Remote::payments($remote) as $payment) {
            $at = (string) ($payment['created_at'] ?? '');
            if ($code !== '' && $at < $newest) {
                continue;
            }
            if (isset($payment['decline_reason']) && is_string($payment['decline_reason']) && $payment['decline_reason'] !== '') {
                $code = $payment['decline_reason'];
            } elseif (($payment['state'] ?? '') === 'authentication_challenge') {
                // Nobody is present to answer the challenge of a merchant-initiated charge.
                $code = 'authentication_challenge';
            } else {
                $code = (string) ($payment['state'] ?? '');
            }
            $newest = $at;
        }
        if ($code === '') {
            $code = (string) ($remote['state'] ?? 'failed');
        }
        return ['category' => self::categoryFor($code), 'code' => self::clean($code)];
    }

    /**
     * An API error: no answer or a Revolut-side failure may pass later.
     *
     * @return array{category:string, code:string}
     */
    public static function fromError(\WP_Error $error): array
    {
        $code = Api::code($error);
        return [
            'category' => Api::isTransient($error) ? SubscriptionData::DECLINE_NETWORK : self::categoryFor($code),
            'code' => self::clean($code !== '' ? $code : 'api_error'),
        ];
    }

    /**
     * Write the decline details on an order (call before the order turns failed).
     *
     * @param array{category:string, code:string} $decline
     */
    public static function record(int $orderId, array $decline): void
    {
        Orders::setMeta($orderId, [
            SubscriptionData::META_DECLINE => in_array($decline['category'], SubscriptionData::DECLINES, true) ? $decline['category'] : SubscriptionData::DECLINE_SOFT,
            SubscriptionData::META_DECLINE_CODE => self::clean($decline['code']),
        ]);
    }

    private static function clean(string $code): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $code), 0, 64);
    }
}
