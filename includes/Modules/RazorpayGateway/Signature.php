<?php

namespace Stackless\Modules\RazorpayGateway;

defined('ABSPATH') || exit;

/**
 * Razorpay signatures, all HMAC-SHA256 in lowercase hex:
 *  - webhooks: the raw request body, keyed with the webhook secret (X-Razorpay-Signature);
 *  - Standard Checkout: "{order_id}|{payment_id}", keyed with the API key secret.
 */
final class Signature
{
    public static function webhook(string $body, string $signature, string $secret): bool
    {
        $signature = strtolower(trim($signature));
        if ($body === '' || $secret === '' || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    public static function payment(string $orderId, string $paymentId, string $signature, string $keySecret): bool
    {
        $signature = strtolower(trim($signature));
        if ($orderId === '' || $paymentId === '' || $keySecret === '' || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret), $signature);
    }

    /** Signature header value for a body (tests and the staging simulator). */
    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
