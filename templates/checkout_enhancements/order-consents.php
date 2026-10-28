<?php
/**
 * Consent answers stored with an order: thank-you page and My Account order view.
 *
 * Override in your theme at stackless/checkout_enhancements/order-consents.php.
 *
 * @var WC_Order $order
 * @var array[]  $records id, type, label, version, required, accepted, at (UTC)
 * @var string   $heading
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<section class="stackless-order-consents woocommerce-customer-details">
    <h2 class="woocommerce-column__title"><?php echo esc_html($heading); ?></h2>
    <dl>
        <?php foreach ($records as $stackless_record) :
            $stackless_time = strtotime((string) $stackless_record['at'] . ' UTC'); ?>
            <div>
                <dt><?php echo esc_html(wp_strip_all_tags($stackless_record['label'])); ?></dt>
                <dd>
                    <?php echo esc_html(!empty($stackless_record['accepted']) ? __('Accepted', 'stackless-lite') : __('Not accepted', 'stackless-lite')); ?>
                    <?php if ($stackless_time) : ?><br><small><time datetime="<?php echo esc_attr(gmdate('c', $stackless_time)); ?>"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $stackless_time)); ?></time></small><?php endif; ?>
                </dd>
            </div>
        <?php endforeach; ?>
    </dl>
</section>
