<?php

namespace Stackless\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Product meta storage and the rows the storefront shows.
 *
 * Product: _stackless_product_enhancements = {v, preset, values{key: value}, hidden[], custom[{label, value}]}
 * Variation: _stackless_product_enhancements_variation = {key: value}
 *
 * Both live on the product objects, so WooCommerce's duplicate-product keeps them.
 */
final class Repository
{
    public const META = '_stackless_product_enhancements';
    public const META_VARIATION = '_stackless_product_enhancements_variation';
    public const MAX_CUSTOM = 20;

    /** The preset a product starts with, from what WooCommerce knows about it. */
    public static function suggestedPreset(\WC_Product $product): string
    {
        if ($product->is_downloadable()) {
            return 'digital';
        }
        if ($product->is_virtual()) {
            return 'service';
        }
        return 'physical';
    }

    /**
     * @return array{v: int, preset: string, show: bool, values: array<string, string>, hidden: string[], custom: array<int, array{label: string, value: string}>, saved: bool}
     */
    public static function get(\WC_Product $product): array
    {
        $stored = $product->get_meta(self::META);
        $stored = is_array($stored) ? $stored : [];
        $presets = Fields::presets();
        $preset = isset($stored['preset'], $presets[(string) $stored['preset']]) ? (string) $stored['preset'] : self::suggestedPreset($product);
        $values = [];
        foreach ((array) ($stored['values'] ?? []) as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $key] = (string) $value;
            }
        }
        $custom = [];
        foreach ((array) ($stored['custom'] ?? []) as $row) {
            if (is_array($row) && isset($row['label'], $row['value'])) {
                $custom[] = ['label' => (string) $row['label'], 'value' => (string) $row['value']];
            }
        }
        return [
            'v' => 1,
            'preset' => $preset,
            'show' => !empty($stored) && (!array_key_exists('show', $stored) || !empty($stored['show'])),
            'values' => $values,
            'hidden' => array_values(array_map('strval', (array) ($stored['hidden'] ?? []))),
            'custom' => $custom,
            'saved' => !empty($stored),
        ];
    }

    /** @return array<string, string> */
    public static function variationValues(\WC_Product $variation): array
    {
        $stored = $variation->get_meta(self::META_VARIATION);
        $values = [];
        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $values[(string) $key] = (string) $value;
            }
        }
        return $values;
    }

    /**
     * Validate the product editor input. Invalid values are dropped and reported.
     *
     * @param array<string, mixed> $input preset, values{}, hidden[], custom (textarea "Label | Value" lines)
     * @return array{0: array<string, mixed>, 1: string[]} [data, errors]
     */
    public static function validate(\WC_Product $product, array $input): array
    {
        $presets = Fields::presets();
        $preset = isset($input['preset'], $presets[(string) $input['preset']]) ? (string) $input['preset'] : self::suggestedPreset($product);
        $keys = $presets[$preset]['fields'];
        $errors = [];
        $values = [];
        $rawValues = is_array($input['values'] ?? null) ? $input['values'] : [];
        foreach ($keys as $key) {
            try {
                $value = Fields::clean($key, $rawValues[$key] ?? '');
                if ($value !== '') {
                    $values[$key] = $value;
                }
            } catch (\InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }
        $hidden = array_values(array_intersect($keys, array_map('strval', (array) ($input['hidden'] ?? []))));
        [$custom, $customErrors] = self::parseCustom((string) ($input['custom'] ?? ''));
        return [
            ['v' => 1, 'preset' => $preset, 'show' => !empty($input['show']), 'values' => $values, 'hidden' => $hidden, 'custom' => $custom],
            array_merge($errors, $customErrors),
        ];
    }

    /**
     * "Label | Value" per line (a colon works too when there is no pipe).
     *
     * @return array{0: array<int, array{label: string, value: string}>, 1: string[]}
     */
    public static function parseCustom(string $text): array
    {
        $rows = [];
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $separator = strpos($line, '|') !== false ? '|' : (strpos($line, ':') !== false ? ':' : '');
            if ($separator === '') {
                $errors[] = sprintf(
                    /* translators: %s: the line the merchant typed */
                    __('Extra detail "%s" needs a name and a value separated by |.', 'stackless-lite'),
                    mb_substr($line, 0, 40)
                );
                continue;
            }
            [$label, $value] = array_map('trim', explode($separator, $line, 2));
            $label = mb_substr(sanitize_text_field($label), 0, 80);
            $value = mb_substr(sanitize_text_field($value), 0, 300);
            if ($label === '' || $value === '') {
                $errors[] = __('Each extra detail needs a name and a value.', 'stackless-lite');
                continue;
            }
            if (count($rows) >= self::MAX_CUSTOM) {
                /* translators: %d: maximum number of extra details */
                $errors[] = sprintf(__('Only the first %d extra details are kept.', 'stackless-lite'), self::MAX_CUSTOM);
                break;
            }
            $rows[] = ['label' => $label, 'value' => $value];
        }
        return [$rows, $errors];
    }

    /** @param array<int, array{label: string, value: string}> $rows */
    public static function customText(array $rows): string
    {
        return implode("\n", array_map(static function ($row) {
            return $row['label'] . ' | ' . $row['value'];
        }, $rows));
    }

    /**
     * Validate one variation's values against the product's preset.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, string>, 1: string[]}
     */
    public static function validateVariation(string $preset, array $input): array
    {
        $presets = Fields::presets();
        $keys = $presets[$preset]['fields'] ?? [];
        $values = [];
        $errors = [];
        foreach ($keys as $key) {
            try {
                $value = Fields::clean($key, $input[$key] ?? '');
                if ($value !== '') {
                    $values[$key] = $value;
                }
            } catch (\InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }
        return [$values, $errors];
    }

    /**
     * What the storefront shows: preset fields in order (entered value, then WooCommerce's
     * value, then the suggested default), then custom rows. Rows that differ by variation
     * carry one value per variation id.
     *
     * @return array<int, array{key: string, label: string, value: string, type: string, variations: array<int, string>}>
     */
    public static function rows(\WC_Product $product): array
    {
        $data = self::get($product);
        // A product shows details only once the merchant turns the table on, so enabling the module changes no page by itself.
        if (!$data['show']) {
            return (array) apply_filters('stackless_product_enhancements_rows', [], $product);
        }
        $fields = Fields::all();
        $presets = Fields::presets();
        $variations = [];
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product($childId);
                if ($child instanceof \WC_Product && $child->get_status() === 'publish') {
                    $variations[] = $child;
                }
            }
        }
        $rows = [];
        foreach ($presets[$data['preset']]['fields'] ?? [] as $key) {
            if (in_array($key, $data['hidden'], true) || !isset($fields[$key])) {
                continue;
            }
            $field = $fields[$key];
            $value = self::value($product, $key, $field, [], $data['values']);
            $perVariation = [];
            foreach ($variations as $variation) {
                $variationValue = self::value($variation, $key, $field, self::variationValues($variation), $data['values']);
                if ($variationValue !== '' && $variationValue !== $value) {
                    $perVariation[$variation->get_id()] = $variationValue;
                }
            }
            if ($value === '' && !$perVariation) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => (string) $field['label'], 'value' => $value, 'type' => (string) $field['type'], 'variations' => $perVariation];
        }
        foreach ($data['custom'] as $index => $row) {
            $rows[] = ['key' => 'custom_' . $index, 'label' => $row['label'], 'value' => $row['value'], 'type' => 'text', 'variations' => []];
        }
        /**
         * Filters the rows of the product details table.
         *
         * @param array       $rows
         * @param \WC_Product $product
         */
        return (array) apply_filters('stackless_product_enhancements_rows', $rows, $product);
    }

    /**
     * @param array<string, mixed>  $field
     * @param array<string, string> $ownValues     Variation-level values (empty for the product itself)
     * @param array<string, string> $productValues Product-level values
     */
    private static function value(\WC_Product $product, string $key, array $field, array $ownValues, array $productValues): string
    {
        if (isset($ownValues[$key]) && $ownValues[$key] !== '') {
            return $ownValues[$key];
        }
        if (isset($productValues[$key]) && $productValues[$key] !== '') {
            return $productValues[$key];
        }
        // Each ISBN can be worked out from the other.
        $isbnSource = $ownValues['isbn13'] ?? $ownValues['isbn10'] ?? $productValues['isbn13'] ?? $productValues['isbn10'] ?? '';
        if ($key === 'isbn13' && $isbnSource !== '') {
            return Fields::isbn13($isbnSource);
        }
        if ($key === 'isbn10' && $isbnSource !== '') {
            return Fields::isbn10(Fields::isbn13($isbnSource));
        }
        if (!empty($field['auto'])) {
            $auto = AutoFill::values($product)[(string) $field['auto']] ?? '';
            if ($auto !== '') {
                return $auto;
            }
        }
        return (string) ($field['default'] ?? '');
    }
}
