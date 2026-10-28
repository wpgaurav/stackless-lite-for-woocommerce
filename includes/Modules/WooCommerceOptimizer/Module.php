<?php

namespace Stackless\Modules\WooCommerceOptimizer;

defined('ABSPATH') || exit;

/**
 * WooCommerce Optimizer: trims what WooCommerce loads on pages that do not need it,
 * defers what can wait, and keeps its background tables lean.
 */
final class Module extends \Stackless\Core\Module
{
    /** @var bool Set once a WooCommerce or Stackless block renders in this request. */
    private $renderedStoreBlock = false;

    /** @var bool|null */
    private $storePage;

    public function id(): string
    {
        return 'woocommerce_optimizer';
    }

    public function title(): string
    {
        return __('WooCommerce Optimizer', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Load WooCommerce scripts and styles only where they are used, stop cart and checkout prefetching, defer store scripts and keep sessions and logs lean.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'store';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_woocommerce_optimizer_';
        return [
            ['type' => 'title', 'id' => $p . 'frontend', 'title' => __('Storefront', 'stackless-lite'), 'desc' => __('Stackless already serves its own files minified and deferred. These settings tune what WooCommerce itself loads. Clear your page cache after changing them.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'remove_prefetch', 'title' => __('Cart and checkout prefetch', 'stackless-lite'), 'default' => 'yes', 'desc' => __('Stop prefetching the Cart and Checkout block scripts on every page', 'stackless-lite'), 'desc_tip' => __('Once the cart has items, WooCommerce adds about 50 prefetch links to every page so checkout opens faster. Shoppers download them whether or not they check out.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'trim_assets', 'title' => __('Store assets', 'stackless-lite'), 'default' => 'no', 'desc' => __('Load WooCommerce scripts and styles only on store pages', 'stackless-lite'), 'desc_tip' => __('Store pages are the shop, products, categories, cart, checkout, account and any page with a WooCommerce or Stackless block or shortcode.', 'stackless-lite')],
            ['type' => 'textarea', 'id' => $p . 'keep_pages', 'title' => __('Always treat as store pages', 'stackless-lite'), 'default' => '', 'desc' => __('Page IDs or URL paths, one per line (for example 42 or /landing/). Use for pages whose store widgets come from a page builder.', 'stackless-lite'), 'css' => 'min-width:300px;height:70px'],
            ['type' => 'select', 'id' => $p . 'cart_fragments', 'title' => __('Cart fragments', 'stackless-lite'), 'default' => 'keep', 'class' => 'wc-enhanced-select', 'options' => [
                'keep' => __('Keep (WooCommerce default)', 'stackless-lite'),
                'non_store' => __('Remove outside store pages', 'stackless-lite'),
                'empty' => __('Remove while the cart is empty', 'stackless-lite'),
                'all' => __('Remove everywhere', 'stackless-lite'),
            ], 'desc' => __('Cart fragments refresh the classic mini cart with an AJAX request on page load. Block themes and the Mini-Cart block do not use them.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'defer_scripts', 'title' => __('Defer WooCommerce scripts', 'stackless-lite'), 'default' => 'yes', 'desc' => __('Load WooCommerce scripts with defer outside cart and checkout', 'stackless-lite'), 'desc_tip' => __('WordPress keeps a script blocking when another plugin needs it that way, so this never breaks load order.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'password_meter', 'title' => __('Password strength meter', 'stackless-lite'), 'default' => 'no', 'desc' => __('Remove the password strength meter (about 400 KB of JavaScript) from account and checkout pages', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'order_attribution', 'title' => __('Order attribution', 'stackless-lite'), 'default' => 'no', 'desc' => __('Turn off order attribution tracking and its two scripts', 'stackless-lite'), 'desc_tip' => __('Orders stop recording their traffic source (Origin column and order screen box).', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'generator_tag', 'title' => __('Generator tag', 'stackless-lite'), 'default' => 'yes', 'desc' => __('Remove the WooCommerce version meta tag from page source', 'stackless-lite')],
            ['type' => 'sectionend', 'id' => $p . 'frontend'],

            ['type' => 'title', 'id' => $p . 'admin', 'title' => __('Admin', 'stackless-lite'), 'desc' => ''],
            ['type' => 'checkbox', 'id' => $p . 'marketplace', 'title' => __('Marketplace suggestions', 'stackless-lite'), 'default' => 'no', 'desc' => __('Hide WooCommerce.com extension suggestions and promotions', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'inbox', 'title' => __('Remote inbox notes', 'stackless-lite'), 'default' => 'no', 'desc' => __('Stop fetching remote tips and notes into the WooCommerce inbox', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'dashboard_widgets', 'title' => __('Dashboard widgets', 'stackless-lite'), 'default' => 'no', 'desc' => __('Remove the WooCommerce status and recent reviews widgets from the WordPress dashboard', 'stackless-lite'), 'desc_tip' => __('Each widget runs its own report queries every time the dashboard loads.', 'stackless-lite')],
            ['type' => 'checkbox', 'id' => $p . 'helper_notices', 'title' => __('Connect notices', 'stackless-lite'), 'default' => 'no', 'desc' => __('Hide WooCommerce.com connection and subscription notices', 'stackless-lite')],
            ['type' => 'sectionend', 'id' => $p . 'admin'],

            ['type' => 'title', 'id' => $p . 'housekeeping', 'title' => __('Housekeeping', 'stackless-lite'), 'desc' => __('Smaller tables mean faster queries for orders, carts and scheduled actions.', 'stackless-lite')],
            ['type' => 'number', 'id' => $p . 'as_retention', 'title' => __('Scheduled action logs', 'stackless-lite'), 'default' => '31', 'desc' => __('Days to keep completed and cancelled scheduled actions. WooCommerce keeps 31; 7 is plenty for most stores.', 'stackless-lite'), 'custom_attributes' => ['min' => 1, 'max' => 365, 'step' => 1]],
            ['type' => 'number', 'id' => $p . 'session_hours', 'title' => __('Guest cart lifetime', 'stackless-lite'), 'default' => '48', 'desc' => __('Hours a guest cart session is kept. WooCommerce keeps 48. Logged-in customers keep their saved cart either way.', 'stackless-lite'), 'custom_attributes' => ['min' => 1, 'max' => 720, 'step' => 1]],
            ['type' => 'sectionend', 'id' => $p . 'housekeeping'],
        ];
    }

    public function boot(): void
    {
        if ($this->enabled('remove_prefetch', 'yes')) {
            add_filter('wp_resource_hints', [$this, 'filterResourceHints'], 20, 2);
        }
        if ($this->enabled('generator_tag', 'yes')) {
            add_action('init', [$this, 'removeGeneratorTag']);
        }
        if ($this->enabled('order_attribution')) {
            add_filter('wc_order_attribution_allow_tracking', '__return_false');
        }

        if (!is_admin()) {
            add_filter('render_block', [$this, 'trackBlock'], 10, 2);
            add_action('wp_enqueue_scripts', [$this, 'trimAssets'], 999);
            if ($this->enabled('defer_scripts', 'yes')) {
                add_action('wp_print_scripts', [$this, 'deferScripts'], 2);
                add_action('wp_print_footer_scripts', [$this, 'deferScripts'], 2);
            }
        }

        if ($this->enabled('marketplace')) {
            add_filter('woocommerce_allow_marketplace_suggestions', '__return_false');
        }
        if ($this->enabled('inbox')) {
            add_filter('woocommerce_admin_features', [$this, 'removeInboxFeature'], 20);
        }
        if ($this->enabled('dashboard_widgets')) {
            add_action('wp_dashboard_setup', [$this, 'removeDashboardWidgets'], 99);
        }
        if ($this->enabled('helper_notices')) {
            add_filter('woocommerce_helper_suppress_admin_notices', '__return_true');
        }

        $retention = Rules::retentionSeconds($this->option('as_retention', 31), DAY_IN_SECONDS);
        if ($retention !== null) {
            add_filter('action_scheduler_retention_period', static function () use ($retention) {
                return $retention;
            });
        }
        $session = Rules::sessionLifetime($this->option('session_hours', 48));
        if ($session !== null) {
            add_filter('wc_session_expiring', static function () use ($session) {
                return $session[0];
            });
            add_filter('wc_session_expiration', static function () use ($session) {
                return $session[1];
            });
        }
    }

    /* ---------------------------------------------------------------- storefront */

    /**
     * @param array  $urls
     * @param string $relation
     */
    public function filterResourceHints($urls, $relation): array
    {
        if (!is_array($urls) || !function_exists('wc_get_page_id')) {
            return is_array($urls) ? $urls : [];
        }
        $bases = [includes_url('js/dist/')];
        if (defined('WC_PLUGIN_FILE')) {
            $bases[] = plugins_url('assets/client/', WC_PLUGIN_FILE);
        }
        $pages = [];
        foreach (['cart', 'checkout'] as $page) {
            $id = wc_get_page_id($page);
            if ($id > 0) {
                $pages[] = (string) get_permalink($id);
            }
        }
        return Rules::filterResourceHints($urls, (string) $relation, $bases, $pages);
    }

    public function removeGeneratorTag(): void
    {
        remove_filter('get_the_generator_html', 'wc_generator_tag', 10);
        remove_filter('get_the_generator_xhtml', 'wc_generator_tag', 10);
    }

    /**
     * @param string $content
     * @param array  $block
     * @return string
     */
    public function trackBlock($content, $block)
    {
        if (!$this->renderedStoreBlock && is_array($block) && Rules::isStoreBlock((string) ($block['blockName'] ?? ''))) {
            $this->renderedStoreBlock = true;
        }
        return $content;
    }

    public function trimAssets(): void
    {
        $store = $this->isStorePage();

        if ($this->enabled('trim_assets') && !$store) {
            foreach (Rules::STORE_STYLES as $handle) {
                wp_dequeue_style($handle);
            }
            foreach (Rules::STORE_SCRIPTS as $handle) {
                wp_dequeue_script($handle);
            }
        }

        $cart = function_exists('WC') ? WC()->cart : null;
        $empty = !$cart instanceof \WC_Cart || $cart->is_empty();
        if (Rules::dropCartFragments((string) $this->option('cart_fragments', 'keep'), $store, $empty)) {
            wp_dequeue_script('wc-cart-fragments');
        }

        if ($this->enabled('password_meter')) {
            foreach (Rules::PASSWORD_SCRIPTS as $handle) {
                wp_dequeue_script($handle);
            }
        }
        if ($this->enabled('order_attribution')) {
            foreach (Rules::ATTRIBUTION_SCRIPTS as $handle) {
                wp_dequeue_script($handle);
            }
        }
    }

    public function deferScripts(): void
    {
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout())) {
            return;
        }
        $scripts = wp_scripts();
        /**
         * Filters the WooCommerce script handles the optimizer defers.
         *
         * @param string[] $handles
         */
        $handles = (array) apply_filters('stackless_woocommerce_optimizer_defer_handles', Rules::DEFERRABLE_SCRIPTS);
        foreach ($handles as $handle) {
            if (isset($scripts->registered[$handle]) && !$scripts->get_data($handle, 'strategy')) {
                $scripts->add_data($handle, 'strategy', 'defer');
            }
        }
    }

    /** Shop, product, cart, checkout, account, or any page that renders store blocks or shortcodes. */
    public function isStorePage(): bool
    {
        // Only a positive answer is final: a store block can still render later in the request.
        if ($this->storePage === true) {
            return true;
        }
        $store = $this->renderedStoreBlock
            || (function_exists('is_woocommerce') && is_woocommerce())
            || (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout())
            || (function_exists('is_account_page') && is_account_page())
            || is_active_widget(false, false, 'woocommerce_widget_cart')
            || $this->isKeptPage();

        if (!$store && is_singular()) {
            $post = get_queried_object();
            $store = $post instanceof \WP_Post && Rules::contentHasStoreMarkupDeep((string) $post->post_content, [$this, 'syncedPatternContent']);
        }

        /**
         * Filters whether the current request is a store page that needs WooCommerce assets.
         *
         * @param bool $store
         */
        $store = (bool) apply_filters('stackless_woocommerce_optimizer_is_store_page', $store);
        if ($store) {
            $this->storePage = true;
        }
        return $store;
    }

    /** Content of a published synced pattern (wp_block post), or '' for anything else. */
    public function syncedPatternContent(int $id): string
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== 'wp_block' || $post->post_status !== 'publish') {
            return '';
        }
        return (string) $post->post_content;
    }

    private function isKeptPage(): bool
    {
        $lines = preg_split('/[\r\n]+/', (string) $this->option('keep_pages', '')) ?: [];
        $id = (int) get_queried_object_id();
        $path = '/' . trim((string) wp_parse_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '', PHP_URL_PATH), '/') . '/';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (ctype_digit($line) ? $id === (int) $line : $path === '/' . trim($line, '/') . '/') {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------- admin */

    /**
     * @param array $features
     * @return array
     */
    public function removeInboxFeature($features)
    {
        return is_array($features) ? array_values(array_diff($features, ['remote-inbox-notifications'])) : $features;
    }

    public function removeDashboardWidgets(): void
    {
        remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');
        remove_meta_box('woocommerce_dashboard_recent_reviews', 'dashboard', 'normal');
    }

    private function enabled(string $key, string $default = 'no'): bool
    {
        return $this->option($key, $default) === 'yes';
    }
}
