<?php

namespace Stackless\Modules\ServiceProducts;

defined('ABSPATH') || exit;

/**
 * Service products: no shipping, and after-payment instructions (rich text) with an
 * optional booking or intake link on the thank-you page, in order emails and in
 * My Account → Orders.
 */
class Module extends \Stackless\Core\Module
{
    public const META = '_stackless_service';
    public const META_INSTRUCTIONS = '_stackless_service_instructions';
    public const META_LINK = '_stackless_service_link';
    public const META_LINK_LABEL = '_stackless_service_link_label';
    public const ITEM_META = '_stackless_service';

    /** @var array<int, bool> orders already printed on this request */
    private $printed = [];

    public function id(): string
    {
        return 'service_products';
    }

    public function title(): string
    {
        return __('Service Products', 'stackless-lite');
    }

    public function description(): string
    {
        return __('Mark products as services: no shipping, plus after-payment instructions and an optional booking or intake link on the thank-you page, emails and the customer account.', 'stackless-lite');
    }

    public function group(): string
    {
        return 'store';
    }

    public function settingsFields(): array
    {
        return [
            ['type' => 'title', 'id' => 'stackless_service_products_options', 'title' => __('Service products', 'stackless-lite'), 'desc' => __('Tick "Service" next to Virtual and Downloadable on a product, then add instructions in its Service tab.', 'stackless-lite')],
            [
                'id' => 'stackless_service_products_heading',
                'type' => 'text',
                'title' => __('Heading', 'stackless-lite'),
                'desc' => __('Shown above the instructions. %s is replaced with the product name.', 'stackless-lite'),
                'desc_tip' => true,
                'default' => __('%s: what happens next', 'stackless-lite'),
            ],
            [
                'id' => 'stackless_service_products_emails',
                'type' => 'checkbox',
                'title' => __('Emails', 'stackless-lite'),
                'desc' => __('Include instructions in the customer\'s processing and completed order emails', 'stackless-lite'),
                'default' => 'yes',
            ],
            [
                'id' => 'stackless_service_products_pending_note',
                'type' => 'text',
                'title' => __('Before payment', 'stackless-lite'),
                'desc' => __('Shown instead of the instructions while an order is unpaid (for example bank transfer). Leave empty to show nothing.', 'stackless-lite'),
                'desc_tip' => true,
                'default' => __('Instructions for your service appear here as soon as your payment is confirmed.', 'stackless-lite'),
            ],
            ['type' => 'sectionend', 'id' => 'stackless_service_products_options'],
        ];
    }

