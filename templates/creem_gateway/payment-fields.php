<?php
/**
 * Creem payment fields (classic checkout and order-pay).
 *
 * Override by copying to yourtheme/stackless/creem_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\CreemGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-creem">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-creem__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
