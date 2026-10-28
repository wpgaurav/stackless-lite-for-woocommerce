<?php

namespace Stackless\Modules\PayWhatYouLike;

defined('ABSPATH') || exit;

/**
 * Pure money logic for Pay What You Like. No WordPress calls, so it is unit-tested directly.
 *
 * Amounts arrive as text typed by a buyer or merchant and leave as decimal strings
 * ("12.50") or integer minor units (1250) for exact comparisons.
 */
final class Amounts
{
    /** Quick amounts shown as one-tap buttons. */
    public const MAX_PRESETS = 4;

    /** The most a buyer can enter when the product sets no maximum, so a typo can't charge a fortune. */
    public const HARD_MAX = 1000000;

    /** Longest whole-number part accepted (a guard against absurd input, not a business rule). */
    private const MAX_DIGITS = 12;

    /**
     * Parse typed text into a decimal string with exactly $decimals places, or null when it is
     * not a non-negative amount. Accepts "12.50", "12,50", "1,234.50", "1.234,50", "$ 12" and
     * "12.500" (extra zeros); refuses "-3", "abc" and more significant decimals than the currency has.
     *
     * A lone separator followed by exactly three digits is read as a thousands separator ("1,000")
     * unless it is the store's own decimal separator.
     *
     * @param mixed $raw
     */
    public static function parse($raw, int $decimals, string $decimalSep = '.'): ?string
    {
        $decimals = max(0, min(8, $decimals));
        if (is_int($raw) || is_float($raw)) {
            if ($raw < 0 || !is_finite((float) $raw)) {
                return null;
            }
            $raw = number_format((float) $raw, 8, '.', '');
            $decimalSep = '.';
        }
        if (!is_string($raw)) {
            return null;
        }
        // Spaces (including no-break spaces) and apostrophes are only ever grouping characters.
        $value = (string) preg_replace('/[\s\x{00A0}\x{202F}\']+/u', '', trim($raw));
        if ($value === '' || strpos($value, '-') !== false) {
            return null;
        }
        // A currency symbol or code before or after the number.
        $value = (string) preg_replace('/^[^\d.,]+|[^\d.,]+$/u', '', $value);
        if ($value === '' || !preg_match('/^[\d.,]+$/', $value)) {
            return null;
        }

        $int = $value;
        $frac = '';
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');
        $pos = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
        if ($pos >= 0) {
            $sep = $value[$pos];
            $after = (string) substr($value, $pos + 1);
            $before = (string) substr($value, 0, $pos);
            $single = substr_count($value, $sep) === 1;
            if ($sep === $decimalSep) {
                $isDecimal = $single && !($decimals === 0 && strlen($after) === 3);
            } else {
                $isDecimal = $single && strlen($after) !== 3;
            }
            if ($isDecimal) {
                $int = $before;
                $frac = $after;
            }
        }
        // Grouped digits must look like thousands: "1,234,567", never "1.2.3".
        if (strpbrk($int, '.,') !== false && !preg_match('/^\d{1,3}([.,])\d{3}(\1\d{3})*$/', $int)) {
            return null;
        }
        $int = str_replace(['.', ','], '', $int);
        if ($int === '') {
            $int = '0';
        }
        if (!ctype_digit($int) || ($frac !== '' && !ctype_digit($frac))) {
            return null;
        }
        if (strlen($frac) > $decimals) {
            if (rtrim((string) substr($frac, $decimals), '0') !== '') {
                return null;
            }
            $frac = (string) substr($frac, 0, $decimals);
        }
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        if (strlen($int) > self::MAX_DIGITS) {
            return null;
        }
        return $decimals > 0 ? $int . '.' . str_pad($frac, $decimals, '0') : $int;
    }

