<?php

namespace Stackless\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Maps product detail rows onto WooCommerce's Product structured data.
 * Pure: takes arrays, returns arrays. Only values the page shows for the whole product
 * are added; per-variation values stay on the page.
 */
final class Schema
{
    /**
     * @param array<string, mixed>                $markup WooCommerce Product markup
     * @param array<int, array<string, mixed>>    $rows   Repository::rows()
     * @param array<string, array<string, mixed>> $fields Fields::all()
     * @return array<string, mixed>
     */
    public static function apply(array $markup, array $rows, array $fields): array
    {
        $properties = [];
        foreach ($rows as $row) {
            if (!empty($row['variations']) || (string) ($row['value'] ?? '') === '') {
                continue;
            }
            $key = (string) $row['key'];
            $value = (string) $row['value'];
            $map = (string) ($fields[$key]['schema'] ?? '');
            switch ($map) {
                case 'brand':
                    if (empty($markup['brand'])) {
                        $markup['brand'] = ['@type' => 'Brand', 'name' => $value];
                    }
                    break;
                case 'gtin':
                    if (empty($markup['gtin'])) {
                        $markup['gtin'] = $value;
                    }
                    break;
                case 'mpn':
                    $markup['mpn'] = $value;
                    break;
                case 'isbn':
                    // Product has no isbn property; an ISBN-13 is a GTIN-13.
                    if (empty($markup['gtin']) && empty($markup['gtin13'])) {
                        $markup['gtin13'] = $value;
                    }
                    break;
                case 'color':
                case 'material':
                case 'size':
                case 'countryOfOrigin':
                    $markup[$map] = $value;
                    break;
                case 'weight':
                    $quantity = self::quantity($value);
                    if ($quantity) {
                        $markup['weight'] = $quantity;
                    }
                    break;
                case 'dimensions':
                    foreach (self::dimensions($value) as $side => $quantity) {
                        $markup[$side] = $quantity;
                    }
                    break;
                case 'itemCondition':
                    $condition = self::condition($value);
                    if ($condition !== '' && !empty($markup['offers'])) {
                        $markup['offers'] = self::eachOffer($markup['offers'], static function ($offer) use ($condition) {
                            if (is_array($offer)) {
                                $offer['itemCondition'] = 'https://schema.org/' . $condition;
                            }
                            return $offer;
                        });
                    }
                    break;
                default:
                    $properties[] = ['@type' => 'PropertyValue', 'name' => (string) $row['label'], 'value' => $value];
            }
        }
        if ($properties) {
            $markup['additionalProperty'] = array_merge((array) ($markup['additionalProperty'] ?? []), $properties);
        }
        return $markup;
    }

    public static function condition(string $value): string
    {
        $map = [
            'new' => 'NewCondition',
            'used' => 'UsedCondition',
            'refurbished' => 'RefurbishedCondition',
        ];
        // Labels are translated, so also match the merchant's own language through the English keys.
        $key = strtolower(trim($value));
        if (isset($map[$key])) {
            return $map[$key];
        }
        $options = [__('New', 'stackless-lite') => 'NewCondition', __('Used', 'stackless-lite') => 'UsedCondition', __('Refurbished', 'stackless-lite') => 'RefurbishedCondition'];
        return $options[$value] ?? '';
    }

    /**
     * @param mixed $offers
     * @return mixed
     */
    private static function eachOffer($offers, callable $callback)
    {
        if (!is_array($offers)) {
            return $offers;
        }
        if (isset($offers['@type'])) {
            if ($offers['@type'] === 'AggregateOffer' && !empty($offers['offers']) && is_array($offers['offers'])) {
                $offers['offers'] = array_map($callback, $offers['offers']);
                return $offers;
            }
            return $callback($offers);
        }
        return array_map($callback, $offers);
    }

    public static function unitCode(string $unit): string
    {
        $codes = ['kg' => 'KGM', 'g' => 'GRM', 'lbs' => 'LBR', 'lb' => 'LBR', 'oz' => 'ONZ', 'cm' => 'CMT', 'mm' => 'MMT', 'in' => 'INH', 'm' => 'MTR', 'yd' => 'YRD'];
        return $codes[strtolower($unit)] ?? '';
    }

    /** "1.2 kg" → QuantitativeValue, or null when the unit is unknown. */
    public static function quantity(string $value): ?array
    {
        if (!preg_match('/^([\d.]+)\s*([a-z]+)$/i', trim($value), $match)) {
            return null;
        }
        $code = self::unitCode($match[2]);
        return $code !== '' ? ['@type' => 'QuantitativeValue', 'value' => (float) $match[1], 'unitCode' => $code] : null;
    }

    /**
     * "20 × 15 × 3 cm" → depth, width, height QuantitativeValues.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function dimensions(string $value): array
    {
        $unit = preg_match('/([a-z]+)\s*$/i', $value, $match) ? $match[1] : '';
        $code = self::unitCode($unit);
        $numbers = preg_split('/\s*[×x]\s*/u', trim((string) preg_replace('/\s*[a-z]+\s*$/i', '', $value))) ?: [];
        if ($code === '' || count($numbers) !== 3) {
            return [];
        }
        $out = [];
        foreach (['depth', 'width', 'height'] as $index => $side) {
            if (is_numeric($numbers[$index])) {
                $out[$side] = ['@type' => 'QuantitativeValue', 'value' => (float) $numbers[$index], 'unitCode' => $code];
            }
        }
        return count($out) === 3 ? $out : [];
    }
}
