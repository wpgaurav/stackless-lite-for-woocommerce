<?php
/**
 * Saved carts and order again (My Account tab and [stackless_saved_carts]).
 *
 * Override in your theme at stackless/saved_carts/account.php.
 *
 * @var array<int, array<string, mixed>> $carts each: key, name, created, lines, quantity, previews[]
 * @var WC_Order[] $orders
 * @var callable   $order_again_url fn(WC_Order): string
 * @var bool       $can_save
 * @var int        $cart_count
 * @var int        $limit
 * @var string     $nonce
 * @var bool       $in_account
 */

defined('ABSPATH') || exit;

$stackless_button_class = function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '';
?>
<section class="stackless-saved-carts" aria-label="<?php esc_attr_e('Saved carts and order again', 'stackless-lite'); ?>">
    <p class="stackless-saved-carts-intro"><?php esc_html_e('Keep a cart for later, or start a new cart from a past order. Prices and stock are checked again when you restore.', 'stackless-lite'); ?></p>

    <div class="stackless-saved-carts-panel">
        <h3><?php esc_html_e('Save your current cart', 'stackless-lite'); ?></h3>
        <?php if ($can_save) : ?>
            <form method="post" class="stackless-saved-carts-save">
                <input type="hidden" name="stackless_saved_carts_op" value="save">
                <input type="hidden" name="_stackless_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
                <p class="stackless-saved-carts-muted">
                    <?php
                    /* translators: %d: number of items */
                    echo esc_html(sprintf(_n('%d item in your cart.', '%d items in your cart.', $cart_count, 'stackless-lite'), $cart_count));
                    ?>
                </p>
                <label for="stackless-saved-cart-name"><?php esc_html_e('Cart name', 'stackless-lite'); ?></label>
                <span class="stackless-saved-carts-row">
                    <input type="text" id="stackless-saved-cart-name" name="cart_name" maxlength="100" required placeholder="<?php esc_attr_e('For example: monthly supplies', 'stackless-lite'); ?>">
                    <button type="submit" class="button<?php echo esc_attr($stackless_button_class); ?>"><?php esc_html_e('Save cart', 'stackless-lite'); ?></button>
                </span>
            </form>
        <?php else : ?>
            <p class="stackless-saved-carts-muted"><?php esc_html_e('Your cart is empty. Add products, then save the cart here.', 'stackless-lite'); ?></p>
            <p><a class="button" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Browse products', 'stackless-lite'); ?></a></p>
        <?php endif; ?>
    </div>

    <div class="stackless-saved-carts-section">
        <h3><?php esc_html_e('Saved for later', 'stackless-lite'); ?> <span class="stackless-saved-carts-count"><?php echo esc_html(count($carts) . ' / ' . $limit); ?></span></h3>
        <?php if (!$carts) : ?>
            <p class="stackless-saved-carts-muted"><?php esc_html_e('Saved carts appear here.', 'stackless-lite'); ?></p>
        <?php endif; ?>
        <?php foreach ($carts as $cart) : ?>
            <article class="stackless-saved-cart">
                <div class="stackless-saved-cart-info">
                    <strong><?php echo esc_html($cart['name']); ?></strong>
                    <span class="stackless-saved-carts-muted">
                        <?php
                        /* translators: %d: number of products */
                        echo esc_html(sprintf(_n('%d product', '%d products', (int) $cart['lines'], 'stackless-lite'), (int) $cart['lines']));
                        if ($cart['created']) {
                            echo ' · ' . esc_html(wp_date((string) get_option('date_format'), (int) $cart['created']));
                        }
                        ?>
                    </span>
                    <span class="stackless-saved-cart-previews">
                        <?php foreach ($cart['previews'] as $preview) : ?>
                            <span title="<?php echo esc_attr($preview['name']); ?>"><?php echo wp_kses_post($preview['image']); ?></span>
                        <?php endforeach; ?>
                    </span>
                </div>
                <div class="stackless-saved-cart-actions">
                    <form method="post">
                        <input type="hidden" name="stackless_saved_carts_op" value="restore">
                        <input type="hidden" name="cart_key" value="<?php echo esc_attr($cart['key']); ?>">
                        <input type="hidden" name="_stackless_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <label class="stackless-saved-carts-muted"><input type="checkbox" name="replace" value="1" checked> <?php esc_html_e('Replace my current cart', 'stackless-lite'); ?></label>
                        <button type="submit" class="button<?php echo esc_attr($stackless_button_class); ?>"><?php esc_html_e('Restore cart', 'stackless-lite'); ?></button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="stackless_saved_carts_op" value="delete">
                        <input type="hidden" name="cart_key" value="<?php echo esc_attr($cart['key']); ?>">
                        <input type="hidden" name="_stackless_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
                        <button type="submit" class="stackless-link-button"><?php esc_html_e('Remove', 'stackless-lite'); ?></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="stackless-saved-carts-section">
        <h3><?php esc_html_e('Order again', 'stackless-lite'); ?></h3>
        <?php if (!$orders) : ?>
            <p class="stackless-saved-carts-muted"><?php esc_html_e('Your past orders appear here so you can reorder in one click.', 'stackless-lite'); ?></p>
        <?php endif; ?>
        <?php foreach ($orders as $order) : ?>
            <article class="stackless-saved-cart">
                <div class="stackless-saved-cart-info">
                    <strong>
                        <?php
                        /* translators: %s: order number */
                        echo esc_html(sprintf(__('Order #%s', 'stackless-lite'), $order->get_order_number()));
                        ?>
                    </strong>
                    <span class="stackless-saved-carts-muted">
                        <?php
                        $stackless_created = $order->get_date_created();
                        echo esc_html(($stackless_created ? wc_format_datetime($stackless_created) . ' · ' : '') . wc_get_order_status_name($order->get_status()) . ' · ');
                        echo wp_kses_post($order->get_formatted_order_total());
                        ?>
                    </span>
                </div>
                <div class="stackless-saved-cart-actions">
                    <a class="button" href="<?php echo esc_url($order_again_url($order)); ?>"><?php esc_html_e('Order again', 'stackless-lite'); ?></a>
                    <a class="stackless-link-button" href="<?php echo esc_url($order->get_view_order_url()); ?>"><?php esc_html_e('View', 'stackless-lite'); ?></a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
