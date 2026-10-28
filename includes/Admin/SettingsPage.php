<?php

namespace Stackless\Admin;

use Stackless\Core\Module;
use Stackless\Core\ModuleRegistry;

defined('ABSPATH') || exit;

/**
 * Top-level "Stackless" admin menu.
 *
 *  Stackless → Dashboard   module cards with instant on/off switches, search and filters
 *            → (module screens: Subscriptions, Invoices, Licenses… register under PARENT)
 *            → Settings    grouped sidebar of enabled modules, WooCommerce-native fields
 *            → License     plugin license + updates
 */
final class SettingsPage
{
    /** Parent slug for every Stackless admin screen. Modules: add_submenu_page(SettingsPage::PARENT, …). */
    public const PARENT = 'stackless';
    public const SLUG = self::PARENT;
    public const SETTINGS = 'stackless-settings';
    public const LICENSE = 'stackless-license';
    public const CAP = 'manage_woocommerce';
    private const LICENSE_MODULE = 'plugin_license';

    /** @var ModuleRegistry */
    private $modules;

    /** @var string[] */
    private $hooks = [];

    public function __construct(ModuleRegistry $modules)
    {
        $this->modules = $modules;
    }

    /** Dashboard URL, or a module's settings URL when $module is given. */
    public static function url(string $module = '', array $args = []): string
    {
        if ($module === '') {
            $base = ['page' => self::PARENT];
        } elseif ($module === self::LICENSE_MODULE) {
            $base = ['page' => self::LICENSE];
        } else {
            $base = ['page' => self::SETTINGS, 'module' => $module];
        }
        return add_query_arg(array_merge($base, $args), admin_url('admin.php'));
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menuTop'], 9);
        add_action('admin_menu', [$this, 'menuBottom'], 99);
        add_action('admin_init', [$this, 'legacyRedirect'], 1);
        add_action('admin_init', [$this, 'save']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_stackless_toggle_module', [$this, 'ajaxToggle']);
        add_filter('woocommerce_screen_ids', [$this, 'screenIds']);
        add_filter('plugin_action_links_' . plugin_basename(STACKLESS_PLUGIN_FILE), function ($links) {
            array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Modules', 'stackless-lite') . '</a>');
            return $links;
        });
        add_filter('plugin_row_meta', function ($meta, $file) {
            if ($file === plugin_basename(STACKLESS_PLUGIN_FILE)) {
                $meta[] = Documentation::link('dashboard');
            }
            return $meta;
        }, 10, 2);
    }

    public function menuTop(): void
    {
        $this->hooks[] = (string) add_menu_page(
            __('Stackless', 'stackless-lite'),
            __('Stackless', 'stackless-lite'),
            self::CAP,
            self::PARENT,
            [$this, 'renderDashboard'],
            Icons::menuDataUri(),
            55.7
        );
        $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless modules', 'stackless-lite'), __('Dashboard', 'stackless-lite'), self::CAP, self::PARENT, [$this, 'renderDashboard']);
    }

    public function menuBottom(): void
    {
        $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless settings', 'stackless-lite'), __('Settings', 'stackless-lite'), self::CAP, self::SETTINGS, [$this, 'renderSettings']);
        if ($this->modules->get(self::LICENSE_MODULE)) {
            $this->hooks[] = (string) add_submenu_page(self::PARENT, __('Stackless license', 'stackless-lite'), __('License', 'stackless-lite'), self::CAP, self::LICENSE, [$this, 'renderLicense']);
        }
    }

    /** WooCommerce loads its admin styles/scripts (select2, tooltips, color picker) on these screens. */
    public function screenIds(array $ids): array
    {
        global $submenu;
        $hooks = array_filter($this->hooks);
        // Module screens registered under PARENT get WooCommerce admin assets too.
        foreach ((array) ($submenu[self::PARENT] ?? []) as $item) {
            if (!empty($item[2])) {
                $hooks[] = get_plugin_page_hookname($item[2], self::PARENT);
            }
        }
        return array_values(array_unique(array_merge($ids, $hooks)));
    }

    /** Old WooCommerce → Stackless URLs (?page=stackless&tab=id) keep working. */
    public function legacyRedirect(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (!isset($_GET['page'], $_GET['tab']) || $_GET['page'] !== self::PARENT || !current_user_can(self::CAP)) {
            return;
        }
        $tab = sanitize_key(wp_unslash($_GET['tab']));
        $extra = array_diff_key(wp_unslash($_GET), ['page' => 1, 'tab' => 1]);
        // phpcs:enable
        wp_safe_redirect(self::url($tab === 'modules' ? '' : $tab, array_map('sanitize_text_field', array_filter($extra, 'is_scalar'))));
        exit;
    }

    public function assets(string $hook): void
    {
        if (!in_array($hook, $this->hooks, true)) {
            return;
        }
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('stackless-admin', STACKLESS_PLUGIN_URL . 'assets/css/admin.css', [], STACKLESS_VERSION);
        wp_enqueue_script('stackless-admin', STACKLESS_PLUGIN_URL . 'assets/js/admin.js', [], STACKLESS_VERSION, true);
        wp_localize_script('stackless-admin', 'stacklessAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stackless_toggle_module'),
            'i18n' => [
                'on' => __('On', 'stackless-lite'),
                'off' => __('Off', 'stackless-lite'),
                'failed' => __('Could not save. Reload and try again.', 'stackless-lite'),
                /* translators: 1: enabled count, 2: total */
                'count' => __('%1$d of %2$d on', 'stackless-lite'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */

    public function ajaxToggle(): void
    {
        check_ajax_referer('stackless_toggle_module', 'nonce');
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => __('You are not allowed to change modules.', 'stackless-lite')], 403);
        }
        $id = isset($_POST['module']) ? sanitize_key(wp_unslash($_POST['module'])) : '';
        $module = $this->modules->get($id);
        if (!$module) {
            wp_send_json_error(['message' => __('Unknown module.', 'stackless-lite')], 400);
        }
        $on = !empty($_POST['enabled']) && $_POST['enabled'] !== 'false';
        // Change only this module's entry, re-read right before the write (concurrent toggles).
        $ids = $this->modules->setModuleEnabled($id, $on);
        update_option('stackless_flush_rewrite', 1);
        // Run install() for modules switched on for the first time (tables, schedules).
        if ($on) {
            try {
                $module->install();
            } catch (\Throwable $e) {
                \Stackless\Core\Logger::log($id, 'Install failed: ' . $e->getMessage(), 'error');
            }
        }
        wp_send_json_success([
            'enabled' => $on,
            'settingsUrl' => ($on && $module->settingsFields()) ? self::url($id) : '',
            'count' => count($ids),
            'total' => count($this->modules->all()),
        ]);
    }

    public function save(): void
    {
        if (empty($_POST['stackless_settings_nonce']) || !current_user_can(self::CAP)) {
            return;
        }
        check_admin_referer('stackless_settings', 'stackless_settings_nonce');
        $id = isset($_POST['stackless_module']) ? sanitize_key(wp_unslash($_POST['stackless_module'])) : '';
        $module = $this->modules->get($id);
        if (!$module || !$module->settingsFields()) {
            return;
        }
        \WC_Admin_Settings::save_fields($module->settingsFields());
        do_action('stackless_settings_saved_' . $module->id());
        wp_safe_redirect(self::url($id, ['updated' => '1']));
        exit;
    }

    /* ------------------------------------------------------------------ */

    private function groups(): array
    {
        return [
            'subscriptions' => __('Subscriptions', 'stackless-lite'),
            'payments' => __('Payments', 'stackless-lite'),
            'checkout' => __('Checkout', 'stackless-lite'),
            'sales' => __('Sales & invoices', 'stackless-lite'),
            'currency' => __('Currency', 'stackless-lite'),
            'access' => __('Access & licensing', 'stackless-lite'),
            'catalog' => __('Catalog', 'stackless-lite'),
            'store' => __('Store tools', 'stackless-lite'),
            'admin' => __('Admin', 'stackless-lite'),
        ];
    }

    /** @return array<string, Module[]> group => modules, in group order */
    private function byGroup(?callable $filter = null): array
    {
        $out = array_fill_keys(array_keys($this->groups()), []);
        foreach ($this->modules->all() as $id => $module) {
            if ($filter && !$filter($module)) {
                continue;
            }
            $out[$module->group()][$id] = $module;
        }
        foreach ($out as $group => $mods) {
            uasort($mods, function (Module $a, Module $b) {
                return strcasecmp($a->title(), $b->title());
            });
            $out[$group] = $mods;
        }
        return array_filter($out);
    }

    private function header(string $title, string $subtitle = ''): void
    {
        echo '<div class="stackless-header">';
        echo '<span class="stackless-header__logo">' . Icons::svg('stackless', 26) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG
        echo '<div><h1 class="stackless-header__title">' . esc_html($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="stackless-header__sub">' . esc_html($subtitle) . '</p>';
        }
        echo '</div>';
        echo '<nav class="stackless-header__nav">';
        $current = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $links = [
            self::PARENT => ['layout-dashboard', __('Dashboard', 'stackless-lite'), self::url()],
            self::SETTINGS => ['settings', __('Settings', 'stackless-lite'), admin_url('admin.php?page=' . self::SETTINGS)],
        ];
        if ($this->modules->get(self::LICENSE_MODULE)) {
            $links[self::LICENSE] = ['license', __('License', 'stackless-lite'), admin_url('admin.php?page=' . self::LICENSE)];
        }
        foreach ($links as $page => $link) {
            printf(
                '<a href="%s" class="stackless-header__link%s"%s>%s<span>%s</span></a>',
                esc_url($link[2]),
                $current === $page ? ' is-current' : '',
                $current === $page ? ' aria-current="page"' : '',
                Icons::svg($link[0], 18), // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html($link[1])
            );
        }
        /** Extra header links after Dashboard/Settings/License (echo `.stackless-header__link` anchors). */
        do_action('stackless_admin_header_nav', $current);
        $docsKey = $current === self::LICENSE ? 'plugin_license' : ($current === self::PARENT ? 'dashboard' : '');
        printf(
            '<a href="%s" class="stackless-header__link" target="_blank" rel="noopener noreferrer">%s<span>%s</span><span class="screen-reader-text"> %s</span></a>',
            esc_url(Documentation::url($docsKey)),
            Icons::svg('book', 18), // phpcs:ignore WordPress.Security.EscapeOutput
            esc_html__('Docs', 'stackless-lite'),
            esc_html__('(opens in a new tab)', 'stackless-lite')
        );
        echo '</nav></div>';
    }

    private function notices(): void
    {
        if (!empty($_GET['updated'])) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success is-dismissible stackless-notice"><p>' . esc_html__('Settings saved.', 'stackless-lite') . '</p></div>';
        }
    }

