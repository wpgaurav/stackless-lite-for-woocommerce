<?php
/**
 * Back-in-stock availability email body (wrapped in the WooCommerce email template).
 *
 * Override in your theme at stackless/back_in_stock/email-available.php.
 *
 * @var WC_Product $product
 * @var string     $product_url
 * @var string     $unsubscribe_url
 * @var string     $heading
 */

defined('ABSPATH') || exit;
?>
<p>
    <?php
    /* translators: %s: product name */
    echo esc_html(sprintf(__('Good news: %s is back in stock.', 'stackless-lite'), $product->get_name()));
    ?>
</p>
<?php if ($product->get_image_id()) : ?>
    <p><?php echo wp_kses_post(wp_get_attachment_image((int) $product->get_image_id(), 'woocommerce_thumbnail', false, ['style' => 'max-width:160px;height:auto;'])); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url($product_url); ?>" style="display:inline-block;padding:10px 18px;border-radius:4px;background:#2271b1;color:#fff;text-decoration:none;font-weight:600;"><?php esc_html_e('View product', 'stackless-lite'); ?></a></p>
<p><?php esc_html_e('Stock is limited and items are not reserved, so it may sell out again.', 'stackless-lite'); ?></p>
<p style="font-size:12px;color:#646970;">
    <?php esc_html_e('This was a one-time alert you asked for.', 'stackless-lite'); ?>
    <a href="<?php echo esc_url($unsubscribe_url); ?>"><?php esc_html_e('Manage this alert', 'stackless-lite'); ?></a>
</p>
