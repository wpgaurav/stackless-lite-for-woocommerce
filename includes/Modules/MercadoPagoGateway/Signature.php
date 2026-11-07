<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * Mercado Pago webhook signatures: the x-signature header "ts=…,v1=…", where v1 is the hex
 * HMAC-SHA256, keyed with the application's webhook secret, of the manifest
 * "id:{data.id};request-id:{x-request-id};ts:{ts};". data.id comes from the notification
 * URL's query string (lowercased when alphanumeric); a part whose value is missing is left
 * out of the manifest.
 */
final class Signature
{
    /** @return array{ts:string,v1:string[]} */
    public static function parse(string $header): array
    {
        $ts = '';
        $v1 = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            $key = strtolower(trim($pair[0]));
            if ($key === 'ts') {
                $ts = trim($pair[1]);
            } elseif ($key === 'v1') {
                $v1[] = strtolower(trim($pair[1]));
            }
        }
        return ['ts' => $ts, 'v1' => $v1];
    }

    public static function manifest(string $dataId, string $requestId, string $ts): string
    {
        $manifest = '';
        if ($dataId !== '') {
            $manifest .= 'id:' . (ctype_alnum($dataId) ? strtolower($dataId) : $dataId) . ';';
        }
        if ($requestId !== '') {
            $manifest .= 'request-id:' . $requestId . ';';
        }
        return $manifest . 'ts:' . $ts . ';';
    }

    public static function verify(string $header, string $dataId, string $requestId, string $secret): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $parsed = self::parse($header);
        if ($parsed['ts'] === '' || !ctype_digit($parsed['ts']) || !$parsed['v1']) {
            return false;
        }
        $expected = hash_hmac('sha256', self::manifest($dataId, $requestId, $parsed['ts']), $secret);
        foreach ($parsed['v1'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    /** x-signature value for a notification (tests and the staging simulator). */
    public static function sign(string $dataId, string $requestId, string $ts, string $secret): string
    {
        return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', self::manifest($dataId, $requestId, $ts), $secret);
    }
}
