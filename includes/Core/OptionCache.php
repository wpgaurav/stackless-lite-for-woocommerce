<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Loads the options that enabled modules read on every request with one query.
 *
 * Modules read their settings with get_option() and a default in code. Without a persistent
 * object cache, every setting that was never saved costs a database query on every request,
 * because WordPress only remembers a missing option until the request ends. This keeps the
 * option names of the enabled modules in one small autoloaded index and hands them to
 * wp_prime_option_caches() before the modules boot: one query loads the saved values that are
 * not autoloaded and records every missing one, so the reads that follow cost nothing.
 *
 * Defaults are never written to the database. An unsaved setting keeps following the default
 * in code, including its translation, and a saved one is read exactly as before.
 */
final class OptionCache
{
    /** ['version' => string, 'modules' => string[], 'names' => string[]], autoloaded. */
    public const INDEX_OPTION = 'stackless_option_index';

    /** Options outside module settings that Stackless reads on every request. */
    private const CORE_OPTIONS = [LiveStore::HELD_OPTION];

    /**
     * Primes the options of the enabled modules. When the index was built for another plugin
     * version or another set of enabled modules, it is rebuilt once WordPress has loaded,
     * because settingsFields() translates its labels and must not run this early.
     */
    public static function prime(ModuleRegistry $modules): void
    {
        $enabled = $modules->enabledIds();
        $names = self::CORE_OPTIONS;
        $index = get_option(self::INDEX_OPTION);
        if (self::isCurrent($index, $enabled)) {
            $names = array_merge($names, $index['names']);
        } else {
            add_action('wp_loaded', static function () use ($modules): void {
                self::rebuild($modules);
            });
        }
        wp_prime_option_caches(array_values(array_unique($names)));
    }

    /** Stores the option names of the modules enabled now. */
    public static function rebuild(ModuleRegistry $modules): void
    {
        $enabled = $modules->enabledIds();
        $names = [];
        foreach ($enabled as $id) {
            $module = $modules->get($id);
            if (!$module) {
                continue;
            }
            try {
                $names = array_merge($names, $module->optionNames());
            } catch (\Throwable $e) {
                // Its options are read one by one, as they were before the index existed.
                Logger::log($id, 'Option names could not be listed: ' . $e->getMessage(), 'warning');
            }
        }
        $names = array_values(array_unique(array_filter(array_map('strval', $names), [self::class, 'isOptionName'])));
        update_option(self::INDEX_OPTION, [
            'version' => STACKLESS_VERSION,
            'modules' => self::sorted($enabled),
            'names' => $names,
        ], true);
    }

    /** Drops the index, so the next request lists the option names again. */
    public static function flush(): void
    {
        delete_option(self::INDEX_OPTION);
    }

    /**
     * A plain option name. Settings fields with array ids (stackless_x_y[USD]) save into one
     * option of another name, and get_option() never sees the field id.
     */
    public static function isOptionName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,191}$/', $name);
    }

    /**
     * @param mixed    $index
     * @param string[] $enabled
     * @phpstan-assert-if-true array{names: string[]} $index
     */
    private static function isCurrent($index, array $enabled): bool
    {
        return is_array($index)
            && ($index['version'] ?? '') === STACKLESS_VERSION
            && ($index['modules'] ?? null) === self::sorted($enabled)
            && isset($index['names'])
            && is_array($index['names']);
    }

    /**
     * @param string[] $ids
     * @return string[]
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids);
        return $ids;
    }
}
