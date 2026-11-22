<?php

namespace Stackless\Modules\AirwallexGateway;

defined('ABSPATH') || exit;

/** Order and user meta keys of the Airwallex gateway. */
final class Meta
{
    /** test|live: the Airwallex environment every id on the order belongs to. */
    public const MODE = '_stackless_airwallex_mode';
    /** The Airwallex account those ids belong to. */
    public const ACCOUNT = '_stackless_airwallex_account_id';
    /** merchant_order_id of every payment for the order. It carries the order id, so a copied order never owns it. */
    public const REFERENCE = '_stackless_airwallex_reference';
    /** The Checkout Session the buyer may still use, and a hash of what it was created for. */
    public const SESSION = '_stackless_airwallex_session_id';
    public const SESSION_HASH = '_stackless_airwallex_session_hash';
    /** "yes" while the hosted payment must leave a PaymentConsent. */
    public const SAVE = '_stackless_airwallex_save';
    /** Who started the hosted payment ("u:{user id}" or "g:{hash of the email}"): the saved card's owner. */
    public const OWNER = '_stackless_airwallex_owner';
    public const CUSTOMER = '_stackless_airwallex_customer_id';
    /** The PaymentIntent that paid the order. */
    public const INTENT = '_stackless_airwallex_payment_intent_id';
    /** An off-session charge whose result is not known yet, and the last intent such a charge used. */
    public const CHARGE = '_stackless_airwallex_charge';
    public const CHARGE_INTENT = '_stackless_airwallex_charge_intent';
    /** "yes" when the last attempt to pay the order was a charge without the buyer. */
    public const OFF_SESSION = '_stackless_airwallex_off_session';
    /** "yes" while this gateway parked the order for an asynchronous payment. */
    public const WAITING = '_stackless_airwallex_waiting';
    public const CHECKS = '_stackless_airwallex_checks';
    public const RETRIES = '_stackless_airwallex_retries';
    public const CONSENT_WAIT = '_stackless_airwallex_consent_wait';
    /** Money received that did not pay the order: intent id => reason. REVIEW is "yes" while any is listed. */
    public const FLAGS = '_stackless_airwallex_flags';
    public const REVIEW = '_stackless_airwallex_review';
    public const REFUNDS = '_stackless_airwallex_refund_ids';
    /** A refund whose result is not known yet. */
    public const REFUND_PENDING = '_stackless_airwallex_refund_pending';
    public const REFUND_ROUND = '_stackless_airwallex_refund_round';
    public const DISPUTES = '_stackless_airwallex_disputes';

    /** User meta: the customer id per mode and account, and hosted "add a card" pages in progress. */
    public const USER_CUSTOMER = '_stackless_airwallex_customer_';
    public const USER_PENDING_ADD = '_stackless_airwallex_pending_add';

    public static function customerKey(string $mode, string $account): string
    {
        return self::USER_CUSTOMER . $mode . '_' . substr(md5($account), 0, 12);
    }
}
