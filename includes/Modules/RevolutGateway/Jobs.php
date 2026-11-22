<?php

namespace Stackless\Modules\RevolutGateway;

use Stackless\Core\LiveStore;

defined('ABSPATH') || exit;

/**
 * Scheduled checks of payments Revolut has not settled: the backstop for a closed tab, a lost
 * webhook, and a charge whose answer never arrived.
 */
final class Jobs
{
    public const HOOK = 'stackless_revolut_gateway_check_payment';
    public const GROUP = 'stackless-revolut_gateway';

    /** Waits between checks, in seconds: a bank payment can take days. */
    private const DELAYS = [120, 900, 3600, 10800, 21600, 43200, 86400, 86400];

    /** A checkout nobody came back from is checked once. */
    public const ABANDONED_AFTER = 1800;

    /** Schedule the next check; false once the checks are used up. */
    public static function later(int $orderId): bool
    {
        $checks = self::count($orderId);
        if ($checks >= count(self::DELAYS)) {
            return false;
        }
        if (self::at($orderId, self::DELAYS[$checks])) {
            Orders::setMeta($orderId, [Keys::CHECKS => (string) ($checks + 1)]);
        }
        return true;
    }

    /** Schedule a check, unless one is due sooner. True when one was scheduled. */
    public static function at(int $orderId, int $delay): bool
    {
        if (!function_exists('as_schedule_single_action')) {
            return false;
        }
        $when = time() + $delay;
        $args = ['order_id' => $orderId];
        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(self::HOOK, $args, self::GROUP);
            if (is_int($next) && $next <= $when + 60) {
                return false;
            }
        }
        as_schedule_single_action($when, self::HOOK, $args, self::GROUP);
        return true;
    }

    public static function count(int $orderId): int
    {
        return (int) Orders::meta($orderId, Keys::CHECKS);
    }

    /**
     * Action Scheduler callback.
     *
     * @param mixed $order_id
     */
    public static function run($order_id): void
    {
        $orderId = (int) $order_id;
        if (LiveStore::hold(self::HOOK, ['order_id' => $orderId], self::GROUP)) {
            return;
        }
        if (!Orders::exists($orderId)) {
            return;
        }
        if (Orders::meta($orderId, Keys::CHARGE) === 'yes' && Orders::isPending($orderId) && Orders::isOurs($orderId)) {
            (new Charges())->charge($orderId, Orders::subscriptionFor($orderId));
            return;
        }
        $sync = new Sync();
        foreach (Attempts::open($orderId) as $attempt) {
            if ((string) $attempt['id'] !== '') {
                $outcome = $sync->apply($orderId, (string) $attempt['id'], (string) $attempt['mode']);
                if ($outcome === 'retry' || $outcome === 'busy') {
                    self::later($orderId);
                }
            }
        }
    }
}
