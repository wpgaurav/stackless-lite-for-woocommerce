<?php
/**
 * "Notify me" form for out-of-stock products.
 *
 * Override in your theme at stackless/back_in_stock/form.php.
 *
 * @var WC_Product $product
 * @var array<int, array{id:int, name:string}> $options out-of-stock simple product or variations
 * @var bool   $variable
 * @var string $heading
 * @var string $email    prefilled for signed-in customers
 * @var string $nonce
 */

defined('ABSPATH') || exit;

$stackless_button_class = function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '';

$stackless_uid = wp_unique_id('stackless-stock-alert-');
$stackless_ids = wp_json_encode(array_map(function ($option) {
    return (int) $option['id'];
}, $options));
?>
<section class="stackless-stock-alert" data-stackless-stock-alert data-variable="<?php echo $variable ? '1' : '0'; ?>" data-options="<?php echo esc_attr((string) $stackless_ids); ?>"<?php echo $variable ? ' hidden' : ''; ?> aria-labelledby="<?php echo esc_attr($stackless_uid); ?>-title">
    <h3 class="stackless-stock-alert-title" id="<?php echo esc_attr($stackless_uid); ?>-title"><?php echo esc_html($heading); ?></h3>
    <form method="post" class="stackless-stock-alert-form" novalidate>
        <input type="hidden" name="product_id" value="<?php echo esc_attr((string) ($variable ? 0 : $options[0]['id'])); ?>">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
        <?php if ($variable) : ?>
            <p class="stackless-stock-alert-option" data-stackless-option-label></p>
        <?php endif; ?>
        <p class="stackless-stock-alert-field">
            <label for="<?php echo esc_attr($stackless_uid); ?>-email"><?php esc_html_e('Email address', 'stackless-lite'); ?></label>
            <input id="<?php echo esc_attr($stackless_uid); ?>-email" name="email" type="email" required autocomplete="email" value="<?php echo esc_attr($email); ?>">
        </p>
        <p class="stackless-stock-alert-consent">
            <label><input type="checkbox" name="consent" value="1" required> <?php esc_html_e('Send me one email when this is available again.', 'stackless-lite'); ?></label>
        </p>
        <p class="stackless-hp" aria-hidden="true"><label><?php esc_html_e('Leave this empty', 'stackless-lite'); ?><input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
        <p><button type="submit" class="button<?php echo esc_attr($stackless_button_class); ?>"><?php esc_html_e('Notify me', 'stackless-lite'); ?></button></p>
        <p class="stackless-stock-alert-status" role="status" aria-live="polite"></p>
        <noscript><p><?php esc_html_e('Enable JavaScript to sign up for availability alerts.', 'stackless-lite'); ?></p></noscript>
    </form>
</section>
