<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/**
 * Revolut-Signature: comma-separated "v1={hex}" values (several while a secret rotates), each an
 * HMAC-SHA256 of "v1.{Revolut-Request-Timestamp}.{raw body}". The timestamp is in milliseconds;
 * Revolut's integration examples allow five minutes.
 */
final class Signature
{
    public const TOLERANCE = 300;

    public static function fresh(string $timestamp, ?int $now = null): bool
    {
        if (!preg_match('/^\d{10,13}$/', $timestamp)) {
            return false;
        }
        $seconds = strlen($timestamp) === 13 ? (int) floor((int) $timestamp / 1000) : (int) $timestamp;
        return abs(($now ?? time()) - $seconds) <= self::TOLERANCE;
    }

    public static function valid(string $body, string $timestamp, string $header, string $secret): bool
    {
        if ($secret === '' || $timestamp === '' || $header === '') {
            return false;
        }
        $expected = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $body, $secret);
        foreach (explode(',', $header) as $signature) {
            if (hash_equals($expected, trim($signature))) {
                return true;
            }
        }
        return false;
    }
}
