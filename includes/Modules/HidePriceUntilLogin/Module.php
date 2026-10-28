<?php

namespace Stackless\Modules\HidePriceUntilLogin;

defined('ABSPATH') || exit;

/**
 * Hide Price Until Login: guests see "Log in to see prices" and cannot buy.
 *
 * Applies to every product, chosen categories, or products opted in one by one. Hidden
 * products are non-purchasable server-side for guests, so classic forms, AJAX, add-to-cart
 * links and the Store API all refuse them; prices are removed from price HTML, variation
 * JSON, Store API product responses and structured data.
 */
final class Module extends \Stackless\Core\Module
{
    public const META = '_stackless_hide_price_until_login';
    private const HANDLE = 'stackless-hide_price_until_login';

    /** @var array<int, bool> viewer result per user id, so a user switch mid-request (login, wp_set_current_user) is honoured */
    private $viewerSees = [];

    /** @var int[]|null */
    private $hiddenCategories;

    /** @var array<int, bool> */
    private $cache = [];

    /** @var bool */
    private $styled = false;

    public function id(): string
    {
        return 'hide_price_until_login';
    }

    public function title(): string
    {
        return __('Hide Price Until Login', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Hide prices and buying from guests, store-wide, by category or per product, with a login link in place of the price.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'catalog';
    }

    public function settingsFields(): array
    {
        $p = 'stackless_hide_price_until_login_';
        $categories = [];
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term) {
                    $categories[(string) $term->term_id] = $term->name;
                }
            }
        }
        $roles = function_exists('wp_roles') ? wp_roles()->get_names() : [];
        return [
            ['type' => 'title', 'id' => $p . 'section', 'title' => __('Who and what', 'stackless-lite'), 'desc' => __('Guests never see hidden prices and cannot add those products to the cart. Each product can also be set to always or never hide under Product data → Advanced.', 'stackless-lite')],
            ['type' => 'select', 'id' => $p . 'scope', 'title' => __('Hide prices on', 'stackless-lite'), 'default' => Scope::ALL, 'class' => 'wc-enhanced-select', 'options' => [
                Scope::ALL => __('All products', 'stackless-lite'),
                Scope::CATEGORIES => __('Products in selected categories', 'stackless-lite'),
                Scope::SELECTED => __('Only products set to hide', 'stackless-lite'),
            ]],
            ['type' => 'multiselect', 'id' => $p . 'categories', 'title' => __('Categories', 'stackless-lite'), 'default' => [], 'class' => 'wc-enhanced-select', 'options' => $categories, 'desc' => __('Used when hiding by category. Subcategories are included.', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'multiselect', 'id' => $p . 'roles', 'title' => __('Roles that see prices', 'stackless-lite'), 'default' => [], 'class' => 'wc-enhanced-select', 'options' => $roles, 'desc' => __('Leave empty to show prices to every logged-in customer.', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'sectionend', 'id' => $p . 'section'],

            ['type' => 'title', 'id' => $p . 'display', 'title' => __('What guests see', 'stackless-lite')],
            ['type' => 'text', 'id' => $p . 'text', 'title' => __('Price text', 'stackless-lite'), 'default' => __('Log in to see prices', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'checkbox', 'id' => $p . 'link', 'title' => __('Login link', 'stackless-lite'), 'desc' => __('Link the price text to the login page and return the shopper to the product afterwards', 'stackless-lite'), 'default' => 'yes'],
            ['type' => 'select', 'id' => $p . 'login_page', 'title' => __('Login page', 'stackless-lite'), 'default' => 'myaccount', 'class' => 'wc-enhanced-select', 'options' => [
                'myaccount' => __('WooCommerce My Account page', 'stackless-lite'),
                'wp-login' => __('WordPress login screen', 'stackless-lite'),
            ]],
            ['type' => 'text', 'id' => $p . 'button_text', 'title' => __('Button text', 'stackless-lite'), 'default' => __('Log in to buy', 'stackless-lite'), 'desc' => __('Replaces the add to cart button on hidden products. Leave blank to remove the button.', 'stackless-lite'), 'css' => 'min-width:300px'],
            ['type' => 'sectionend', 'id' => $p . 'display'],
        ];
    }

    public function boot(): void
    {
        // Prices.
        add_filter('woocommerce_get_price_html', [$this, 'filterPriceHtml'], 9999, 2);
        add_filter('woocommerce_available_variation', [$this, 'filterVariationData'], 9999, 3);
        add_filter('woocommerce_sale_flash', [$this, 'filterSaleFlash'], 9999, 3);
        add_filter('render_block_woocommerce/product-sale-badge', [$this, 'filterSaleBadgeBlock'], 20, 3);
        add_filter('woocommerce_structured_data_product', [$this, 'filterStructuredData'], 9999, 2);
        add_filter('rest_request_before_callbacks', [$this, 'filterStoreApiRequest'], 20, 3);
        add_filter('rest_request_after_callbacks', [$this, 'filterStoreApiResponse'], 20, 3);

        // Purchasing.
        add_filter('woocommerce_is_purchasable', [$this, 'filterPurchasable'], 9999, 2);
        add_filter('woocommerce_variation_is_purchasable', [$this, 'filterPurchasable'], 9999, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateAddToCart'], 5, 4);

        // Buttons.
        add_filter('woocommerce_product_add_to_cart_text', [$this, 'filterButtonText'], 9999, 2);
        add_filter('woocommerce_product_add_to_cart_url', [$this, 'filterButtonUrl'], 9999, 2);
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'filterLoopLink'], 9999, 2);
        add_action('woocommerce_single_product_summary', [$this, 'swapSingleAddToCart'], 29);
        add_filter('render_block_woocommerce/add-to-cart-form', [$this, 'filterAddToCartBlock'], 20, 3);
        add_filter('render_block_woocommerce/add-to-cart-with-options', [$this, 'filterAddToCartBlock'], 20, 3);

        // Login round trip.
        add_action('woocommerce_login_form_end', [$this, 'loginRedirectField']);
        add_shortcode('stackless_login_to_see_price', [$this, 'shortcode']);

        // Product editor.
        add_action('woocommerce_product_options_advanced', [$this, 'renderProductField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductField']);
    }

    /* ---------------------------------------------------------------------
     * Rules
     * ------------------------------------------------------------------- */

    public function viewerSeesPrices(): bool
    {
        $userId = get_current_user_id();
        if (!isset($this->viewerSees[$userId])) {
            $user = wp_get_current_user();
            $sees = Scope::viewerSeesPrices(is_user_logged_in(), $user instanceof \WP_User ? (array) $user->roles : [], (array) $this->option('roles', []));
            /**
             * Filters whether the current visitor may see hidden prices.
             *
             * @param bool $sees
             */
            $this->viewerSees[$userId] = (bool) apply_filters('stackless_hide_price_until_login_viewer_sees_prices', $sees);
        }
        return $this->viewerSees[$userId];
    }

    /** Whether the product is covered by the rule, regardless of the viewer. */
    public function productHidden(\WC_Product $product): bool
    {
        $owner = $product;
        if ($product->is_type('variation') && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                $owner = $parent;
            }
        }
        $id = $owner->get_id();
        if (!isset($this->cache[$id])) {
            $scope = Scope::normalizeScope((string) $this->option('scope', Scope::ALL));
            $this->cache[$id] = Scope::productHidden(
                $scope,
                (string) $owner->get_meta(self::META),
                $scope === Scope::CATEGORIES ? $owner->get_category_ids() : [],
                $scope === Scope::CATEGORIES ? $this->hiddenCategories() : []
            );
        }
        return $this->cache[$id];
    }

    /** Hidden for this viewer right now. */
    public function hides($product): bool
    {
        return $product instanceof \WC_Product && !$this->viewerSeesPrices() && $this->productHidden($product);
    }

    /** @return int[] */
    private function hiddenCategories(): array
    {
        if ($this->hiddenCategories === null) {
            $ids = array_filter(array_map('absint', (array) $this->option('categories', [])));
            $all = $ids;
            foreach ($ids as $id) {
                $children = get_term_children($id, 'product_cat');
                if (is_array($children)) {
                    $all = array_merge($all, array_map('intval', $children));
                }
            }
            $this->hiddenCategories = array_values(array_unique($all));
        }
        return $this->hiddenCategories;
    }

    /* ---------------------------------------------------------------------
     * Login URL and markup
     * ------------------------------------------------------------------- */

    public function loginUrl(?\WC_Product $product = null): string
    {
        $return = $this->returnUrl($product);
        if ($this->option('login_page', 'myaccount') === 'myaccount' && function_exists('wc_get_page_permalink') && wc_get_page_id('myaccount') > 0) {
            return add_query_arg('redirect_to', rawurlencode($return), wc_get_page_permalink('myaccount'));
        }
        return wp_login_url($return);
    }

    private function returnUrl(?\WC_Product $product): string
    {
        $isRest = (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax();
        if ($product && ($isRest || !is_singular() || is_singular('product'))) {
            $parentId = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $link = get_permalink($parentId);
            if ($link) {
                return $link;
            }
        }
        if (!$isRest && isset($_SERVER['REQUEST_URI'])) {
            $uri = esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']));
            return wp_validate_redirect(home_url($uri), home_url('/'));
        }
        return home_url('/');
    }

    public function text(): string
    {
        $text = trim((string) $this->option('text', __('Log in to see prices', 'stackless-lite')));
        return $text !== '' ? $text : __('Log in to see prices', 'stackless-lite');
    }

    public function priceHtml(?\WC_Product $product = null): string
    {
        $this->style();
        $text = esc_html($this->text());
        if ($this->option('link', 'yes') === 'yes' && $this->isMainProductSummary($product)) {
            $text = sprintf('<a href="%s" class="stackless-login-to-see-price__link">%s</a>', esc_url($this->loginUrl($product)), $text);
        }
        return '<span class="stackless-login-to-see-price">' . $text . '</span>';
    }

    /**
     * Product cards wrap the price in the product link, and browsers cannot nest links, so the
     * sign-in link only goes on the main product's own page. Cards, Store API responses and
     * other products' prices get plain text; their "Log in to buy" button carries the link.
     */
    private function isMainProductSummary(?\WC_Product $product): bool
    {
        if (!$product || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || !function_exists('is_product') || !is_product()) {
            return false;
        }
        if (function_exists('wc_get_loop_prop') && (string) wc_get_loop_prop('name') !== '') {
            return false;
        }
        $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        return $id === (int) get_queried_object_id();
    }

    private function buttonHtml(\WC_Product $product): string
    {
        $text = trim((string) $this->option('button_text', __('Log in to buy', 'stackless-lite')));
        if ($text === '') {
            return '';
        }
        $this->style();
        return sprintf(
            '<p class="stackless-login-to-buy"><a href="%s" class="button stackless-login-to-buy__button%s">%s</a></p>',
            esc_url($this->loginUrl($product)),
            esc_attr(function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''),
            esc_html($text)
        );
    }

    private function style(): void
    {
        if ($this->styled) {
            return;
        }
        $this->styled = true;
        wp_register_style(self::HANDLE, false, [], STACKLESS_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, '.stackless-login-to-see-price{font-weight:600}.stackless-login-to-see-price__link{text-decoration:underline;text-underline-offset:.15em}.stackless-login-to-buy{margin:1em 0}');
    }

    /* ---------------------------------------------------------------------
     * Prices
     * ------------------------------------------------------------------- */

    /**
     * @param string      $html
     * @param \WC_Product $product
     * @return string
     */
    public function filterPriceHtml($html, $product)
    {
        return $this->hides($product) ? $this->priceHtml($product) : $html;
    }

    /**
     * @param array                      $data
     * @param \WC_Product_Variable       $parent
     * @param \WC_Product_Variation|null $variation
     * @return array
     */
    public function filterVariationData($data, $parent, $variation)
    {
        if (!is_array($data) || !$this->hides($variation ?: $parent)) {
            return $data;
        }
        $data['price_html'] = '<span class="price">' . $this->priceHtml($variation instanceof \WC_Product ? $variation : ($parent instanceof \WC_Product ? $parent : null)) . '</span>';
        $data['display_price'] = '';
        $data['display_regular_price'] = '';
        $data['is_purchasable'] = false;
        return $data;
    }

    /**
     * @param string      $html
     * @param mixed       $post
     * @param \WC_Product $product
     * @return string
     */
    public function filterSaleFlash($html, $post = null, $product = null)
    {
        return $this->hides($product) ? '' : $html;
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function filterSaleBadgeBlock($content, $block, $instance = null)
    {
        $id = $instance instanceof \WP_Block ? (int) ($instance->context['postId'] ?? 0) : 0;
        return $id && $this->hides(wc_get_product($id)) ? '' : $content;
    }

    /**
     * @param array       $markup
     * @param \WC_Product $product
     * @return array
     */
    public function filterStructuredData($markup, $product)
    {
        if (is_array($markup) && $this->hides($product)) {
            unset($markup['offers']);
        }
        return $markup;
    }

    /**
     * Store API product responses carry raw prices next to price_html; blank them for hidden products.
     * Runs inside dispatch, so internal requests (block hydration, preloading) are covered too.
     *
     * @param \WP_REST_Response|\WP_Error|mixed $response
     * @param array                            $handler
     * @param \WP_REST_Request                 $request
     * @return mixed
     */
    public function filterStoreApiResponse($response, $handler, $request)
    {
        if (!$response instanceof \WP_REST_Response || !$request instanceof \WP_REST_Request || !$this->isStoreApiProducts($request) || $this->viewerSeesPrices()) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        if (isset($data['id'], $data['prices'])) {
            $data = $this->stripProductPrices($data);
        } elseif (array_key_exists('price_range', $data)) {
            // collection-data: the range of hidden prices is itself a price.
            if ($this->hidesEverything()) {
                $data['price_range'] = null;
            }
        } else {
            // A price filter must not answer "is this hidden product between X and Y?".
            $priceFiltered = $request->get_param('min_price') !== null || $request->get_param('max_price') !== null;
            foreach ($data as $index => $item) {
                if (!is_array($item) || !isset($item['id'], $item['prices'])) {
                    continue;
                }
                if ($priceFiltered && $this->hides(wc_get_product((int) $item['id']))) {
                    unset($data[$index]);
                    continue;
                }
                $data[$index] = $this->stripProductPrices($item);
            }
            $data = array_values($data);
        }
        $response->set_data($data);
        return $response;
    }

    /**
     * When every price is hidden from this viewer, Store API price filters and price ranges are
     * dropped before the query runs, so they cannot be used to probe hidden prices.
     *
     * @param \WP_REST_Response|\WP_Error|mixed $response
     * @param array                            $handler
     * @param \WP_REST_Request                 $request
     * @return mixed
     */
    public function filterStoreApiRequest($response, $handler, $request)
    {
        if ($request instanceof \WP_REST_Request && $this->isStoreApiProducts($request) && !$this->viewerSeesPrices() && $this->hidesEverything()) {
            foreach (['min_price', 'max_price'] as $param) {
                if ($request->get_param($param) !== null) {
                    $request->set_param($param, null);
                }
            }
            if ($request->get_param('calculate_price_range')) {
                $request->set_param('calculate_price_range', false);
            }
        }
        return $response;
    }

    private function isStoreApiProducts(\WP_REST_Request $request): bool
    {
        $route = (string) $request->get_route();
        return strpos($route, '/wc/store') === 0 && strpos($route, '/products') !== false;
    }

    private function hidesEverything(): bool
    {
        return Scope::normalizeScope((string) $this->option('scope', Scope::ALL)) === Scope::ALL;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function stripProductPrices(array $item): array
    {
        $product = wc_get_product((int) $item['id']);
        if (!$this->hides($product)) {
            return $item;
        }
        $prices = (array) $item['prices'];
        foreach (['price', 'regular_price', 'sale_price'] as $key) {
            $prices[$key] = '';
        }
        $prices['price_range'] = null;
        $item['prices'] = (object) $prices;
        $item['on_sale'] = false;
        return $item;
    }

    /* ---------------------------------------------------------------------
     * Purchasing
     * ------------------------------------------------------------------- */

    /**
     * @param bool        $purchasable
     * @param \WC_Product $product
     * @return bool
     */
    public function filterPurchasable($purchasable, $product)
    {
        if (!$purchasable || !$product instanceof \WC_Product) {
            return $purchasable;
        }
        // Admin order editing and cron-created orders are not shoppers.
        if (is_admin() && !wp_doing_ajax()) {
            return $purchasable;
        }
        return $this->hides($product) ? false : $purchasable;
    }

    /**
     * @param bool $passed
     * @param int  $productId
     * @param int  $quantity
     * @param int  $variationId
     * @return bool
     */
    public function validateAddToCart($passed, $productId, $quantity, $variationId = 0)
    {
        if (!$passed) {
            return $passed;
        }
        $product = wc_get_product($variationId ? (int) $variationId : (int) $productId);
        if ($this->hides($product)) {
            wc_add_notice(esc_html__('Please log in to buy this product.', 'stackless-lite'), 'error');
            return false;
        }
        return $passed;
    }

    /* ---------------------------------------------------------------------
     * Buttons
     * ------------------------------------------------------------------- */

    /**
     * @param string      $text
     * @param \WC_Product $product
     * @return string
     */
    public function filterButtonText($text, $product)
    {
        if (!$this->hides($product)) {
            return $text;
        }
        $label = trim((string) $this->option('button_text', __('Log in to buy', 'stackless-lite')));
        return $label !== '' ? $label : $text;
    }

    /**
     * @param string      $url
     * @param \WC_Product $product
     * @return string
     */
    public function filterButtonUrl($url, $product)
    {
        if (!$this->hides($product) || trim((string) $this->option('button_text', 'x')) === '') {
            return $url;
        }
        return $this->loginUrl($product);
    }

    /**
     * @param string      $html
     * @param \WC_Product $product
     * @return string
     */
    public function filterLoopLink($html, $product)
    {
        if ($this->hides($product) && trim((string) $this->option('button_text', __('Log in to buy', 'stackless-lite'))) === '') {
            return '';
        }
        return $html;
    }

    /** Classic single product page: replace the add to cart form with a login button. */
    public function swapSingleAddToCart(): void
    {
        global $product;
        if (!$this->hides($product)) {
            return;
        }
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        add_action('woocommerce_single_product_summary', function () use ($product) {
            echo $this->buttonHtml($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in buttonHtml().
        }, 30);
    }

    /**
     * @param string        $content
     * @param array         $block
     * @param \WP_Block|null $instance
     * @return string
     */
    public function filterAddToCartBlock($content, $block, $instance = null)
    {
        $id = $instance instanceof \WP_Block ? (int) ($instance->context['postId'] ?? 0) : 0;
        if (!$id) {
            global $product;
            $id = $product instanceof \WC_Product ? $product->get_id() : 0;
        }
        $item = $id ? wc_get_product($id) : null;
        if (!$item instanceof \WC_Product || !$this->hides($item)) {
            return $content;
        }
        return $this->buttonHtml($item);
    }

    /* ---------------------------------------------------------------------
     * Login round trip
     * ------------------------------------------------------------------- */

    /** Carry ?redirect_to= into the My Account login form; WooCommerce validates it on login. */
    public function loginRedirectField(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect target, validated below and again by WooCommerce.
        $target = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash((string) $_GET['redirect_to'])) : '';
        if ($target === '') {
            return;
        }
        $target = wp_validate_redirect($target, '');
        if ($target !== '') {
            printf('<input type="hidden" name="redirect" value="%s">', esc_attr($target));
        }
    }

    /**
     * [stackless_login_to_see_price text="Log in for trade prices"]
     *
     * @param array|string $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['text' => $this->text()], is_array($atts) ? $atts : [], 'stackless_login_to_see_price');
        if ($this->viewerSeesPrices()) {
            return '';
        }
        return sprintf('<a href="%s" class="stackless-login-to-see-price__link">%s</a>', esc_url($this->loginUrl()), esc_html((string) $atts['text']));
    }

    /* ---------------------------------------------------------------------
     * Product editor
     * ------------------------------------------------------------------- */

    public function renderProductField(): void
    {
        global $product_object;
        $value = $product_object instanceof \WC_Product ? (string) $product_object->get_meta(self::META) : '';
        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => self::META,
            'value' => in_array($value, ['yes', 'no'], true) ? $value : '',
            'label' => __('Hide price until login', 'stackless-lite'),
            'options' => [
                '' => __('Follow the store setting', 'stackless-lite'),
                'yes' => __('Always hide from guests', 'stackless-lite'),
                'no' => __('Never hide', 'stackless-lite'),
            ],
            'desc_tip' => true,
            'description' => __('Guests see the login text instead of the price and cannot buy until they log in.', 'stackless-lite'),
        ]);
        echo '</div>';
    }

    public function saveProductField(\WC_Product $product): void
    {
        // Nonce and capability are checked by WooCommerce before this action runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = isset($_POST[self::META]) ? sanitize_key(wp_unslash((string) $_POST[self::META])) : '';
        if (in_array($value, ['yes', 'no'], true)) {
            $product->update_meta_data(self::META, $value);
        } else {
            $product->delete_meta_data(self::META);
        }
        unset($this->cache[$product->get_id()]);
    }
}
