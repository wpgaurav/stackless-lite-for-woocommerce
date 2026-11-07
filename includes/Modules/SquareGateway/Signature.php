<?php

namespace Stackless\Modules\SquareGateway;

defined('ABSPATH') || exit;

/**
 * Square webhook signature: base64(HMAC-SHA256(signature key, notification URL + raw body)),
 * sent in x-square-hmacsha256-signature. The URL must be exactly the one the subscription was
 * created with, so the caller passes the stored URL, not the one this request arrived on.
 */
final class Signature
{
    public static function verify(string $url, string $body, string $signature, string $key): bool
    {
        $signature = trim($signature);
        if ($url === '' || $body === '' || $key === '' || $signature === '') {
            return false;
        }
        return hash_equals(self::sign($url, $body, $key), $signature);
    }

    public static function sign(string $url, string $body, string $key): string
    {
        return base64_encode(hash_hmac('sha256', $url . $body, $key, true));
    }
}
