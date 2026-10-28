<?php
/**
 * "Save this cart" form below the cart.
 *
 * Override in your theme at stackless/saved_carts/cart-form.php.
 *
 * @var string $nonce
 * @var string $account_url
 */

defined('ABSPATH') || exit;

$stackless_button_class = function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '';
?>
<form method="post" class="stackless-saved-carts-save stackless-saved-carts-cart">
    <input type="hidden" name="stackless_saved_carts_op" value="save">
    <input type="hidden" name="_stackless_saved_carts_nonce" value="<?php echo esc_attr($nonce); ?>">
    <label for="stackless-cart-save-name"><strong><?php esc_html_e('Save this cart for later', 'stackless-lite'); ?></strong></label>
    <span class="stackless-saved-carts-row">
        <input type="text" id="stackless-cart-save-name" name="cart_name" maxlength="100" required placeholder="<?php esc_attr_e('Cart name', 'stackless-lite'); ?>">
        <button type="submit" class="button<?php echo esc_attr($stackless_button_class); ?>"><?php esc_html_e('Save cart', 'stackless-lite'); ?></button>
    </span>
    <?php if ($account_url) : ?>
        <a class="stackless-saved-carts-muted" href="<?php echo esc_url($account_url); ?>"><?php esc_html_e('View saved carts', 'stackless-lite'); ?></a>
    <?php endif; ?>
</form>
