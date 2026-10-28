<?php

namespace Stackless\Modules\SoldCount;

defined('ABSPATH') || exit;

/**
 * Pure formatting for the sold-count badge.
 */
final class Badge
{
    public const ROUNDING = ['exact', 'rounded'];

    /** Highest count multiplier the setting accepts. */
    public const MAX_MULTIPLIER = 100;

    /**
     * A positive multiplier up to MAX_MULTIPLIER, decimals allowed. Anything else counts as 1.
     *
     * @param mixed $value Stored setting.
     */
    public static function multiplier($value): float
    {
        $value = is_numeric($value) ? (float) $value : 1.0;
        return $value > 0 ? min($value, (float) self::MAX_MULTIPLIER) : 1.0;
    }

    /**
     * Real count times the multiplier, rounded up: 12 × 6.32 = 75.84 shows as 76.
     * Rounded to 6 decimals first so float noise (10 × 1.1 = 11.000000000000002) does not round up to 12.
     */
    public static function multiply(int $count, float $multiplier): int
    {
        if ($count <= 0 || abs($multiplier - 1.0) < 1e-9) {
            return max(0, $count);
        }
        return (int) ceil(round($count * $multiplier, 6));
    }

    /**
     * Whether a count clears the display threshold.
     */
    public static function visible(int $count, int $threshold): bool
    {
        return $count > 0 && $count >= max(1, $threshold);
    }

    /**
     * Format a count. "rounded" floors to a friendly number and adds "+":
     * 87 → 87, 134 → 130+, 1,268 → 1,200+, 23,940 → 23,000+.
     *
     * @param callable(int): string|null $numberFormat Locale formatter (number_format_i18n in WordPress).
     */
    public static function formatCount(int $count, string $rounding = 'exact', ?callable $numberFormat = null): string
    {
        $format = $numberFormat ?: static function (int $n): string {
            return number_format($n);
        };
        $count = max(0, $count);
        if ($rounding !== 'rounded' || $count < 100) {
            return (string) $format($count);
        }
        if ($count < 1000) {
            $step = 10;
        } elseif ($count < 10000) {
            $step = 100;
        } else {
            $step = 1000;
        }
        $floored = (int) (floor($count / $step) * $step);
        return $floored === $count ? (string) $format($count) : $format($floored) . '+';
    }

    /**
     * Replace {count} in the merchant's template. Templates without the placeholder get the count prepended.
     */
    public static function text(string $template, string $formattedCount): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = '{count} sold';
        }
        if (strpos($template, '{count}') === false) {
            return $formattedCount . ' ' . $template;
        }
        return str_replace('{count}', $formattedCount, $template);
    }
}
