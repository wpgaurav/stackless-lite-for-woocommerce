<?php
/**
 * Revolut payment fields (classic checkout, order-pay and Add payment method).
 *
 * Override by copying to yourtheme/stackless/revolut_gateway/payment-fields.php.
 *
 * @var string $description
 * @var bool   $testmode
 * @var bool   $requires_save Whether this payment saves the card for later
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-revolut">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($requires_save) : ?>
		<p class="stackless-revolut__save-note"><?php esc_html_e('You enter your card in Revolut’s window on the next page. It is saved with Revolut for future payments.', 'stackless-lite'); ?></p>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-revolut__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
