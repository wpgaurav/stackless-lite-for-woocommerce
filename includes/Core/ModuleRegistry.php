<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

final class ModuleRegistry
{
    public const OPTION = 'stackless_enabled_modules';

    /**
     * Every module id an edition has shipped while the enabled list existed. A default-on module
     * that is not in it is new to this site (Stackless replacing Lite, or a new default module),
     * so it is switched on once instead of staying off because the list was saved without it.
     */
    public const KNOWN_OPTION = 'stackless_known_modules';

    /** @var array<string, Module> */
    private $modules = [];

    /** @var bool */
    private $adopted = false;

    /** @var array<string, string> module id => reason it did not boot */
    private $blocked = [];

    /** @var array<string, bool> */
    private $booted = [];

    public function discover(): void
    {
        foreach (glob(STACKLESS_PLUGIN_PATH . 'includes/Modules/*/Module.php') ?: [] as $file) {
            $dir = basename(dirname($file));
            $class = 'Stackless\\Modules\\' . $dir . '\\Module';
            if (!class_exists($class)) {
                continue;
            }
            $module = new $class();
            if ($module instanceof Module) {
                $this->modules[$module->id()] = $module;
            }
        }
        ksort($this->modules);
    }

    /** @return array<string, Module> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $id): ?Module
    {
        return $this->modules[$id] ?? null;
    }

    public function enabledIds(): array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)) {
            $stored = [];
            foreach ($this->modules as $id => $module) {
                if ($module->enabledByDefault()) {
                    $stored[] = $id;
                }
            }
        } elseif (!$this->adopted) {
            $this->adopted = true;
            $stored = $this->adoptNewDefaults($stored);
        }
        return array_values(array_intersect($stored, array_keys($this->modules)));
    }

    /**
     * Switch on default-on modules this site has never seen and record every module of this build
     * as known. Without a known list (saved before 1.7.1) a module counts as seen unless it is
     * default-on, missing from the list and has no stored data.
     *
     * @param array<int, mixed> $stored
     * @return array<int, string>
     */
    private function adoptNewDefaults(array $stored): array
    {
        $stored = array_values(array_filter(array_map('strval', $stored)));
        $known = get_option(self::KNOWN_OPTION, null);
        if (!is_array($known)) {
            $known = [];
            foreach ($this->modules as $id => $module) {
                if (!$module->enabledByDefault() || in_array($id, $stored, true) || $module->hasStoredData()) {
                    $known[] = $id;
                }
            }
        }
        $known = array_values(array_filter(array_map('strval', $known)));

        $adopt = [];
        foreach ($this->modules as $id => $module) {
            if ($module->enabledByDefault() && !in_array($id, $known, true) && !in_array($id, $stored, true)) {
                $adopt[] = $id;
            }
        }
        if ($adopt) {
            $stored = array_merge($stored, $adopt);
            update_option(self::OPTION, $stored);
        }
        $this->rememberKnown($known);
        return $stored;
    }

    /** @param array<int, string> $known */
    private function rememberKnown(array $known): void
    {
        $all = array_values(array_unique(array_merge($known, array_keys($this->modules))));
        $current = get_option(self::KNOWN_OPTION, null);
        if (!is_array($current) || array_diff($all, $current) || array_diff($current, $all)) {
            update_option(self::KNOWN_OPTION, $all);
        }
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->enabledIds(), true);
    }

    public function isActive(string $id): bool
    {
        return !empty($this->booted[$id]);
    }

    /**
     * Store the enabled list. Ids of modules this build does not ship (Stackless Lite running on
     * data saved by Stackless, or the other way round) are kept as stored, so switching editions
     * never forgets which modules were on.
     */
    public function setEnabled(array $ids): void
    {
        $known = array_keys($this->modules);
        $stored = get_option(self::OPTION, null);
        $foreign = is_array($stored) ? array_diff(array_filter(array_map('strval', $stored)), $known) : [];
        update_option(self::OPTION, array_values(array_unique(array_merge(array_intersect($ids, $known), $foreign))));
        $seen = get_option(self::KNOWN_OPTION, null);
        $this->rememberKnown(is_array($seen) ? array_values(array_filter(array_map('strval', $seen))) : []);
    }

    /**
     * Switch one module on or off against the freshly stored list, so two toggles sent at
     * the same time each change only their own module instead of overwriting each other.
     *
     * @return string[] enabled module ids after the change
     */
    public function setModuleEnabled(string $id, bool $on): array
    {
        // Another request may have saved since this one loaded its options: drop the
        // request's cached copy and read the current value right before writing.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete(self::OPTION, 'options');
        $stored = get_option(self::OPTION, null);
        $ids = is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : $this->enabledIds();
        $ids = $on ? array_values(array_unique(array_merge($ids, [$id]))) : array_values(array_diff($ids, [$id]));
        $this->setEnabled($ids);
        return $this->enabledIds();
    }

    public function blockedReason(string $id): ?string
    {
        return $this->blocked[$id] ?? null;
    }

    public function bootEnabled(): void
    {
        $enabled = $this->enabledIds();
        foreach ($enabled as $id) {
            $this->bootOne($id, $enabled, []);
        }
    }

    private function bootOne(string $id, array $enabled, array $stack): bool
    {
        if (isset($this->booted[$id])) {
            return $this->booted[$id];
        }
        $module = $this->modules[$id] ?? null;
        if (!$module || in_array($id, $stack, true)) {
            return false;
        }

        $conflict = ConflictGuard::firstActive($module->conflicts());
        if ($conflict !== null) {
            /* translators: %s: plugin name */
            $this->blocked[$id] = sprintf(__('Paused because %s is active and does the same job.', 'stackless-lite'), $conflict);
            return $this->booted[$id] = false;
        }

        foreach ($module->dependencies() as $dep) {
            if (!in_array($dep, $enabled, true) || !$this->bootOne($dep, $enabled, array_merge($stack, [$id]))) {
                $title = isset($this->modules[$dep]) ? $this->modules[$dep]->title() : $dep;
                /* translators: %s: module title */
                $this->blocked[$id] = sprintf(__('Needs the %s module enabled.', 'stackless-lite'), $title);
                return $this->booted[$id] = false;
            }
        }

        try {
            $module->boot();
        } catch (\Throwable $e) {
            Logger::log($id, 'Boot failed: ' . $e->getMessage(), 'error');
            $this->blocked[$id] = __('Failed to start. See WooCommerce → Status → Logs (stackless-*).', 'stackless-lite');
            return $this->booted[$id] = false;
        }
        return $this->booted[$id] = true;
    }
}