    /**
     * Integer minor units for an amount ("12.5" with 2 decimals → 1250), or null when unreadable.
     * Plain machine values only: "." decimals, no grouping.
     *
     * @param mixed $value
     */
    public static function toMinor($value, int $decimals): ?int
    {
        $decimals = max(0, min(8, $decimals));
        if (is_int($value) || is_float($value)) {
            if ($value < 0 || !is_finite((float) $value)) {
                return null;
            }
            return (int) round((float) $value * pow(10, $decimals));
        }
        if (!is_string($value) || !preg_match('/^\d+(\.\d+)?$/', trim($value))) {
            return null;
        }
        $parts = explode('.', trim($value), 2);
        $frac = $parts[1] ?? '';
        $minor = (int) $parts[0] * (int) pow(10, $decimals);
        if ($decimals > 0) {
            $minor += (int) str_pad((string) substr($frac, 0, $decimals), $decimals, '0');
        }
        // Round half up on the first dropped digit.
        if (strlen($frac) > $decimals && (int) $frac[$decimals] >= 5) {
            $minor++;
        }
        return $minor;
    }

    /** Decimal string for minor units (1250, 2 → "12.50"). */
    public static function fromMinor(int $minor, int $decimals): string
    {
        $decimals = max(0, min(8, $decimals));
        $minor = max(0, $minor);
        if ($decimals === 0) {
            return (string) $minor;
        }
        $factor = (int) pow(10, $decimals);
        return intdiv($minor, $factor) . '.' . str_pad((string) ($minor % $factor), $decimals, '0', STR_PAD_LEFT);
    }

    /** The hard maximum in minor units. */
    public static function hardMax(int $decimals): int
    {
        return self::HARD_MAX * (int) pow(10, max(0, min(8, $decimals)));
    }

    /* ---------------------------------------------------------------------
     * Merchant settings
     * ------------------------------------------------------------------- */

    /** @return array{enabled: bool, min: string, suggested: string, max: string, presets: string[]} */
    public static function productDefaults(): array
    {
        return ['enabled' => false, 'min' => '', 'suggested' => '', 'max' => '', 'presets' => []];
    }

    /** @return array{mode: string, min: string, suggested: string, max: string} */
    public static function variationDefaults(): array
    {
        return ['mode' => 'inherit', 'min' => '', 'suggested' => '', 'max' => ''];
    }

    /**
     * Quick amounts from merchant input: unreadable and empty entries dropped, 0 kept (a one-tap
     * "free" choice), duplicates removed, sorted, capped at the hard maximum, at most four.
     *
     * @param mixed $raw list of strings, or one string separated by spaces, semicolons or pipes
     * @return string[]
     */
    public static function presets($raw, int $decimals, string $decimalSep = '.'): array
    {
        $list = self::presetInput($raw);
        $minors = [];
        $hard = self::hardMax($decimals);
        foreach ($list as $entry) {
            $parsed = self::parse($entry, $decimals, $decimalSep);
            $minor = $parsed === null ? null : self::toMinor($parsed, $decimals);
            if ($minor !== null && $minor <= $hard) {
                $minors[$minor] = true;
            }
        }
        $minors = array_keys($minors);
        sort($minors);
        return array_map(static function ($minor) use ($decimals) {
            return self::fromMinor((int) $minor, $decimals);
        }, array_slice($minors, 0, self::MAX_PRESETS));
    }

