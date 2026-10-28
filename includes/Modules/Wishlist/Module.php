<?php

namespace Stackless\Modules\Wishlist;

defined('ABSPATH') || exit;

/**
 * Wishlists for guests and customers.
 *
 * Guests get a cookie-backed list that merges into their account list when they sign in.
 * Buttons on shop loops, single products and block product templates; a [stackless_wishlist]
 * shortcode and stackless/wishlist block; a My Account "Wishlist" tab; a read-only share link;
 * and "Move to cart".
 */
class Module extends \Stackless\Core\Module
{
    public const COOKIE = 'stackless_wishlist';
    public const ENDPOINT_KEY = 'stackless-wishlist';
    public const NONCE = 'stackless_wishlist';

    /** @var Repository|null */
    private $repo;

    /** @var array<int, bool> products whose single-page button was printed */
    private $singleRendered = [];

    /** @var string|null token created during this request (cookie not yet readable) */
    private $freshToken;

    /** @var string[]|null memoised keys of the visitor's list for this request */
    private $keys;

    public function id(): string
    {
        return 'wishlist';
    }

    public function title(): string
    {
        return __('Wishlist', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Guest and account wishlists that merge on sign-in, with buttons on product pages and shop grids, a wishlist page, a My Account tab, share links and move to cart.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'store';
    }

    public function conflicts(): array
    {
        return [
            'yith-woocommerce-wishlist/init.php' => 'YITH WooCommerce Wishlist',
            'ti-woocommerce-wishlist/ti-woocommerce-wishlist.php' => 'TI WooCommerce Wishlist',
            'woocommerce-wishlists/woocommerce-wishlists.php' => 'WooCommerce Wishlists',
        ];
    }

    public function install(): void
    {
        Repository::install();
    }

    public function settingsFields(): array
    {
        return [
            ['type' => 'title', 'id' => 'stackless_wishlist_options', 'title' => __('Wishlist', 'stackless-lite')],
            [
                'id' => 'stackless_wishlist_page_id',
                'type' => 'single_select_page',
                'title' => __('Wishlist page', 'stackless-lite'),
                'desc' => __('A page containing the [stackless_wishlist] shortcode or the Wishlist block. Needed for share links.', 'stackless-lite'),
                'desc_tip' => true,
                'class' => 'wc-enhanced-select-nostd',
                'css' => 'min-width:300px;',
                'default' => '',
            ],
            [
                'id' => 'stackless_wishlist_endpoint',
                'type' => 'text',
                'title' => __('My Account endpoint', 'stackless-lite'),
                'desc' => __('URL slug for the Wishlist tab in My Account. Leave empty to hide the tab.', 'stackless-lite'),
                'desc_tip' => true,
                'default' => 'wishlist',
            ],
            [
                'id' => 'stackless_wishlist_show_loop',
                'type' => 'checkbox',
                'title' => __('Shop grids', 'stackless-lite'),
                'desc' => __('Show the wishlist button on product grids and Product Collection blocks', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_wishlist_show_single',
                'type' => 'checkbox',
                'title' => __('Product pages', 'stackless-lite'),
                'desc' => __('Show the wishlist button below the add to cart button', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_wishlist_guests',
                'type' => 'checkbox',
                'title' => __('Guest wishlists', 'stackless-lite'),
                'desc' => __('Let visitors save products without an account (kept in a cookie, merged when they sign in)', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_wishlist_guest_days',
                'type' => 'number',
                'title' => __('Keep guest lists for', 'stackless-lite'),
                'desc' => __('days since the last change', 'stackless-lite'),
                'default' => '30',
                'custom_attributes' => ['min' => 1, 'max' => 365, 'step' => 1],
            ],
            [
                'id' => 'stackless_wishlist_limit',
                'type' => 'number',
                'title' => __('Maximum products per list', 'stackless-lite'),
                'default' => '100',
                'custom_attributes' => ['min' => 1, 'max' => 500, 'step' => 1],
            ],
            [
                'id' => 'stackless_wishlist_remove_on_cart',
                'type' => 'checkbox',
                'title' => __('Move to cart', 'stackless-lite'),
                'desc' => __('Remove a product from the wishlist after it is moved to the cart', 'stackless-lite'),
                'default' => 'yes',
            ],
            ['type' => 'sectionend', 'id' => 'stackless_wishlist_options'],
        ];
    }

    public function boot(): void
    {
        add_action('wp_loaded', [$this, 'handlePost'], 20);
        add_action('wp_loaded', [$this, 'maybeMergeGuest'], 5);
        add_action('wp_login', [$this, 'mergeOnLogin'], 10, 2);
        add_action('wp_ajax_stackless_wishlist', [$this, 'ajax']);
        add_action('wp_ajax_nopriv_stackless_wishlist', [$this, 'ajax']);
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_action('init', [$this, 'registerBlock']);
        add_shortcode('stackless_wishlist', [$this, 'shortcode']);

        if ($this->option('show_loop', 'yes') === 'yes') {
            add_action('woocommerce_after_shop_loop_item', [$this, 'loopButton'], 15);
            add_filter('render_block_woocommerce/product-button', [$this, 'blockProductButton'], 10, 3);
        }
        if ($this->option('show_single', 'yes') === 'yes') {
            add_action('woocommerce_single_product_summary', [$this, 'singleButton'], 35);
            add_filter('render_block_woocommerce/add-to-cart-form', [$this, 'blockSingleButton'], 10, 3);
            add_filter('render_block_woocommerce/add-to-cart-with-options', [$this, 'blockSingleButton'], 10, 3);
        }

        // My Account tab.
        add_filter('woocommerce_get_query_vars', [$this, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems']);
        add_action('woocommerce_account_' . self::ENDPOINT_KEY . '_endpoint', [$this, 'accountEndpoint']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT_KEY . '_title', [$this, 'endpointTitle']);

        // Housekeeping and privacy.
        add_action('woocommerce_cleanup_sessions', [$this, 'purgeGuests']);
        add_action('delete_user', [$this, 'deleteUser']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    private function repo(): Repository
    {
        if (!$this->repo) {
            $this->repo = new Repository();
        }
        return $this->repo;
    }

    private function limit(): int
    {
        return max(1, min(500, (int) $this->option('limit', 100)));
    }

    private function guestsAllowed(): bool
    {
        return $this->option('guests', 'yes') === 'yes';
    }

    /* ------------------------------------------------------------------
     * Current visitor's list
     * ---------------------------------------------------------------- */

    private function cookieToken(): string
    {
        if ($this->freshToken) {
            return $this->freshToken;
        }
        $raw = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';
        return preg_match('/^[a-f0-9]{32}$/', $raw) ? $raw : '';
    }

    private function setCookie(string $token, int $days): void
    {
        $this->freshToken = $token === '' ? null : $token;
        if (headers_sent()) {
            return;
        }
        $expires = $token === '' ? time() - YEAR_IN_SECONDS : time() + $days * DAY_IN_SECONDS;
        wc_setcookie(self::COOKIE, $token, $expires, is_ssl(), true);
        if ($token === '') {
            unset($_COOKIE[self::COOKIE]);
        }
    }

    /** @return array<string, mixed>|null */
    private function currentList(bool $create)
    {
        if (is_user_logged_in()) {
            return $this->repo()->forUser(get_current_user_id(), $create);
        }
        if (!$this->guestsAllowed()) {
            return null;
        }
        $token = $this->cookieToken();
        if ($token === '' && $create) {
            $token = bin2hex(random_bytes(16));
            $this->setCookie($token, (int) $this->option('guest_days', 30));
        }
        return $token === '' ? null : $this->repo()->forGuest($token, $create);
    }

    /** @return array<int, array{product_id:int, variation_id:int}> */
    private function currentItems(): array
    {
        $list = $this->currentList(false);
        return $list ? $this->repo()->items((int) $list['id']) : [];
    }

    /** @return string[] "product:variation" keys */
    private function currentKeys(): array
    {
        if ($this->keys === null) {
            $this->keys = array_map(function ($e) {
                return Merge::key($e['product_id'], $e['variation_id']);
            }, $this->currentItems());
        }
        return $this->keys;
    }

    public function maybeMergeGuest(): void
    {
        if (!is_user_logged_in() || $this->cookieToken() === '') {
            return;
        }
        $this->mergeFor(get_current_user_id());
    }

    /** @param \WP_User|mixed $user */
    public function mergeOnLogin($login, $user): void
    {
        if ($user instanceof \WP_User && $this->cookieToken() !== '') {
            $this->mergeFor((int) $user->ID);
        }
    }

    private function mergeFor(int $user_id): void
    {
        $this->keys = null;
        $guest = $this->repo()->forGuest($this->cookieToken(), false);
        $this->setCookie('', 0);
        if (!$guest || !$this->repo()->count((int) $guest['id'])) {
            if ($guest) {
                $this->repo()->deleteList((int) $guest['id']);
            }
            return;
        }
        $account = $this->repo()->forUser($user_id, true);
        if (!$account) {
            return;
        }
        $added = $this->repo()->merge((int) $guest['id'], (int) $account['id'], $this->limit());
        if ($added > 0) {
            $this->log(sprintf('Merged %d guest wishlist item(s) into user #%d.', $added, $user_id));
        }
    }

    /* ------------------------------------------------------------------
     * Mutations
     * ---------------------------------------------------------------- */

    /**
     * Resolve and validate a product/variation pair from request input.
     *
     * @return array{0:int,1:int}|null
     */
    private function resolveTarget(int $product_id, int $variation_id)
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product || !$product->is_visible() && !$product->is_type('variation')) {
            return null;
        }
        if ($product->is_type('variation')) {
            $parent = (int) $product->get_parent_id();
            $parentProduct = wc_get_product($parent);
            if (!$parentProduct || !$parentProduct->is_visible()) {
                return null;
            }
            return [$parent, (int) $product->get_id()];
        }
        if ('publish' !== get_post_status($product->get_id())) {
            return null;
        }
        return [(int) $product->get_id(), 0];
    }

    /** @return array{ok:bool, in:bool, message:string} */
    private function mutate(string $op, int $product_id, int $variation_id): array
    {
        $this->keys = null;
        $target = $this->resolveTarget($product_id, $variation_id);
        if (!$target) {
            return ['ok' => false, 'in' => false, 'message' => __('This product is not available.', 'stackless-lite')];
        }
        list($product_id, $variation_id) = $target;
        if (!is_user_logged_in() && !$this->guestsAllowed()) {
            return ['ok' => false, 'in' => false, 'message' => __('Sign in to save products to your wishlist.', 'stackless-lite')];
        }
        $list = $this->currentList($op !== 'remove');
        if (!$list) {
            return ['ok' => $op === 'remove', 'in' => false, 'message' => __('Removed from your wishlist.', 'stackless-lite')];
        }
        $list_id = (int) $list['id'];
        $has = $this->repo()->has($list_id, $product_id, $variation_id);
        if ($op === 'toggle') {
            $op = $has ? 'remove' : 'add';
        }
        if ($op === 'remove') {
            $this->repo()->remove($list_id, $product_id, $variation_id);
            return ['ok' => true, 'in' => false, 'message' => __('Removed from your wishlist.', 'stackless-lite')];
        }
        if (!$has && $this->repo()->count($list_id) >= $this->limit()) {
            return ['ok' => false, 'in' => false, 'message' => __('Your wishlist is full. Remove a product before adding another.', 'stackless-lite')];
        }
        $in = $this->repo()->add($list_id, $product_id, $variation_id);
        return ['ok' => $in, 'in' => $in, 'message' => $in ? __('Saved to your wishlist.', 'stackless-lite') : __('Could not save this product. Please try again.', 'stackless-lite')];
    }

    /**
     * Move one entry to the cart. Variations with "any" attributes and products that need
     * options go to their product page instead.
     *
     * @return array{ok:bool, message:string, redirect:string}
     */
    private function moveToCart(int $product_id, int $variation_id): array
    {
        $target = $this->resolveTarget($product_id, $variation_id);
        $fail = ['ok' => false, 'message' => __('This product is not available.', 'stackless-lite'), 'redirect' => ''];
        if (!$target || !function_exists('WC') || !WC()->cart) {
            return $fail;
        }
        list($product_id, $variation_id) = $target;
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return $fail;
        }
        $parent = $variation_id ? wc_get_product($product_id) : $product;
        $link = $parent ? (string) $parent->get_permalink() : '';
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return ['ok' => false, 'message' => __('This product cannot be bought right now.', 'stackless-lite'), 'redirect' => ''];
        }
        $attributes = [];
        if ($variation_id && $product instanceof \WC_Product_Variation) {
            foreach ($product->get_variation_attributes() as $key => $value) {
                if ($value === '') {
                    return ['ok' => false, 'message' => __('Choose the product options first.', 'stackless-lite'), 'redirect' => $link];
                }
                $attributes[$key] = $value;
            }
        } elseif (!$product->is_type('simple')) {
            return ['ok' => false, 'message' => __('Choose the product options first.', 'stackless-lite'), 'redirect' => $link];
        }
        $passed = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, 1, $variation_id, $attributes);
        if (!$passed) {
            $notices = wc_get_notices('error');
            wc_clear_notices();
            $message = $notices ? wp_strip_all_tags((string) ($notices[0]['notice'] ?? '')) : __('Choose the product options first.', 'stackless-lite');
            return ['ok' => false, 'message' => $message, 'redirect' => $link];
        }
        try {
            $key = WC()->cart->add_to_cart($product_id, 1, $variation_id, $attributes);
        } catch (\Exception $e) {
            $key = false;
        }
        if (!$key) {
            $notices = wc_get_notices('error');
            wc_clear_notices();
            return ['ok' => false, 'message' => $notices ? wp_strip_all_tags((string) ($notices[0]['notice'] ?? '')) : __('This product cannot be added to the cart.', 'stackless-lite'), 'redirect' => ''];
        }
        if ($this->option('remove_on_cart', 'yes') === 'yes') {
            $this->mutate('remove', $product_id, $variation_id);
        }
        return ['ok' => true, 'message' => __('Moved to your cart.', 'stackless-lite'), 'redirect' => wc_get_cart_url()];
    }

    /** No-JS form posts. */
    public function handlePost(): void
    {
        if (empty($_POST['stackless_wishlist_op']) || empty($_POST['_stackless_wishlist_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_stackless_wishlist_nonce'])), self::NONCE)) {
            wc_add_notice(__('Your session expired. Please try again.', 'stackless-lite'), 'error');
            return;
        }
        $op = sanitize_key(wp_unslash($_POST['stackless_wishlist_op']));
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $back = wp_get_referer() ?: home_url('/');

        if ($op === 'move') {
            $result = $this->moveToCart($product_id, $variation_id);
            wc_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
            wp_safe_redirect($result['redirect'] ?: $back);
            exit;
        }
        if ($op === 'reshare' && is_user_logged_in()) {
            $list = $this->currentList(false);
            if ($list) {
                $this->repo()->newShareKey((int) $list['id']);
                wc_add_notice(__('A new share link was created. The old link no longer works.', 'stackless-lite'), 'success');
            }
            wp_safe_redirect($back);
            exit;
        }
        if (in_array($op, ['add', 'remove', 'toggle'], true)) {
            $result = $this->mutate($op, $product_id, $variation_id);
            wc_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
            wp_safe_redirect($back);
            exit;
        }
    }

    public function ajax(): void
    {
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : 'state';
        if ($op === 'state') {
            // Read-only, and returns the nonce cached pages need for later writes.
            wp_send_json_success(['keys' => $this->currentKeys(), 'nonce' => wp_create_nonce(self::NONCE)]);
        }
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload the page and try again.', 'stackless-lite'), 'nonce' => wp_create_nonce(self::NONCE)], 403);
        }
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        if ($op === 'move') {
            $result = $this->moveToCart($product_id, $variation_id);
            if ($result['ok']) {
                wp_send_json_success($result + ['keys' => $this->currentKeys()]);
            }
            wp_send_json_error($result, 400);
        }
        if (!in_array($op, ['add', 'remove', 'toggle'], true)) {
            wp_send_json_error(['message' => __('Unknown wishlist action.', 'stackless-lite')], 400);
        }
        $result = $this->mutate($op, $product_id, $variation_id);
        $payload = $result + ['keys' => $this->currentKeys()];
        if ($result['ok']) {
            wp_send_json_success($payload);
        }
        wp_send_json_error($payload, 400);
    }

