<?php
/**
 * Plugin Name: Stackless Lite for WooCommerce
 * Plugin URI: https://gauravtiwari.org/product/stackless-lite-for-woocommerce/
 * Description: Free store tools for WooCommerce: wishlist, saved carts, back-in-stock alerts, quantity limits, buy links, pay what you like, checkout tweaks and catalog labels. Switch on only what you use.
 * Version: 1.7.0
 * Author: Gaurav Tiwari
 * Author URI: https://gauravtiwari.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stackless-lite
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.9
 * WC tested up to: 11.2
 * Update URI: https://gauravtiwari.org/product/stackless-lite-for-woocommerce/
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;

/*
 * Stackless (the full plugin) contains every Lite module, uses the same classes and reads the same
 * settings, meta and tables. The two must never load together, so this check runs before Lite
 * defines anything: while Stackless is active, Lite stays idle and Stackless switches it off.
 */
if (defined('STACKLESS_VERSION') || (static function (): bool {
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
    }
    foreach ($active as $plugin) {
        if (is_string($plugin) && basename($plugin) === 'stackless.php') {
            return true;
        }
    }
    return false;
})()) {
    add_action('admin_notices', static function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Stackless Lite is idle because Stackless is active. Stackless includes every Lite module and uses the same settings and data, so you can deactivate and delete Stackless Lite.', 'stackless-lite') . '</p></div>';
    });
    return;
}

define('STACKLESS_LITE_VERSION', '1.7.0');
define('STACKLESS_VERSION', STACKLESS_LITE_VERSION);
define('STACKLESS_PLUGIN_FILE', __FILE__);
define('STACKLESS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('STACKLESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STACKLESS_MIN_WC', '9.9');

/** Map Stackless\* onto includes/*.php. */
spl_autoload_register(function ($class) {
    $prefix = 'Stackless\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    if ($relative === '' || strpos($relative, '..') !== false) {
        return;
    }
    $path = STACKLESS_PLUGIN_PATH . 'includes/' . $relative . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

require_once STACKLESS_PLUGIN_PATH . 'includes/functions.php';

/** HPOS and Cart/Checkout blocks compatibility. */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', STACKLESS_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', STACKLESS_PLUGIN_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    \Stackless\Core\LiteUpdater::boot();
    if (!\Stackless\Core\Requirements::met()) {
        add_action('admin_notices', [\Stackless\Core\Requirements::class, 'notice']);
        return;
    }
    \Stackless\Core\Plugin::instance()->boot();
    if (is_admin()) {
        \Stackless\Lite\Upgrade::boot();
    }
}, 20);

register_activation_hook(__FILE__, [\Stackless\Core\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\Stackless\Core\Installer::class, 'deactivate']);
