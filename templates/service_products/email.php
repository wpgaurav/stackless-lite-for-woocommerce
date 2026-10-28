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

foreach ($blocks as $stackless_block) : ?>
    <div style="margin:0 0 24px;">
        <h2><?php echo esc_html($stackless_block['heading']); ?></h2>
        <?php if (trim(wp_strip_all_tags($stackless_block['instructions'])) !== '') : ?>
            <?php echo wp_kses_post(wpautop($stackless_block['instructions'])); ?>
        <?php endif; ?>
        <?php if ($stackless_block['link'] !== '') : ?>
            <p><a href="<?php echo esc_url($stackless_block['link']); ?>" style="font-weight:600;"><?php echo esc_html($stackless_block['link_label']); ?></a></p>
        <?php endif; ?>
    </div>
<?php endforeach;
