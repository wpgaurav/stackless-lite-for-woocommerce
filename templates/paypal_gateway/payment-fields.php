<?php
/**
 * PayPal payment fields (classic checkout, order-pay, My Account → Add payment method).
 *
 * Override by copying to yourtheme/stackless/paypal_gateway/payment-fields.php.
 *
 * @var \Stackless\Modules\PayPalGateway\Gateway $gateway
 * @var string $description
 * @var string $context        checkout|order-pay|add-payment-method
 * @var string $mode           order|setup
 * @var bool   $requires_vault
 * @var string $intent         capture|authorize
 * @var string $currency
 * @var string $total
 * @var int    $order_id
 * @var string $order_key
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-paypal" data-stackless-paypal="1">
	<?php if ($description !== '') : ?>
		<div class="stackless-paypal__description"><?php echo wp_kses_post(wpautop(wptexturize($description))); ?></div>
	<?php endif; ?>

	<?php if ($requires_vault) : ?>
		<p class="stackless-paypal__vault-note"><?php esc_html_e('Your PayPal account will be saved so future subscription payments are taken automatically. You can remove it any time in your PayPal account.', 'stackless-lite'); ?></p>
	<?php endif; ?>

	<div
		id="stackless-paypal-buttons"
		class="stackless-paypal__buttons"
		data-context="<?php echo esc_attr($context); ?>"
		data-mode="<?php echo esc_attr($mode); ?>"
		data-vault="<?php echo $requires_vault ? '1' : '0'; ?>"
		data-intent="<?php echo esc_attr($intent); ?>"
		data-currency="<?php echo esc_attr($currency); ?>"
		data-total="<?php echo esc_attr($total); ?>"
		data-order-id="<?php echo esc_attr((string) $order_id); ?>"
		data-order-key="<?php echo esc_attr($order_key); ?>"
	></div>
	<div class="stackless-paypal__messages" id="stackless-paypal-messages"></div>
	<p class="stackless-paypal__status" role="status" aria-live="polite"></p>

	<input type="hidden" name="stackless_paypal_order_id" value="" />
	<input type="hidden" name="stackless_paypal_setup_token" value="" />
	<noscript><p><?php esc_html_e('Place your order and you will be sent to PayPal to pay.', 'stackless-lite'); ?></p></noscript>
</div>
