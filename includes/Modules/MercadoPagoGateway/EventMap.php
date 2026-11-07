<?php

namespace Stackless\Modules\MercadoPagoGateway;

defined('ABSPATH') || exit;

/**
 * Pure mapping from Mercado Pago notifications and payments to Stackless behavior.
 */
final class EventMap
{
    /** Notification topics to turn on under Your integrations → Webhooks. */
    public const EVENTS = ['payment'];

    /** Handler for a notification type, or null to acknowledge and ignore. */
    public static function handler(string $type): ?string
    {
        return $type === 'payment' ? 'payment' : null;
    }

    /**
     * Replay key: the payment and the signed x-request-id of the delivery, so a captured delivery
     * replayed as is counts once (the notification id in the body is not signed). Without a
     * request id, the notification id stands in.
     */
    public static function eventKey(array $notification, string $dataId, string $requestId = ''): string
    {
        if ($dataId === '') {
            return '';
        }
        $id = $requestId !== '' ? 'r' . $requestId : (string) ($notification['id'] ?? '');
        return (string) ($notification['type'] ?? '') . ':' . $dataId . ($id !== '' ? ':' . substr($id, 0, 100) : '');
    }

    /** paid|pending|failed|refunded for a payment status. */
    public static function paymentState(string $status): string
    {
        switch (strtolower($status)) {
            case 'approved':
            case 'authorized':
                return 'paid';
            case 'pending':
            case 'in_process':
            case 'in_mediation':
                return 'pending';
            case 'refunded':
            case 'charged_back':
                return 'refunded';
            default:
                return 'failed'; // rejected, cancelled
        }
    }

    /** Order id from an external reference made by this store ("wc{id}-{site}"), else 0. */
    public static function orderIdFrom(string $externalReference): int
    {
        return preg_match('/^wc(\d+)-[a-f0-9]{8}$/', $externalReference, $m) ? (int) $m[1] : 0;
    }

    /** "wc{order id}-{site hash}": at most 64 letters, digits, "-" and "_". */
    public static function externalReference(int $orderId, string $site): string
    {
        return 'wc' . $orderId . '-' . substr(md5($site), 0, 8);
    }

    /**
     * Whether a token is for testing: the TEST- tokens of older applications, or the APP_USR-
     * token of a test user (Checkout Pro's test credentials), which /users/me tags test_user.
     */
    public static function isTestToken(string $token, array $account): bool
    {
        return strpos(trim($token), 'TEST-') === 0 || !empty($account['test_user']);
    }
}
