<?php
/**
 * Page that opens Paddle's overlay checkout for an order (or a subscription's
 * payment method update). Paddle.js must run on a page of an approved domain.
 *
 * Override by copying to yourtheme/stackless/paddle_gateway/pay-page.php.
 * Keep the #stackless-paddle-config element and both scripts.
 *
 * @var \WC_Order $order
 * @var array     $config     transactionId, successUrl, cancelUrl, email, sandbox, token, locale, i18n.
 * @var string    $script_url
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html(sprintf(/* translators: %s: site name */ __('Checkout · %s', 'stackless-lite'), get_bloginfo('name'))); ?></title>
	<style>
		:root { color-scheme: light dark; }
		body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f6f7f7; color: #1d2327; }
		@media (prefers-color-scheme: dark) { body { background: #1d2327; color: #f0f0f1; } }
		.stackless-paddle-pay { text-align: center; padding: 24px; max-width: 420px; }
		.stackless-paddle-pay a { color: inherit; }
		.stackless-paddle-pay__error { display: none; }
	</style>
</head>
<body>
	<main class="stackless-paddle-pay">
		<p class="stackless-paddle-pay__status"><?php esc_html_e('Opening secure checkout…', 'stackless-lite'); ?></p>
		<p class="stackless-paddle-pay__error" role="alert"></p>
		<p><a href="<?php echo esc_url((string) $config['cancelUrl']); ?>"><?php esc_html_e('Back to the store', 'stackless-lite'); ?></a></p>
	</main>
	<script type="application/json" id="stackless-paddle-config"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
	<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page; Paddle requires loading Paddle.js from its CDN. ?>
	<script src="<?php echo esc_url($script_url); ?>"></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
</body>
</html>
