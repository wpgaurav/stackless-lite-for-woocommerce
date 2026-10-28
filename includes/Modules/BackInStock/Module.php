<?php

namespace Stackless\Modules\BackInStock;

defined('ABSPATH') || exit;

/**
 * "Email me when it's back" for out-of-stock simple products and variations.
 *
 * Double opt-in: a sign-up is pending until the customer confirms from the email. When
 * stock returns, Action Scheduler sends one email per confirmed sign-up in batches of 50.
 */
class Module extends \Stackless\Core\Module
{
    public const ACTION = 'stackless_back_in_stock_send';
    public const GROUP = 'stackless-back_in_stock';
    public const NONCE = 'stackless_back_in_stock';
    public const QUERY = 'stackless_stock_alert';

    /** @var Repository|null */
    private $repo;

    /** @var array<int, bool> */
    private $rendered = [];

    public function id(): string
    {
        return 'back_in_stock';
    }

    public function title(): string
    {
        return __('Back-in-stock Alerts', 'stackless-lite');
    }

    public function description(): string
    {
        return __('A "Notify me" form on out-of-stock products and variations, confirmed by email, with one availability email when stock returns.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'store';
    }

    public function conflicts(): array
    {
        return [
            'woocommerce-waitlist/woocommerce-waitlist.php' => 'WooCommerce Waitlist',
            'back-in-stock-notifier-for-woocommerce/cwginstocknotifier.php' => 'Back In Stock Notifier for WooCommerce',
            'woocommerce-back-in-stock-notifications/woocommerce-back-in-stock-notifications.php' => 'WooCommerce Back In Stock Notifications',
        ];
    }

    public function install(): void
    {
        Repository::install();
    }

    public function settingsFields(): array
    {
        return [
            ['type' => 'title', 'id' => 'stackless_back_in_stock_options', 'title' => __('Back-in-stock alerts', 'stackless-lite')],
            [
                'id' => 'stackless_back_in_stock_heading',
                'type' => 'text',
                'title' => __('Form heading', 'stackless-lite'),
                'default' => __('Email me when it is back', 'stackless-lite'),
            ],
            [
                'id' => 'stackless_back_in_stock_guests',
                'type' => 'checkbox',
                'title' => __('Guests', 'stackless-lite'),
                'desc' => __('Let visitors without an account sign up', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_back_in_stock_skip_confirm',
                'type' => 'checkbox',
                'title' => __('Signed-in customers', 'stackless-lite'),
                'desc' => __('Skip the confirmation email when a signed-in customer uses their account email', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_back_in_stock_backorders',
                'type' => 'checkbox',
                'title' => __('Backorders', 'stackless-lite'),
                'desc' => __('Also notify when a product becomes available on backorder', 'stackless-lite'),
                'default' => 'no',
            ],
            [
                'id' => 'stackless_back_in_stock_hourly_limit',
                'type' => 'number',
                'title' => __('Sign-ups per visitor per hour', 'stackless-lite'),
                'desc' => __('Limits abuse of the form. Store managers are never limited.', 'stackless-lite'),
                'desc_tip' => true,
                'default' => '8',
                'custom_attributes' => ['min' => 1, 'max' => 100, 'step' => 1],
            ],
            ['type' => 'sectionend', 'id' => 'stackless_back_in_stock_options'],
        ];
    }

    public function boot(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_action('woocommerce_single_product_summary', [$this, 'renderSummary'], 31);
        add_filter('render_block_woocommerce/add-to-cart-form', [$this, 'renderBlock'], 10, 3);
        add_filter('render_block_woocommerce/add-to-cart-with-options', [$this, 'renderBlock'], 10, 3);
        add_action('wp_ajax_stackless_back_in_stock', [$this, 'ajax']);
        add_action('wp_ajax_nopriv_stackless_back_in_stock', [$this, 'ajax']);
        add_action('template_redirect', [$this, 'handleLink'], 1);

        add_action('woocommerce_product_set_stock_status', [$this, 'stockStatusChanged'], 20, 3);
        add_action('woocommerce_variation_set_stock_status', [$this, 'stockStatusChanged'], 20, 3);
        add_action(self::ACTION, [$this, 'processBatch'], 10, 2);
        add_action('before_delete_post', [$this, 'productDeleted']);

        (new Agent($this->repo(), [$this, 'queue'], $this->option('backorders', 'no') === 'yes'))->register();

        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);

        if (is_admin()) {
            $screen = new AdminScreen($this->repo());
            add_action('admin_menu', [$screen, 'menu'], 70);
            add_action('admin_post_stackless_back_in_stock_run', [$this, 'adminRun']);
            add_action('admin_enqueue_scripts', [$screen, 'assets']);
        }
    }

    private function repo(): Repository
    {
        if (!$this->repo) {
            $this->repo = new Repository();
        }
        return $this->repo;
    }

    /* ------------------------------------------------------------------
     * Storefront form
     * ---------------------------------------------------------------- */

    public function registerAssets(): void
    {
        wp_register_style('stackless-back_in_stock', STACKLESS_PLUGIN_URL . 'assets/back_in_stock/form.css', [], STACKLESS_VERSION);
        wp_register_script('stackless-back_in_stock', STACKLESS_PLUGIN_URL . 'assets/back_in_stock/form.js', [], STACKLESS_VERSION, true);
        wp_localize_script('stackless-back_in_stock', 'stacklessBackInStock', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'error' => __('Something went wrong. Please try again.', 'stackless-lite'),
        ]);
    }

    /**
     * Out-of-stock purchasable options for a product.
     *
     * @return array<int, array{id:int, name:string}>
     */
    private function outOfStockOptions(\WC_Product $product): array
    {
        $include_backorder = $this->option('backorders', 'no') === 'yes';
        if ($product->is_type('variable')) {
            $options = [];
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if (!$child instanceof \WC_Product_Variation || !$child->variation_is_visible() || $child->get_status() !== 'publish') {
                    continue;
                }
                if (!Batch::isAvailable($child->get_stock_status(), $include_backorder)) {
                    $options[] = ['id' => (int) $child->get_id(), 'name' => wc_get_formatted_variation($child, true, false, false)];
                }
            }
            return $options;
        }
        if ($product->is_type('simple') && !Batch::isAvailable($product->get_stock_status(), $include_backorder)) {
            return [['id' => (int) $product->get_id(), 'name' => $product->get_name()]];
        }
        return [];
    }

