<?php
/**
 * Trust block under the payment methods.
 *
 * Override in your theme at stackless/checkout_enhancements/trust.php.
 *
 * @var array $trust enabled, heading, lines, image_id, policy_page, policy_label
 * @package STACKLESS
 */

defined('ABSPATH') || exit;

$stackless_lock = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 13a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6z"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0"/><path d="M8 11V7a4 4 0 1 1 8 0v4"/></svg>';
$stackless_check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0"/><path d="M9 12l2 2l4-4"/></svg>';
$stackless_allowed_svg = ['svg' => ['viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'focusable' => true], 'path' => ['d' => true]];
?>
<aside class="stackless-trust"<?php echo $trust['heading'] !== '' ? ' aria-label="' . esc_attr($trust['heading']) . '"' : ''; ?>>
    <?php if ($trust['heading'] !== '') : ?>
        <p class="stackless-trust-heading"><?php echo wp_kses($stackless_lock, $stackless_allowed_svg); ?><span><?php echo esc_html($trust['heading']); ?></span></p>
    <?php endif; ?>
    <?php if ($trust['lines']) : ?>
        <ul>
            <?php foreach ($trust['lines'] as $stackless_line) : ?>
                <li><?php echo wp_kses($stackless_check, $stackless_allowed_svg); ?><span><?php echo esc_html($stackless_line); ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($trust['image_id']) :
        $stackless_alt = (string) get_post_meta((int) $trust['image_id'], '_wp_attachment_image_alt', true); ?>
        <div class="stackless-trust-image"><?php echo wp_get_attachment_image((int) $trust['image_id'], 'medium', false, ['loading' => 'lazy', 'alt' => $stackless_alt !== '' ? $stackless_alt : __('Accepted payment methods', 'stackless-lite')]); ?></div>
    <?php endif; ?>
    <?php if ($trust['policy_page']) : ?>
        <p class="stackless-trust-policy"><a href="<?php echo esc_url((string) get_permalink((int) $trust['policy_page'])); ?>" target="_blank" rel="noopener"><?php echo esc_html($trust['policy_label']); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'stackless-lite'); ?></span></a></p>
    <?php endif; ?>
</aside>
