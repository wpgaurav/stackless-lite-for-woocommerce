<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

final class ConflictGuard
{
    /** Shared conflict lists, reused by modules. */
    public const SUBSCRIPTIONS = [
        'woocommerce-subscriptions/woocommerce-subscriptions.php' => 'WooCommerce Subscriptions',
        'subscriptions-for-woocommerce/subscriptions-for-woocommerce.php' => 'Subscriptions for WooCommerce',
        'yith-woocommerce-subscription/init.php' => 'YITH WooCommerce Subscription',
        'sumosubscriptions/sumosubscriptions.php' => 'SUMO Subscriptions',
    ];

    public const MULTI_CURRENCY = [
        'woocommerce-multicurrency/woocommerce-multicurrency.php' => 'WooCommerce Multi-Currency',
        'woocommerce-payments/woocommerce-payments.php' => 'WooPayments (multi-currency)',
        'woocommerce-currency-switcher/index.php' => 'FOX Currency Switcher',
        'woocommerce-multilingual/wpml-woocommerce.php' => 'WPML WooCommerce Multilingual & Multicurrency',
        'currency-switcher-for-woocommerce/currency-switcher-for-woocommerce.php' => 'Currency Switcher for WooCommerce',
    ];

    public const LICENSING = [
        'woocommerce-software-add-on/woocommerce-software.php' => 'WooCommerce Software Add-on',
        'license-manager-for-woocommerce/license-manager-for-woocommerce.php' => 'License Manager for WooCommerce',
    ];

    /** @param array<string,string> $plugins basename => name */
    public static function firstActive(array $plugins): ?string
    {
        if (!$plugins) {
            return null;
        }
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        foreach ($plugins as $basename => $name) {
            if (in_array($basename, $active, true)) {
                return $name;
            }
        }
        return null;
    }
}
