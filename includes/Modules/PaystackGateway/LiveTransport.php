<?php

namespace Stackless\Modules\PaystackGateway;

defined('ABSPATH') || exit;

/**
 * Sends live-mode Paystack requests. Test and live keys share one API host, so this class
 * is what tells them apart for payment alerts: only calls made through it count as live
 * (the alert route names this class, not the whole module).
 */
final class LiveTransport
{
    /** @return array|\WP_Error */
    public static function send(string $url, array $args)
    {
        return wp_remote_request($url, $args);
    }
}
