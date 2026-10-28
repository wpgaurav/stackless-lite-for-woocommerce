<?php

namespace Stackless\Modules\StockUrgency;

defined('ABSPATH') || exit;

/**
 * Pure stock-level classification.
 */
final class Levels
{
    public const NONE = 'none';
    public const IN_STOCK = 'in-stock';
    public const LOW = 'low';
    public const VERY_LOW = 'very-low';
    public const OUT = 'out';

    /**
     * @param int|null $quantity Units left, null when stock is not tracked.
     * @param int      $low      Show the low label at or below this.
     * @param int      $veryLow  Show the very-low label at or below this.
     */
    public static function level(?int $quantity, int $low, int $veryLow): string
    {
        if ($quantity === null) {
            return self::NONE;
        }
        if ($quantity <= 0) {
            return self::OUT;
        }
        $low = max(0, $low);
        $veryLow = max(0, min($veryLow, $low));
        if ($veryLow > 0 && $quantity <= $veryLow) {
            return self::VERY_LOW;
        }
        if ($low > 0 && $quantity <= $low) {
            return self::LOW;
        }
        return self::IN_STOCK;
    }

    /**
     * Sum of child stock when every child tracks stock; null when any child does not
     * (an untracked option means the total is unknown, so no urgency should show).
     *
     * @param array<int, int|null> $childQuantities
     */
    public static function sum(array $childQuantities): ?int
    {
        if (!$childQuantities) {
            return null;
        }
        $total = 0;
        foreach ($childQuantities as $quantity) {
            if ($quantity === null) {
                return null;
            }
            $total += max(0, (int) $quantity);
        }
        return $total;
    }

    public static function text(string $template, int $quantity): string
    {
        return str_replace('{count}', (string) $quantity, $template);
    }
}
