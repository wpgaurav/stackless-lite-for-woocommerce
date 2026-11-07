<?php
/**
 * Square card form (classic checkout, order-pay and Add payment method). Square's Web Payments
 * SDK fills #stackless-square-card with its own secure fields; card numbers never touch this site.
 *
 * Override by copying to yourtheme/stackless/square_gateway/payment-fields.php. Keep the ids and
 * the data-config attribute: the script reads them.
 *
 * @var \Stackless\Modules\SquareGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 * @var array  $data Settings for the card form (see Gateway::scriptData()).
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-square" id="stackless-square-form" data-config="<?php echo esc_attr((string) wp_json_encode($data)); ?>">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<?php if (!empty($data['wallets'])) : ?>
		<div class="stackless-square__wallets">
			<?php if (in_array('apple_pay', $data['wallets'], true)) : ?>
				<button type="button" class="stackless-square__apple-pay" id="stackless-square-apple-pay" hidden aria-label="<?php esc_attr_e('Pay with Apple Pay', 'stackless-lite'); ?>"></button>
			<?php endif; ?>
			<?php if (in_array('google_pay', $data['wallets'], true)) : ?>
				<div class="stackless-square__google-pay" id="stackless-square-google-pay"></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="stackless-square__card" id="stackless-square-card"></div>
	<p class="stackless-square__status" role="alert" aria-live="polite"></p>
	<input type="hidden" name="<?php echo esc_attr($data['fields']['token']); ?>" value="" />
	<input type="hidden" name="<?php echo esc_attr($data['fields']['method']); ?>" value="card" />
	<?php if ($testmode) : ?>
		<p class="stackless-square__testmode"><strong><?php esc_html_e('Sandbox: use Square’s test cards; no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
