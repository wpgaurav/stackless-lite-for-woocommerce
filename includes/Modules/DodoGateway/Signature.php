<?php

namespace Stackless\Modules\DodoGateway;

defined('ABSPATH') || exit;

/**
 * Standard Webhooks signature check, as Dodo Payments signs deliveries:
 * base64(HMAC-SHA256("{webhook-id}.{webhook-timestamp}.{body}")), sent as
 * "v1,<signature>" (space-separated when several are valid during rotation).
 *
 * Dodo secrets are "whsec_" plus a base64 key, which is the HMAC key. The raw
 * secret bytes are tried as well, as the Standard Webhooks SDKs do.
 */
final class Signature
{
    /** Seconds a delivery's timestamp may differ from now (replay window). */
    public const TOLERANCE = 300;

    /** @param array{id?:string,timestamp?:string,signature?:string} $headers */
    public static function verify(string $body, array $headers, string $secret, ?int $now = null): bool
    {
        $id = (string) ($headers['id'] ?? '');
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $signatures = (string) ($headers['signature'] ?? '');

        if ($id === '' || !ctype_digit($timestamp) || $signatures === '' || $secret === '') {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $keys = [$secret];
        if (strpos($secret, 'whsec_') === 0) {
            $decoded = base64_decode(substr($secret, 6), true);
            if ($decoded !== false && $decoded !== '') {
                array_unshift($keys, $decoded);
            }
        }

        $content = $id . '.' . $timestamp . '.' . $body;
        foreach ((array) preg_split('/\s+/', trim($signatures)) as $entry) {
            $parts = explode(',', (string) $entry, 2);
            if (count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }
            foreach ($keys as $key) {
                if (hash_equals(base64_encode(hash_hmac('sha256', $content, $key, true)), $parts[1])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Signature header value for a payload (used by tests and the staging simulator). */
    public static function sign(string $body, string $id, int $timestamp, string $secret): string
    {
        $key = $secret;
        if (strpos($secret, 'whsec_') === 0) {
            $decoded = base64_decode(substr($secret, 6), true);
            if ($decoded !== false && $decoded !== '') {
                $key = $decoded;
            }
        }
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
    }
}
