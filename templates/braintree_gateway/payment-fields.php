<?php
/**
 * Braintree card form (classic checkout, order-pay and Add payment method). Braintree's Hosted
 * Fields fill the three field containers with their own iframes; card numbers never touch this
 * site.
 *
 * Override by copying to yourtheme/stackless/braintree_gateway/payment-fields.php. Keep the ids,
 * the data-stackless-braintree attributes, the hidden inputs and data-config: the script reads them.
 *
 * @var \Stackless\Modules\BraintreeGateway\Gateway $gateway
 * @var string $description
 * @var bool   $testmode
 * @var array  $data Settings for the card form (see Gateway::scriptData()).
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-braintree" id="stackless-braintree-form" data-config="<?php echo esc_attr((string) wp_json_encode($data)); ?>">
	<?php if ($description !== '') : ?>
		<?php echo wp_kses_post(wpautop(wptexturize($description))); ?>
	<?php endif; ?>
	<div class="stackless-braintree__card">
		<div class="stackless-braintree__field stackless-braintree__field--wide">
			<span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['number']); ?></span>
			<div class="stackless-braintree__input" data-stackless-braintree="number"></div>
		</div>
		<div class="stackless-braintree__field">
			<span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['expiry']); ?></span>
			<div class="stackless-braintree__input" data-stackless-braintree="expiration"></div>
		</div>
		<div class="stackless-braintree__field">
			<span class="stackless-braintree__label"><?php echo esc_html($data['i18n']['cvv']); ?></span>
			<div class="stackless-braintree__input" data-stackless-braintree="cvv"></div>
		</div>
	</div>
	<p class="stackless-braintree__status" role="alert" aria-live="polite"></p>
	<input type="hidden" name="<?php echo esc_attr($data['fields']['nonce']); ?>" value="" />
	<input type="hidden" name="<?php echo esc_attr($data['fields']['device']); ?>" value="" />
	<?php if ($testmode) : ?>
		<p class="stackless-braintree__testmode"><strong><?php echo esc_html($data['i18n']['testmode']); ?></strong></p>
	<?php endif; ?>
</div>
