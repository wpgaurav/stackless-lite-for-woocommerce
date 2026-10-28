<?php
/**
 * "Name your price" box on the classic checkout, one amount field per product that takes a
 * chosen amount. It sits inside the checkout form, so the amounts are sent with Place order.
 *
 * Override in your theme at stackless/pay_what_you_like/checkout-box.php.
 *
 * @var string $heading
 * @var string $note
 * @var array<int, array<string, mixed>> $fields arguments for pay_what_you_like/amount-field.php
 */

defined('ABSPATH') || exit;

$stackless_title_id = wp_unique_id('stackless-pwyl-title-');
?>
<section class="stackless-pwyl-box" aria-labelledby="<?php echo esc_attr($stackless_title_id); ?>" data-stackless-pwyl-box>
    <h3 class="stackless-pwyl-box__title" id="<?php echo esc_attr($stackless_title_id); ?>"><?php echo esc_html($heading); ?></h3>
    <?php if ($note !== '') : ?>
        <p class="stackless-pwyl-box__note"><?php echo esc_html($note); ?></p>
    <?php endif; ?>
    <?php
    foreach ($fields as $stackless_field) {
        stackless_get_template('pay_what_you_like/amount-field.php', $stackless_field + ['note' => '', 'product_id' => 0, 'variable' => false]);
    }
    ?>
</section>
