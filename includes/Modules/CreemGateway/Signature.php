<?php

namespace Stackless\Modules\CreemGateway;

defined('ABSPATH') || exit;

/**
 * Creem webhook signatures.
 *
 * Creem signs every delivery with creem-signature: the hex HMAC-SHA256 of the raw body keyed
 * with the endpoint's webhook secret (optionally prefixed "sha256="). Creem's SDK also checks
 * Standard Webhooks headers (webhook-id, webhook-timestamp, webhook-signature "v1,<base64>")
 * when they are present; those are accepted too, with a five-minute timestamp window.
 */
final class Signature
{
    /** Seconds a Standard Webhooks timestamp may differ from now. */
    public const TOLERANCE = 300;

    /** @param array{signature?:string,id?:string,timestamp?:string,std_signature?:string} $headers */
    public static function verify(string $body, array $headers, string $secret, ?int $now = null): bool
    {
        if ($secret === '') {
            return false;
        }
        $signature = strtolower(trim((string) ($headers['signature'] ?? '')));
        if (strpos($signature, 'sha256=') === 0) {
            $signature = substr($signature, 7);
        }
        if ($signature !== '' && ctype_xdigit($signature) && hash_equals(self::sign($body, $secret), $signature)) {
            return true;
        }
        return self::verifyStandard($body, $headers, $secret, $now);
    }

    /** @param array{id?:string,timestamp?:string,std_signature?:string} $headers */
    private static function verifyStandard(string $body, array $headers, string $secret, ?int $now): bool
    {
        $id = (string) ($headers['id'] ?? '');
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $signatures = (string) ($headers['std_signature'] ?? '');
        if ($id === '' || !ctype_digit($timestamp) || $signatures === '' || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE) {
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

    /** creem-signature value for a payload (tests and the staging simulator). */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
