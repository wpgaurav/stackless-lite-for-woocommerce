<?php
/**
 * A small page that opens Razorpay Checkout to save a renewal method (My Account → Add payment
 * method). It prints its own document so it works whatever the theme does.
 *
 * Override by copying to yourtheme/stackless/razorpay_gateway/authorize-page.php.
 *
 * @var array  $config   Options for Razorpay Checkout plus return and cancel URLs.
 * @var string $sdk      Razorpay checkout.js URL.
 * @var string $script   This gateway's checkout script URL.
 * @var string $method   Renewal method label.
 * @var int    $amount   Authorization amount in paise (refunded).
 * @var bool   $testmode
 * @var string $cancel   Where "Cancel" goes.
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e('Save a payment method', 'stackless-lite'); ?> – <?php bloginfo('name'); ?></title>
	<style>
		body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f6f7f7; color: #1d2327; }
		.stackless-razorpay { max-width: 460px; margin: 10vh auto; background: #fff; padding: 32px; border-radius: 4px; border: 1px solid #dcdcde; }
		.stackless-razorpay h1 { font-size: 20px; margin: 0 0 12px; }
		.stackless-razorpay .button { display: inline-block; min-height: 40px; padding: 8px 18px; border-radius: 2px; border: 1px solid #2271b1; background: #2271b1; color: #fff; font-size: 15px; cursor: pointer; text-decoration: none; }
		.stackless-razorpay .button.secondary { background: #fff; color: #2271b1; }
	</style>
</head>
<body>
	<div class="stackless-razorpay stackless-razorpay--checkout" id="stackless-razorpay-checkout">
		<h1><?php esc_html_e('Save a payment method', 'stackless-lite'); ?></h1>
		<p>
			<?php
			echo esc_html(sprintf(
				/* translators: %s: method, e.g. UPI Autopay */
				__('Approve a %s mandate in the Razorpay window to use it for your subscriptions.', 'stackless-lite'),
				$method
			));
			?>
		</p>
		<?php if ($amount > 0) : ?>
			<p><?php esc_html_e('Razorpay charges ₹1 to verify it, which is refunded straight away.', 'stackless-lite'); ?></p>
		<?php endif; ?>
		<p class="stackless-razorpay__status" role="status" aria-live="polite"></p>
		<p>
			<button type="button" class="button" id="stackless-razorpay-pay"><?php esc_html_e('Open Razorpay', 'stackless-lite'); ?></button>
			<a class="button secondary" href="<?php echo esc_url($cancel); ?>"><?php esc_html_e('Cancel', 'stackless-lite'); ?></a>
		</p>
		<?php if ($testmode) : ?>
			<p><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
		<?php endif; ?>
	</div>
	<script>window.stacklessRazorpay = <?php echo wp_json_encode($config); ?>;</script>
	<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- a standalone document without wp_head(); nothing else is loaded. ?>
	<script src="<?php echo esc_url($sdk); ?>"></script>
	<script src="<?php echo esc_url($script); ?>"></script>
	<?php // phpcs:enable ?>
</body>
</html>
