<?php

namespace Stackless\Modules\FreePriceText;

defined('ABSPATH') || exit;

/**
 * Pure price checks used by the module. No WordPress calls, so they are unit tested directly.
 */
final class Price
{
    /**
     * A price is free only when it is set and equals zero. An empty string means
     * "no price entered", which WooCommerce treats as unpurchasable, not free.
     *
     * @param mixed $price
     */
    public static function isZero($price): bool
    {
        if ($price === null || $price === '' || is_bool($price) || is_array($price) || is_object($price)) {
            return false;
        }
        if (!is_numeric($price)) {
            return false;
        }
        return abs((float) $price) < 0.000001;
    }

    /**
     * Classify a list of display prices (variable or grouped products).
     *
     * @param array<int|string, mixed> $prices
     * @return string "none" (nothing free or no prices), "all" (every price is zero), "from" (cheapest is zero, others are not)
     */
    public static function rangeState(array $prices): string
    {
        $numeric = [];
        foreach ($prices as $price) {
            if ($price !== '' && $price !== null && is_numeric($price)) {
                $numeric[] = (float) $price;
            }
        }
        if (!$numeric) {
            return 'none';
        }
        $min = min($numeric);
        $max = max($numeric);
        if (!self::isZero($min)) {
            return 'none';
        }
        return self::isZero($max) ? 'all' : 'from';
    }

    /**
     * Highest price in a list, for "Free – $X" ranges.
     *
     * @param array<int|string, mixed> $prices
     */
    public static function max(array $prices): float
    {
        $numeric = [];
        foreach ($prices as $price) {
            if ($price !== '' && $price !== null && is_numeric($price)) {
                $numeric[] = (float) $price;
            }
        }
        return $numeric ? max($numeric) : 0.0;
    }
}
