<?php

namespace Stackless\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Product detail fields and the presets that group them by product type.
 *
 * Field types: text, number, date, url, list (comma separated), select, gtin, isbn13, isbn10.
 * "auto" names a value WooCommerce already stores (see AutoFill). "default" is a suggested
 * value shown until the merchant types their own. "schema" maps the field to schema.org.
 */
final class Fields
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $fields = [
            'brand' => ['label' => __('Brand', 'stackless-lite'), 'type' => 'text', 'auto' => 'brand', 'schema' => 'brand'],
            'mpn' => ['label' => __('Model number', 'stackless-lite'), 'type' => 'text', 'schema' => 'mpn'],
            'gtin' => ['label' => __('GTIN / UPC / EAN', 'stackless-lite'), 'type' => 'gtin', 'auto' => 'gtin', 'schema' => 'gtin', 'help' => __('8, 12, 13 or 14 digits from the barcode.', 'stackless-lite')],
            'condition' => ['label' => __('Condition', 'stackless-lite'), 'type' => 'select', 'options' => [__('New', 'stackless-lite'), __('Used', 'stackless-lite'), __('Refurbished', 'stackless-lite')], 'schema' => 'itemCondition'],
            'color' => ['label' => __('Colors', 'stackless-lite'), 'type' => 'list', 'schema' => 'color'],
            'size' => ['label' => __('Size', 'stackless-lite'), 'type' => 'list', 'schema' => 'size'],
            'material' => ['label' => __('Material', 'stackless-lite'), 'type' => 'list', 'schema' => 'material'],
            'dimensions' => ['label' => __('Dimensions', 'stackless-lite'), 'type' => 'text', 'auto' => 'dimensions', 'schema' => 'dimensions', 'help' => __('Length × width × height, for example 20 × 15 × 3 cm.', 'stackless-lite')],
            'weight' => ['label' => __('Weight', 'stackless-lite'), 'type' => 'text', 'auto' => 'weight', 'schema' => 'weight'],
            'in_box' => ['label' => __('In the box', 'stackless-lite'), 'type' => 'list'],
            'origin' => ['label' => __('Country of origin', 'stackless-lite'), 'type' => 'text', 'schema' => 'countryOfOrigin'],
            'warranty' => ['label' => __('Warranty', 'stackless-lite'), 'type' => 'text'],
            'file_format' => ['label' => __('File type', 'stackless-lite'), 'type' => 'text', 'auto' => 'file_format'],
            'file_size' => ['label' => __('File size', 'stackless-lite'), 'type' => 'text', 'auto' => 'file_size'],
            'file_count' => ['label' => __('Files included', 'stackless-lite'), 'type' => 'number', 'auto' => 'file_count'],
            'pages' => ['label' => __('Pages', 'stackless-lite'), 'type' => 'number', 'schema' => 'numberOfPages'],
            'version' => ['label' => __('Version', 'stackless-lite'), 'type' => 'text'],
            'updated' => ['label' => __('Last updated', 'stackless-lite'), 'type' => 'date', 'auto' => 'updated'],
            'requirements' => ['label' => __('Requirements', 'stackless-lite'), 'type' => 'list'],
            'license' => ['label' => __('License', 'stackless-lite'), 'type' => 'text'],
            'delivery' => ['label' => __('Delivery', 'stackless-lite'), 'type' => 'text', 'default' => __('Instant download after payment', 'stackless-lite')],
            'author' => ['label' => __('Author', 'stackless-lite'), 'type' => 'list', 'schema' => 'author'],
            'publisher' => ['label' => __('Publisher', 'stackless-lite'), 'type' => 'text', 'schema' => 'publisher'],
            'published' => ['label' => __('Publication date', 'stackless-lite'), 'type' => 'date', 'schema' => 'datePublished'],
            'edition' => ['label' => __('Edition', 'stackless-lite'), 'type' => 'text'],
            'language' => ['label' => __('Language', 'stackless-lite'), 'type' => 'text', 'auto' => 'language', 'schema' => 'inLanguage'],
            'book_format' => ['label' => __('Format', 'stackless-lite'), 'type' => 'select', 'options' => [__('Ebook', 'stackless-lite'), __('Paperback', 'stackless-lite'), __('Hardcover', 'stackless-lite'), __('Audiobook', 'stackless-lite')], 'auto' => 'book_format'],
            'isbn13' => ['label' => __('ISBN-13', 'stackless-lite'), 'type' => 'isbn13', 'auto' => 'isbn13', 'schema' => 'isbn', 'help' => __('Enter either ISBN. The other is worked out for you when it exists.', 'stackless-lite')],
            'isbn10' => ['label' => __('ISBN-10', 'stackless-lite'), 'type' => 'isbn10', 'auto' => 'isbn10'],
            'platform' => ['label' => __('Works with', 'stackless-lite'), 'type' => 'list'],
            'activations' => ['label' => __('Sites or devices', 'stackless-lite'), 'type' => 'text'],
            'support' => ['label' => __('Support', 'stackless-lite'), 'type' => 'text'],
            'updates' => ['label' => __('Updates', 'stackless-lite'), 'type' => 'text'],
            'docs_url' => ['label' => __('Documentation', 'stackless-lite'), 'type' => 'url'],
            'demo_url' => ['label' => __('Live demo', 'stackless-lite'), 'type' => 'url'],
            'duration' => ['label' => __('Duration', 'stackless-lite'), 'type' => 'text'],
            'format' => ['label' => __('Format', 'stackless-lite'), 'type' => 'select', 'options' => [__('Self-paced', 'stackless-lite'), __('Live online', 'stackless-lite'), __('In person', 'stackless-lite'), __('Hybrid', 'stackless-lite')]],
            'level' => ['label' => __('Level', 'stackless-lite'), 'type' => 'select', 'options' => [__('Beginner', 'stackless-lite'), __('Intermediate', 'stackless-lite'), __('Advanced', 'stackless-lite'), __('All levels', 'stackless-lite')]],
            'includes' => ['label' => __('Includes', 'stackless-lite'), 'type' => 'list'],
            'access' => ['label' => __('Access', 'stackless-lite'), 'type' => 'text'],
            'turnaround' => ['label' => __('Delivery time', 'stackless-lite'), 'type' => 'text'],
            'location' => ['label' => __('Location', 'stackless-lite'), 'type' => 'text'],
        ];
        /**
         * Filters the available product detail fields.
         *
         * @param array<string, array<string, mixed>> $fields
         */
        return (array) apply_filters('stackless_product_enhancements_fields', $fields);
    }

    /** @return array<string, array{label: string, fields: string[]}> */
    public static function presets(): array
    {
        $presets = [
            'physical' => ['label' => __('Physical product', 'stackless-lite'), 'fields' => ['brand', 'mpn', 'gtin', 'condition', 'color', 'size', 'material', 'dimensions', 'weight', 'in_box', 'origin', 'warranty']],
            'digital' => ['label' => __('Digital download', 'stackless-lite'), 'fields' => ['file_format', 'file_size', 'file_count', 'pages', 'version', 'updated', 'requirements', 'license', 'delivery']],
            'book' => ['label' => __('Book or ebook', 'stackless-lite'), 'fields' => ['author', 'publisher', 'published', 'edition', 'language', 'pages', 'book_format', 'isbn13', 'isbn10', 'file_format', 'file_size', 'dimensions', 'weight']],
            'software' => ['label' => __('Software or plugin', 'stackless-lite'), 'fields' => ['version', 'updated', 'requirements', 'platform', 'file_format', 'file_size', 'license', 'activations', 'support', 'updates', 'docs_url', 'demo_url']],
            'service' => ['label' => __('Course or service', 'stackless-lite'), 'fields' => ['duration', 'format', 'level', 'language', 'includes', 'access', 'turnaround', 'location']],
            'custom' => ['label' => __('Custom details only', 'stackless-lite'), 'fields' => []],
        ];
        /**
         * Filters the presets. Unknown field keys are dropped.
         *
         * @param array<string, array{label: string, fields: string[]}> $presets
         */
        $presets = (array) apply_filters('stackless_product_enhancements_presets', $presets);
        $known = self::all();
        foreach ($presets as $key => $preset) {
            $presets[$key]['fields'] = array_values(array_filter((array) ($preset['fields'] ?? []), static function ($field) use ($known) {
                return isset($known[$field]);
            }));
        }
        return $presets;
    }

    /**
     * Validate and normalize one entered value.
     *
     * @param mixed $value
     * @throws \InvalidArgumentException With a reader-facing message.
     */
    public static function clean(string $key, $value): string
    {
        $field = self::all()[$key] ?? ['type' => 'text', 'label' => $key];
        $label = (string) $field['label'];
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim(sanitize_text_field((string) $value));
        if ($value === '') {
            return '';
        }
        $value = mb_substr($value, 0, 300);
        switch ($field['type']) {
            case 'number':
                if (!preg_match('/^\d{1,9}$/', $value)) {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(sprintf(__('%s must be a whole number.', 'stackless-lite'), $label));
                }
                return (string) (int) $value;
            case 'date':
                $time = strtotime($value);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $time === false || gmdate('Y-m-d', $time) !== $value) {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(sprintf(__('%s must be a valid date (YYYY-MM-DD).', 'stackless-lite'), $label));
                }
                return $value;
            case 'url':
                $url = esc_url_raw($value, ['http', 'https']);
                if ($url === '') {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(sprintf(__('%s must be a web address starting with https://.', 'stackless-lite'), $label));
                }
                return $url;
            case 'select':
                if (!in_array($value, (array) ($field['options'] ?? []), true)) {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(sprintf(__('Choose one of the options for %s.', 'stackless-lite'), $label));
                }
                return $value;
            case 'list':
                return implode(', ', array_slice(array_values(array_filter(array_map('trim', explode(',', $value)), static function (string $item): bool {
                    return $item !== '';
                })), 0, 30));
            case 'gtin':
                $digits = (string) preg_replace('/[\s-]/', '', $value);
                if (!self::validGtin($digits)) {
                    throw new \InvalidArgumentException(__('The GTIN must be 8, 12, 13 or 14 digits with a valid check digit.', 'stackless-lite'));
                }
                return $digits;
            case 'isbn13':
            case 'isbn10':
                $isbn = self::isbn13($value);
                if ($isbn === '') {
                    /* translators: %s: field name */
                    throw new \InvalidArgumentException(sprintf(__('%s is not a valid ISBN. Check the digits, including the last one.', 'stackless-lite'), $label));
                }
                if ($field['type'] === 'isbn13') {
                    return $isbn;
                }
                $isbn10 = self::isbn10($isbn);
                if ($isbn10 === '') {
                    throw new \InvalidArgumentException(__('ISBNs starting with 979 have no ISBN-10. Enter it as the ISBN-13 instead.', 'stackless-lite'));
                }
                return $isbn10;
        }
        return $value;
    }

    public static function validGtin(string $digits): bool
    {
        if (!preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $digits)) {
            return false;
        }
        $sum = 0;
        $body = substr($digits, 0, -1);
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }
        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /** A valid ISBN-13 for an ISBN-10 or ISBN-13 entry, or '' when it is not valid. */
    public static function isbn13(string $value): string
    {
        $raw = strtoupper((string) preg_replace('/[\s-]/', '', $value));
        if (preg_match('/^\d{13}$/', $raw)) {
            return (strpos($raw, '978') === 0 || strpos($raw, '979') === 0) && self::validGtin($raw) ? $raw : '';
        }
        if (!preg_match('/^\d{9}[\dX]$/', $raw)) {
            return '';
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($raw[$i] === 'X' ? 10 : (int) $raw[$i]) * (10 - $i);
        }
        if ($sum % 11 !== 0) {
            return '';
        }
        $body = '978' . substr($raw, 0, 9);
        $total = 0;
        for ($i = 0; $i < 12; $i++) {
            $total += (int) $body[$i] * ($i % 2 ? 3 : 1);
        }
        return $body . ((10 - $total % 10) % 10);
    }

    /** ISBN-10 exists only for 978-prefixed ISBN-13s. */
    public static function isbn10(string $isbn13): string
    {
        if (!preg_match('/^978(\d{9})\d$/', $isbn13, $match)) {
            return '';
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $match[1][$i] * (10 - $i);
        }
        $check = (11 - $sum % 11) % 11;
        return $match[1] . ($check === 10 ? 'X' : (string) $check);
    }
}