    private function form(\WC_Product $product): string
    {
        if (isset($this->rendered[$product->get_id()]) || get_post_status($product->get_id()) !== 'publish') {
            return '';
        }
        if (!is_user_logged_in() && $this->option('guests', 'yes') !== 'yes') {
            return '';
        }
        $options = $this->outOfStockOptions($product);
        if (!$options) {
            return '';
        }
        $this->rendered[$product->get_id()] = true;
        if (!wp_script_is('stackless-back_in_stock', 'registered')) {
            $this->registerAssets();
        }
        wp_enqueue_style('stackless-back_in_stock');
        wp_enqueue_script('stackless-back_in_stock');
        $user = wp_get_current_user();
        ob_start();
        stackless_get_template('back_in_stock/form.php', [
            'product' => $product,
            'options' => $options,
            'variable' => $product->is_type('variable'),
            'heading' => (string) $this->option('heading', __('Email me when it is back', 'stackless-lite')),
            'email' => $user && $user->exists() ? $user->user_email : '',
            'nonce' => wp_create_nonce(self::NONCE),
        ]);
        return (string) ob_get_clean();
    }

    public function renderSummary(): void
    {
        global $product;
        if ($product instanceof \WC_Product) {
            echo $this->form($product); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in template.
        }
    }

    /**
     * @param string $content
     * @param array<string, mixed> $parsed
     * @param \WP_Block|mixed $block
     */
    public function renderBlock($content, $parsed, $block = null): string
    {
        $id = $block instanceof \WP_Block && isset($block->context['postId']) ? (int) $block->context['postId'] : (int) get_the_ID();
        $product = $id ? wc_get_product($id) : null;
        if (!$product || strpos((string) $content, 'data-stackless-stock-alert') !== false) {
            return (string) $content;
        }
        return $content . $this->form($product);
    }

