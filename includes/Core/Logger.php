<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

final class Logger
{
    /** Writes to WooCommerce → Status → Logs under source "stackless-{channel}". Never log secrets or card data. */
    public static function log(string $channel, string $message, string $level = 'info', array $context = []): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $context['source'] = 'stackless-' . sanitize_key($channel);
        wc_get_logger()->log($level, $message, $context);
    }
}
