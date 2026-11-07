<?php

namespace Stackless\Modules\StripeGateway\Managed;

defined('ABSPATH') || exit;

/**
 * Managed Payments events arriving at the Stripe module's webhook endpoint. The endpoint has
 * already verified the signature and claimed the event; every object is read back from
 * Stripe with the Managed Payments API version before anything changes.
 */
final class Events
{
    /** True when the Stripe webhook should hand this event type to Managed Payments. */
    public static function handles(string $type): bool
    {
        return EventMap::handler($type) !== null;
    }

    /**
     * @param array<string,mixed> $object The event's data.object.
     * @throws \RuntimeException With code Sync::DEFERRED when Stripe should retry later.
     */
    public static function dispatch(string $type, array $object, string $mode): string
    {
        $handler = EventMap::handler($type);
        $id = (string) ($object['id'] ?? '');
        if ($handler === null || $id === '') {
            return 'ignored';
        }
        $gateway = Gateway::instance();
        $sync = new Sync($gateway);
        $client = Gateway::client($mode);

        switch ($handler) {
            case 'session':
                $metadata = (array) ($object['metadata'] ?? []);
                if (empty($metadata['stackless_managed']) || !Sync::isOurs($metadata)) {
                    return 'not managed payments';
                }
                $order = wc_get_order(absint($metadata['order_id'] ?? 0));
                if (!$order instanceof \WC_Order || $order->get_payment_method() !== $gateway->id) {
                    return 'no matching order';
                }
                $session = $client->get('checkout/sessions/' . rawurlencode($id));
                if ($type === 'checkout.session.async_payment_failed') {
                    return 'order ' . $order->get_id() . ': ' . $sync->sessionFailed($order, $session);
                }
                $outcome = $sync->confirmSession($order, $session, $mode);
                if ($outcome === 'busy') {
                    throw new \RuntimeException('Order is locked by another request; retry later.', Sync::DEFERRED);
                }
                return 'order ' . $order->get_id() . ': ' . $outcome;

            case 'invoicePaid':
            case 'invoiceFailed':
                $invoice = $client->get('invoices/' . rawurlencode($id), ['expand' => ['payments']]);
                return $handler === 'invoicePaid' ? $sync->invoicePaid($invoice, $mode) : $sync->invoiceFailed($invoice, $mode);

            case 'subscription':
                $remote = $client->get('subscriptions/' . rawurlencode($id));
                return $sync->syncSubscription($remote, $mode, $type);
        }
        return 'ignored';
    }
}
