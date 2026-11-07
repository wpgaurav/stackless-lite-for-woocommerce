<?php
/**
 * Mollie payment fields (classic checkout, order-pay and Add payment method).
 *
 * Override by copying to yourtheme/stackless/mollie_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\MollieGateway\Gateway $gateway
 * @var string                $description
 * @var bool                  $testmode
 * @var array<string,string>  $methods       id => label; empty = the buyer picks on Mollie's page
 * @var string                $field         POST field name of the method choice
 * @var bool                  $requires_save Whether this payment saves the method for later
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-mollie">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($methods) : ?>
		<p class="form-row form-row-wide stackless-mollie__method">
			<label for="<?php echo esc_attr($field); ?>"><?php esc_html_e('Payment method', 'stackless-lite'); ?></label>
			<select name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>">
				<option value=""><?php esc_html_e('Choose on the next page', 'stackless-lite'); ?></option>
				<?php foreach ($methods as $method_id => $method_label) : ?>
					<option value="<?php echo esc_attr($method_id); ?>"><?php echo esc_html($method_label); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
	<?php endif; ?>
	<?php if ($requires_save) : ?>
		<p class="stackless-mollie__save-note"><?php esc_html_e('This payment method is saved with Mollie for future payments.', 'stackless-lite'); ?></p>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-mollie__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
