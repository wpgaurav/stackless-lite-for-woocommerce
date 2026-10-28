<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Keyless updates for Stackless Lite from gauravtiwari.org (Lite builds only).
 *
 * Reads one public JSON document about the latest release and feeds it to WordPress's own update
 * screens. No license key, no site data beyond what wp_remote_get() sends, no tracking. The update
 * package is the stable /download address, which redirects to a fresh signed file, so a cached
 * update never points at an expired link. The file is checked against the published SHA-256.
 */
final class LiteUpdater
{
    public const BASENAME = 'stackless-lite/stackless-lite.php';
    public const SLUG = 'stackless-lite';
    public const REMOTE_SLUG = 'stackless-lite-for-woocommerce';
    public const API = 'https://gauravtiwari.org/wp-json/gt-plugin-updates/v1/plugins/stackless-lite-for-woocommerce';
    public const DOWNLOAD = self::API . '/download';
    public const HOMEPAGE = 'https://gauravtiwari.org/product/stackless-lite-for-woocommerce/';
    public const CACHE = 'stackless_lite_update';
    public const TTL = 43200;
    public const FAILURE_TTL = 1800;

    public static function boot(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'inject']);
        add_filter('plugins_api', [self::class, 'info'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'flush'], 10, 0);
    }

    /**
     * Validate the release document. Returns the fields WordPress needs, or null when the document
     * is not a release of this plugin.
     *
     * @param mixed $body decoded JSON array or a JSON string
     * @return array{version:string,name:string,url:string,sha256:string,requires:string,requires_php:string,tested:string,changelog:string}|null
     */
    public static function parse($body): ?array
    {
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        if (!is_array($body) || (int) ($body['schema'] ?? 0) !== 1) {
            return null;
        }
        if (isset($body['plugin']) && $body['plugin'] !== self::REMOTE_SLUG) {
            return null;
        }
        $version = is_scalar($body['version'] ?? null) ? trim((string) $body['version']) : '';
        if (!preg_match('/^\d+(\.\d+){1,3}(-[0-9A-Za-z.]+)?$/', $version)) {
            return null;
        }
        $text = static function (string $key, int $max = 200) use ($body): string {
            return is_scalar($body[$key] ?? null) ? substr(trim((string) $body[$key]), 0, $max) : '';
        };
        $url = $text('url', 500);
        $sha = strtolower($text('sha256', 64));
        return [
            'version' => $version,
            'name' => $text('name') !== '' ? $text('name') : 'Stackless Lite for WooCommerce',
            'url' => preg_match('#^https://#', $url) ? $url : self::HOMEPAGE,
            'sha256' => preg_match('/^[a-f0-9]{64}$/', $sha) ? $sha : '',
            'requires' => self::versionField($text('requires', 20)),
            'requires_php' => self::versionField($text('requires_php', 20)),
            'tested' => self::versionField($text('tested', 20)),
            'changelog' => is_string($body['changelog'] ?? null) ? $body['changelog'] : '',
        ];
    }

    private static function versionField(string $value): string
    {
        return preg_match('/^\d+(\.\d+){0,3}$/', $value) ? $value : '';
    }

    /** Whether the release is newer than the installed version. */
    public static function isNewer(string $installed, array $release): bool
    {
        return isset($release['version']) && version_compare((string) $release['version'], $installed, '>');
    }

    /** The release, from cache (12 hours, 30 minutes after a failure) or the store. */
    public static function release(bool $fresh = false): ?array
    {
        $cached = $fresh ? false : get_site_transient(self::CACHE);
        if (is_array($cached)) {
            return isset($cached['version']) ? $cached : null;
        }
        $response = wp_remote_get(self::API, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        $release = null;
        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $release = self::parse((string) wp_remote_retrieve_body($response));
        }
        // A 404 (no release yet), an error or a bad document are cached briefly as "nothing".
        set_site_transient(self::CACHE, $release ?: ['none' => time()], $release ? self::TTL : self::FAILURE_TTL);
        return $release;
    }

    public static function flush(): void
    {
        delete_site_transient(self::CACHE);
    }

    /** The update entry WordPress keeps in the update_plugins transient. */
    public static function item(array $release): object
    {
        return (object) [
            'id' => self::HOMEPAGE,
            'slug' => self::SLUG,
            'plugin' => self::BASENAME,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => self::DOWNLOAD,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'icons' => [],
            'banners' => [],
        ];
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public static function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        // "Check again" on Dashboard → Updates asks the store now.
        $fresh = isset($_GET['force-check']) && current_user_can('update_plugins'); // phpcs:ignore WordPress.Security.NonceVerification
        $release = self::release($fresh);
        if (!$release) {
            return $transient;
        }
        $item = self::item($release);
        if (self::isNewer(STACKLESS_LITE_VERSION, $release)) {
            $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
            $transient->response[self::BASENAME] = $item;
            if (isset($transient->no_update) && is_array($transient->no_update)) {
                unset($transient->no_update[self::BASENAME]);
            }
        } else {
            $transient->no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
            $transient->no_update[self::BASENAME] = $item;
            if (isset($transient->response) && is_array($transient->response)) {
                unset($transient->response[self::BASENAME]);
            }
        }
        return $transient;
    }

    /**
     * "View details" in the plugin list and the update screen.
     *
     * @param mixed $result
     * @param mixed $action
     * @param mixed $args
     * @return mixed
     */
    public static function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }
        $release = self::release();
        if (!$release) {
            return $result;
        }
        return self::details($release);
    }

    public static function details(array $release): object
    {
        return (object) [
            'name' => $release['name'],
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://gauravtiwari.org">Gaurav Tiwari</a>',
            'homepage' => $release['url'],
            'download_link' => self::DOWNLOAD,
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'tested' => $release['tested'],
            'sections' => [
                'changelog' => $release['changelog'] !== '' ? $release['changelog'] : '<p>' . esc_html__('See the release notes on gauravtiwari.org.', 'stackless-lite') . '</p>',
            ],
        ];
    }

    /**
     * Download the Lite package and check it against the published SHA-256 before WordPress
     * unpacks it. Other packages are left to WordPress.
     *
     * @param mixed $reply
     * @param mixed $package
     * @return mixed
     */
    public static function download($reply, $package)
    {
        if ($reply !== false || $package !== self::DOWNLOAD) {
            return $reply;
        }
        $release = self::release(true);
        if (!$release || $release['sha256'] === '') {
            return $reply;
        }
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!self::matches((string) $file, $release['sha256'])) {
            wp_delete_file((string) $file);
            return new \WP_Error('stackless_lite_checksum', __('The downloaded Stackless Lite package does not match its published checksum, so it was not installed. Try again in a few minutes.', 'stackless-lite'));
        }
        return $file;
    }

    public static function matches(string $file, string $sha256): bool
    {
        $actual = is_readable($file) ? hash_file('sha256', $file) : false;
        return is_string($actual) && hash_equals($sha256, $actual);
    }
}
