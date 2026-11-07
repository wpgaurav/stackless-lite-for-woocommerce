<?php

namespace Stackless\Modules\StripeGateway;

use Stackless\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Writes the decline category and the provider's own code on an order (2.0.0).
 *
 * Every renewal or switch charge that fails writes both BEFORE the order turns failed,
 * because Dunning reacts to that status change and reads them then. The caller saves.
 */
final class Declines
{
    public static function write(\WC_Order $order, string $category, string $code): void
    {
        if (!in_array($category, SubscriptionData::DECLINES, true)) {
            $category = SubscriptionData::DECLINE_SOFT;
        }
        $order->update_meta_data(SubscriptionData::META_DECLINE, $category);
        $order->update_meta_data(SubscriptionData::META_DECLINE_CODE, substr(sanitize_key($code), 0, 64));
    }

    /** @param array<string,mixed> $error A PaymentIntent's last_payment_error. */
    public static function fromIntentError(\WC_Order $order, array $error): void
    {
        $code = (string) ($error['decline_code'] ?? '') ?: (string) ($error['code'] ?? '');
        self::write($order, ErrorMapper::declineCategoryFromIntentError($error), $code !== '' ? $code : 'declined');
    }

    public static function fromException(\WC_Order $order, StripeApiException $e): void
    {
        $code = $e->getDeclineCode() ?: $e->getStripeCode();
        if ($code === '') {
            $code = $e->getHttpStatus() === 0 ? 'api_connection_error' : 'http_' . $e->getHttpStatus();
        }
        self::write($order, ErrorMapper::declineCategoryFromException($e), $code);
    }
}
