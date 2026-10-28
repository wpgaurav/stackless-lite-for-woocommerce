<?php

namespace Stackless\Modules\PayPalGateway;

defined('ABSPATH') || exit;

/**
 * Short-lived cross-request mutex: an INSERT IGNORE on the options table's
 * unique option_name index, so exactly one request wins. Keeps the browser
 * return, the webhook and a renewal job from settling the same order at once.
 * Bypasses the options cache on purpose; these rows are never read with get_option().
 *
 * Each holder writes "{time}:{token}", so a request whose lock went stale and was
 * taken over can only release its own row, never the new owner's.
 */
final class Lock
{
    private const PREFIX = '_stackless_paypal_gateway_lock_';

    /** @return string|null The owner token to pass to release(), or null when the lock is held elsewhere. */
    public static function acquire(string $key, int $ttl = 120): ?string
    {
        global $wpdb;
        $name = self::PREFIX . md5($key);
        $value = time() . ':' . bin2hex(random_bytes(10));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value))) {
            return $value;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        $since = (int) $current;
        if ($since > 0 && $since < time() - $ttl) {
            // Abandoned by a request that died; take it over only if nobody else did first.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $taken = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current));
            return $taken ? $value : null;
        }
        return null;
    }

    /** Release the lock, but only while $token still owns it. */
    public static function release(string $key, string $token): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($wpdb->options, ['option_name' => self::PREFIX . md5($key), 'option_value' => $token]);
    }

    /**
     * Run $callback while holding the lock, waiting up to $wait seconds for it.
     *
     * @return mixed The callback's return value, or $busy when the lock never freed up.
     */
    public static function run(string $key, callable $callback, $busy = null, int $wait = 10)
    {
        $deadline = microtime(true) + $wait;
        while (($token = self::acquire($key)) === null) {
            if (microtime(true) >= $deadline) {
                return $busy;
            }
            usleep(250000);
        }
        try {
            return $callback();
        } finally {
            self::release($key, $token);
        }
    }
}
