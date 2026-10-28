<?php

namespace Stackless\Modules\WooCommerceOptimizer;

defined('ABSPATH') || exit;

/**
 * Pure decisions for the optimizer: which assets belong to WooCommerce, what counts as store
 * markup, and which resource hints to drop. No WordPress calls, so it is unit tested directly.
 */
final class Rules
{
    /** Classic WooCommerce styles that only matter on store pages. */
    public const STORE_STYLES = ['woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-blocktheme'];

    /** Classic WooCommerce scripts that only matter on store pages. Order attribution is kept: it must see the landing page. */
    public const STORE_SCRIPTS = ['woocommerce', 'wc-add-to-cart', 'wc-cart-fragments', 'wc-jquery-blockui', 'jquery-blockui', 'wc-js-cookie', 'js-cookie'];

    public const ATTRIBUTION_SCRIPTS = ['sourcebuster-js', 'wc-order-attribution'];

    public const PASSWORD_SCRIPTS = ['wc-password-strength-meter'];

    /** WooCommerce front-end scripts that initialise on DOM ready and can run deferred. */
    public const DEFERRABLE_SCRIPTS = [
        'woocommerce', 'wc-add-to-cart', 'wc-add-to-cart-variation', 'wc-cart-fragments', 'wc-single-product',
        'wc-flexslider', 'wc-photoswipe', 'wc-photoswipe-ui-default', 'wc-zoom', 'wc-jquery-blockui', 'wc-js-cookie',
        'sourcebuster-js', 'wc-order-attribution', 'wc-password-strength-meter', 'wc-country-select', 'wc-address-i18n',
    ];

    /** Shortcodes that render store UI. */
    public const SHORTCODES = [
        'products', 'product', 'product_page', 'product_category', 'product_categories', 'add_to_cart', 'add_to_cart_url',
        'recent_products', 'featured_products', 'sale_products', 'best_selling_products', 'top_rated_products', 'related_products',
        'shop_messages', 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'woocommerce_order_tracking',
    ];

    public const CART_FRAGMENTS_MODES = ['keep', 'non_store', 'empty', 'all'];

    /** Does post content contain WooCommerce or Stackless blocks or shortcodes? */
    public static function contentHasStoreMarkup(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        if (strpos($content, '<!-- wp:woocommerce/') !== false || strpos($content, '<!-- wp:stackless/') !== false) {
            return true;
        }
        if (strpos($content, '[') === false) {
            return false;
        }
        if (preg_match('/\[stackless_[a-z_]+/', $content)) {
            return true;
        }
        return (bool) preg_match('/\[(' . implode('|', array_map('preg_quote', self::SHORTCODES)) . ')[\s\]\/]/', $content);
    }

    /**
     * Post IDs referenced by synced patterns (`<!-- wp:block {"ref":12} /-->`) in content.
     *
     * @return int[]
     */
    public static function syncedPatternRefs(string $content): array
    {
        if (strpos($content, 'wp:block') === false && strpos($content, 'wp:core/block') === false) {
            return [];
        }
        // Block attributes are JSON with ">" escaped, so [^>] stays inside the comment.
        if (!preg_match_all('/<!--\s+wp:(?:core\/)?block\s+\{[^>]*?"ref"\s*:\s*(\d+)/', $content, $matches)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $matches[1]))));
    }

    /**
     * Like contentHasStoreMarkup(), but follows synced pattern references through $load
     * (post ID => that pattern's content), recursively, each pattern at most once.
     *
     * @param callable(int): string $load
     * @param array<int, bool> $seen
     */
    public static function contentHasStoreMarkupDeep(string $content, callable $load, int $depth = 5, array &$seen = []): bool
    {
        if (self::contentHasStoreMarkup($content)) {
            return true;
        }
        if ($depth <= 0) {
            return false;
        }
        foreach (self::syncedPatternRefs($content) as $ref) {
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            if (self::contentHasStoreMarkupDeep((string) $load($ref), $load, $depth - 1, $seen)) {
                return true;
            }
        }
        return false;
    }

    /** Is this a block that needs WooCommerce assets around it? */
    public static function isStoreBlock(string $blockName): bool
    {
        return strpos($blockName, 'woocommerce/') === 0 || strpos($blockName, 'stackless/') === 0;
    }

    /** Should cart fragments be removed for this request? */
    public static function dropCartFragments(string $mode, bool $isStorePage, bool $cartEmpty): bool
    {
        switch ($mode) {
            case 'all':
                return true;
            case 'non_store':
                return !$isStorePage;
            case 'empty':
                return $cartEmpty;
            default:
                return false;
        }
    }

    /**
     * Drop WooCommerce's cart/checkout prefetch and prerender hints.
     *
     * @param array    $urls       Hints passed to wp_resource_hints.
     * @param string   $relation   prefetch, prerender, preconnect, dns-prefetch.
     * @param string[] $scriptBases URL prefixes whose script prefetches belong to WooCommerce or core packages.
     * @param string[] $pageUrls   Cart and checkout page URLs.
     */
    public static function filterResourceHints(array $urls, string $relation, array $scriptBases, array $pageUrls): array
    {
        if ($relation === 'prefetch') {
            return array_values(array_filter($urls, static function ($hint) use ($scriptBases) {
                if (!is_array($hint) || ($hint['as'] ?? '') !== 'script' || !isset($hint['href'])) {
                    return true;
                }
                foreach ($scriptBases as $base) {
                    if ($base !== '' && strpos((string) $hint['href'], $base) === 0) {
                        return false;
                    }
                }
                return true;
            }));
        }
        if ($relation === 'prerender') {
            $pages = array_filter(array_map([self::class, 'normalizeUrl'], $pageUrls));
            return array_values(array_filter($urls, static function ($hint) use ($pages) {
                $href = is_array($hint) ? (string) ($hint['href'] ?? '') : (string) $hint;
                return !in_array(self::normalizeUrl($href), $pages, true);
            }));
        }
        return $urls;
    }

    /** Retention in seconds from a day count, or null to keep Action Scheduler's default. */
    public static function retentionSeconds($days, int $daySeconds = 86400): ?int
    {
        $days = (int) $days;
        if ($days < 1 || $days === 31) {
            return null;
        }
        return min($days, 365) * $daySeconds;
    }

    /**
     * Guest session lifetime as [expiring, expiration] seconds, or null for WooCommerce's default (48 hours).
     *
     * @return array{0:int,1:int}|null
     */
    public static function sessionLifetime($hours): ?array
    {
        $hours = (int) $hours;
        if ($hours < 1 || $hours === 48) {
            return null;
        }
        $hours = min($hours, 24 * 30);
        $expiration = $hours * 3600;
        // WooCommerce extends a session once it is within the "expiring" window.
        return [max(1800, $expiration - min(3600, (int) ($expiration / 2))), $expiration];
    }

    private static function normalizeUrl(string $url): string
    {
        return rtrim(strtolower((string) preg_replace('#^https?:#i', '', $url)), '/');
    }
}

