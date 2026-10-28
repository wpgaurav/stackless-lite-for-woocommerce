<?php
/**
 * Polar payment fields (classic checkout and order-pay).
 *
 * Override by copying to yourtheme/stackless/polar_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\PolarGateway\Gateway $gateway
 * @var string $description
 * @var bool   $sandbox
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-polar">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($sandbox) : ?>
		<p class="stackless-polar__sandbox"><strong><?php esc_html_e('Sandbox mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
