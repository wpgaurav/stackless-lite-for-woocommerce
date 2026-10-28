<?php

namespace Stackless\Modules\SavedCarts;

defined('ABSPATH') || exit;

/**
 * Saved carts and order again.
 *
 * Signed-in customers name and save their cart, restore it later and reorder from any
 * past order, not only completed ones. Everything lives under My Account → Saved carts,
 * with a [stackless_saved_carts] shortcode for a standalone page and a link on the cart.
 */
class Module extends \Stackless\Core\Module
{
    public const META = '_stackless_saved_carts';
    public const ENDPOINT_KEY = 'stackless-saved-carts';
    public const NONCE = 'stackless_saved_carts';

    public function id(): string
    {
        return 'saved_carts';
    }

    public function title(): string
    {
        return __('Saved Carts & Order Again', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Customers save their cart under a name, restore it later, and reorder from any past order. Adds a Saved carts tab to My Account.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'store';
    }

    public function conflicts(): array
    {
        return [
            'save-cart-later/save-cart-later.php' => 'Save Cart Later',
            'woocommerce-save-for-later/woocommerce-save-for-later.php' => 'Save For Later for WooCommerce',
        ];
    }

    public function settingsFields(): array
    {
        $statuses = wc_get_order_statuses();
        unset($statuses['wc-checkout-draft']);
        return [
            ['type' => 'title', 'id' => 'stackless_saved_carts_options', 'title' => __('Saved carts & order again', 'stackless-lite')],
            [
                'id' => 'stackless_saved_carts_endpoint',
                'type' => 'text',
                'title' => __('My Account endpoint', 'stackless-lite'),
                'desc' => __('URL slug of the Saved carts tab. Leave empty to hide the tab (the shortcode still works).', 'stackless-lite'),
                'desc_tip' => true,
                'default' => 'saved-carts',
            ],
            [
                'id' => 'stackless_saved_carts_cart_link',
                'type' => 'checkbox',
                'title' => __('Cart page', 'stackless-lite'),
                'desc' => __('Show a "Save this cart" form below the cart for signed-in customers', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_saved_carts_reorder_statuses',
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'title' => __('Order again for', 'stackless-lite'),
                'desc' => __('Order statuses that show an Order again button. WooCommerce alone allows completed orders only.', 'stackless-lite'),
                'desc_tip' => true,
                'options' => $statuses,
                'default' => ['wc-processing', 'wc-completed', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed'],
            ],
            ['type' => 'sectionend', 'id' => 'stackless_saved_carts_options'],
        ];
    }

    public function boot(): void
    {
        add_action('wp_loaded', [$this, 'handlePost'], 20);
        add_shortcode('stackless_saved_carts', [$this, 'shortcode']);
        add_filter('woocommerce_valid_order_statuses_for_order_again', [$this, 'reorderStatuses'], 20);

        add_filter('woocommerce_get_query_vars', [$this, 'queryVars']);
        add_filter('woocommerce_account_menu_items', [$this, 'menuItems']);
        add_action('woocommerce_account_' . self::ENDPOINT_KEY . '_endpoint', [$this, 'accountEndpoint']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT_KEY . '_title', [$this, 'endpointTitle']);

        if ($this->option('cart_link', 'yes') === 'yes') {
            add_action('woocommerce_after_cart', [$this, 'cartForm']);
            add_filter('render_block_woocommerce/cart', [$this, 'cartBlockForm'], 10, 2);
        }

        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    /* ------------------------------------------------------------------
     * Storage
     * ---------------------------------------------------------------- */

    /** @return array<string, array<string, mixed>> */
    public function saved(int $user_id): array
    {
        return CartList::normalise(get_user_meta($user_id, self::META, true));
    }

    /** @param array<string, array<string, mixed>> $list */
    private function store(int $user_id, array $list): void
    {
        if ($list) {
            update_user_meta($user_id, self::META, $list);
        } else {
            delete_user_meta($user_id, self::META);
        }
    }

    /**
     * Save the current cart for a user.
     *
     * @return array{ok:bool, message:string}
     */
    public function saveCurrent(int $user_id, string $name): array
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return ['ok' => false, 'message' => __('Your cart is empty. Add products before saving it.', 'stackless-lite')];
        }
        $items = CartList::fromCart(WC()->cart->get_cart());
        try {
            $list = CartList::add($this->saved($user_id), wp_generate_uuid4(), sanitize_text_field($name), $items, time());
        } catch (\LengthException $e) {
            /* translators: %d: maximum number of saved carts */
            return ['ok' => false, 'message' => sprintf(__('You can keep %d saved carts. Remove one before saving another.', 'stackless-lite'), CartList::MAX_CARTS)];
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => __('Give this cart a name.', 'stackless-lite')];
        }
        $this->store($user_id, $list);
        return ['ok' => true, 'message' => __('Cart saved. Find it any time under Saved carts.', 'stackless-lite')];
    }

    /**
     * Put a saved cart's lines into the live cart. Current prices and stock apply.
     *
     * @return array{ok:bool, added:int, skipped:array<int, string>}
     */
    public function restore(int $user_id, string $key, bool $replace): array
    {
        $list = $this->saved($user_id);
        if (!isset($list[$key]) || !function_exists('WC') || !WC()->cart) {
            return ['ok' => false, 'added' => 0, 'skipped' => []];
        }
        if ($replace) {
            WC()->cart->empty_cart();
        }
        $added = 0;
        $skipped = [];
        foreach ($list[$key]['items'] as $line) {
            $product = wc_get_product($line['variation_id'] ?: $line['product_id']);
            $name = $product ? $product->get_name() : __('A product that is no longer available', 'stackless-lite');
            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                $skipped[] = $name;
                continue;
            }
            $qty = wc_stock_amount($line['quantity']);
            $passed = apply_filters('woocommerce_add_to_cart_validation', true, $line['product_id'], $qty, $line['variation_id'], $line['variation'], $line['data']);
            $key_added = false;
            if ($passed) {
                try {
                    $key_added = WC()->cart->add_to_cart($line['product_id'], $qty, $line['variation_id'], $line['variation'], $line['data']);
                } catch (\Exception $e) {
                    $key_added = false;
                }
            }
            if ($key_added) {
                $added++;
            } else {
                $skipped[] = $name;
            }
        }
        // Validation notices are summarised below instead of shown one by one.
        wc_clear_notices();
        return ['ok' => $added > 0, 'added' => $added, 'skipped' => $skipped];
    }

