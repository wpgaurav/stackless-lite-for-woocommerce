<?php

namespace Stackless\Admin;

defined('ABSPATH') || exit;

/**
 * Notch Toast confirmations on Stackless's own admin screens (assets/js/toast.js, built by
 * tools/build/toast.mjs on WordPress's React). Short success messages print inline with a
 * data-stackless-toast attribute; the script turns them into a toast under the admin bar and
 * removes the inline copy. Errors, warnings and long messages stay inline, and pages without
 * JavaScript keep the inline notice. Storefront pages never load React.
 *
 * Scripts call window.StacklessToast.success('…') (also error, warning, info, loading, dismiss).
 */
final class Toast
{
    public const HANDLE = 'stackless-toast';

    /** Characters that fit a toast's single line on a laptop screen. */
    public const LENGTH = 60;

    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    /** On every screen under the Stackless menu: the dashboard, settings and module screens. */
    public static function enqueue(): void
    {
        if (!function_exists('get_admin_page_parent') || get_admin_page_parent() !== SettingsPage::PARENT) {
            return;
        }
        wp_enqueue_style(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/css/toast.css', [], STACKLESS_VERSION);
        wp_enqueue_script(self::HANDLE, STACKLESS_PLUGIN_URL . 'assets/js/toast.js', ['react', 'react-dom', 'react-jsx-runtime'], STACKLESS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    }

    /**
     * A WordPress admin notice that becomes a toast when it is a short success message.
     *
     * @param string $type success, error, warning or info.
     * @param string $class Extra classes for the inline notice.
     */
    public static function notice(string $message, string $type = 'success', string $class = 'is-dismissible'): string
    {
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        $toast = $type === 'success' && self::fits($message);
        return sprintf(
            '<div class="notice notice-%1$s%2$s"%3$s><p>%4$s</p></div>',
            esc_attr($type),
            $class !== '' ? ' ' . esc_attr($class) : '',
            $toast ? ' data-stackless-toast="' . esc_attr($message) . '" data-stackless-toast-type="success"' : '',
            esc_html($message)
        );
    }

    public static function fits(string $message): bool
    {
        return (function_exists('mb_strlen') ? mb_strlen($message) : strlen($message)) <= self::LENGTH;
    }
}
