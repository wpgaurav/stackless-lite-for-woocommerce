<?php
/**
 * Uninstall Stackless Lite for WooCommerce.
 *
 * Settings, wishlists, saved carts and alert subscribers are kept by default, so you can reinstall
 * Lite or move to Stackless without losing anything. To remove Stackless tables, options and
 * scheduled actions on uninstall, define STACKLESS_REMOVE_ALL_DATA as true in wp-config.php
 * before deleting the plugin. Nothing is removed while Stackless (the full plugin) is installed,
 * because it uses the same data.
 *
 * @package Stackless
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

if (!defined('STACKLESS_REMOVE_ALL_DATA') || STACKLESS_REMOVE_ALL_DATA !== true) {
    return;
}

// Stackless shares every option and table with Lite: keep them while it is installed anywhere.
foreach (array_keys(function_exists('get_plugins') ? get_plugins() : []) as $stackless_lite_plugin) {
    if (basename((string) $stackless_lite_plugin) === 'stackless.php') {
        return;
    }
}

global $wpdb;

// Custom tables.
$stackless_lite_tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'stackless_') . '%'));
foreach ($stackless_lite_tables as $stackless_lite_table) {
    $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($stackless_lite_table) . '`'); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Options and transients.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('stackless_') . '%',
    $wpdb->esc_like('_transient_stackless_') . '%',
    $wpdb->esc_like('_transient_timeout_stackless_') . '%'
));

// Scheduled actions.
if (function_exists('as_unschedule_all_actions')) {
    foreach ($wpdb->get_col("SELECT DISTINCT hook FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'stackless%'") as $stackless_lite_hook) {
        as_unschedule_all_actions($stackless_lite_hook);
    }
}
