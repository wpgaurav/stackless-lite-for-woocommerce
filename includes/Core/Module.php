<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Base class for every module.
 *
 * A module lives in includes/Modules/{Dir}/Module.php as class Stackless\Modules\{Dir}\Module.
 * The registry discovers it automatically; nothing outside the module folder needs editing.
 */
abstract class Module
{
    /** Stable id, snake_case. Used for option keys: stackless_{id}_*. */
    abstract public function id(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Called once on plugins_loaded (priority 20) when the module is enabled and allowed. */
    abstract public function boot(): void;

    /** Admin grouping: subscriptions, payments, checkout, catalog, store, sales, currency, access, admin. */
    public function group(): string
    {
        return 'store';
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    /** Other module ids that must be enabled for this one to boot. */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * Plugins that already do this job. Map of plugin basename => human name.
     * When any is active, the module refuses to boot and the admin says why.
     */
    public function conflicts(): array
    {
        return [];
    }

    /**
     * WooCommerce settings-API field arrays (same shape as WC_Settings_Page::get_settings()).
     * Field ids must start with "stackless_{id}_". Rendered and saved by the Stackless admin page.
     */
    public function settingsFields(): array
    {
        return [];
    }

    /** Runs on plugin activation and on version change, even when disabled. Create tables here (dbDelta). */
    public function install(): void
    {
    }

    /** Read one of this module's options with a default. */
    protected function option(string $key, $default = null)
    {
        return get_option('stackless_' . $this->id() . '_' . $key, $default);
    }

    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        Logger::log($this->id(), $message, $level, $context);
    }
}
