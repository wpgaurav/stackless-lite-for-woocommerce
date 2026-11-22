<?php
/**
 * Airwallex payment fields (classic checkout, order-pay and Add payment method).
 *
 * Override by copying to yourtheme/stackless/airwallex_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\AirwallexGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 * @var bool   $requires_save Whether this payment saves the card for later
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-airwallex">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($requires_save) : ?>
		<p class="stackless-airwallex__save-note"><?php esc_html_e('Your card is saved with Airwallex and charged automatically for renewals.', 'stackless-lite'); ?></p>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-airwallex__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
