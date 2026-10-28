<?php

namespace Stackless\Modules\StripeGateway;

defined('ABSPATH') || exit;

/**
 * Verifies the Stripe-Signature header (https://docs.stripe.com/webhooks#verify-manually).
 *
 * Header: "t=1700000000,v1=hexsig[,v1=hexsig2][,v0=...]". Signed payload is "{t}.{raw body}",
 * HMAC-SHA256 with the endpoint's signing secret. Any matching v1 passes, and the timestamp
 * must be within the tolerance window (both directions) to block replays of old deliveries.
 */
final class WebhookSignature
{
    public const DEFAULT_TOLERANCE = 300;

    public const ERROR_NO_SECRET = 'no_secret';
    public const ERROR_MALFORMED = 'malformed_header';
    public const ERROR_NO_SIGNATURE = 'no_v1_signature';
    public const ERROR_TIMESTAMP = 'timestamp_outside_tolerance';
    public const ERROR_MISMATCH = 'signature_mismatch';

    /**
     * @return string|null null when the signature is valid, otherwise one of the ERROR_* codes.
     */
    public static function verify(string $payload, string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): ?string
    {
        if ($secret === '') {
            return self::ERROR_NO_SECRET;
        }
        $parsed = self::parse($header);
        if ($parsed === null) {
            return self::ERROR_MALFORMED;
        }
        if (!$parsed['v1']) {
            return self::ERROR_NO_SIGNATURE;
        }
        $now = $now ?? time();
        if ($tolerance > 0 && abs($now - $parsed['t']) > $tolerance) {
            return self::ERROR_TIMESTAMP;
        }
        $expected = self::compute($payload, $secret, $parsed['t']);
        foreach ($parsed['v1'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return null;
            }
        }
        return self::ERROR_MISMATCH;
    }

    /** Builds a header value the way Stripe does. Used by tests and staging scripts. */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . self::compute($payload, $secret, $timestamp);
    }

    public static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * @return array{t:int, v1:string[]}|null
     */
    public static function parse(string $header): ?array
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            list($key, $value) = $pair;
            if ($key === 't') {
                if (!ctype_digit($value)) {
                    return null;
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '' && ctype_xdigit($value)) {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null) {
            return null;
        }
        return ['t' => $timestamp, 'v1' => $signatures];
    }
}
