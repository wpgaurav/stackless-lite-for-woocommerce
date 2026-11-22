<?php

namespace Stackless\Modules\BraintreeGateway;

defined('ABSPATH') || exit;

/** Order and user meta this module writes. */
final class Meta
{
    /** Global id of the transaction that took the money. Refunds and syncing start here. */
    public const TRANSACTION = '_stackless_braintree_transaction_id';
    /** Its id as the Control Panel and webhooks show it. */
    public const LEGACY_ID = '_stackless_braintree_legacy_id';
    public const MODE = '_stackless_braintree_mode';
    /** Braintree merchant the payment was made with. */
    public const ACCOUNT = '_stackless_braintree_account';
    public const MERCHANT_ACCOUNT = '_stackless_braintree_merchant_account';
    /** The charge whose outcome is not settled yet. Written before Braintree is called. */
    public const ATTEMPT = '_stackless_braintree_attempt';
    /** Charges closed as absent, searched once more in case they show up late. */
    public const ABSENT = '_stackless_braintree_absent_attempts';
    /** Set while the order waits on Braintree out of "pending". */
    public const CONFIRMING = '_stackless_braintree_confirming';
    /** Captured transactions that must be refunded by hand. */
    public const FLAGGED = '_stackless_braintree_needs_refund';
    public const REFUNDS = '_stackless_braintree_refund_ids';
    public const REFUND_OPERATION = '_stackless_braintree_refund_operation';
    public const REFUNDS_DONE = '_stackless_braintree_refunds_done';
    public const DISPUTES = '_stackless_braintree_disputes';
    /** Customer created for an order without an account. */
    public const CUSTOMER = '_stackless_braintree_customer';

    /** User meta prefix; completed with the mode and the merchant. */
    public const USER_CUSTOMER = '_stackless_braintree_customer_';
}
