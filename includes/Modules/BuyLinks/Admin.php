<?php

namespace Stackless\Modules\BuyLinks;

defined('ABSPATH') || exit;

/** "Buy link" box on the product screen: link generator and the per-product Buy now override. */
final class Admin
{
    public const AJAX = 'stackless_buy_links_generate';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'metaBox'], 30);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProduct'], 20);
        add_action('wp_ajax_' . self::AJAX, [$this, 'generate']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function metaBox(): void
    {
        add_meta_box('stackless-buy-link', __('Buy link', 'stackless-lite'), [$this, 'render'], 'product', 'side', 'default');
    }

    public function assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || get_post_type() !== 'product') {
            return;
        }
        $url = STACKLESS_PLUGIN_URL . 'assets/buy_links/';
        wp_enqueue_style('stackless-buy_links-admin', $url . 'admin.css', [], STACKLESS_VERSION);
        wp_enqueue_script('stackless-buy_links-admin', $url . 'admin.js', ['jquery', 'wc-enhanced-select'], STACKLESS_VERSION, true);
        wp_localize_script('stackless-buy_links-admin', 'stacklessBuyLinks', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX,
            'nonce' => wp_create_nonce(self::AJAX),
            'copied' => __('Link copied.', 'stackless-lite'),
            'copyFailed' => __('Copy failed. Select the link and copy it yourself.', 'stackless-lite'),
            'error' => __('The link could not be made. Save the product and try again.', 'stackless-lite'),
        ]);
    }

    /** @param \WP_Post $post */
    public function render($post): void
    {
        $product = wc_get_product($post->ID);
        if (!$product instanceof \WC_Product) {
            return;
        }
        $required = Links::signingRequired();
        $override = (string) $product->get_meta(Module::META_BUY_NOW, true);
        wp_nonce_field('stackless_buy_links_product', 'stackless_buy_links_nonce');
        echo '<div class="stackless-bl" data-stackless-bl data-product="' . (int) $product->get_id() . '">';
        if ($product->get_status() !== 'publish') {
            echo '<p class="description">' . esc_html__('Publish the product before sharing its link. Links to drafts only work for store managers.', 'stackless-lite') . '</p>';
        }
        if ($product->is_type(['grouped', 'external'])) {
            echo '<p>' . esc_html__('Grouped and external products cannot be bought through a link.', 'stackless-lite') . '</p></div>';
            return;
        }
        if ($product->is_type('variable')) {
            echo '<p><label for="stackless-bl-variation">' . esc_html__('Variation', 'stackless-lite') . '</label><select id="stackless-bl-variation" class="widefat" data-bl-variation><option value="">' . esc_html__('Choose a variation', 'stackless-lite') . '</option>';
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product($childId);
                if ($child instanceof \WC_Product_Variation) {
                    echo '<option value="' . (int) $childId . '">' . esc_html(wp_strip_all_tags(wc_get_formatted_variation($child, true, false, false)) ?: '#' . $childId) . '</option>';
                }
            }
            echo '</select></p>';
        }
        echo '<p><label for="stackless-bl-qty">' . esc_html__('Quantity', 'stackless-lite') . '</label><input type="number" id="stackless-bl-qty" class="small-text" min="1" max="' . (int) LinkParser::MAX_QTY . '" value="1" data-bl-qty></p>';
        echo '<p><label for="stackless-bl-extra">' . esc_html__('Also add', 'stackless-lite') . '</label><select id="stackless-bl-extra" class="wc-product-search" multiple data-action="woocommerce_json_search_products_and_variations" data-exclude="' . (int) $product->get_id() . '" data-placeholder="' . esc_attr__('Other products (optional)', 'stackless-lite') . '" style="width:100%" data-bl-extra></select></p>';
        echo '<p><label for="stackless-bl-coupon">' . esc_html__('Coupon', 'stackless-lite') . '</label><input type="text" id="stackless-bl-coupon" class="widefat" maxlength="200" data-bl-coupon placeholder="' . esc_attr__('Optional', 'stackless-lite') . '"></p>';
        echo '<p><label for="stackless-bl-clear">' . esc_html__('Existing cart', 'stackless-lite') . '</label><select id="stackless-bl-clear" class="widefat" data-bl-clear><option value="">' . esc_html__('Store default', 'stackless-lite') . '</option><option value="1">' . esc_html__('Empty it first', 'stackless-lite') . '</option><option value="0">' . esc_html__('Keep it', 'stackless-lite') . '</option></select></p>';
        echo '<p><label for="stackless-bl-to">' . esc_html__('Open', 'stackless-lite') . '</label><select id="stackless-bl-to" class="widefat" data-bl-to><option value="">' . esc_html__('Store default', 'stackless-lite') . '</option><option value="checkout">' . esc_html__('Checkout', 'stackless-lite') . '</option><option value="cart">' . esc_html__('Cart', 'stackless-lite') . '</option></select></p>';
        echo '<p><label><input type="checkbox" data-bl-sign' . checked(true, true, false) . disabled($required, true, false) . '> ' . esc_html__('Sign the link so it cannot be edited', 'stackless-lite') . '</label></p>';
        echo '<p data-bl-exp-row><label for="stackless-bl-exp">' . esc_html__('Expires (optional)', 'stackless-lite') . '</label><input type="date" id="stackless-bl-exp" class="widefat" data-bl-exp min="' . esc_attr(wp_date('Y-m-d')) . '"></p>';
        echo '<p><button type="button" class="button" data-bl-generate>' . esc_html__('Make link', 'stackless-lite') . '</button></p>';
        echo '<div class="stackless-bl-result" data-bl-result hidden><label for="stackless-bl-url" class="screen-reader-text">' . esc_html__('Buy link', 'stackless-lite') . '</label><input type="text" id="stackless-bl-url" class="widefat code" readonly data-bl-url><button type="button" class="button button-small" data-bl-copy>' . esc_html__('Copy', 'stackless-lite') . '</button></div>';
        echo '<p class="stackless-bl-status" role="status" aria-live="polite" data-bl-status></p>';
        echo '<hr><p><label for="stackless-bl-buy-now">' . esc_html__('Buy now button', 'stackless-lite') . '</label><select id="stackless-bl-buy-now" name="stackless_buy_links_buy_now" class="widefat">';
        foreach (['' => __('Store default', 'stackless-lite'), 'yes' => __('Show on this product', 'stackless-lite'), 'no' => __('Hide on this product', 'stackless-lite')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($override, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p></div>';
    }

    public function saveProduct($product): void
    {
        if (!$product instanceof \WC_Product || !isset($_POST['stackless_buy_links_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['stackless_buy_links_nonce'])), 'stackless_buy_links_product') || !current_user_can('edit_product', $product->get_id())) {
            return;
        }
        $value = isset($_POST['stackless_buy_links_buy_now']) ? sanitize_key(wp_unslash($_POST['stackless_buy_links_buy_now'])) : '';
        if ($value === 'yes' || $value === 'no') {
            $product->update_meta_data(Module::META_BUY_NOW, $value);
        } else {
            $product->delete_meta_data(Module::META_BUY_NOW);
        }
    }

    public function generate(): void
    {
        check_ajax_referer(self::AJAX, 'nonce');
        $productId = isset($_POST['product_id']) ? absint(wp_unslash($_POST['product_id'])) : 0;
        if (!$productId || !current_user_can('edit_product', $productId)) {
            wp_send_json_error(['message' => __('You cannot make links for this product.', 'stackless-lite')], 403);
        }
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product) {
            wp_send_json_error(['message' => __('Product not found.', 'stackless-lite')], 404);
        }
        $qty = isset($_POST['qty']) ? max(1, min(LinkParser::MAX_QTY, absint(wp_unslash($_POST['qty'])))) : 1;
        $items = [];
        if ($product->is_type('variable')) {
            $variationId = isset($_POST['variation_id']) ? absint(wp_unslash($_POST['variation_id'])) : 0;
            if (!$variationId || !in_array($variationId, array_map('intval', $product->get_children()), true)) {
                wp_send_json_error(['message' => __('Choose a variation first.', 'stackless-lite')], 400);
            }
            $items[] = ['product' => $productId, 'variation' => $variationId, 'qty' => $qty];
        } else {
            $items[] = ['product' => $productId, 'variation' => 0, 'qty' => $qty];
        }
        $skipped = 0;
        $extra = isset($_POST['extra']) ? array_map('absint', (array) wp_unslash($_POST['extra'])) : [];
        foreach (array_slice(array_filter($extra), 0, LinkParser::MAX_ITEMS - 1) as $id) {
            $other = wc_get_product($id);
            if (!$other instanceof \WC_Product || $other->is_type(['variable', 'grouped', 'external'])) {
                $skipped++;
                continue;
            }
            $items[] = $other instanceof \WC_Product_Variation
                ? ['product' => $other->get_parent_id(), 'variation' => $other->get_id(), 'qty' => 1]
                : ['product' => $other->get_id(), 'variation' => 0, 'qty' => 1];
        }
        $sign = Links::signingRequired() || (isset($_POST['sign']) && sanitize_key(wp_unslash($_POST['sign'])) === '1');
        $exp = 0;
        $date = isset($_POST['exp']) ? sanitize_text_field(wp_unslash($_POST['exp'])) : '';
        if ($sign && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $end = date_create_immutable($date . ' 23:59:59', wp_timezone());
            $exp = $end ? $end->getTimestamp() : 0;
        }
        $url = Links::url($items, [
            'coupon' => isset($_POST['coupon']) ? sanitize_text_field(wp_unslash($_POST['coupon'])) : '',
            'clear' => isset($_POST['clear']) ? sanitize_key(wp_unslash($_POST['clear'])) : '',
            'to' => isset($_POST['to']) ? sanitize_key(wp_unslash($_POST['to'])) : '',
            'sign' => $sign,
            'exp' => $exp,
        ]);
        $message = $skipped
            /* translators: %d: number of products left out */
            ? sprintf(_n('%d product was left out: variable, grouped and external products need a specific variation or cannot be linked.', '%d products were left out: variable, grouped and external products need a specific variation or cannot be linked.', $skipped, 'stackless-lite'), $skipped)
            : __('Link ready.', 'stackless-lite');
        wp_send_json_success(['url' => $url, 'message' => $message]);
    }
}
