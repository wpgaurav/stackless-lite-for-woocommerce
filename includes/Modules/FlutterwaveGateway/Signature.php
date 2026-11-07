<?php

namespace Stackless\Modules\FlutterwaveGateway;

defined('ABSPATH') || exit;

/**
 * Flutterwave v3 webhooks carry the merchant's secret hash, as set under Settings →
 * Webhooks, in the verif-hash header: it is compared in constant time. Accounts moved to
 * v4 webhooks send flutterwave-signature instead, the base64 HMAC-SHA256 of the raw body
 * keyed with the same secret hash; that is accepted too.
 */
final class Signature
{
    public static function verify(string $body, array $headers, string $secretHash): bool
    {
        if ($secretHash === '') {
            return false;
        }
        $hash = trim((string) ($headers['verif_hash'] ?? ''));
        if ($hash !== '' && hash_equals($secretHash, $hash)) {
            return true;
        }
        $signature = trim((string) ($headers['signature'] ?? ''));
        return $signature !== '' && hash_equals(self::sign($body, $secretHash), $signature);
    }

    /** flutterwave-signature value for a payload (tests and the staging simulator). */
    public static function sign(string $body, string $secretHash): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secretHash, true));
    }
}
