<?php

namespace Stackless\Modules\RevolutGateway;

defined('ABSPATH') || exit;

/** Names this gateway stores on orders and users, and sends to Revolut as order metadata. */
final class Keys
{
    public const MODULE_ID = 'revolut_gateway';
    public const GATEWAY_ID = 'stackless_revolut';

    /** Every Revolut order created for a store order: the list Attempts keeps. */
    public const ATTEMPTS = '_stackless_revolut_attempts';
    /** Revolut order that paid the store order; refunds go to it. */
    public const PAID = '_stackless_revolut_order_id';
    /** live|test */
    public const MODE = '_stackless_revolut_mode';
    /** "yes" while a saved-card charge is wanted or unresolved. */
    public const CHARGE = '_stackless_revolut_charge';
    /** "yes" while Revolut is processing a payment for the order. */
    public const WAITING = '_stackless_revolut_waiting';
    /** Scheduled checks made since the last settled attempt. */
    public const CHECKS = '_stackless_revolut_checks';
    /** "yes" when money arrived that the store must look at (a second payment, a wrong amount, a failed refund). */
    public const REVIEW = '_stackless_revolut_review';
    /** When REVIEW was set (Unix time). */
    public const REVIEW_AT = '_stackless_revolut_review_at';
    /** Revolut refund order ids this store knows. */
    public const REFUNDS = '_stackless_revolut_refund_ids';
    /** The refund being sent, saved before the request. */
    public const REFUND_INTENT = '_stackless_revolut_refund_intent';
    /** Payments and dispute states already reported on the order. */
    public const REPORTED = '_stackless_revolut_reported';

    /** Revolut customer per user and mode: suffix live|test. */
    public const USER_CUSTOMER = '_stackless_revolut_customer_';
    /** Revolut customer created for a guest order. */
    public const GUEST_CUSTOMER = '_stackless_revolut_guest_customer';
    /** Add-payment-method sessions of a user. */
    public const USER_ADDS = '_stackless_revolut_pending_methods';

    /** Revolut order metadata: the store order, or the user adding a card. */
    public const REMOTE_ORDER = 'stackless_order';
    public const REMOTE_USER = 'stackless_user';
}
