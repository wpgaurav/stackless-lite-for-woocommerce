<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Live-store identity (audit F04). A copy of a production database at another address keeps every
 * saved payment method, schedule and customer address. Until someone confirms that the copy is
 * the live store, Stackless treats it as a copy: scheduled billing and customer messaging are held,
 * charges and provider-side cancellations are refused, and customer emails are not sent.
 *
 * The identity is a hash of the normalized home URL plus the WordPress environment type. A hash,
 * because search-replace tools rewrite plain URLs stored in the database, so a stored URL would
 * follow the copy. Nothing is changed on a copy: tokens, payment modes and orders stay as copied.
 *
 * Recorded on plugin activation when nothing is recorded yet, on the first load of an existing
 * install whose environment type is "production", and when an admin confirms this site as the live
 * store. Hosts can decide with the STACKLESS_LIVE_STORE constant (true or false) or the
 * stackless_live_store filter.
 *
 * Held actions are parked as one Action Scheduler action each and queued to run again as soon as
 * the store is live; their handlers re-check everything, so a renewal that came due while the site
 * was paused runs for its own period.
 */
final class LiveStore
{
    public const OPTION = 'stackless_live_store';
    public const DISMISSED_OPTION = 'stackless_live_store_dismissed';
    public const HELD_OPTION = 'stackless_live_store_held';
    public const CONSTANT = 'STACKLESS_LIVE_STORE';

    public const HELD_HOOK = 'stackless_held_action';
    public const RELEASE_HOOK = 'stackless_release_held_actions';
    public const HELD_GROUP = 'stackless-held';

    public const STATE_LIVE = 'live';
    public const STATE_UNRECORDED = 'unrecorded';
    public const STATE_MOVED = 'moved';
    public const STATE_ENVIRONMENT = 'environment';
    public const STATE_FORCED_LIVE = 'forced_live';
    public const STATE_FORCED_COPY = 'forced_copy';

    /** A held action is parked this long, then parked again while the site is still a copy. */
    private const PARK_FOR = 7 * 86400;
    private const RELEASE_BATCH = 100;
    private const ACTION = 'stackless_live_store';

    /** @var bool|null */
    private static $live;

    public static function boot(): void
    {
        self::bootstrap();
        add_action(self::HELD_HOOK, [self::class, 'runHeld'], 10, 3);
        add_action(self::RELEASE_HOOK, [self::class, 'releaseBatch'], 10, 0);
        add_action('init', [self::class, 'maybeRelease'], 99);
        add_action('update_option_home', [self::class, 'flush']);
        add_action('switch_blog', [self::class, 'flush']);
        add_filter('woocommerce_mail_callback', [self::class, 'filterMailCallback'], PHP_INT_MAX, 2);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handleAdminAction']);
    }

    /* ----- Identity ----- */

