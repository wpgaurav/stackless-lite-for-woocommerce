<?php

namespace Stackless\Contracts;

defined('ABSPATH') || exit;

/**
 * What every other module may rely on from a subscription object.
 *
 * The Subscriptions module implements this on its WC_Order subclass (order type
 * "stackless_subscription"), so get_id(), get_customer_id(), get_currency(), get_items(),
 * get_payment_method(), get_parent_id(), get_meta()/update_meta_data() etc. also work.
 */
interface SubscriptionData
{
    /** Internal status slugs (stored as "wc-" + slug). */
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIAL = 'sub-trial';
    public const STATUS_ACTIVE = 'sub-active';
    public const STATUS_ON_HOLD = 'sub-onhold';
    public const STATUS_PENDING_CANCEL = 'sub-pendcancel';
    public const STATUS_CANCELLED = 'sub-cancelled';
    public const STATUS_EXPIRED = 'sub-expired';

    /** Meta keys a gateway writes on the PARENT order before payment_complete(); copied to each subscription. */
    public const META_TOKEN_ID = '_stackless_payment_token_id';
    public const META_GATEWAY_CUSTOMER = '_stackless_gateway_customer_id';
    public const META_GATEWAY_SUBSCRIPTION = '_stackless_gateway_subscription_id';

    /** Active, trialing or pending-cancel. */
    public function is_active(): bool;

    /** day|week|month|year */
    public function get_billing_period(): string;

    public function get_billing_interval(): int;

    /** @return \WC_DateTime|null */
    public function get_next_payment_date();

    /** @return \WC_DateTime|null */
    public function get_trial_end_date();

    /** @return \WC_DateTime|null null = runs until cancelled */
    public function get_end_date();

    public function has_product(int $product_id): bool;

    /** "auto" or "manual" */
    public function get_renewal_mode(): string;

    /** Recurring total per cycle in the subscription's own currency. */
    public function get_recurring_total(): float;
}
