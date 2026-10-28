<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

final class Requirements
{
    public static function met(): bool
    {
        return defined('WC_VERSION') && version_compare(WC_VERSION, STACKLESS_MIN_WC, '>=');
    }

    public static function notice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $message = defined('WC_VERSION')
            /* translators: 1: required WooCommerce version, 2: installed version */
            ? sprintf(__('Stackless for WooCommerce needs WooCommerce %1$s or newer. You have %2$s.', 'stackless-lite'), STACKLESS_MIN_WC, WC_VERSION)
            : __('Stackless for WooCommerce needs WooCommerce installed and active.', 'stackless-lite');
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    }
}