    public function boot(): void
    {
        // Product editor.
        add_filter('product_type_options', [$this, 'typeOption']);
        add_filter('woocommerce_product_data_tabs', [$this, 'tab']);
        add_action('woocommerce_product_data_panels', [$this, 'panel']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save']);
        add_action('admin_enqueue_scripts', [$this, 'adminAssets']);

        // Shipping.
        add_filter('woocommerce_product_needs_shipping', [$this, 'needsShipping'], 20, 2);

        // Orders.
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'markLine'], 20, 4);
        add_action('woocommerce_order_details_after_order_table', [$this, 'orderDetails'], 5);
        add_action('woocommerce_thankyou', [$this, 'orderDetails'], 5);
        add_action('woocommerce_email_after_order_table', [$this, 'email'], 5, 4);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hiddenItemMeta']);
    }

    /* ------------------------------------------------------------------
     * Detection
     * ---------------------------------------------------------------- */

    /** @param \WC_Product|int|null $product */
    public static function isService($product): bool
    {
        $product = is_numeric($product) ? wc_get_product((int) $product) : $product;
        if (!$product instanceof \WC_Product) {
            return false;
        }
        if ($product->is_type('variation')) {
            $product = wc_get_product($product->get_parent_id());
            if (!$product) {
                return false;
            }
        }
        return $product->get_meta(self::META, true) === 'yes';
    }

    /** @param bool $needs */
    public function needsShipping($needs, $product): bool
    {
        return $needs && !self::isService($product instanceof \WC_Product ? $product : null);
    }

    /* ------------------------------------------------------------------
     * Product editor
     * ---------------------------------------------------------------- */

    /** @param array<string, array<string, string>> $options */
    public function typeOption($options): array
    {
        $options['stackless_service'] = [
            'id' => self::META,
            'wrapper_class' => 'show_if_simple show_if_variable',
            'label' => __('Service', 'stackless-lite'),
            'description' => __('Services need no shipping. Customers get the instructions from the Service tab after paying.', 'stackless-lite'),
            'default' => 'no',
        ];
        return (array) $options;
    }

    /** @param array<string, array<string, mixed>> $tabs */
    public function tab($tabs): array
    {
        $tabs['stackless_service'] = [
            'label' => __('Service', 'stackless-lite'),
            'target' => 'stackless_service_data',
            'class' => ['show_if_simple', 'show_if_variable', 'stackless-service-tab'],
            'priority' => 76,
        ];
        return (array) $tabs;
    }

    public function adminAssets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'product') {
            wp_enqueue_script('stackless-service_products-admin', STACKLESS_PLUGIN_URL . 'assets/service_products/admin.js', ['jquery'], STACKLESS_VERSION, true);
        }
    }

    public function panel(): void
    {
        global $post;
        $product = $post ? wc_get_product($post->ID) : null;
        if (!$product) {
            return;
        }
        echo '<div id="stackless_service_data" class="panel woocommerce_options_panel hidden">';
        echo '<p class="form-field stackless-docs-field">' . \Stackless\Admin\Documentation::link('service_products', __('How this works', 'stackless-lite')) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Documentation::link().
        echo '<div class="options_group"><p class="form-field"><label for="stackless_service_instructions">' . esc_html__('After-payment instructions', 'stackless-lite') . '</label></p><div style="padding:0 20px 12px 162px;">';
        wp_editor((string) $product->get_meta(self::META_INSTRUCTIONS, true), 'stackless_service_instructions', [
            'textarea_name' => 'stackless_service_instructions',
            'textarea_rows' => 8,
            'media_buttons' => false,
            'teeny' => true,
        ]);
        echo '<p class="description">' . esc_html__('How the customer books, sends a brief or receives the service. Shown on the thank-you page, in paid order emails and in My Account once the order is paid.', 'stackless-lite') . '</p></div></div>';
        echo '<div class="options_group">';
        woocommerce_wp_text_input([
            'id' => 'stackless_service_link',
            'value' => (string) $product->get_meta(self::META_LINK, true),
            'label' => __('Booking or intake link', 'stackless-lite'),
            'placeholder' => 'https://cal.com/you/session?email={email}',
            'desc_tip' => true,
            'description' => __('Optional. Placeholders: {order_number}, {order_id}, {email}, {first_name}, {last_name}, {name}, {product}. Values are URL-encoded.', 'stackless-lite'),
            'type' => 'url',
        ]);
        woocommerce_wp_text_input([
            'id' => 'stackless_service_link_label',
            'value' => (string) $product->get_meta(self::META_LINK_LABEL, true),
            'label' => __('Link button text', 'stackless-lite'),
            'placeholder' => __('Book your session', 'stackless-lite'),
        ]);
        echo '</div></div>';
    }

    public function save(\WC_Product $product): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- WooCommerce verified the product save nonce.
        $product->update_meta_data(self::META, isset($_POST[self::META]) ? 'yes' : 'no');
        if (isset($_POST['stackless_service_instructions'])) {
            $product->update_meta_data(self::META_INSTRUCTIONS, wp_kses_post(wp_unslash($_POST['stackless_service_instructions'])));
        }
        if (isset($_POST['stackless_service_link'])) {
            $raw = trim(sanitize_text_field(wp_unslash($_POST['stackless_service_link'])));
            // Keep placeholders intact; check the link with sample values.
            $valid = $raw === '' || Links::fill($raw, ['order_id' => '1', 'order_number' => '1', 'email' => 'a@b.c', 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B', 'product' => 'P']) !== '';
            $product->update_meta_data(self::META_LINK, $valid ? $raw : '');
            if (!$valid && class_exists('WC_Admin_Meta_Boxes')) {
                \WC_Admin_Meta_Boxes::add_error(__('The booking link must start with http:// or https://. It was not saved.', 'stackless-lite'));
            }
        }
        if (isset($_POST['stackless_service_link_label'])) {
            $product->update_meta_data(self::META_LINK_LABEL, sanitize_text_field(wp_unslash($_POST['stackless_service_link_label'])));
        }
        // phpcs:enable
    }

    /* ------------------------------------------------------------------
     * Orders
     * ---------------------------------------------------------------- */

    /**
     * @param \WC_Order_Item_Product|mixed $item
     * @param string $key
     * @param array<string, mixed> $values
     */
    public function markLine($item, $key, $values, $order = null): void
    {
        if ($item instanceof \WC_Order_Item_Product && self::isService($item->get_product() ?: null)) {
            $item->add_meta_data(self::ITEM_META, 'yes', true);
        }
    }

    /** @param string[] $keys */
    public function hiddenItemMeta($keys): array
    {
        $keys = (array) $keys;
        $keys[] = self::ITEM_META;
        return $keys;
    }

    /**
     * Service blocks for an order: one per service product.
     *
     * @return array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}>
     */
    public function blocks(\WC_Order $order): array
    {
        $lines = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $id = (int) $item->get_product_id();
            $lines[] = ['product_id' => $id, 'is_service' => $item->get_meta(self::ITEM_META, true) === 'yes' || self::isService($id)];
        }
        $blocks = [];
        $heading = (string) $this->option('heading', __('%s: what happens next', 'stackless-lite'));
        foreach (Links::serviceProducts($lines) as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            $instructions = (string) $product->get_meta(self::META_INSTRUCTIONS, true);
            $link = Links::fill((string) $product->get_meta(self::META_LINK, true), [
                'order_id' => (string) $order->get_id(),
                'order_number' => (string) $order->get_order_number(),
                'email' => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'product' => $product->get_name(),
            ]);
            if (trim(wp_strip_all_tags($instructions)) === '' && $link === '') {
                continue;
            }
            $label = (string) $product->get_meta(self::META_LINK_LABEL, true);
            $blocks[] = [
                'name' => $product->get_name(),
                'heading' => str_replace('%s', $product->get_name(), $heading),
                'instructions' => $instructions,
                'link' => $link,
                'link_label' => $label !== '' ? $label : __('Book your session', 'stackless-lite'),
            ];
        }
        return $blocks;
    }

    private function hasServices(\WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product && ($item->get_meta(self::ITEM_META, true) === 'yes' || self::isService((int) $item->get_product_id()))) {
                return true;
            }
        }
        return false;
    }

    /** @param \WC_Order|int|mixed $order */
    public function orderDetails($order): void
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order((int) $order);
        if (!$order instanceof \WC_Order || isset($this->printed[$order->get_id()])) {
            return;
        }
        $this->printed[$order->get_id()] = true;
        if (!$order->is_paid()) {
            $note = (string) $this->option('pending_note', '');
            if ($note !== '' && !$order->has_status(['cancelled', 'failed', 'refunded']) && $this->hasServices($order)) {
                echo '<p class="stackless-service-pending woocommerce-info">' . esc_html($note) . '</p>';
            }
            return;
        }
        $blocks = $this->blocks($order);
        if ($blocks) {
            wp_enqueue_style('stackless-service_products', STACKLESS_PLUGIN_URL . 'assets/service_products/service.css', [], STACKLESS_VERSION);
            stackless_get_template('service_products/instructions.php', ['blocks' => $blocks, 'order' => $order]);
        }
    }

    /**
     * @param \WC_Order|mixed $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     * @param \WC_Email|mixed $email
     */
    public function email($order, $sent_to_admin = false, $plain_text = false, $email = null): void
    {
        if ($sent_to_admin || !$order instanceof \WC_Order || $this->option('emails', 'yes') !== 'yes') {
            return;
        }
        $id = $email instanceof \WC_Email ? (string) $email->id : '';
        if (!in_array($id, ['customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_note'], true) || !$order->is_paid()) {
            return;
        }
        $blocks = $this->blocks($order);
        if ($blocks) {
            stackless_get_template($plain_text ? 'service_products/email-plain.php' : 'service_products/email.php', ['blocks' => $blocks, 'order' => $order]);
        }
    }
}
