<?php
/**
 * Dodo Payments payment fields (classic checkout and order-pay).
 *
 * Override by copying to yourtheme/stackless/dodo_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\DodoGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-dodo">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-dodo__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
