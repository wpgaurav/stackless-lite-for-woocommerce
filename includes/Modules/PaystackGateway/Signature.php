<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * Paystack webhook signatures, both schemes Paystack sends:
 *
 * - Paystack-Signature (current): "t=<unix>,v1=<whk_id>:<hex>[,v1=…]", where hex is the
 *   HMAC-SHA512 of "{t}.{raw body}" keyed with the webhook signing secret (whsec_…) from
 *   Developers → API configuration. Deliveries older than five minutes are refused.
 * - x-paystack-signature (legacy, deprecated by Paystack): the hex HMAC-SHA512 of the raw
 *   body keyed with the secret key. Sent only while the account still has a secret key.
 */
final class Signature
{
    /** Seconds a delivery's timestamp may differ from now (replay window). */
    public const TOLERANCE = 300;

    /** Current scheme: the Paystack-Signature header and the webhook signing secret. */
    public static function verify(string $body, string $header, string $secret, ?int $now = null): bool
    {
        if ($header === '' || $secret === '') {
            return false;
        }
        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = trim($pair[1]);
            } elseif ($pair[0] === 'v1') {
                // "<whk_id>:<hex>" names the endpoint; the signature is the part after the colon.
                $value = trim($pair[1]);
                $colon = strrpos($value, ':');
                $signatures[] = strtolower($colon === false ? $value : substr($value, $colon + 1));
            }
        }
        if (!ctype_digit($timestamp) || !$signatures || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha512', $timestamp . '.' . $body, $secret);
        foreach ($signatures as $signature) {
            if (ctype_xdigit($signature) && hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    /** Legacy scheme: x-paystack-signature keyed with the secret key. */
    public static function verifyLegacy(string $body, string $header, string $secretKey): bool
    {
        $header = strtolower(trim($header));
        if ($header === '' || $secretKey === '' || !ctype_xdigit($header)) {
            return false;
        }
        return hash_equals(self::signLegacy($body, $secretKey), $header);
    }

    /** Paystack-Signature value for a payload (tests and the staging simulator). */
    public static function sign(string $body, string $secret, int $timestamp, string $endpoint = 'whk_sim'): string
    {
        return 't=' . $timestamp . ',v1=' . $endpoint . ':' . hash_hmac('sha512', $timestamp . '.' . $body, $secret);
    }

    /** x-paystack-signature value for a payload (tests and the staging simulator). */
    public static function signLegacy(string $body, string $secretKey): string
    {
        return hash_hmac('sha512', $body, $secretKey);
    }
}
