<?php
/**
 * Razorpay payment fields (classic checkout, order-pay and Add payment method).
 *
 * Override by copying to yourtheme/stackless/razorpay_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\RazorpayGateway\Gateway $gateway
 * @var string               $description
 * @var bool                 $testmode
 * @var array<string,string> $methods Renewal methods to choose from (only when a method is saved).
 * @var string               $field   POST field name of the renewal method.
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-razorpay">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if ($methods) : ?>
		<fieldset class="stackless-razorpay__methods">
			<legend><?php esc_html_e('Pay renewals with', 'stackless-lite'); ?></legend>
			<?php $stackless_first = true; ?>
			<?php foreach ($methods as $method_id => $method_label) : ?>
				<label class="stackless-razorpay__method">
					<input type="radio" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($method_id); ?>" <?php checked($stackless_first); ?> />
					<?php echo esc_html($method_label); ?>
				</label>
				<?php $stackless_first = false; ?>
			<?php endforeach; ?>
			<small><?php esc_html_e('Your bank or UPI app asks you to approve the mandate once. Subscriptions renew in Indian rupees, and you are notified before every debit.', 'stackless-lite'); ?></small>
		</fieldset>
	<?php endif; ?>
	<?php if ($testmode) : ?>
		<p class="stackless-razorpay__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