    private function maybeFlush(): void
    {
        if (get_option('stackless_flush_rewrite')) {
            delete_option('stackless_flush_rewrite');
            flush_rewrite_rules(false);
        }
    }

    /* ---------------------------- Dashboard --------------------------- */

    public function renderDashboard(): void
    {
        $this->maybeFlush();
        $all = $this->modules->all();
        $enabled = count($this->modules->enabledIds());
        echo '<div class="wrap stackless-admin stackless-dashboard">';
        $this->header(__('Stackless', 'stackless-lite'), __('You don\'t need any other add-ons for WooCommerce. Switch on only what you use; switched-off modules load no code.', 'stackless-lite'));
        echo '<hr class="wp-header-end">';
        $this->notices();

        echo '<div class="stackless-toolbar">';
        echo '<label class="stackless-search">' . Icons::svg('search', 18) . '<span class="screen-reader-text">' . esc_html__('Search modules', 'stackless-lite') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<input type="search" id="stackless-module-search" placeholder="' . esc_attr__('Search modules', 'stackless-lite') . '"></label>';
        echo '<div class="stackless-filters" role="group" aria-label="' . esc_attr__('Filter modules', 'stackless-lite') . '">';
        foreach (['all' => __('All', 'stackless-lite'), 'on' => __('On', 'stackless-lite'), 'off' => __('Off', 'stackless-lite')] as $key => $label) {
            printf('<button type="button" class="stackless-filter%s" data-filter="%s" aria-pressed="%s">%s</button>', $key === 'all' ? ' is-active' : '', esc_attr($key), $key === 'all' ? 'true' : 'false', esc_html($label));
        }
        echo '</div>';
        /* translators: 1: enabled count, 2: total */
        echo '<span class="stackless-count" id="stackless-module-count">' . esc_html(sprintf(__('%1$d of %2$d on', 'stackless-lite'), $enabled, count($all))) . '</span>';
        echo '</div>';

        foreach ($this->byGroup() as $group => $mods) {
            $label = $this->groups()[$group] ?? ucfirst($group);
            echo '<section class="stackless-group" data-group="' . esc_attr($group) . '">';
            echo '<h2 class="stackless-group__title">' . Icons::svg(Icons::GROUPS[$group] ?? 'sparkles', 18) . esc_html($label) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<div class="stackless-grid">';
            foreach ($mods as $id => $module) {
                $this->card($id, $module);
            }
            echo '</div></section>';
        }
        /** Extra dashboard sections after the module groups (cards inside `.stackless-group` take part in search). */
        do_action('stackless_dashboard_after_groups');
        echo '<p class="stackless-empty" hidden>' . esc_html__('No modules match your search.', 'stackless-lite') . '</p>';
        echo '</div>';
    }

