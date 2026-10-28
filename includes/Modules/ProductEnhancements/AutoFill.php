<?php

namespace Stackless\Modules\ProductEnhancements;

defined('ABSPATH') || exit;

/**
 * Values WooCommerce already stores. The merchant's own entry always wins over these.
 */
final class AutoFill
{
    /** @var array<int, array<string, string>> */
    private static $cache = [];

    /**
     * @return array<string, string> auto key => display value
     */
    public static function values(\WC_Product $product): array
    {
        $id = $product->get_id();
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $parent = $product;
        if ($product->is_type('variation') && $product->get_parent_id()) {
            $maybe = wc_get_product($product->get_parent_id());
            $parent = $maybe instanceof \WC_Product ? $maybe : $product;
        }
        $values = [];

        if (taxonomy_exists('product_brand')) {
            $brands = wp_get_post_terms($parent->get_id(), 'product_brand', ['fields' => 'names']);
            if (is_array($brands) && $brands) {
                $values['brand'] = implode(', ', array_map('strval', $brands));
            }
        }

        $gtin = (string) $product->get_global_unique_id();
        if ($gtin === '' && $parent !== $product) {
            $gtin = (string) $parent->get_global_unique_id();
        }
        $gtin = (string) preg_replace('/[\s-]/', '', $gtin);
        if ($gtin !== '' && Fields::validGtin($gtin)) {
            $values['gtin'] = $gtin;
            $isbn = Fields::isbn13($gtin);
            if ($isbn !== '') {
                $values['isbn13'] = $isbn;
                $isbn10 = Fields::isbn10($isbn);
                if ($isbn10 !== '') {
                    $values['isbn10'] = $isbn10;
                }
            }
        }

        if ($product->has_weight()) {
            $values['weight'] = self::number($product->get_weight()) . ' ' . get_option('woocommerce_weight_unit', 'kg');
        }
        if ($product->has_dimensions()) {
            $sides = [$product->get_length(), $product->get_width(), $product->get_height()];
            $sides = array_filter($sides, static function ($side) {
                return $side !== '' && is_numeric($side) && (float) $side > 0;
            });
            if (count($sides) === 3) {
                $values['dimensions'] = implode(' × ', array_map([self::class, 'number'], $sides)) . ' ' . get_option('woocommerce_dimension_unit', 'cm');
            }
        }

        if ($product->is_downloadable()) {
            $downloads = $product->get_downloads();
            if ($downloads) {
                $formats = [];
                $size = 0;
                foreach ($downloads as $download) {
                    if (!$download instanceof \WC_Product_Download) {
                        continue;
                    }
                    $path = (string) wp_parse_url($download->get_file(), PHP_URL_PATH);
                    $extension = strtoupper((string) pathinfo($path, PATHINFO_EXTENSION));
                    if ($extension !== '' && strlen($extension) <= 6) {
                        $formats[$extension] = true;
                    }
                    $size += self::fileSize($download->get_file());
                }
                if ($formats) {
                    $values['file_format'] = implode(', ', array_keys($formats));
                }
                if ($size > 0) {
                    $values['file_size'] = (string) size_format($size, $size >= 1048576 ? 1 : 0);
                }
                $values['file_count'] = (string) count($downloads);
                $values['book_format'] = __('Ebook', 'stackless-lite');
            }
            $modified = $parent->get_date_modified();
            if ($modified instanceof \WC_DateTime) {
                $values['updated'] = $modified->date('Y-m-d');
            }
        }

        $language = get_bloginfo('language');
        if ($language && function_exists('locale_get_display_language')) {
            $name = locale_get_display_language(str_replace('-', '_', $language), get_locale());
            if ($name) {
                $values['language'] = $name;
            }
        }

        /**
         * Filters the values auto-filled from WooCommerce data.
         *
         * @param array<string, string> $values
         * @param \WC_Product           $product
         */
        return self::$cache[$id] = (array) apply_filters('stackless_product_enhancements_auto_values', $values, $product);
    }

    public static function flush(int $productId): void
    {
        unset(self::$cache[$productId]);
    }

    /** Size of a download stored in the media library or under uploads; 0 when unknown (remote files). */
    private static function fileSize(string $file): int
    {
        if ($file === '') {
            return 0;
        }
        $attachment = attachment_url_to_postid($file);
        $path = $attachment ? get_attached_file($attachment) : '';
        if (!$path) {
            $uploads = wp_get_upload_dir();
            if (!empty($uploads['baseurl']) && strpos($file, (string) $uploads['baseurl']) === 0) {
                $path = (string) $uploads['basedir'] . substr($file, strlen((string) $uploads['baseurl']));
            }
        }
        if ($path && strpos(wp_normalize_path($path), '..') === false && is_readable($path)) {
            $size = filesize($path);
            return $size === false ? 0 : (int) $size;
        }
        return 0;
    }

    /** @param mixed $value */
    public static function number($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
