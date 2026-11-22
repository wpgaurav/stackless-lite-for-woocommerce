<?php
/**
 * The page with Revolut's checkout window, for payments that save the card and for saving a
 * card from the account page. It prints its own small document so it works whatever the theme
 * does. The script opens the window on load; the button opens it again.
 *
 * Override by copying to yourtheme/stackless/revolut_gateway/pay-page.php. Keep the
 * #stackless-revolut-checkout container, the .stackless-revolut__status element and the
 * #stackless-revolut-pay button: the script uses them.
 *
 * @var string              $heading
 * @var string[]            $lines        Explanations shown above the button.
 * @var string              $error        Shown instead of opening Revolut when not empty.
 * @var string              $button       Button label.
 * @var string              $cancel       Where the second link goes.
 * @var string              $cancel_label
 * @var bool                $testmode
 * @var array<string,mixed> $config       Options for Revolut's checkout window ([] when nothing can be paid).
 * @var string              $sdk          Revolut's embed.js URL.
 * @var string              $script       This gateway's checkout script URL.
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
	<meta name="referrer" content="strict-origin-when-cross-origin">
	<title><?php echo esc_html($heading); ?> - <?php bloginfo('name'); ?></title>
	<style>
		body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f6f7f7; color: #1d2327; }
		.stackless-revolut { max-width: 460px; margin: 10vh auto; background: #fff; padding: 32px; border-radius: 4px; border: 1px solid #dcdcde; }
		.stackless-revolut h1 { font-size: 20px; margin: 0 0 12px; }
		.stackless-revolut__actions { display: flex; flex-wrap: wrap; gap: 8px; }
		.stackless-revolut .button { display: inline-block; box-sizing: border-box; min-height: 40px; padding: 8px 18px; border-radius: 2px; border: 1px solid #2271b1; background: #2271b1; color: #fff; font: inherit; font-size: 15px; line-height: 22px; cursor: pointer; text-decoration: none; }
		.stackless-revolut .button.secondary { background: #fff; color: #2271b1; }
		.stackless-revolut__error { padding: 8px 12px; border: 1px solid #d63638; background: #fcf0f1; border-radius: 2px; }
		@media (max-width: 520px) { .stackless-revolut { margin: 16px; padding: 20px; } }
	</style>
</head>
<body>
	<main class="stackless-revolut" id="stackless-revolut-checkout">
		<h1><?php echo esc_html($heading); ?></h1>
		<?php if ($error !== '') : ?>
			<p class="stackless-revolut__error" role="alert"><?php echo esc_html($error); ?></p>
		<?php endif; ?>
		<?php if ($config) : ?>
			<?php foreach ($lines as $stackless_line) : ?>
				<p><?php echo esc_html($stackless_line); ?></p>
			<?php endforeach; ?>
		<?php endif; ?>
		<p class="stackless-revolut__status" role="status" aria-live="polite"></p>
		<p class="stackless-revolut__actions">
			<?php if ($config) : ?>
				<button type="button" class="button" id="stackless-revolut-pay"><?php echo esc_html($button); ?></button>
			<?php endif; ?>
			<a class="button secondary" href="<?php echo esc_url($cancel); ?>"><?php echo esc_html($cancel_label); ?></a>
		</p>
		<?php if ($testmode) : ?>
			<p class="stackless-revolut__testmode"><strong><?php esc_html_e('Test mode: no real payment will be taken.', 'stackless-lite'); ?></strong></p>
		<?php endif; ?>
	</main>
	<?php if ($config) : ?>
		<script>window.stacklessRevolut = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
		<?php // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- a standalone document without wp_head(); nothing else is loaded. ?>
		<script src="<?php echo esc_url($sdk); ?>"></script>
		<script src="<?php echo esc_url($script); ?>"></script>
		<?php // phpcs:enable ?>
	<?php endif; ?>
</body>
</html>
