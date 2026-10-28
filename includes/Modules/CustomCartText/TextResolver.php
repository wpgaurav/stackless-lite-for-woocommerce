<?php

namespace Stackless\Modules\CustomCartText;

defined('ABSPATH') || exit;

/**
 * Pure precedence rules for button text.
 */
final class TextResolver
{
    /**
     * First non-empty candidate wins; the WooCommerce default is the fallback.
     *
     * @param array<int, mixed> $candidates Most specific first.
     */
    public static function resolve(array $candidates, string $default): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return $default;
    }

    /**
     * The state keys that apply to a product, most specific first.
     *
     * @return string[] e.g. ['out_of_stock', 'variable']
     */
    public static function keys(string $type, bool $inStock, bool $free, bool $subscription): array
    {
        $keys = [];
        if (!$inStock) {
            $keys[] = 'out_of_stock';
        }
        if ($free && $inStock) {
            $keys[] = 'free';
        }
        if ($subscription) {
            $keys[] = 'subscription';
        }
        if (in_array($type, ['simple', 'variable', 'grouped', 'external'], true)) {
            $keys[] = $type;
        }
        return $keys;
    }
}