    /**
     * @param mixed $raw
     * @return string[] non-empty trimmed entries
     */
    private static function presetInput($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s;|]+/', $raw) ?: [];
        }
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_scalar($entry) && trim((string) $entry) !== '') {
                $out[] = trim((string) $entry);
            }
        }
        return $out;
    }

    /**
     * Product-level settings from the product editor (amounts in the store's base currency).
     * Problems come back as error codes and the offending value is left out, so a save never
     * stores settings that contradict each other.
     *
     * Error codes: invalid_min, invalid_suggested, invalid_max, invalid_preset, too_many_presets,
     * min_above_max, suggested_above_max, preset_above_max.
     *
     * @param array<string, mixed> $input enabled, min, suggested, max, presets
     * @return array{settings: array{enabled: bool, min: string, suggested: string, max: string, presets: string[]}, errors: string[]}
     */
    public static function sanitizeProduct(array $input, int $decimals, string $decimalSep = '.'): array
    {
        $errors = [];
        $settings = self::productDefaults();
        $settings['enabled'] = self::truthy($input['enabled'] ?? false);
        foreach (['min', 'suggested', 'max'] as $key) {
            $settings[$key] = self::optional($input[$key] ?? '', $decimals, $decimalSep, $key, $errors);
        }
        $entries = self::presetInput($input['presets'] ?? []);
        foreach ($entries as $entry) {
            $parsed = self::parse($entry, $decimals, $decimalSep);
            if ($parsed === null || (int) self::toMinor($parsed, $decimals) > self::hardMax($decimals)) {
                $errors[] = 'invalid_preset';
                break;
            }
        }
        $presets = self::presets($entries, $decimals, $decimalSep);
        if (count(array_unique($entries)) > self::MAX_PRESETS && count($presets) === self::MAX_PRESETS) {
            $errors[] = 'too_many_presets';
        }
        $settings['presets'] = $presets;
        $settings = self::limitToMax($settings, $decimals, $errors);
        return ['settings' => $settings, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Per-variation overrides. Empty values inherit the product's settings; "off" turns pay what
     * you like off for this variation only.
     *
     * @param array<string, mixed> $input off, min, suggested, max
     * @return array{settings: array{mode: string, min: string, suggested: string, max: string}, errors: string[]}
     */
    public static function sanitizeVariation(array $input, int $decimals, string $decimalSep = '.'): array
    {
        $errors = [];
        $settings = self::variationDefaults();
        $settings['mode'] = self::truthy($input['off'] ?? false) ? 'off' : 'inherit';
        foreach (['min', 'suggested', 'max'] as $key) {
            $settings[$key] = self::optional($input[$key] ?? '', $decimals, $decimalSep, $key, $errors);
        }
        $limited = self::limitToMax($settings + ['presets' => []], $decimals, $errors);
        unset($limited['presets']);
        /** @var array{mode: string, min: string, suggested: string, max: string} $limited */
        return ['settings' => $limited, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * @param mixed $value
     * @param string[] $errors
     */
    private static function optional($value, int $decimals, string $decimalSep, string $key, array &$errors): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return '';
        }
        $parsed = self::parse((string) $value, $decimals, $decimalSep);
        if ($parsed === null || (int) self::toMinor($parsed, $decimals) > self::hardMax($decimals)) {
            $errors[] = 'invalid_' . $key;
            return '';
        }
        // A maximum of 0 means "no maximum".
        if ($key === 'max' && (int) self::toMinor($parsed, $decimals) === 0) {
            return '';
        }
        return $parsed;
    }

    /**
     * @param array<string, mixed> $settings
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private static function limitToMax(array $settings, int $decimals, array &$errors): array
    {
        if ($settings['max'] === '') {
            return $settings;
        }
        $max = (int) self::toMinor($settings['max'], $decimals);
        if ($settings['min'] !== '' && (int) self::toMinor($settings['min'], $decimals) > $max) {
            $errors[] = 'min_above_max';
            $settings['max'] = '';
            return $settings;
        }
        if ($settings['suggested'] !== '' && (int) self::toMinor($settings['suggested'], $decimals) > $max) {
            $errors[] = 'suggested_above_max';
            $settings['suggested'] = '';
        }
        $kept = [];
        foreach ((array) $settings['presets'] as $preset) {
            if ((int) self::toMinor((string) $preset, $decimals) > $max) {
                $errors[] = 'preset_above_max';
                continue;
            }
            $kept[] = (string) $preset;
        }
        $settings['presets'] = $kept;
        return $settings;
    }

    /** @param mixed $value */
    private static function truthy($value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'yes', 'on', 'true'], true);
        }
        return (bool) $value;
    }

    /**
     * Stored product meta in the expected shape, whatever was saved.
     *
     * @param mixed $stored
     * @return array{enabled: bool, min: string, suggested: string, max: string, presets: string[]}
     */
    public static function normalizeProduct($stored): array
    {
        $out = self::productDefaults();
        if (!is_array($stored)) {
            return $out;
        }
        $out['enabled'] = !empty($stored['enabled']);
        foreach (['min', 'suggested', 'max'] as $key) {
            $value = $stored[$key] ?? '';
            $out[$key] = is_scalar($value) && preg_match('/^\d+(\.\d+)?$/', (string) $value) ? (string) $value : '';
        }
        $presets = [];
        foreach (is_array($stored['presets'] ?? null) ? $stored['presets'] : [] as $preset) {
            if (is_scalar($preset) && preg_match('/^\d+(\.\d+)?$/', (string) $preset)) {
                $presets[] = (string) $preset;
            }
        }
        $out['presets'] = array_slice(array_values(array_unique($presets)), 0, self::MAX_PRESETS);
        return $out;
    }

    /**
     * @param mixed $stored
     * @return array{mode: string, min: string, suggested: string, max: string}
     */
    public static function normalizeVariation($stored): array
    {
        $out = self::variationDefaults();
        if (!is_array($stored)) {
            return $out;
        }
        $out['mode'] = ($stored['mode'] ?? '') === 'off' ? 'off' : 'inherit';
        foreach (['min', 'suggested', 'max'] as $key) {
            $value = $stored[$key] ?? '';
            $out[$key] = is_scalar($value) && preg_match('/^\d+(\.\d+)?$/', (string) $value) ? (string) $value : '';
        }
        return $out;
    }

    /**
     * Effective settings for a product or one of its variations: the product's settings with the
     * variation's non-empty overrides on top.
     *
     * @param array<string, mixed> $product   normalizeProduct() shape
     * @param array<string, mixed>|null $variation normalizeVariation() shape, null for simple products
     * @return array{enabled: bool, min: string, suggested: string, max: string, presets: string[]}
     */
    public static function resolve(array $product, ?array $variation = null): array
    {
        $settings = self::normalizeProduct($product);
        if ($variation === null) {
            return $settings;
        }
        $variation = self::normalizeVariation($variation);
        if ($variation['mode'] === 'off') {
            $settings['enabled'] = false;
        }
        foreach (['min', 'suggested', 'max'] as $key) {
            if ($variation[$key] !== '') {
                $settings[$key] = $variation[$key];
            }
        }
        return $settings;
    }

    /* ---------------------------------------------------------------------
     * Buyer bounds
     * ------------------------------------------------------------------- */

    /**
     * Minimum, maximum, default and quick amounts for one product, all in minor units of the
     * buyer's currency.
     *
     * - The minimum is the custom minimum when set, otherwise the product's own price.
     * - The maximum is the merchant's maximum (or the hard maximum), never below the minimum.
     * - The default is the suggested amount, otherwise the product's price, kept inside the range.
     * - Quick amounts outside the range are left out (a 0 quick amount shows only when the minimum is 0).
     *
     * @param int[] $presets
     * @return array{min: int, max: int, default: int, presets: int[]}
     */
    public static function bounds(int $price, ?int $min, ?int $suggested, ?int $max, array $presets, int $hardMax): array
    {
        $price = max(0, $price);
        $low = $min !== null ? max(0, $min) : $price;
        $low = min($low, $hardMax);
        $high = $max !== null && $max > 0 ? min($max, $hardMax) : $hardMax;
        $high = max($low, $high);
        $default = $suggested !== null ? max(0, $suggested) : $price;
        $default = min($high, max($low, $default));
        $kept = [];
        foreach ($presets as $preset) {
            $preset = (int) $preset;
            if ($preset >= $low && $preset <= $high) {
                $kept[$preset] = true;
            }
        }
        $kept = array_keys($kept);
        sort($kept);
        return ['min' => $low, 'max' => $high, 'default' => $default, 'presets' => array_map('intval', $kept)];
    }

    /**
     * '' when $amount fits the bounds, otherwise "too_low" or "too_high".
     *
     * @param array{min: int, max: int} $bounds
     */
    public static function check(int $amount, array $bounds): string
    {
        if ($amount < $bounds['min']) {
            return 'too_low';
        }
        if ($amount > $bounds['max']) {
            return 'too_high';
        }
        return '';
    }

    /** @param array{min: int, max: int} $bounds */
    public static function clamp(int $amount, array $bounds): int
    {
        return min($bounds['max'], max($bounds['min'], $amount));
    }

    /**
     * A display-currency amount as the base-currency runtime price for set_price(). MultiCurrency
     * converts runtime prices once at $rate (no charm rounding) and rounds to the display
     * currency's decimals, so keeping ten decimals here makes the round trip land exactly on the
     * amount the buyer chose. With the base currency (rate 1) the amount is used as is.
     */
    public static function toBase(string $amount, float $rate): string
    {
        if ($rate <= 0 || abs($rate - 1.0) < 1e-12) {
            return $amount;
        }
        $value = rtrim(rtrim(number_format((float) $amount / $rate, 10, '.', ''), '0'), '.');
        return $value === '' ? '0' : $value;
    }
}