    private function card(string $id, Module $module): void
    {
        $on = $this->modules->isEnabled($id);
        $blocked = $on ? $this->modules->blockedReason($id) : null;
        $settings = $on && $module->settingsFields() ? self::url($id) : '';
        $switchId = 'stackless-switch-' . $id;
        printf(
            '<article class="stackless-card%s" data-module="%s" data-state="%s" data-search="%s">',
            $on ? ' is-on' : '',
            esc_attr($id),
            $on ? 'on' : 'off',
            esc_attr(strtolower($module->title() . ' ' . $module->description() . ' ' . $module->group()))
        );
        echo '<div class="stackless-card__head">';
        echo '<span class="stackless-card__icon">' . Icons::svg(Icons::forModule($id, $module->group()), 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<h3 class="stackless-card__title"><label for="' . esc_attr($switchId) . '">' . esc_html($module->title()) . '</label></h3>';
        printf(
            '<button type="button" role="switch" id="%s" class="stackless-switch" aria-checked="%s" data-module="%s"><span class="stackless-switch__track" aria-hidden="true"><span class="stackless-switch__thumb"></span></span><span class="screen-reader-text">%s</span></button>',
            esc_attr($switchId),
            $on ? 'true' : 'false',
            esc_attr($id),
            /* translators: %s: module name */
            esc_html(sprintf(__('Enable %s', 'stackless-lite'), $module->title()))
        );
        echo '</div>';
        echo '<p class="stackless-card__desc">' . esc_html($module->description()) . '</p>';
        echo '<div class="stackless-card__foot">';
        if ($blocked) {
            echo '<span class="stackless-card__blocked">' . Icons::svg('info-circle', 16) . esc_html($blocked) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo Documentation::link($id, __('Docs', 'stackless-lite'), 'stackless-card__docs'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        printf(
            '<a class="stackless-card__link"%s href="%s">%s%s</a>',
            $settings === '' ? ' hidden' : '',
            esc_url($settings ?: self::url($id)),
            esc_html__('Settings', 'stackless-lite'),
            Icons::svg('chevron-right', 16) // phpcs:ignore WordPress.Security.EscapeOutput
        );
        echo '</div></article>';
    }

    /* ----------------------------- Settings --------------------------- */

    public function renderSettings(): void
    {
        $this->maybeFlush();
        $configurable = $this->byGroup(function (Module $m) {
            return $m->id() !== self::LICENSE_MODULE && $this->modules->isEnabled($m->id()) && $m->settingsFields();
        });
        $requested = isset($_GET['module']) ? sanitize_key(wp_unslash($_GET['module'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        /**
         * A callable that renders the main panel instead of a module's settings form, or null.
         * Used with `stackless_settings_sidebar` to add read-only screens to Settings.
         */
        $view = apply_filters('stackless_settings_view', null);
        $view = is_callable($view) ? $view : null;
        $extraSidebar = has_action('stackless_settings_sidebar');
        $current = null;
        foreach ($view ? [] : $configurable as $mods) {
            if ($requested !== '' && isset($mods[$requested])) {
                $current = $mods[$requested];
                break;
            }
            if ($requested === '' && !$current) {
                $current = reset($mods) ?: null;
            }
        }

        echo '<div class="wrap stackless-admin stackless-settings">';
        $this->header(__('Settings', 'stackless-lite'), __('Configure the modules you have switched on.', 'stackless-lite'));
        echo '<hr class="wp-header-end">';
        $this->notices();

        if (!$configurable && !$view && !$extraSidebar) {
            echo '<div class="stackless-panel stackless-panel--empty"><p>' . esc_html__('Switch on a module from the Dashboard to configure it here.', 'stackless-lite') . '</p>';
            echo '<a class="button button-primary" href="' . esc_url(self::url()) . '">' . esc_html__('Open Dashboard', 'stackless-lite') . '</a></div></div>';
            return;
        }

        echo '<div class="stackless-layout">';
        echo '<nav class="stackless-sidebar" aria-label="' . esc_attr__('Modules', 'stackless-lite') . '">';
        foreach ($configurable as $group => $mods) {
            echo '<p class="stackless-sidebar__group">' . esc_html($this->groups()[$group] ?? ucfirst($group)) . '</p><ul>';
            foreach ($mods as $id => $module) {
                $active = $current && $current->id() === $id;
                printf(
                    '<li><a href="%s" class="stackless-sidebar__link%s"%s>%s<span>%s</span></a></li>',
                    esc_url(self::url($id)),
                    $active ? ' is-current' : '',
                    $active ? ' aria-current="page"' : '',
                    Icons::svg(Icons::forModule($id, $module->group()), 18), // phpcs:ignore WordPress.Security.EscapeOutput
                    esc_html($module->title())
                );
            }
            echo '</ul>';
        }
        /** Extra sidebar groups after the module groups (same `.stackless-sidebar__*` markup). */
        do_action('stackless_settings_sidebar', $view !== null);
        echo '</nav>';

        echo '<div class="stackless-main">';
        if ($view) {
            call_user_func($view);
        } elseif (!$configurable) {
            echo '<div class="stackless-panel stackless-panel--empty"><p>' . esc_html__('Switch on a module from the Dashboard to configure it here.', 'stackless-lite') . '</p>';
            echo '<a class="button button-primary" href="' . esc_url(self::url()) . '">' . esc_html__('Open Dashboard', 'stackless-lite') . '</a></div>';
        } elseif (!$current) {
            echo '<div class="stackless-panel"><p>' . esc_html__('That module is switched off or has no settings.', 'stackless-lite') . '</p></div>';
        } else {
            $this->moduleForm($current);
        }
        echo '</div></div></div>';
    }

    private function moduleForm(Module $module): void
    {
        echo '<div class="stackless-panel">';
        echo '<div class="stackless-panel__head"><span class="stackless-card__icon">' . Icons::svg(Icons::forModule($module->id(), $module->group()), 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<div><h2>' . esc_html($module->title()) . '</h2><p>' . esc_html($module->description()) . '</p></div>';
        echo '<p class="stackless-panel__docs">' . Icons::svg('book', 16) . Documentation::link($module->id(), sprintf(/* translators: %s: module name */ __('How to set up %s', 'stackless-lite'), $module->title())) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- local SVG; link escaped in Documentation::link().
        echo '<form method="post" action="" class="stackless-form woocommerce">';
        wp_nonce_field('stackless_settings', 'stackless_settings_nonce');
        echo '<input type="hidden" name="stackless_module" value="' . esc_attr($module->id()) . '">';
        \WC_Admin_Settings::output_fields($module->settingsFields());
        do_action('stackless_settings_after_' . $module->id());
        echo '<div class="stackless-form__actions">';
        submit_button(__('Save changes', 'stackless-lite'), 'primary', 'submit', false);
        echo '</div></form></div>';
    }

    /* ------------------------------ License --------------------------- */

    public function renderLicense(): void
    {
        $module = $this->modules->get(self::LICENSE_MODULE);
        echo '<div class="wrap stackless-admin stackless-license">';
        $this->header(__('License', 'stackless-lite'), __('Activate your license for automatic updates and support. Every feature works without it.', 'stackless-lite'));
        echo '<hr class="wp-header-end">';
        $this->notices();
        echo '<div class="stackless-narrow">';
        if ($module && $this->modules->isEnabled(self::LICENSE_MODULE) && $module->settingsFields()) {
            $this->moduleForm($module);
        } else {
            echo '<div class="stackless-panel"><p>' . esc_html__('Turn on the License & Updates module on the Dashboard to manage your license.', 'stackless-lite') . '</p></div>';
        }
        echo '</div></div>';
    }
}
