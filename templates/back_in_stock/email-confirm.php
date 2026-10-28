<?php
/**
 * Back-in-stock confirmation email body (wrapped in the WooCommerce email template).
 *
 * Override in your theme at stackless/back_in_stock/email-confirm.php.
 *
 * @var WC_Product $product
 * @var string     $confirm_url
 * @var string     $unsubscribe_url
 * @var string     $heading
 */

defined('ABSPATH') || exit;
?>
<p>
    <?php
    /* translators: %s: product name */
    echo esc_html(sprintf(__('You asked to hear when %s is back in stock.', 'stackless-lite'), $product->get_name()));
    ?>
</p>
<p><a href="<?php echo esc_url($confirm_url); ?>" style="display:inline-block;padding:10px 18px;border-radius:4px;background:#2271b1;color:#fff;text-decoration:none;font-weight:600;"><?php esc_html_e('Confirm my alert', 'stackless-lite'); ?></a></p>
<p><?php esc_html_e('We will send one email when it is available. The link expires in 7 days.', 'stackless-lite'); ?></p>
<p style="font-size:12px;color:#646970;">
    <?php esc_html_e('Did not ask for this? Ignore this email and nothing will be sent.', 'stackless-lite'); ?>
    <a href="<?php echo esc_url($unsubscribe_url); ?>"><?php esc_html_e('Stop this alert', 'stackless-lite'); ?></a>
</p>