    /* ------------------------------------------------------------------
     * Sign-up
     * ---------------------------------------------------------------- */

    private function rateLimited(): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return false;
        }
        $ip = class_exists('WC_Geolocation') ? \WC_Geolocation::get_ip_address() : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        $key = 'stackless_bis_rl_' . substr(hash_hmac('sha256', (string) $ip, wp_salt('nonce')), 0, 32);
        $count = (int) get_transient($key);
        $limit = max(1, (int) $this->option('hourly_limit', 8));
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return false;
    }

    public function ajax(): void
    {
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : 'subscribe';
        if ($op === 'nonce') {
            wp_send_json_success(['nonce' => wp_create_nonce(self::NONCE)]);
        }
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Please try again.', 'stackless-lite'), 'retry' => true, 'nonce' => wp_create_nonce(self::NONCE)], 403);
        }
        $result = $this->subscribe(
            isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
            isset($_POST['product_id']) ? absint($_POST['product_id']) : 0,
            !empty($_POST['consent']),
            !empty($_POST['website'])
        );
        if ($result['ok']) {
            wp_send_json_success(['message' => $result['message']]);
        }
        wp_send_json_error(['message' => $result['message']], 400);
    }

    /** @return array{ok:bool, message:string} */
    public function subscribe(string $email, int $product_id, bool $consent, bool $honeypot): array
    {
        $generic = __('Check your email to confirm the alert. If you already confirmed, you are on the list.', 'stackless-lite');
        if ($honeypot) {
            return ['ok' => true, 'message' => $generic];
        }
        if (!$consent) {
            return ['ok' => false, 'message' => __('Tick the box to confirm you want an availability email.', 'stackless-lite')];
        }
        if (!is_user_logged_in() && $this->option('guests', 'yes') !== 'yes') {
            return ['ok' => false, 'message' => __('Sign in to get availability alerts.', 'stackless-lite')];
        }
        $email = strtolower(trim($email));
        $product = $product_id ? wc_get_product($product_id) : null;
        if (!is_email($email) || !$product || !($product->is_type('simple') || $product->is_type('variation'))) {
            return ['ok' => false, 'message' => __('Choose a product option and enter a valid email address.', 'stackless-lite')];
        }
        $parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : 0;
        if (get_post_status($parent_id ?: $product->get_id()) !== 'publish') {
            return ['ok' => false, 'message' => __('This product is not available.', 'stackless-lite')];
        }
        if (Batch::isAvailable($product->get_stock_status(), $this->option('backorders', 'no') === 'yes')) {
            return ['ok' => false, 'message' => __('Good news: this option is available now. You can add it to your cart.', 'stackless-lite')];
        }
        if ($this->rateLimited()) {
            return ['ok' => false, 'message' => __('Too many requests. Please try again in an hour.', 'stackless-lite')];
        }

        $existing = $this->repo()->find($email, (int) $product->get_id());
        if (!Batch::startsFresh($existing, time())) {
            return ['ok' => true, 'message' => $generic];
        }

        $user = wp_get_current_user();
        $trusted = $user && $user->exists() && strtolower($user->user_email) === $email && $this->option('skip_confirm', 'yes') === 'yes';
        $row = $this->repo()->upsert($email, (int) $product->get_id(), $parent_id, $user && $user->exists() ? (int) $user->ID : 0, $trusted ? 'confirmed' : 'pending', $existing);
        if (!$row) {
            return ['ok' => false, 'message' => __('Your alert could not be saved. Please try again.', 'stackless-lite')];
        }
        if ($trusted) {
            return ['ok' => true, 'message' => __('You are on the list. We will email you once when this is back in stock.', 'stackless-lite')];
        }
        if (!$this->sendConfirmation($row, $product)) {
            $this->repo()->setStatus((int) $row['id'], 'failed');
            return ['ok' => false, 'message' => __('The confirmation email could not be sent. Please try again later.', 'stackless-lite')];
        }
        return ['ok' => true, 'message' => $generic];
    }

    /* ------------------------------------------------------------------
     * Links: confirm / unsubscribe
     * ---------------------------------------------------------------- */

    /** @param array<string, mixed> $row */
    private function signature(array $row): string
    {
        return hash_hmac('sha256', $row['id'] . '|' . $row['token'], wp_salt('auth'));
    }

    /** @param array<string, mixed> $row */
    public function linkUrl(array $row, string $action): string
    {
        return add_query_arg([self::QUERY => (int) $row['id'], 'key' => $this->signature($row), 'do' => $action], home_url('/'));
    }

    public function handleLink(): void
    {
        if (!isset($_GET[self::QUERY])) { // phpcs:ignore WordPress.Security.NonceVerification -- signed link.
            return;
        }
        nocache_headers();
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
        }
        $row = $this->repo()->get(absint($_GET[self::QUERY])); // phpcs:ignore WordPress.Security.NonceVerification
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $title = __('Product availability', 'stackless-lite');
        if (!$row || !hash_equals($this->signature($row), $key)) {
            wp_die(esc_html__('This alert link is not valid any more.', 'stackless-lite'), esc_html($title), ['response' => 404]);
        }
        $unsubscribe = isset($_GET['do']) && sanitize_key(wp_unslash($_GET['do'])) === 'unsubscribe'; // phpcs:ignore WordPress.Security.NonceVerification
        $product = wc_get_product((int) $row['product_id']);
        $name = $product ? $product->get_name() : __('this product', 'stackless-lite');

        // Only a POST changes state, so mail scanners that open links do not confirm or unsubscribe.
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('stackless_stock_alert_' . $row['id']);
            if ($unsubscribe) {
                $this->repo()->setStatus((int) $row['id'], 'unsubscribed');
                wp_die(esc_html__('You will not get an availability email for this product.', 'stackless-lite'), esc_html($title), ['response' => 200]);
            }
            if (Batch::confirmationExpired($row, time())) {
                wp_die(esc_html__('This confirmation link expired. Please sign up again on the product page.', 'stackless-lite'), esc_html($title), ['response' => 410]);
            }
            if ($row['status'] === 'pending') {
                $this->repo()->claim((int) $row['id'], 'pending', 'confirmed');
                if ($product && Batch::isAvailable($product->get_stock_status(), $this->option('backorders', 'no') === 'yes')) {
                    $this->queue((int) $row['product_id']);
                }
            }
            $message = sprintf(
                /* translators: %s: product name */
                __('Your alert is confirmed. We will email you once when %s is back in stock.', 'stackless-lite'),
                $name
            );
            wp_die(esc_html($message), esc_html($title), ['response' => 200]);
        }

        $label = $unsubscribe ? __('Stop this alert', 'stackless-lite') : __('Confirm my alert', 'stackless-lite');
        $intro = $unsubscribe
            /* translators: %s: product name */
            ? sprintf(__('Stop the availability email for %s?', 'stackless-lite'), $name)
            /* translators: %s: product name */
            : sprintf(__('Confirm that you want one email when %s is back in stock.', 'stackless-lite'), $name);
        $html = '<p>' . esc_html($intro) . '</p><form method="post">' . wp_nonce_field('stackless_stock_alert_' . $row['id'], '_wpnonce', false, false)
            . '<p><button type="submit" class="button">' . esc_html($label) . '</button></p></form>';
        wp_die($html, esc_html($title), ['response' => 200]); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /* ------------------------------------------------------------------
     * Emails
     * ---------------------------------------------------------------- */

    /** @param array<string, mixed> $vars */
    private function mail(string $to, string $subject, string $heading, string $template, array $vars): bool
    {
        if (!function_exists('WC') || !\Stackless\Core\LiveStore::allowsCustomerEmail('back_in_stock_' . $template)) {
            return false;
        }
        ob_start();
        stackless_get_template('back_in_stock/' . $template, $vars + ['heading' => $heading]);
        $body = (string) ob_get_clean();
        $mailer = WC()->mailer();
        return (bool) $mailer->send($to, $subject, $mailer->wrap_message($heading, $body), "Content-Type: text/html\r\n");
    }

    /** @param array<string, mixed> $row */
    private function sendConfirmation(array $row, \WC_Product $product): bool
    {
        return $this->mail(
            (string) $row['email'],
            /* translators: %s: product name */
            sprintf(__('Confirm your alert for %s', 'stackless-lite'), $product->get_name()),
            __('Confirm your availability alert', 'stackless-lite'),
            'email-confirm.php',
            [
                'product' => $product,
                'confirm_url' => $this->linkUrl($row, 'confirm'),
                'unsubscribe_url' => $this->linkUrl($row, 'unsubscribe'),
            ]
        );
    }

    /** @param array<string, mixed> $row */
    private function sendAvailable(array $row, \WC_Product $product): bool
    {
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        return $this->mail(
            (string) $row['email'],
            /* translators: %s: product name */
            sprintf(__('%s is back in stock', 'stackless-lite'), $product->get_name()),
            __('It is back', 'stackless-lite'),
            'email-available.php',
            [
                'product' => $product,
                'product_url' => $parent ? $product->get_permalink() : home_url('/'),
                'unsubscribe_url' => $this->linkUrl($row, 'unsubscribe'),
            ]
        );
    }

    /* ------------------------------------------------------------------
     * Stock changes and batches
     * ---------------------------------------------------------------- */

    /**
     * @param int|string $product_id
     * @param string $status
     * @param \WC_Product|mixed $product
     */
    public function stockStatusChanged($product_id, $status, $product = null): void
    {
        if (Batch::isAvailable((string) $status, $this->option('backorders', 'no') === 'yes')) {
            $this->queue((int) $product_id);
        }
    }

    /** Queue the first batch for a product unless one is already pending. */
    public function queue(int $product_id): bool
    {
        if ($product_id <= 0 || !function_exists('as_enqueue_async_action') || !function_exists('as_has_scheduled_action')) {
            return false;
        }
        $args = ['product_id' => $product_id, 'after' => 0];
        if (as_has_scheduled_action(self::ACTION, $args, self::GROUP)) {
            return false;
        }
        as_enqueue_async_action(self::ACTION, $args, self::GROUP);
        return true;
    }

    /**
     * Send one batch, then queue the next if this one was full.
     *
     * @param int|string $product_id
     * @param int|string $after
     */
    public function processBatch($product_id, $after = 0): void
    {
        if (\Stackless\Core\LiveStore::hold(self::ACTION, ['product_id' => (int) $product_id, 'after' => (int) $after], self::GROUP)) {
            return;
        }
        $product_id = (int) $product_id;
        $after = (int) $after;
        $product = wc_get_product($product_id);
        $include_backorder = $this->option('backorders', 'no') === 'yes';
        if (!$product || !Batch::isAvailable($product->get_stock_status(), $include_backorder)) {
            return;
        }
        $rows = $this->repo()->confirmedBatch($product_id, $after, Batch::SIZE);
        $sent = 0;
        $failed = 0;
        $fresh = $product;
        foreach ($rows as $i => $row) {
            // Stock can run out again mid-batch; re-check every 10 emails and stop if it did.
            if ($i > 0 && $i % 10 === 0) {
                clean_post_cache($product_id);
                $fresh = wc_get_product($product_id);
                if (!$fresh || !Batch::isAvailable($fresh->get_stock_status(), $include_backorder)) {
                    $this->log(sprintf('Product #%d went out of stock during a batch; remaining alerts wait for the next restock.', $product_id));
                    return;
                }
            }
            // Claim before sending so a parallel worker cannot email the same person twice.
            if (!$this->repo()->claim((int) $row['id'], 'confirmed', 'notified')) {
                continue;
            }
            $switched = !empty($row['locale']) && function_exists('switch_to_locale') && switch_to_locale((string) $row['locale']);
            $ok = $this->sendAvailable($row, $fresh);
            if ($switched) {
                restore_previous_locale();
            }
            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $this->repo()->setStatus((int) $row['id'], 'failed');
            }
        }
        if ($sent || $failed) {
            $this->log(sprintf('Product #%d: %d availability email(s) sent, %d failed.', $product_id, $sent, $failed), $failed ? 'warning' : 'info');
        }
        $next = Batch::nextCursor(array_map(function ($row) {
            return (int) $row['id'];
        }, $rows));
        if ($next !== null && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, ['product_id' => $product_id, 'after' => $next], self::GROUP);
        }
    }

    public function adminRun(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do that.', 'stackless-lite'), '', ['response' => 403]);
        }
        check_admin_referer('stackless_back_in_stock_run');
        $queued = 0;
        $include_backorder = $this->option('backorders', 'no') === 'yes';
        foreach ($this->repo()->productsWaiting() as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && Batch::isAvailable($product->get_stock_status(), $include_backorder) && $this->queue($product_id)) {
                $queued++;
            }
        }
        wp_safe_redirect(add_query_arg(['page' => AdminScreen::SLUG, 'queued' => $queued], admin_url('admin.php')));
        exit;
    }

    /** @param int|string $post_id */
    public function productDeleted($post_id): void
    {
        $type = get_post_type((int) $post_id);
        if ($type === 'product' || $type === 'product_variation') {
            $this->repo()->deleteByProduct((int) $post_id);
        }
    }

    /* ------------------------------------------------------------------
     * Privacy
     * ---------------------------------------------------------------- */

    /** @param array<string, array<string, mixed>> $exporters */
    public function registerExporter($exporters): array
    {
        $exporters['stackless-back-in-stock'] = ['exporter_friendly_name' => __('Back-in-stock alerts', 'stackless-lite'), 'callback' => [$this, 'export']];
        return (array) $exporters;
    }

    /** @param array<string, array<string, mixed>> $erasers */
    public function registerEraser($erasers): array
    {
        $erasers['stackless-back-in-stock'] = ['eraser_friendly_name' => __('Back-in-stock alerts', 'stackless-lite'), 'callback' => [$this, 'erase']];
        return (array) $erasers;
    }

    /** @return array{data: array<int, mixed>, done: bool} */
    public function export(string $email, int $page = 1): array
    {
        $per_page = 100;
        $rows = $this->repo()->byEmail(strtolower($email), $per_page, max(0, ($page - 1) * $per_page));
        $data = [];
        foreach ($rows as $row) {
            $product = wc_get_product((int) $row['product_id']);
            $data[] = [
                'group_id' => 'stackless-back-in-stock',
                'group_label' => __('Back-in-stock alerts', 'stackless-lite'),
                'item_id' => 'stackless-stock-alert-' . (int) $row['id'],
                'data' => [
                    ['name' => __('Product', 'stackless-lite'), 'value' => $product ? $product->get_name() : '#' . (int) $row['product_id']],
                    ['name' => __('Email', 'stackless-lite'), 'value' => (string) $row['email']],
                    ['name' => __('Status', 'stackless-lite'), 'value' => (string) $row['status']],
                    ['name' => __('Signed up', 'stackless-lite'), 'value' => (string) $row['created_at'] . ' UTC'],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < $per_page];
    }

    /** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
    public function erase(string $email, int $page = 1): array
    {
        $removed = $this->repo()->deleteByEmail(strtolower($email));
        return ['items_removed' => $removed > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
