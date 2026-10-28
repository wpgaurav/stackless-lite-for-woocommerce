<?php

namespace Stackless\Modules\ExternalProduct;

defined('ABSPATH') || exit;

/**
 * Pure link rules for external products.
 */
final class Link
{
    /**
     * Product value '' inherits the store default; 'yes'/'no' override it.
     */
    public static function flag(string $productValue, bool $storeDefault): bool
    {
        if ($productValue === 'yes') {
            return true;
        }
        if ($productValue === 'no') {
            return false;
        }
        return $storeDefault;
    }

    /** '' inherits; '_self' or '_blank' override. */
    public static function target(string $productValue, string $storeDefault): string
    {
        if (in_array($productValue, ['_self', '_blank'], true)) {
            return $productValue;
        }
        return $storeDefault === '_self' ? '_self' : '_blank';
    }

    public static function rel(bool $nofollow, bool $sponsored, string $target): string
    {
        $rel = [];
        if ($nofollow) {
            $rel[] = 'nofollow';
        }
        if ($sponsored) {
            $rel[] = 'sponsored';
        }
        if ($target === '_blank') {
            $rel[] = 'noopener';
        }
        return implode(' ', $rel);
    }

    public static function validUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }
}
