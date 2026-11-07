<?php

namespace Stackless\Contracts;

defined('ABSPATH') || exit;

/**
 * Implemented by a WC_Payment_Gateway that can charge a customer's saved payment token
 * for a new order outside the renewal schedule (2.0.0). Post-purchase offers use it.
 *
 * The caller has already created $order (pending, totals calculated, payment method set
 * to this gateway, customer set) and owns the customer's consent: it only calls this after
 * an explicit click. The token belongs to the order's customer and to this gateway; the
 * gateway must check both again and refuse otherwise.
 *
 * Must be idempotent per order (use the order id in the provider idempotency key) and
 * must respect \Stackless\Core\LiveStore::isLive() for off-session charges.
 *
 * On success the gateway calls $order->payment_complete($transaction_id) itself.
 */
interface SavedMethodCharger
{
    /**
     * @param bool $on_session true when the customer is on the page and can authenticate
     *                         (3-D Secure, payer approval); false for unattended charges.
     * @return array{result:string, message?:string, redirect?:string}
     *         result: success | pending (async, finished by webhook) | failed |
     *         action_required (redirect = a page where the customer completes authentication;
     *         the order stays pending until then).
     */
    public function stackless_charge_saved_method(\WC_Order $order, \WC_Payment_Token $token, bool $on_session): array;
}
