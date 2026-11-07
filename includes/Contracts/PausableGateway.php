<?php

namespace Stackless\Contracts;

defined('ABSPATH') || exit;

/**
 * Implemented by a gateway_managed SubscriptionGateway whose provider can pause billing
 * (2.0.0). Gateways whose renewals Stackless schedules itself need nothing: Subscriptions
 * pauses those locally. A gateway_managed gateway without this interface cannot pause, and
 * Subscriptions hides the Pause action for its subscriptions.
 *
 * Both methods are called after \Stackless\Core\LiveStore::isLive() passed and before the
 * local status changes; return false to refuse (the subscription stays as it was).
 */
interface PausableGateway
{
    /**
     * @param SubscriptionData&\WC_Order $subscription
     * @param int|null $resume_at Unix time billing resumes, or null to pause until resumed by hand.
     */
    public function stackless_pause_subscription($subscription, ?int $resume_at): bool;

    /** @param SubscriptionData&\WC_Order $subscription */
    public function stackless_resume_subscription($subscription): bool;
}
