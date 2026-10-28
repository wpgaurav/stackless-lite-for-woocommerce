<?php
/**
 * Wishlist button.
 *
 * Override in your theme at stackless/wishlist/button.php.
 *
 * @var WC_Product $product
 * @var string     $context  loop|single
 * @var bool       $in_list
 * @var bool       $variable
 * @var string     $nonce
 */

defined('ABSPATH') || exit;

$stackless_label = $in_list ? __('Saved to wishlist', 'stackless-lite') : __('Save to wishlist', 'stackless-lite');
?>
<form class="stackless-wishlist-form stackless-wishlist-form--<?php echo esc_attr($context); ?>" method="post" data-stackless-wishlist-button data-product-id="<?php echo esc_attr((string) $product->get_id()); ?>" data-variable="<?php echo $variable ? '1' : '0'; ?>">
    <input type="hidden" name="stackless_wishlist_op" value="toggle">
    <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $product->get_id()); ?>">
    <input type="hidden" name="variation_id" value="0">
    <input type="hidden" name="_stackless_wishlist_nonce" value="<?php echo esc_attr($nonce); ?>">
    <button type="submit" class="stackless-wishlist-toggle<?php echo $in_list ? ' is-saved' : ''; ?>" aria-pressed="<?php echo $in_list ? 'true' : 'false'; ?>">
        <svg class="stackless-wishlist-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19.5 12.572 12 20l-7.5-7.428A5 5 0 1 1 12 6.006a5 5 0 1 1 7.5 6.572" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="stackless-wishlist-label"><?php echo esc_html($stackless_label); ?></span>
    </button>
    <span class="stackless-wishlist-status screen-reader-text" role="status" aria-live="polite"></span>
</form>
