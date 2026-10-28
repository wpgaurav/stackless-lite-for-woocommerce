<?php

namespace Stackless\Modules\PaddleGateway;

defined('ABSPATH') || exit;

/**
 * Paddle Billing webhook signature check.
 *
 * Paddle sends "Paddle-Signature: ts=<unix>;h1=<hex>" where h1 is
 * hex(HMAC-SHA256("<ts>:<raw body>", endpoint secret key)). During secret
 * rotation the header can carry more than one h1; any match is accepted.
 */
final class Signature
{
    /** Seconds a delivery's timestamp may differ from now (replay window). */
    public const TOLERANCE = 300;

    /** @return array{ts:string,h1:string[]} */
    public static function parse(string $header): array
    {
        $parsed = ['ts' => '', 'h1' => []];
        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 'ts') {
                $parsed['ts'] = $pair[1];
            } elseif ($pair[0] === 'h1' && $pair[1] !== '') {
                $parsed['h1'][] = strtolower($pair[1]);
            }
        }
        return $parsed;
    }

    public static function verify(string $body, string $header, string $secret, ?int $now = null): bool
    {
        if ($header === '' || $secret === '') {
            return false;
        }
        $parsed = self::parse($header);
        if (!ctype_digit($parsed['ts']) || !$parsed['h1']) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $parsed['ts']) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $parsed['ts'] . ':' . $body, $secret);
        foreach ($parsed['h1'] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** Signature header value for a payload (used by tests and the staging simulator). */
    public static function sign(string $body, int $timestamp, string $secret): string
    {
        return 'ts=' . $timestamp . ';h1=' . hash_hmac('sha256', $timestamp . ':' . $body, $secret);
    }
}
