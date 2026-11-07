<?php
/**
 * Razorpay Checkout on the order's payment page (after Place order). The script opens
 * Razorpay Checkout on load; the button opens it again after the buyer closes it.
 *
 * Override by copying to yourtheme/stackless/razorpay_gateway/checkout.php.
 *
 * @var \Stackless\Modules\RazorpayGateway\Gateway $gateway
 * @var \WC_Order $order
 * @var bool      $testmode
 * @var bool      $first_debit Whether an eNACH first payment follows the mandate.
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<div class="stackless-razorpay stackless-razorpay--checkout" id="stackless-razorpay-checkout">
	<p><?php esc_html_e('Complete your payment in the Razorpay window. If it did not open, use the button below.', 'stackless-lite'); ?></p>
	<?php if ($first_debit) : ?>
		<p><?php esc_html_e('You are setting up an eNACH mandate. Your first payment is debited once your bank confirms it, usually within two working days.', 'stackless-lite'); ?></p>
	<?php endif; ?>
	<p class="stackless-razorpay__status" role="status" aria-live="polite"></p>
	<p>
		<button type="button" class="button alt" id="stackless-razorpay-pay"><?php esc_html_e('Pay now', 'stackless-lite'); ?></button>
		<a class="button" href="<?php echo esc_url($order->get_checkout_payment_url()); ?>"><?php esc_html_e('Choose another payment method', 'stackless-lite'); ?></a>
	</p>
	<?php if ($testmode) : ?>
		<p class="stackless-razorpay__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
	<?php endif; ?>
</div>
