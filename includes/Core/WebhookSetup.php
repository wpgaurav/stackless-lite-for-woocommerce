<?php

namespace Stackless\Core;

defined('ABSPATH') || exit;

/**
 * Shared pieces of the payment gateways' one-click setup: buttons that open the provider's key
 * screens, and the check that a provider can call this site's webhook URL at all.
 */
final class WebhookSetup
{
    /**
     * Whether a payment provider can deliver to $url: HTTPS on a public host. Local and private
     * development sites get the manual instructions instead of an endpoint nobody can reach.
     */
    public static function reachable(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || strpos($host, '.') === false) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        foreach (['.local', '.localhost', '.test', '.invalid', '.example', '.internal', '.lan'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }
        return true;
    }

    /**
     * Buttons that open the provider's screens for creating keys, e.g. ['Create live keys' => url].
     *
     * @param array<string, string> $links label => URL
     */
    public static function keyButtons(array $links): string
    {
        $html = [];
        foreach ($links as $label => $url) {
            $html[] = sprintf(
                '<a class="button button-secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span aria-hidden="true">↗</span><span class="screen-reader-text"> %3$s</span></a>',
                esc_url($url),
                esc_html($label),
                esc_html__('(opens in a new tab)', 'stackless-lite')
            );
        }
        return $html ? '<span class="stackless-key-buttons" style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">' . implode('', $html) . '</span>' : '';
    }

    /** Sentence added to the save notice when a webhook could not be created for this site. */
    public static function unreachableNote(): string
    {
        return __('This site is not reachable over public HTTPS, so the webhook was not created automatically. Add it by hand once the site is live.', 'stackless-lite');
    }
}
