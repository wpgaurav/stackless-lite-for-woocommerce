<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Whether to capture a card payment after 3-D Secure, from PayPal's authentication_result
 * (developer.paypal.com/docs/checkout/advanced/customize/3d-secure/response-parameters/).
 *
 * - liability_shift POSSIBLE: continue (the issuer authenticated or attempted it).
 * - NO with enrollment N, U or B (card not enrolled, unavailable or bypassed): continue.
 * - NO after a failed or rejected authentication (Y + N/R): reject.
 * - UNKNOWN, or NO with an unavailable or errored authentication: ask the buyer to retry.
 * - No authentication_result at all: 3-D Secure did not run (not required), continue.
 */
final class CardSecurity
{
    public const CONTINUE = 'continue';
    public const REJECT = 'reject';
    public const RETRY = 'retry';

    /** @param array<string,mixed> $result payment_source.card.authentication_result */
    public static function decide(array $result): string
    {
        if (!$result) {
            return self::CONTINUE;
        }
        $shift = strtoupper((string) ($result['liability_shift'] ?? ''));
        $secure = (array) ($result['three_d_secure'] ?? []);
        $enrolled = strtoupper((string) ($secure['enrollment_status'] ?? ''));
        $status = strtoupper((string) ($secure['authentication_status'] ?? ''));

        if ($shift === 'POSSIBLE' || $shift === 'YES') {
            return self::CONTINUE;
        }
        if ($shift === 'NO') {
            if (in_array($enrolled, ['N', 'U', 'B'], true)) {
                return self::CONTINUE;
            }
            if ($enrolled === 'Y' && in_array($status, ['N', 'R'], true)) {
                return self::REJECT;
            }
            return self::RETRY;
        }
        return self::RETRY;
    }

    /** authentication_result of the card in a PayPal order (cards and Google Pay cards). */
    public static function resultOf(array $paypalOrder): ?array
    {
        $source = (array) ($paypalOrder['payment_source'] ?? []);
        if (isset($source['card']['authentication_result']) && is_array($source['card']['authentication_result'])) {
            return $source['card']['authentication_result'];
        }
        if (isset($source['google_pay']['card']['authentication_result']) && is_array($source['google_pay']['card']['authentication_result'])) {
            return $source['google_pay']['card']['authentication_result'];
        }
        return isset($source['card']) || isset($source['google_pay']) ? [] : null;
    }
}
