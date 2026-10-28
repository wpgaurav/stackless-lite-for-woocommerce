<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

final class Plugin
{
    /** @var Plugin|null */
    private static $instance;

    /** @var ModuleRegistry */
    private $modules;

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->modules = new ModuleRegistry();
    }

    public function modules(): ModuleRegistry
    {
        return $this->modules;
    }

    public function boot(): void
    {
        load_plugin_textdomain('stackless-lite', false, dirname(plugin_basename(STACKLESS_PLUGIN_FILE)) . '/languages');
        Assets::register();
        OrderQuery::boot();
        LiveStore::boot();
        $this->modules->discover();
        Installer::maybeUpgrade($this->modules);
        $this->modules->bootEnabled();

        if (is_admin()) {
            (new \Stackless\Admin\SettingsPage($this->modules))->register();
        }

        do_action('stackless_loaded', $this);
    }
}