    /** Host (without "www."), non-default port and path, lowercased host; scheme and trailing slash dropped. */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $parts = wp_parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) ? $url : 'http://' . $url);
        if (!is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }
        $host = strtolower((string) $parts['host']);
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        $port = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true) ? ':' . (int) $parts['port'] : '';
        return $host . $port . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    public static function key(string $url): string
    {
        return hash('sha256', 'stackless-live-store|' . self::normalize($url));
    }

    public static function currentUrl(): string
    {
        return (string) get_option('home');
    }

    public static function environment(): string
    {
        return function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : 'production';
    }

    /** @return array<string,mixed> the recorded live store, or [] */
    public static function record(): array
    {
        $record = get_option(self::OPTION, []);
        return is_array($record) && !empty($record['key']) ? $record : [];
    }

    /**
     * Compare a recorded identity with this site's address and environment type.
     *
     * @param array<string,mixed> $record
     */
    public static function evaluate(array $record, string $url, string $environment): string
    {
        if (empty($record['key'])) {
            return self::STATE_UNRECORDED;
        }
        if (!hash_equals((string) $record['key'], self::key($url))) {
            return self::STATE_MOVED;
        }
        if ((string) ($record['env'] ?? 'production') !== $environment) {
            return self::STATE_ENVIRONMENT;
        }
        return self::STATE_LIVE;
    }

    /** Why this site is or isn't the live store (see STATE_*). The constant overrides the record. */
    public static function state(): string
    {
        if (defined(self::CONSTANT)) {
            return constant(self::CONSTANT) ? self::STATE_FORCED_LIVE : self::STATE_FORCED_COPY;
        }
        return self::evaluate(self::record(), self::currentUrl(), self::environment());
    }

    /** Whether automatic billing and customer messaging may run on this site. */
    public static function isLive(): bool
    {
        if (self::$live !== null) {
            return self::$live;
        }
        $state = self::state();
        $live = $state === self::STATE_LIVE || $state === self::STATE_FORCED_LIVE;
        if (!defined(self::CONSTANT)) {
            /**
             * Decide whether this site is the live store. Hosts that manage environments can force it.
             *
             * @param bool   $live  Whether this site matches the recorded live store.
             * @param string $state One of LiveStore::STATE_*.
             */
            $live = (bool) apply_filters('stackless_live_store', $live, $state);
        }
        return self::$live = $live;
    }

    public static function flush(): void
    {
        self::$live = null;
    }

    /** Record this site as the live store. */
    public static function approve(string $source = 'admin', int $user_id = 0): void
    {
        update_option(self::OPTION, [
            'key' => self::key(self::currentUrl()),
            'env' => self::environment(),
            // Encoded so search-replace tools leave it alone; display only, never compared.
            'url' => base64_encode(self::currentUrl()),
            'at' => time(),
            'by' => $user_id,
            'source' => $source,
        ], true);
        delete_option(self::DISMISSED_OPTION);
        self::flush();
        Logger::log('core', sprintf('This site (%s, %s) was recorded as the live store (%s, user #%d).', self::currentUrl(), self::environment(), $source, $user_id));
    }

    /** Existing installs: record a production site on its first load. Other environments wait for an admin. */
    public static function bootstrap(): void
    {
        if (self::record() || defined(self::CONSTANT) || self::environment() !== 'production') {
            return;
        }
        self::approve('upgrade');
    }

    /** Plugin activation on this site: record it unless a live store is already recorded. */
    public static function activate(): void
    {
        if (!self::record() && !(defined(self::CONSTANT) && !constant(self::CONSTANT))) {
            self::approve('activation', function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        }
    }

    /** The live store's address for display, or '' when none is recorded. */
    public static function liveUrl(): string
    {
        $record = self::record();
        $url = isset($record['url']) ? base64_decode((string) $record['url'], true) : false;
        return is_string($url) ? $url : '';
    }

    public static function copyError(string $message = ''): \WP_Error
    {
        return new \WP_Error('stackless_unapproved_copy', $message !== '' ? $message : __('This site is a copy of the live store, so Stackless does not charge customers or contact payment providers here. If this is the live store, confirm it in the Stackless notice first.', 'stackless-lite'));
    }

    /* ----- Held actions ----- */

    /**
     * Call first in an Action Scheduler handler. On the live store it returns false and the handler
     * runs; on a copy the action is parked and true is returned, so the handler must return.
     *
     * @param array<string,mixed> $args the action's args, exactly as scheduled
     */
    public static function hold(string $hook, array $args, string $group): bool
    {
        if (self::isLive()) {
            return false;
        }
        self::park($hook, $args, $group);
        return true;
    }

    /** @param array<string,mixed> $args */
    private static function park(string $hook, array $args, string $group): void
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_unschedule_all_actions')) {
            return;
        }
        $held = ['hook' => $hook, 'args' => $args, 'group' => $group];
        as_unschedule_all_actions(self::HELD_HOOK, $held, self::HELD_GROUP);
        as_schedule_single_action(time() + self::PARK_FOR, self::HELD_HOOK, $held, self::HELD_GROUP);
        update_option(self::HELD_OPTION, '1', true);
        Logger::log('core', sprintf('Held %s %s: this site is not the live store.', $hook, (string) wp_json_encode($args)));
    }

    /**
     * A parked action came due, or was released.
     *
     * @param mixed $hook
     * @param mixed $args
     * @param mixed $group
     */
    public static function runHeld($hook, $args, $group): void
    {
        $hook = (string) $hook;
        $args = is_array($args) ? $args : [];
        if ($hook === '') {
            return;
        }
        if (!self::isLive()) {
            self::park($hook, $args, (string) $group);
            return;
        }
        self::dispatch($hook, $args, (string) $group);
    }

    /**
     * Queue the original action to run now, replacing a pending copy of it. Handlers re-check the
     * subscription, order or quote before acting.
     *
     * @param array<string,mixed> $args
     */
    private static function dispatch(string $hook, array $args, string $group): void
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_unschedule_all_actions')) {
            return;
        }
        as_unschedule_all_actions($hook, $args, $group);
        as_schedule_single_action(time(), $hook, $args, $group);
    }

    /** Queue every held action to run now. Returns how many were queued in this batch. */
    public static function release(): int
    {
        if (!self::isLive() || !function_exists('as_get_scheduled_actions') || !function_exists('as_unschedule_action')) {
            return 0;
        }
        $actions = as_get_scheduled_actions([
            'hook' => self::HELD_HOOK,
            'group' => self::HELD_GROUP,
            'status' => 'pending',
            'per_page' => self::RELEASE_BATCH,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        $count = 0;
        foreach ((array) $actions as $action) {
            if (!is_object($action) || !method_exists($action, 'get_args')) {
                continue;
            }
            $held = (array) $action->get_args();
            as_unschedule_action(self::HELD_HOOK, $held, self::HELD_GROUP);
            self::dispatch((string) ($held['hook'] ?? ''), is_array($held['args'] ?? null) ? $held['args'] : [], (string) ($held['group'] ?? ''));
            $count++;
        }
        if (count((array) $actions) >= self::RELEASE_BATCH && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::RELEASE_HOOK, [], self::HELD_GROUP);
        } else {
            delete_option(self::HELD_OPTION);
        }
        if ($count > 0) {
            Logger::log('core', sprintf('Released %d held action(s): this site is the live store.', $count));
        }
        return $count;
    }

    /** Action Scheduler callback for a release that continues in the background. */
    public static function releaseBatch(): void
    {
        self::release();
    }

    /** The store became live without an admin confirming it here (constant, filter, restored record). */
    public static function maybeRelease(): void
    {
        if (!get_option(self::HELD_OPTION) || !self::isLive() || !function_exists('as_has_scheduled_action') || !function_exists('as_enqueue_async_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::RELEASE_HOOK, [], self::HELD_GROUP)) {
            as_enqueue_async_action(self::RELEASE_HOOK, [], self::HELD_GROUP);
        }
    }

    /* ----- Customer emails ----- */

    /**
     * On a copy, Stackless emails addressed to customers are not handed to wp_mail. Admin emails
     * still send. Callers see an unsent email (false), as when mail delivery fails.
     *
     * @param callable|string $callback
     * @param mixed           $email
     * @return callable|string
     */
    public static function filterMailCallback($callback, $email)
    {
        if (!$email instanceof \WC_Email || strpos(get_class($email), 'Stackless\\') !== 0 || !$email->is_customer_email() || self::isLive()) {
            return $callback;
        }
        $id = (string) $email->id;
        return static function () use ($id): bool {
            Logger::log('core', sprintf('Customer email "%s" was not sent: this site is not the live store.', $id));
            return false;
        };
    }

    /** For Stackless mail sent outside a WC_Email (back-in-stock alerts). */
    public static function allowsCustomerEmail(string $context): bool
    {
        if (self::isLive()) {
            return true;
        }
        Logger::log('core', sprintf('Customer email "%s" was not sent: this site is not the live store.', $context));
        return false;
    }

    /* ----- Admin ----- */

    public static function notice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        $done = isset($_GET[self::ACTION]) ? sanitize_key(wp_unslash($_GET[self::ACTION])) : '';
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        // phpcs:enable
        if (self::isLive()) {
            if ($done === 'approved') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('This site is now the live store. Automatic billing and customer emails resumed, and anything held while it was paused was queued to run now.', 'stackless-lite') . '</p></div>';
            }
            return;
        }
        $state = self::state();
        $dismissed = get_option(self::DISMISSED_OPTION) === self::key(self::currentUrl());
        if ($dismissed && strpos($page, 'stackless') !== 0) {
            return;
        }

        $live_url = self::liveUrl();
        $record = self::record();
        if ($state === self::STATE_FORCED_COPY) {
            $why = __('STACKLESS_LIVE_STORE is set to false in wp-config.php, so this site is treated as a copy of the live store.', 'stackless-lite');
        } elseif ($state === self::STATE_UNRECORDED) {
            /* translators: %s: environment type */
            $why = sprintf(__('No live store is recorded yet, and this site\'s environment type is "%s".', 'stackless-lite'), self::environment());
        } elseif ($state === self::STATE_ENVIRONMENT) {
            /* translators: 1: this site's environment type, 2: the live store's environment type */
            $why = sprintf(__('This site\'s environment type is "%1$s", but the live store was recorded as "%2$s".', 'stackless-lite'), self::environment(), (string) ($record['env'] ?? ''));
        } elseif ($state === self::STATE_MOVED) {
            /* translators: 1: this site's address, 2: the live store's address */
            $why = sprintf(__('This site\'s address (%1$s) does not match the live store (%2$s).', 'stackless-lite'), self::currentUrl(), $live_url !== '' ? $live_url : __('unknown', 'stackless-lite'));
        } else {
            $why = __('A filter on stackless_live_store marks this site as a copy.', 'stackless-lite');
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Stackless paused automatic billing on this site.', 'stackless-lite') . '</strong></p>';
        echo '<p>' . esc_html($why) . ' ' . esc_html__('Saved payment methods are not charged, payments are not retried, dunning does not run and customers are not emailed here. Held renewals and reminders run as soon as this site is confirmed as the live store.', 'stackless-lite') . '</p>';
        if ($state === self::STATE_FORCED_COPY) {
            echo '</div>';
            return;
        }
        echo '<p>' . esc_html__('If this is the live store, for example after moving it to a new domain, confirm it. Hosts can set STACKLESS_LIVE_STORE in wp-config.php instead.', 'stackless-lite') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><p>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION);
        echo '<button type="submit" name="do" value="approve" class="button button-primary" onclick="return confirm(\'' . esc_js(__('Customers with saved payment methods will be charged from this site. Confirm this is the live store?', 'stackless-lite')) . '\');">' . esc_html__('This is the live store', 'stackless-lite') . '</button> ';
        if (!$dismissed) {
            echo '<button type="submit" name="do" value="dismiss" class="button">' . esc_html__('Keep paused and hide this notice', 'stackless-lite') . '</button>';
        }
        echo '</p></form></div>';
    }

    public static function handleAdminAction(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do that.', 'stackless-lite'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $back = wp_get_referer() ?: admin_url();
        if ($do === 'approve') {
            if (defined(self::CONSTANT)) {
                wp_die(esc_html__('STACKLESS_LIVE_STORE in wp-config.php decides whether this site is the live store.', 'stackless-lite'), '', ['response' => 409]);
            }
            self::approve('admin', (int) get_current_user_id());
            self::release();
            wp_safe_redirect(add_query_arg(self::ACTION, 'approved', $back));
            exit;
        }
        if ($do === 'dismiss') {
            update_option(self::DISMISSED_OPTION, self::key(self::currentUrl()), false);
        }
        wp_safe_redirect($back);
        exit;
    }
}
