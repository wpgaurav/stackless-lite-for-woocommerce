<?php

namespace Stackless\Contracts;

defined('ABSPATH') || exit;

/**
 * Implemented by a WC_Payment_Gateway that can renew STACKLESS subscriptions automatically.
 *
 * Any gateway (ours or third-party) may implement it. Gateways that don't are still
 * usable for subscriptions in manual-renewal mode (pending renewal order + pay link).
 *
 * Flow the Subscriptions module follows:
 *  1. Checkout: gateway sees stackless_order_requires_saved_payment_method($order) === true,
 *     saves a reusable method, writes SubscriptionData::META_TOKEN_ID (+ customer id)
 *     on the parent order, then completes payment. Zero-total (all-trial) orders still
 *     go through the gateway so the method can be saved.
 *  2. Renewal due: Subscriptions creates a pending renewal order (same currency, items,
 *     payment method, token meta) and calls charge_renewal().
 *  3. Subscription state follows the renewal ORDER status: processing/completed => renewed,
 *     failed => dunning. Async gateways return 'pending' and complete the order later
 *     from their webhook; the module reacts to the status change.
 */
interface SubscriptionGateway
{
    /** auto_renewal, multiple_subscriptions, zero_total_setup, payment_method_change, amount_change, date_change, gateway_managed */
    public const FEATURES = [
        'auto_renewal',
        'multiple_subscriptions',
        'zero_total_setup',
        'payment_method_change',
        'amount_change',
        'date_change',
        'gateway_managed',
    ];

    public function stackless_supports(string $feature): bool;

    /**
     * Charge a renewal off-session. Must be idempotent per renewal order
     * (use the order id as the provider idempotency key).
     *
     * On success call $renewal_order->payment_complete($transaction_id) yourself.
     *
     * @param SubscriptionData&\WC_Order $subscription
     * @return array{result:string, message?:string} result: success|pending|failed
     */
    public function stackless_charge_renewal(\WC_Order $renewal_order, $subscription): array;

    /**
     * For gateway_managed gateways (provider runs the schedule, e.g. Polar):
     * cancel or schedule cancellation at the provider. Others return true.
     *
     * @param SubscriptionData&\WC_Order $subscription
     */
    public function stackless_cancel_subscription($subscription, bool $at_period_end): bool;
}