    /* ------------------------------------------------------------------
     * Assets and buttons
     * ---------------------------------------------------------------- */

    public function registerAssets(): void
    {
        wp_register_style('stackless-wishlist', STACKLESS_PLUGIN_URL . 'assets/wishlist/wishlist.css', [], STACKLESS_VERSION);
        wp_register_script('stackless-wishlist', STACKLESS_PLUGIN_URL . 'assets/wishlist/wishlist.js', [], STACKLESS_VERSION, true);
        wp_localize_script('stackless-wishlist', 'stacklessWishlist', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n' => [
                'add' => __('Save to wishlist', 'stackless-lite'),
                'remove' => __('Saved to wishlist', 'stackless-lite'),
                'error' => __('Something went wrong. Please try again.', 'stackless-lite'),
                'copied' => __('Link copied.', 'stackless-lite'),
            ],
        ]);
        // The My Account tab renders after wp_head, so load its assets in the head.
        if ($this->endpointSlug() !== '' && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::ENDPOINT_KEY)) {
            $this->enqueue();
        }
    }

    private function enqueue(): void
    {
        if (!wp_style_is('stackless-wishlist', 'registered')) {
            $this->registerAssets();
        }
        wp_enqueue_style('stackless-wishlist');
        wp_enqueue_script('stackless-wishlist');
    }

    /** @param \WC_Product|null $product */
    private function button($product, string $context): string
    {
        if (!$product instanceof \WC_Product || $product->is_type('variation')) {
            return '';
        }
        if (!is_user_logged_in() && !$this->guestsAllowed()) {
            return '';
        }
        $this->enqueue();
        $keys = $this->currentKeys();
        $in = in_array(Merge::key($product->get_id(), 0), $keys, true);
        ob_start();
        stackless_get_template('wishlist/button.php', [
            'product' => $product,
            'context' => $context,
            'in_list' => $in,
            'variable' => $product->is_type('variable'),
            'nonce' => wp_create_nonce(self::NONCE),
        ]);
        return (string) ob_get_clean();
    }

    public function loopButton(): void
    {
        // On block templates WooCommerce replays woocommerce_after_shop_loop_item inside a
        // render_block filter (ArchiveProductTemplatesCompatibility); blockProductButton() already
        // adds the button there, so printing here too would show it twice on each card.
        if (function_exists('doing_filter') && doing_filter('render_block')) {
            return;
        }
        global $product;
        echo $this->button($product instanceof \WC_Product ? $product : null, 'loop'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    public function singleButton(): void
    {
        global $product;
        if (!$product instanceof \WC_Product || isset($this->singleRendered[$product->get_id()])) {
            return;
        }
        $this->singleRendered[$product->get_id()] = true;
        echo $this->button($product, 'single'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    /**
     * @param string $content
     * @param array<string, mixed> $parsed
     * @param \WP_Block|mixed $block
     */
    public function blockProductButton($content, $parsed, $block = null): string
    {
        $id = $block instanceof \WP_Block && isset($block->context['postId']) ? (int) $block->context['postId'] : 0;
        if (!$id) {
            return (string) $content;
        }
        // On a product page the main product's own button block is the single-product button.
        if (is_product() && $id === (int) get_queried_object_id()) {
            if (isset($this->singleRendered[$id])) {
                return (string) $content;
            }
            $this->singleRendered[$id] = true;
            return $content . $this->button(wc_get_product($id) ?: null, 'single');
        }
        return $content . $this->button(wc_get_product($id) ?: null, 'loop');
    }

    /**
     * @param string $content
     * @param array<string, mixed> $parsed
     * @param \WP_Block|mixed $block
     */
    public function blockSingleButton($content, $parsed, $block = null): string
    {
        $id = $block instanceof \WP_Block && isset($block->context['postId']) ? (int) $block->context['postId'] : (int) get_the_ID();
        if (!$id || isset($this->singleRendered[$id]) || strpos((string) $content, 'data-stackless-wishlist-button') !== false) {
            return (string) $content;
        }
        $this->singleRendered[$id] = true;
        return $content . $this->button(wc_get_product($id) ?: null, 'single');
    }

    /* ------------------------------------------------------------------
     * Wishlist page, block, account tab, share view
     * ---------------------------------------------------------------- */

    public function registerBlock(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script('stackless-wishlist-block', STACKLESS_PLUGIN_URL . 'assets/wishlist/wishlist-block.js', ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor'], STACKLESS_VERSION, true);
        register_block_type('stackless/wishlist', [
            'api_version' => '3',
            'title' => __('Wishlist', 'stackless-lite'),
            'category' => 'woocommerce',
            'icon' => 'heart',
            'editor_script' => 'stackless-wishlist-block',
            'render_callback' => function () {
                return $this->shortcode();
            },
            'supports' => ['html' => false, 'align' => ['wide', 'full']],
        ]);
    }

    /** @param array<string, mixed>|string $atts */
    public function shortcode($atts = []): string
    {
        $this->enqueue();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        $share = isset($_GET['wishlist']) ? sanitize_text_field(wp_unslash($_GET['wishlist'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
        if ($share !== '') {
            return $this->renderShared($share);
        }
        return $this->renderOwn();
    }

    private function renderOwn(): string
    {
        $list = $this->currentList(false);
        $items = $list ? $this->repo()->items((int) $list['id']) : [];
        $share_url = '';
        if ($list && $items && is_user_logged_in()) {
            $page = (int) $this->option('page_id', 0);
            if ($page && get_post_status($page) === 'publish') {
                $share_url = add_query_arg('wishlist', rawurlencode((string) $list['share_key']), get_permalink($page));
            }
        }
        ob_start();
        stackless_get_template('wishlist/list.php', [
            'rows' => $this->rows($items),
            'owner' => true,
            'guest' => !is_user_logged_in(),
            'share_url' => $share_url,
            'nonce' => wp_create_nonce(self::NONCE),
            'login_url' => wc_get_page_permalink('myaccount'),
        ]);
        return (string) ob_get_clean();
    }

    private function renderShared(string $key): string
    {
        $list = $this->repo()->byShareKey($key);
        if (!$list || !(int) $list['user_id']) {
            return '<div class="woocommerce-info">' . esc_html__('This wishlist link is no longer available.', 'stackless-lite') . '</div>';
        }
        add_filter('wp_robots', 'wp_robots_no_robots');
        $user = get_userdata((int) $list['user_id']);
        ob_start();
        stackless_get_template('wishlist/list.php', [
            'rows' => $this->rows($this->repo()->items((int) $list['id'])),
            'owner' => is_user_logged_in() && get_current_user_id() === (int) $list['user_id'],
            'guest' => false,
            'share_url' => '',
            'nonce' => wp_create_nonce(self::NONCE),
            'login_url' => '',
            'shared_by' => $user ? $user->display_name : '',
            'shared' => true,
        ]);
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array{product_id:int, variation_id:int}> $items
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $items): array
    {
        $rows = [];
        foreach ($items as $entry) {
            $product = wc_get_product($entry['variation_id'] ?: $entry['product_id']);
            if (!$product || ($entry['variation_id'] && (int) $product->get_parent_id() !== $entry['product_id'])) {
                continue;
            }
            $parent = $entry['variation_id'] ? wc_get_product($entry['product_id']) : $product;
            if (!$parent || get_post_status($parent->get_id()) !== 'publish') {
                continue;
            }
            $rows[] = [
                'product' => $product,
                'product_id' => $entry['product_id'],
                'variation_id' => $entry['variation_id'],
                'name' => $product->get_name(),
                'url' => $product->get_permalink(),
                'image' => $product->get_image('woocommerce_thumbnail'),
                'price_html' => $product->get_price_html(),
                'stock_html' => wc_get_stock_html($product),
                'can_move' => $product->is_purchasable() && $product->is_in_stock() && ($product->is_type('simple') || $product->is_type('variation')),
            ];
        }
        return $rows;
    }

    /** @param array<string, string> $vars */
    public function queryVars($vars): array
    {
        $slug = $this->endpointSlug();
        if ($slug !== '') {
            $vars[self::ENDPOINT_KEY] = $slug;
        }
        return (array) $vars;
    }

    private function endpointSlug(): string
    {
        return sanitize_title((string) $this->option('endpoint', 'wishlist'));
    }

    /** @param array<string, string> $items */
    public function menuItems($items): array
    {
        if ($this->endpointSlug() === '') {
            return (array) $items;
        }
        $label = __('Wishlist', 'stackless-lite');
        $out = [];
        foreach ((array) $items as $key => $value) {
            if ($key === 'customer-logout') {
                $out[self::ENDPOINT_KEY] = $label;
            }
            $out[$key] = $value;
        }
        if (!isset($out[self::ENDPOINT_KEY])) {
            $out[self::ENDPOINT_KEY] = $label;
        }
        return $out;
    }

    public function endpointTitle(): string
    {
        return __('Wishlist', 'stackless-lite');
    }

    public function accountEndpoint(): void
    {
        $this->enqueue();
        echo $this->renderOwn(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    /* ------------------------------------------------------------------
     * Housekeeping and privacy
     * ---------------------------------------------------------------- */

    public function purgeGuests(): void
    {
        $removed = $this->repo()->purgeGuests(max(1, (int) $this->option('guest_days', 30)));
        if ($removed) {
            $this->log(sprintf('Removed %d expired guest wishlist(s).', $removed));
        }
    }

    public function deleteUser(int $user_id): void
    {
        $this->repo()->deleteForUser($user_id);
    }

    /** @param array<string, array<string, mixed>> $exporters */
    public function registerExporter($exporters): array
    {
        $exporters['stackless-wishlist'] = [
            'exporter_friendly_name' => __('Wishlist', 'stackless-lite'),
            'callback' => [$this, 'export'],
        ];
        return (array) $exporters;
    }

    /** @param array<string, array<string, mixed>> $erasers */
    public function registerEraser($erasers): array
    {
        $erasers['stackless-wishlist'] = [
            'eraser_friendly_name' => __('Wishlist', 'stackless-lite'),
            'callback' => [$this, 'erase'],
        ];
        return (array) $erasers;
    }

    /** @return array{data: array<int, mixed>, done: bool} */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $data = [];
        if ($user) {
            $list = $this->repo()->forUser((int) $user->ID, false);
            foreach ($list ? $this->repo()->items((int) $list['id']) : [] as $entry) {
                $product = wc_get_product($entry['variation_id'] ?: $entry['product_id']);
                $data[] = [
                    'group_id' => 'stackless-wishlist',
                    'group_label' => __('Wishlist', 'stackless-lite'),
                    'item_id' => 'stackless-wishlist-' . Merge::key($entry['product_id'], $entry['variation_id']),
                    'data' => [
                        ['name' => __('Product', 'stackless-lite'), 'value' => $product ? $product->get_name() : '#' . $entry['product_id']],
                    ],
                ];
            }
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $removed = false;
        if ($user && $this->repo()->forUser((int) $user->ID, false)) {
            $this->repo()->deleteForUser((int) $user->ID);
            $removed = true;
        }
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
