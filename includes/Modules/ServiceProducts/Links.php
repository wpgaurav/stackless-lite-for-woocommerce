<?php

namespace Stackless\Modules\ServiceProducts;

defined('ABSPATH') || exit;

/**
 * Pure helpers for service products: booking-link placeholders and de-duplication.
 */
final class Links
{
    public const PLACEHOLDERS = ['{order_id}', '{order_number}', '{email}', '{first_name}', '{last_name}', '{name}', '{product}'];

    /**
     * Fill placeholders in a booking/intake URL. Values are URL-encoded; the result must
     * still be an http(s) URL or '' is returned.
     *
     * @param array<string, string> $vars placeholder (without braces) => raw value
     */
    public static function fill(string $template, array $vars): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = rawurlencode((string) $value);
        }
        // Unknown placeholders are removed rather than left in the link.
        $url = strtr($template, $replace);
        $url = preg_replace('/\{[a-z_]+\}/', '', $url) ?? '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            return '';
        }
        return $url;
    }

    /**
     * Unique service product ids from order lines, keeping order.
     *
     * @param array<int, array{product_id:int, is_service:bool}> $lines
     * @return int[]
     */
    public static function serviceProducts(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            if ($id > 0 && !empty($line['is_service']) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
