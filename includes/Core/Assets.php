<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Plugin-wide asset delivery.
 *
 * - Serves assets/foo.min.js / foo.min.css in place of foo.js / foo.css whenever the minified
 *   sibling exists (scripts/minify.sh builds them) and SCRIPT_DEBUG is off. Modules keep
 *   registering the readable file; the swap happens at print time.
 * - Defers Stackless front-end scripts. WordPress (6.3+) checks every script's dependents
 *   and falls back to blocking when deferring would break the order, so this is safe to
 *   apply broadly. Cart/Checkout block integrations and payment gateway scripts are left
 *   alone because they must register before the blocks render.
 */
final class Assets
{
    /** Dependencies that mark a script as a Cart/Checkout blocks integration. */
    private const BLOCKS_DEPS = ['wc-blocks-checkout', 'wc-blocks-registry', 'wc-settings', 'wp-plugins', 'wc-blocks-data-store', 'wc-price-format', 'wp-blocks'];

    /** @var string */
    private static $base = '';

    public static function register(): void
    {
        self::$base = STACKLESS_PLUGIN_URL . 'assets/';
        add_filter('script_loader_src', [self::class, 'filterSrc'], 10, 1);
        add_filter('style_loader_src', [self::class, 'filterSrc'], 10, 1);

        if (!is_admin()) {
            add_action('wp_print_scripts', [self::class, 'deferScripts'], 1);
            add_action('wp_print_footer_scripts', [self::class, 'deferScripts'], 1);
        }
    }

    /** URL of a Stackless asset (path relative to assets/), minified when available. */
    public static function url(string $relative): string
    {
        $url = STACKLESS_PLUGIN_URL . 'assets/' . ltrim($relative, '/');
        return self::minifiedUrl($url);
    }

    /**
     * @param mixed $src
     * @return mixed
     */
    public static function filterSrc($src)
    {
        if (!is_string($src) || self::$base === '' || strpos($src, self::$base) !== 0) {
            return $src;
        }
        return self::minifiedUrl($src);
    }

    /** Swap foo.js → foo.min.js (and .css) when the minified file exists on disk. */
    public static function minifiedUrl(string $url): string
    {
        if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) {
            return $url;
        }
        $assets = STACKLESS_PLUGIN_URL . 'assets/';
        if (strpos($url, $assets) !== 0) {
            return $url;
        }
        $parts = explode('?', $url, 2);
        $relative = substr($parts[0], strlen($assets));
        $min = self::minifiedPath($relative);
        if ($min === null || !is_readable(STACKLESS_PLUGIN_PATH . 'assets/' . $min)) {
            return $url;
        }
        return $assets . $min . (isset($parts[1]) ? '?' . $parts[1] : '');
    }

    /** "dir/foo.js" → "dir/foo.min.js"; null for non js/css or already-minified files. */
    public static function minifiedPath(string $relative): ?string
    {
        if (strpos($relative, '..') !== false || !preg_match('/^(.+)\.(js|css)$/', $relative, $m)) {
            return null;
        }
        if (substr($m[1], -4) === '.min') {
            return null;
        }
        return $m[1] . '.min.' . $m[2];
    }

    /** Mark eligible Stackless front-end scripts as deferred before WordPress prints them. */
    public static function deferScripts(): void
    {
        $scripts = wp_scripts();
        foreach ($scripts->registered as $handle => $script) {
            // Only this plugin's own files: a theme or add-on may also use "stackless-" handles.
            if (!is_string($handle) || !is_string($script->src) || strpos($script->src, self::$base) !== 0
                || !self::shouldDefer($handle, (array) $script->deps, (string) $scripts->get_data($handle, 'strategy'))) {
                continue;
            }
            /**
             * Filters whether a Stackless front-end script is deferred.
             *
             * @param bool   $defer
             * @param string $handle
             */
            if (apply_filters('stackless_defer_script', true, $handle)) {
                $scripts->add_data($handle, 'strategy', 'defer');
            }
        }
    }

    /**
     * Pure eligibility rule for deferring a script.
     *
     * @param string[] $deps
     */
    public static function shouldDefer(string $handle, array $deps, string $strategy = ''): bool
    {
        if (strpos($handle, 'stackless-') !== 0 || $strategy !== '') {
            return false;
        }
        // Payment scripts mount fields and wallets on load; keep their timing exact.
        foreach (['gateway', 'stripe', 'paypal', 'polar', '-blocks', '-editor', '-admin'] as $needle) {
            if (strpos($handle, $needle) !== false) {
                return false;
            }
        }
        foreach ($deps as $dep) {
            if (in_array($dep, self::BLOCKS_DEPS, true) || strpos((string) $dep, 'wc-blocks') === 0) {
                return false;
            }
        }
        return true;
    }
}
