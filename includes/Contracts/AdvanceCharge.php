<?php

namespace Stackless\Contracts;

defined('ABSPATH') || exit;

/**
 * Implemented by a SubscriptionGateway whose renewal charges land some time after they are
 * requested (2.0.0), for example card and UPI mandates in India, where the bank sends the
 * customer a pre-debit notice and debits a day or more later. Subscriptions creates the
 * renewal order and calls stackless_charge_renewal() this many seconds before the due date,
 * so the money arrives on time. The renewal period and the next payment date are unchanged.
 */
interface AdvanceCharge
{
    /** Seconds before the due date to start a renewal charge (0 = on the due date). */
    public function stackless_renewal_lead_seconds(): int;
}
