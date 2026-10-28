<?php

namespace Stackless\Lite;

use Stackless\Admin\Icons;
use Stackless\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * What Lite shows about Stackless: locked cards on the Dashboard, a "Pro" group in the Settings
 * sidebar with read-only details screens, and an Upgrade link in the header. Text and links only;
 * no switches, no settings, no trial, no tracking, no notices.
 */
final class Upgrade
{
    public const QUERY = 'pro';

    public static function boot(): void
    {
        add_action('stackless_admin_header_nav', [self::class, 'headerLink']);
        add_action('stackless_dashboard_after_groups', [self::class, 'dashboard']);
        add_action('stackless_settings_sidebar', [self::class, 'sidebar']);
        add_filter('stackless_settings_view', [self::class, 'view']);
        add_action('admin_enqueue_scripts', [self::class, 'assets'], 20);
    }

    /** Read-only details screen of a Pro module. */
    public static function url(string $slug): string
    {
        return add_query_arg(['page' => SettingsPage::SETTINGS, self::QUERY => $slug], admin_url('admin.php'));
    }

    private static function requested(): string
    {
        $slug = isset($_GET[self::QUERY]) ? sanitize_key(wp_unslash($_GET[self::QUERY])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return ProCatalog::get($slug) ? $slug : '';
    }

    public static function assets(string $hook): void
    {
        if (wp_style_is('stackless-admin', 'enqueued')) {
            wp_enqueue_style('stackless-lite', STACKLESS_PLUGIN_URL . 'assets/lite/lite.css', ['stackless-admin'], STACKLESS_VERSION);
        }
    }

    private static function upgradeLink(string $class, string $label = ''): string
    {
        return sprintf(
            '<a class="%s" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
            esc_attr($class),
            esc_url(ProCatalog::UPGRADE_URL),
            esc_html($label !== '' ? $label : __('Upgrade to Stackless', 'stackless-lite')),
            esc_html__('(opens in a new tab)', 'stackless-lite')
        );
    }

    private static function badge(): string
    {
        return '<span class="stackless-pro-badge">' . esc_html__('Pro', 'stackless-lite') . '</span>';
    }

    public static function headerLink(): void
    {
        printf(
            '<a href="%s" class="stackless-header__link stackless-header__link--upgrade" target="_blank" rel="noopener noreferrer">%s<span>%s</span><span class="screen-reader-text"> %s</span></a>',
            esc_url(ProCatalog::UPGRADE_URL),
            Icons::svg('sparkles', 18), // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
            esc_html__('Upgrade', 'stackless-lite'),
            esc_html__('(opens in a new tab)', 'stackless-lite')
        );
    }

    public static function dashboard(): void
    {
        $groups = ProCatalog::groups();
        echo '<section class="stackless-group stackless-group--pro" data-group="pro" aria-labelledby="stackless-pro-title">';
        echo '<div class="stackless-pro-intro">';
        echo '<div><h2 class="stackless-group__title" id="stackless-pro-title">' . Icons::svg('lock', 18) . esc_html__('In Stackless', 'stackless-lite') . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p>' . esc_html__('Stackless is the full plugin: everything in Lite plus subscriptions with automatic renewals, software licensing, multi-currency, invoices and more. It uses the same settings and data, so upgrading keeps everything you set up here.', 'stackless-lite') . '</p></div>';
        echo self::upgradeLink('button button-primary stackless-pro-intro__cta'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in upgradeLink().
        echo '</div><div class="stackless-grid">';
        foreach (ProCatalog::all() as $slug => $module) {
            $group = $groups[$module['group']] ?? '';
            printf(
                '<article class="stackless-card stackless-card--pro" data-module="%s" data-state="pro" data-search="%s">',
                esc_attr($slug),
                esc_attr(strtolower($module['title'] . ' ' . $module['summary'] . ' ' . $group . ' pro'))
            );
            echo '<div class="stackless-card__head">';
            echo '<span class="stackless-card__icon">' . Icons::svg($module['icon'], 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
            echo '<h3 class="stackless-card__title">' . esc_html($module['title']) . '</h3>';
            echo self::badge(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
            echo '</div>';
            echo '<p class="stackless-card__group">' . esc_html($group) . '</p>';
            echo '<p class="stackless-card__desc">' . esc_html($module['summary']) . '</p>';
            echo '<div class="stackless-card__foot">';
            /* translators: %s: module name */
            echo '<a class="stackless-card__docs" href="' . esc_url(self::url($slug)) . '">' . esc_html__('Details', 'stackless-lite') . '<span class="screen-reader-text"> ' . esc_html(sprintf(__('about %s', 'stackless-lite'), $module['title'])) . '</span></a>';
            echo self::upgradeLink('stackless-card__link', __('Upgrade', 'stackless-lite')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in upgradeLink().
            echo '</div></article>';
        }
        echo '</div></section>';
    }

    public static function sidebar(): void
    {
        $current = self::requested();
        echo '<p class="stackless-sidebar__group">' . esc_html__('Pro', 'stackless-lite') . '</p><ul class="stackless-sidebar__pro">';
        foreach (ProCatalog::all() as $slug => $module) {
            $active = $current === $slug;
            printf(
                '<li><a href="%s" class="stackless-sidebar__link%s"%s>%s<span>%s</span>%s</a></li>',
                esc_url(self::url($slug)),
                $active ? ' is-current' : '',
                $active ? ' aria-current="page"' : '',
                Icons::svg($module['icon'], 18), // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
                esc_html($module['title']),
                self::badge() // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
            );
        }
        echo '</ul>';
    }

    /**
     * @param mixed $view
     * @return mixed
     */
    public static function view($view)
    {
        return self::requested() !== '' ? [self::class, 'details'] : $view;
    }

    public static function details(): void
    {
        $slug = self::requested();
        $module = ProCatalog::get($slug);
        if (!$module) {
            return;
        }
        $groups = ProCatalog::groups();
        echo '<div class="stackless-panel stackless-pro-details">';
        echo '<div class="stackless-panel__head"><span class="stackless-card__icon">' . Icons::svg($module['icon'], 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
        echo '<div><h2>' . esc_html($module['title']) . ' ' . self::badge() . '</h2><p>' . esc_html($module['summary']) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
        echo '<p class="stackless-pro-details__group">' . esc_html($groups[$module['group']] ?? '') . '</p>';
        echo '<h3>' . esc_html__('What you get', 'stackless-lite') . '</h3><ul class="stackless-pro-details__features">';
        foreach ($module['features'] as $feature) {
            echo '<li>' . Icons::svg('check', 16) . '<span>' . esc_html($feature) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
        }
        echo '</ul>';
        echo '<p class="stackless-pro-details__note">' . esc_html__('This module is part of Stackless, the full plugin. Stackless includes every Lite module and keeps your Lite settings and data when you switch.', 'stackless-lite') . '</p>';
        echo '<div class="stackless-pro-details__actions">';
        echo self::upgradeLink('button button-primary'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in upgradeLink().
        printf(
            '<a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
            esc_url(ProCatalog::docsUrl($slug)),
            esc_html__('Read the docs', 'stackless-lite'),
            esc_html__('(opens in a new tab)', 'stackless-lite')
        );
        echo '</div></div>';
    }
}