    public function delete(int $user_id, string $key): bool
    {
        $list = $this->saved($user_id);
        if (!isset($list[$key])) {
            return false;
        }
        unset($list[$key]);
        $this->store($user_id, $list);
        return true;
    }

    /* ------------------------------------------------------------------
     * Requests
     * ---------------------------------------------------------------- */

    public function handlePost(): void
    {
        if (empty($_POST['stackless_saved_carts_op']) || empty($_POST['_stackless_saved_carts_nonce'])) {
            return;
        }
        $back = wp_get_referer() ?: wc_get_page_permalink('myaccount');
        if (!is_user_logged_in()) {
            wc_add_notice(__('Sign in to manage saved carts.', 'stackless-lite'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_stackless_saved_carts_nonce'])), self::NONCE)) {
            wc_add_notice(__('Your session expired. Please try again.', 'stackless-lite'), 'error');
            wp_safe_redirect($back);
            exit;
        }
        $user_id = get_current_user_id();
        $op = sanitize_key(wp_unslash($_POST['stackless_saved_carts_op']));
        $key = isset($_POST['cart_key']) ? sanitize_text_field(wp_unslash($_POST['cart_key'])) : '';

        if ($op === 'save') {
            $name = isset($_POST['cart_name']) ? sanitize_text_field(wp_unslash($_POST['cart_name'])) : '';
            $result = $this->saveCurrent($user_id, $name);
            wc_add_notice($result['message'], $result['ok'] ? 'success' : 'error');
            wp_safe_redirect($result['ok'] && $this->accountUrl() ? $this->accountUrl() : $back);
            exit;
        }
        if ($op === 'delete') {
            $ok = $this->delete($user_id, $key);
            wc_add_notice($ok ? __('Saved cart removed.', 'stackless-lite') : __('That saved cart was not found.', 'stackless-lite'), $ok ? 'success' : 'error');
            wp_safe_redirect($back);
            exit;
        }
        if ($op === 'restore') {
            $result = $this->restore($user_id, $key, !empty($_POST['replace']));
            if (!$result['ok']) {
                wc_add_notice(__('None of these products can be bought right now.', 'stackless-lite'), 'error');
                wp_safe_redirect($back);
                exit;
            }
            wc_add_notice(__('Your saved cart is back. Current prices apply; check any product options before paying.', 'stackless-lite'), 'success');
            if ($result['skipped']) {
                /* translators: %s: product names */
                wc_add_notice(sprintf(__('Not added: %s', 'stackless-lite'), implode(', ', array_unique($result['skipped']))), 'notice');
            }
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }
    }

    /* ------------------------------------------------------------------
     * Order again
     * ---------------------------------------------------------------- */

    /** @param string[] $statuses */
    public function reorderStatuses($statuses): array
    {
        $chosen = $this->option('reorder_statuses', ['wc-processing', 'wc-completed', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed']);
        $chosen = is_array($chosen) ? $chosen : [];
        $clean = [];
        foreach ($chosen as $status) {
            $status = (string) $status;
            $status = strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
            if ($status !== 'checkout-draft' && $status !== '') {
                $clean[] = $status;
            }
        }
        return array_values(array_unique(array_merge((array) $statuses, $clean)));
    }

    /** @return \WC_Order[] recent orders this customer can reorder */
    private function reorderable(int $user_id): array
    {
        $statuses = array_map(function ($s) {
            return 'wc-' . $s;
        }, $this->reorderStatuses(['completed']));
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => $statuses,
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'type' => 'shop_order',
        ]);
        return array_values(array_filter(is_array($orders) ? $orders : [], function ($order) {
            return $order instanceof \WC_Order && count($order->get_items()) > 0;
        }));
    }

    private function orderAgainUrl(\WC_Order $order): string
    {
        return wp_nonce_url(add_query_arg('order_again', $order->get_id(), wc_get_cart_url()), 'woocommerce-order_again');
    }

    /* ------------------------------------------------------------------
     * Output
     * ---------------------------------------------------------------- */

    private function endpointSlug(): string
    {
        return sanitize_title((string) $this->option('endpoint', 'saved-carts'));
    }

    private function accountUrl(): string
    {
        $slug = $this->endpointSlug();
        return $slug === '' ? '' : wc_get_account_endpoint_url(self::ENDPOINT_KEY);
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

    /** @param array<string, string> $items */
    public function menuItems($items): array
    {
        if ($this->endpointSlug() === '') {
            return (array) $items;
        }
        $label = __('Saved carts', 'stackless-lite');
        $out = [];
        foreach ((array) $items as $key => $value) {
            $out[$key] = $value;
            if ($key === 'orders') {
                $out[self::ENDPOINT_KEY] = $label;
            }
        }
        if (!isset($out[self::ENDPOINT_KEY])) {
            $logout = isset($out['customer-logout']) ? ['customer-logout' => $out['customer-logout']] : [];
            unset($out['customer-logout']);
            $out[self::ENDPOINT_KEY] = $label;
            $out += $logout;
        }
        return $out;
    }

    public function endpointTitle(): string
    {
        return __('Saved carts', 'stackless-lite');
    }

    public function accountEndpoint(): void
    {
        echo $this->render(true); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    /** @param array<string, mixed>|string $atts */
    public function shortcode($atts = []): string
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        return $this->render(false);
    }

    private function render(bool $in_account): string
    {
        wp_enqueue_style('stackless-saved_carts', STACKLESS_PLUGIN_URL . 'assets/saved_carts/saved-carts.css', [], STACKLESS_VERSION);
        ob_start();
        if (!is_user_logged_in()) {
            stackless_get_template('saved_carts/signed-out.php', ['login_url' => wc_get_page_permalink('myaccount')]);
            return (string) ob_get_clean();
        }
        $user_id = get_current_user_id();
        $carts = [];
        foreach ($this->saved($user_id) as $key => $cart) {
            $previews = [];
            foreach (array_slice($cart['items'], 0, 4) as $line) {
                $product = wc_get_product($line['variation_id'] ?: $line['product_id']);
                if ($product) {
                    $previews[] = ['name' => $product->get_name(), 'image' => $product->get_image('woocommerce_gallery_thumbnail')];
                }
            }
            $carts[] = [
                'key' => $key,
                'name' => $cart['name'],
                'created' => $cart['created'],
                'lines' => count($cart['items']),
                'quantity' => CartList::quantity($cart),
                'previews' => $previews,
            ];
        }
        stackless_get_template('saved_carts/account.php', [
            'carts' => $carts,
            'orders' => $this->reorderable($user_id),
            'order_again_url' => function (\WC_Order $order) {
                return $this->orderAgainUrl($order);
            },
            'can_save' => function_exists('WC') && WC()->cart && !WC()->cart->is_empty(),
            'cart_count' => function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
            'limit' => CartList::MAX_CARTS,
            'nonce' => wp_create_nonce(self::NONCE),
            'in_account' => $in_account,
        ]);
        return (string) ob_get_clean();
    }

    private function saveForm(): string
    {
        if (!is_user_logged_in() || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return '';
        }
        wp_enqueue_style('stackless-saved_carts', STACKLESS_PLUGIN_URL . 'assets/saved_carts/saved-carts.css', [], STACKLESS_VERSION);
        ob_start();
        stackless_get_template('saved_carts/cart-form.php', [
            'nonce' => wp_create_nonce(self::NONCE),
            'account_url' => $this->accountUrl(),
        ]);
        return (string) ob_get_clean();
    }

    public function cartForm(): void
    {
        echo $this->saveForm(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
    }

    /**
     * @param string $content
     * @param array<string, mixed> $parsed
     */
    public function cartBlockForm($content, $parsed = []): string
    {
        return $content . $this->saveForm();
    }

    /* ------------------------------------------------------------------
     * Privacy
     * ---------------------------------------------------------------- */

    /** @param array<string, array<string, mixed>> $exporters */
    public function registerExporter($exporters): array
    {
        $exporters['stackless-saved-carts'] = ['exporter_friendly_name' => __('Saved carts', 'stackless-lite'), 'callback' => [$this, 'export']];
        return (array) $exporters;
    }

    /** @param array<string, array<string, mixed>> $erasers */
    public function registerEraser($erasers): array
    {
        $erasers['stackless-saved-carts'] = ['eraser_friendly_name' => __('Saved carts', 'stackless-lite'), 'callback' => [$this, 'erase']];
        return (array) $erasers;
    }

    /** @return array{data: array<int, mixed>, done: bool} */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $data = [];
        if ($user) {
            foreach ($this->saved((int) $user->ID) as $key => $cart) {
                $names = [];
                foreach ($cart['items'] as $line) {
                    $product = wc_get_product($line['variation_id'] ?: $line['product_id']);
                    $names[] = ($product ? $product->get_name() : '#' . $line['product_id']) . ' × ' . wc_stock_amount($line['quantity']);
                }
                $data[] = [
                    'group_id' => 'stackless-saved-carts',
                    'group_label' => __('Saved carts', 'stackless-lite'),
                    'item_id' => 'stackless-saved-cart-' . $key,
                    'data' => [
                        ['name' => __('Name', 'stackless-lite'), 'value' => $cart['name']],
                        ['name' => __('Products', 'stackless-lite'), 'value' => implode(', ', $names)],
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
        $removed = $user && $this->saved((int) $user->ID);
        if ($user) {
            delete_user_meta((int) $user->ID, self::META);
        }
        return ['items_removed' => (bool) $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
