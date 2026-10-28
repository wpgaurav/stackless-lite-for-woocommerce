<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

final class Installer
{
    /** Set to STACKLESS_VERSION only once every module has installed for that version. */
    public const VERSION_OPTION = 'stackless_db_version';
    /** Module id => plugin version its install() last completed for. */
    public const MODULES_OPTION = 'stackless_module_db_versions';
    /** Module id => ['title' => string, 'error' => string] for modules whose last install attempt failed. */
    public const FAILURES_OPTION = 'stackless_install_failures';
    /** Held while a request merges its results into the options above. Never read with get_option(). */
    public const LOCK_OPTION = 'stackless_install_lock';
    /** A lock older than this was left by a request that died and can be taken over. */
    private const LOCK_TTL = 30;

    /** @var int Seconds a request waits for another one to finish recording before it gives up. */
    private static $lockWait = 5;

    public static function activate(): void
    {
        // Modules install on the next load, when WooCommerce is available.
        delete_option(self::VERSION_OPTION);
        delete_option(self::MODULES_OPTION);
        LiveStore::activate();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            // Leave subscription schedules intact; only clear housekeeping.
            as_unschedule_all_actions('stackless_housekeeping');
        }
        flush_rewrite_rules(false);
    }

    /**
     * Runs install() for each module not yet installed for this version. Modules that
     * succeed are recorded and skipped next time; failures are retried on the next
     * request, and the global version only advances when none remain.
     *
     * Concurrent requests can upgrade at the same moment. Each one merges its own results into
     * what is stored, module by module, so a request that finishes last neither drops another's
     * results nor leaves the version current next to a failure that would then never be retried.
     */
    public static function maybeUpgrade(ModuleRegistry $modules): void
    {
        if (get_option(self::VERSION_OPTION) === STACKLESS_VERSION) {
            return;
        }
        $versions = get_option(self::MODULES_OPTION, []);
        $versions = is_array($versions) ? $versions : [];
        $installed = [];
        $failures = [];
        foreach ($modules->all() as $id => $module) {
            if (($versions[$id] ?? '') === STACKLESS_VERSION) {
                continue;
            }
            try {
                self::install($module);
                $installed[] = (string) $id;
            } catch (\Throwable $e) {
                $failures[$id] = ['title' => $module->title(), 'error' => $e->getMessage()];
                Logger::log($id, 'Install failed: ' . $e->getMessage(), 'error');
            }
        }
        if (!self::record(array_keys($modules->all()), $installed, $failures)) {
            // Nothing was recorded and the version stays behind, so the next request installs again.
            Logger::log('core', 'Module install results were not recorded: another request held the install lock.', 'warning');
            return;
        }
        if ($installed) {
            // Endpoints registered by modules need a fresh rewrite map.
            add_action('init', function () {
                flush_rewrite_rules(false);
            }, 99);
        }
    }

    /**
     * Merges one request's results into the stored state under a lock: re-reads what other
     * requests recorded, applies this request's modules on top (the latest attempt at a module
     * wins), and marks the version current only when every module is current with no failure.
     *
     * @param string[] $ids Every registered module id.
     * @param string[] $installed Modules this request installed.
     * @param array<string,array{title:string,error:string}> $failures Modules this request failed.
     * @return bool False when the lock could not be taken and nothing was written.
     */
    private static function record(array $ids, array $installed, array $failures): bool
    {
        if (!self::lock()) {
            return false;
        }
        try {
            $versions = self::stored(self::MODULES_OPTION);
            $stored = self::stored(self::FAILURES_OPTION);
            foreach ($installed as $id) {
                $versions[$id] = STACKLESS_VERSION;
                unset($stored[$id]);
            }
            foreach ($failures as $id => $failure) {
                unset($versions[$id]);
                $stored[$id] = $failure;
            }
            // A module removed since it failed has nothing left to retry.
            $stored = array_intersect_key($stored, array_flip($ids));
            $pending = array_filter($ids, static function ($id) use ($versions): bool {
                return ($versions[$id] ?? '') !== STACKLESS_VERSION;
            });
            $current = !$stored && !$pending;
            if (!$current) {
                // First, so the version never reads as current next to a failure, even if this request dies here.
                delete_option(self::VERSION_OPTION);
            }
            update_option(self::MODULES_OPTION, $versions, false);
            if ($stored) {
                update_option(self::FAILURES_OPTION, $stored, false);
                add_action('admin_notices', [self::class, 'failureNotice']);
            } else {
                delete_option(self::FAILURES_OPTION);
            }
            if ($current) {
                update_option(self::VERSION_OPTION, STACKLESS_VERSION, false);
            }
        } finally {
            self::unlock();
        }
        return true;
    }

    /** An option as stored now, not as this request cached it: another request may have written it since. */
    private static function stored(string $name): array
    {
        wp_cache_delete($name, 'options');
        $missing = wp_cache_get('notoptions', 'options');
        if (is_array($missing) && isset($missing[$name])) {
            unset($missing[$name]);
            wp_cache_set('notoptions', $missing, 'options');
        }
        $value = get_option($name, []);
        return is_array($value) ? $value : [];
    }

    /**
     * Cross-request mutex: an INSERT IGNORE on the options table's unique option_name, so exactly
     * one request wins. Waits up to $lockWait seconds; the holder only merges and writes options.
     */
    private static function lock(): bool
    {
        global $wpdb;
        $deadline = microtime(true) + self::$lockWait;
        while (true) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, (string) time()))) {
                return true;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION));
            // Abandoned by a request that died; take it over only if nobody else did first.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($since > 0 && $since < time() - self::LOCK_TTL && $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), self::LOCK_OPTION, (string) $since))) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(100000);
        }
    }

    private static function unlock(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK_OPTION]);
    }

    /**
     * Runs a module's install() and fails it when a table it passed to dbDelta() is missing
     * afterwards, with the database's reason for the CREATE TABLE that failed.
     */
    private static function install(Module $module): void
    {
        global $wpdb;
        $tables = [];
        // dbDelta() reports nothing on failure, so note every table it was asked to create.
        $record = static function ($queries) use (&$tables) {
            foreach (array_keys((array) $queries) as $table) {
                $tables[] = (string) $table;
            }
            return $queries;
        };
        // Every query resets wpdb's last_error, so read each one before the next query runs.
        $errors = [];
        $started = false;
        $watch = static function ($query) use (&$errors, &$started) {
            if ($started) {
                self::noteError($errors);
            }
            $started = true; // the error left before install() belongs to an earlier query
            return $query;
        };
        add_filter('dbdelta_create_queries', $record, PHP_INT_MAX);
        add_filter('query', $watch, PHP_INT_MAX);
        try {
            $module->install();
        } finally {
            remove_filter('dbdelta_create_queries', $record, PHP_INT_MAX);
            remove_filter('query', $watch, PHP_INT_MAX);
        }
        if (!$tables) {
            return;
        }
        $last = (string) $wpdb->last_error; // read before the checks below reset it
        if ($started) {
            self::noteError($errors);
        }
        $missing = array_values(array_filter(array_unique($tables), static function (string $table): bool {
            return !self::tableExists($table);
        }));
        if ($missing) {
            $reasons = [];
            foreach ($missing as $table) {
                $reason = $errors[strtolower($table)] ?? $last;
                if ($reason !== '') {
                    $reasons[$reason] = true;
                }
            }
            throw new \RuntimeException(sprintf('Table %s was not created.%s', implode(', ', $missing), $reasons ? ' Database error: ' . implode('; ', array_keys($reasons)) : ''));
        }
    }

    /** Keeps the database's error for the query that just ran when it was a CREATE TABLE, keyed by lowercased table. */
    private static function noteError(array &$errors): void
    {
        global $wpdb;
        $error = (string) $wpdb->last_error;
        if ($error !== '' && preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^\s`(]+)/i', (string) $wpdb->last_query, $match)) {
            $errors[strtolower($match[1])] = $error;
        }
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        // Case-insensitive: lower_case_table_names can lowercase what SHOW TABLES returns.
        return is_string($found) && strcasecmp($found, $table) === 0;
    }

    public static function failureNotice(): void
    {
        $failures = get_option(self::FAILURES_OPTION, []);
        if (!is_array($failures) || !$failures || !current_user_can('manage_woocommerce')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Stackless could not finish setting up these modules:', 'stackless-lite') . '</strong></p><ul>';
        foreach ($failures as $id => $failure) {
            $title = is_array($failure) && !empty($failure['title']) ? (string) $failure['title'] : (string) $id;
            $error = is_array($failure) && isset($failure['error']) ? (string) $failure['error'] : '';
            echo '<li><strong>' . esc_html($title) . '</strong>' . ($error !== '' ? ': ' . esc_html($error) : '') . '</li>';
        }
        echo '</ul><p>' . esc_html__('Setup will retry on the next page load. See WooCommerce → Status → Logs (stackless-*).', 'stackless-lite') . '</p></div>';
    }
}
