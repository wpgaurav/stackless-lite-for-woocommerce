<?php
/**
 * Service instructions in HTML customer emails.
 *
 * Override in your theme at stackless/service_products/email.php.
 *
 * @var array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}> $blocks
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

foreach ($blocks as $block) : ?>
    <div style="margin:0 0 24px;">
        <h2><?php echo esc_html($block['heading']); ?></h2>
        <?php if (trim(wp_strip_all_tags($block['instructions'])) !== '') : ?>
            <?php echo wp_kses_post(wpautop($block['instructions'])); ?>
        <?php endif; ?>
        <?php if ($block['link'] !== '') : ?>
            <p><a href="<?php echo esc_url($block['link']); ?>" style="font-weight:600;"><?php echo esc_html($block['link_label']); ?></a></p>
        <?php endif; ?>
    </div>
<?php endforeach;
