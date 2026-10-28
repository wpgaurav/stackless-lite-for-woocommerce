<?php
/**
 * After-payment instructions for service products (thank-you page, My Account order view).
 *
 * Override in your theme at stackless/service_products/instructions.php.
 *
 * @var array<int, array{name:string, heading:string, instructions:string, link:string, link_label:string}> $blocks
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

foreach ($blocks as $stackless_block) : ?>
    <section class="stackless-service-instructions">
        <h2><?php echo esc_html($stackless_block['heading']); ?></h2>
        <?php if (trim(wp_strip_all_tags($stackless_block['instructions'])) !== '') : ?>
            <?php echo wp_kses_post(wpautop($stackless_block['instructions'])); ?>
        <?php endif; ?>
        <?php if ($stackless_block['link'] !== '') : ?>
            <p class="stackless-service-link"><a class="button" href="<?php echo esc_url($stackless_block['link']); ?>" target="_blank" rel="noopener"><?php echo esc_html($stackless_block['link_label']); ?></a></p>
        <?php endif; ?>
    </section>
<?php endforeach;
