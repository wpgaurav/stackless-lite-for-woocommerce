<?php
/**
 * Pay page that embeds Stripe Checkout (Managed Payments) for an order: the order summary on
 * one side, Stripe's checkout on the other.
 *
 * Override by copying to yourtheme/stackless/stripe_gateway/managed-pay-page.php.
 * Keep the #stackless-stripe-managed-config element, the #stackless-stripe-managed-checkout
 * container, the data-stackless-state attribute on <body> and both scripts.
 *
 * @var \WC_Order $order
 * @var array     $config     publishableKey, clientSecret, cancelUrl, testMode, accent, i18n.
 * @var array     $summary    store {name, logo, logo_type (logo|icon), url}, number,
 *                            items [{name, quantity, total, image}],
 *                            rows [{label, value}], total, recurring, trial, tax_note, seller_note.
 * @var string    $script_url
 * @var string    $style_url
 *
 * @package STACKLESS
 */

defined('ABSPATH') || exit;

$stackless_store = (array) $summary['store'];
$stackless_name = (string) $stackless_store['name'];
$stackless_logo = (string) $stackless_store['logo'];
// A theme logo already shows the store name; a site icon sits beside it.
$stackless_wordmark = $stackless_logo !== '' && ($stackless_store['logo_type'] ?? 'icon') === 'logo';
$stackless_initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($stackless_name, 0, 1)) : strtoupper(substr($stackless_name, 0, 1));
$stackless_icon = static function (string $paths): string {
    return '<svg class="stackless-pay__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
};
$stackless_svg = ['svg' => ['class' => true, 'xmlns' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'focusable' => true], 'path' => ['d' => true]];
$stackless_paths = [
    'lock' => '<path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6"/><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/><path d="M8 11v-4a4 4 0 1 1 8 0v4"/>',
    'back' => '<path d="M5 12l14 0"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/>',
    'repeat' => '<path d="M4 12v-3a3 3 0 0 1 3 -3h13m-3 -3l3 3l-3 3"/><path d="M20 12v3a3 3 0 0 1 -3 3h-13m3 3l-3 -3l3 -3"/>',
    'shield' => '<path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/><path d="M11 11a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/><path d="M12 12l0 2.5"/>',
    'receipt' => '<path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2m4 -14h6m-6 4h6m-2 4h2"/>',
    'refresh' => '<path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/>',
    'chevron' => '<path d="M6 9l6 6l6 -6"/>',
];
$stackless_plan = '';
if ((string) $summary['trial'] !== '' || (string) $summary['recurring'] !== '') {
    $stackless_plan = '<p class="stackless-pay__plan">' . wp_kses($stackless_icon($stackless_paths['repeat']), $stackless_svg) . '<span class="stackless-pay__plan-text">'
        . ((string) $summary['trial'] !== '' ? '<strong>' . esc_html((string) $summary['trial']) . '</strong>' : '')
        . ((string) $summary['recurring'] !== '' ? '<span>' . esc_html((string) $summary['recurring']) . '</span>' : '')
        . '</span></p>';
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="color-scheme" content="light dark">
	<title><?php echo esc_html(sprintf(/* translators: %s: site name */ __('Checkout · %s', 'stackless-lite'), $stackless_name)); ?></title>
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page that does not run wp_head(). ?>
	<link rel="stylesheet" href="<?php echo esc_url($style_url); ?>">
	<style>:root{--stackless-accent:<?php echo esc_html((string) $config['accent']); ?>}</style>
</head>
<body class="stackless-pay" data-stackless-state="loading">
	<header class="stackless-pay__bar">
		<div class="stackless-pay__bar-inner">
			<a class="stackless-pay__brand" href="<?php echo esc_url((string) $stackless_store['url']); ?>">
				<?php if ($stackless_wordmark) : ?>
					<img class="stackless-pay__logo" src="<?php echo esc_url($stackless_logo); ?>" alt="<?php echo esc_attr($stackless_name); ?>">
				<?php else : ?>
					<?php if ($stackless_logo !== '') : ?>
						<img class="stackless-pay__logo stackless-pay__logo--icon" src="<?php echo esc_url($stackless_logo); ?>" alt="" width="32" height="32">
					<?php elseif ($stackless_initial !== '') : ?>
						<span class="stackless-pay__monogram" aria-hidden="true"><?php echo esc_html($stackless_initial); ?></span>
					<?php endif; ?>
					<span class="stackless-pay__store"><?php echo esc_html($stackless_name); ?></span>
				<?php endif; ?>
			</a>
			<span class="stackless-pay__secure"><?php echo wp_kses($stackless_icon($stackless_paths['lock']), $stackless_svg); ?><span class="stackless-pay__secure-text"><?php esc_html_e('Secure checkout', 'stackless-lite'); ?></span></span>
		</div>
	</header>

	<main class="stackless-pay__layout">
		<aside class="stackless-pay__summary" aria-labelledby="stackless-pay-summary-title">
			<a class="stackless-pay__back" href="<?php echo esc_url((string) $config['cancelUrl']); ?>"><?php echo wp_kses($stackless_icon($stackless_paths['back']), $stackless_svg); ?><?php esc_html_e('Back to the store', 'stackless-lite'); ?></a>

			<details class="stackless-pay__details" open>
				<summary class="stackless-pay__details-toggle">
					<span id="stackless-pay-summary-title"><?php echo esc_html(sprintf(/* translators: %s: order number */ __('Order #%s', 'stackless-lite'), (string) $summary['number'])); ?></span>
					<span class="stackless-pay__details-total"><?php echo esc_html((string) $summary['total']); ?><?php echo wp_kses($stackless_icon($stackless_paths['chevron']), $stackless_svg); ?></span>
				</summary>

				<p class="stackless-pay__eyebrow"><?php esc_html_e('Due today', 'stackless-lite'); ?></p>
				<p class="stackless-pay__amount"><?php echo esc_html((string) $summary['total']); ?></p>
				<?php echo $stackless_plan; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>

				<ul class="stackless-pay__items">
					<?php foreach ((array) $summary['items'] as $stackless_item) : ?>
						<li class="stackless-pay__item">
							<?php if ((string) $stackless_item['image'] !== '') : ?>
								<img class="stackless-pay__thumb" src="<?php echo esc_url((string) $stackless_item['image']); ?>" alt="" width="48" height="48" loading="lazy">
							<?php else : ?>
								<span class="stackless-pay__thumb stackless-pay__thumb--empty" aria-hidden="true"><?php echo wp_kses($stackless_icon($stackless_paths['receipt']), $stackless_svg); ?></span>
							<?php endif; ?>
							<span class="stackless-pay__item-name">
								<?php echo esc_html((string) $stackless_item['name']); ?>
								<?php if ((int) $stackless_item['quantity'] > 1) : ?>
									<small><?php echo esc_html(sprintf(/* translators: %d: quantity */ __('Qty %d', 'stackless-lite'), (int) $stackless_item['quantity'])); ?></small>
								<?php endif; ?>
							</span>
							<span class="stackless-pay__item-total"><?php echo esc_html((string) $stackless_item['total']); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>

				<dl class="stackless-pay__totals">
					<?php foreach ((array) $summary['rows'] as $stackless_row) : ?>
						<div><dt><?php echo esc_html((string) $stackless_row['label']); ?></dt><dd><?php echo esc_html((string) $stackless_row['value']); ?></dd></div>
					<?php endforeach; ?>
					<div class="stackless-pay__totals-due"><dt><?php esc_html_e('Due today', 'stackless-lite'); ?></dt><dd><?php echo esc_html((string) $summary['total']); ?></dd></div>
				</dl>
				<p class="stackless-pay__tax"><?php echo esc_html((string) $summary['tax_note']); ?></p>
			</details>

			<?php // On phones the summary folds away; what renews stays in view. ?>
			<?php echo str_replace('stackless-pay__plan"', 'stackless-pay__plan stackless-pay__plan--compact"', $stackless_plan); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>

			<p class="stackless-pay__trust"><?php echo wp_kses($stackless_icon($stackless_paths['shield']), $stackless_svg); ?><span><?php echo esc_html((string) $summary['seller_note']); ?></span></p>
		</aside>

		<section class="stackless-pay__panel" aria-label="<?php esc_attr_e('Payment', 'stackless-lite'); ?>">
			<?php if (!empty($config['testMode'])) : ?>
				<p class="stackless-pay__test"><?php esc_html_e('Test mode: use a Stripe test card. No real payment will be taken.', 'stackless-lite'); ?></p>
			<?php endif; ?>
			<div class="stackless-pay__frame">
				<div class="stackless-pay__loading" role="status" aria-live="polite">
					<span class="screen-reader-text"><?php esc_html_e('Opening secure checkout…', 'stackless-lite'); ?></span>
					<span class="stackless-pay__skeleton stackless-pay__skeleton--title"></span>
					<span class="stackless-pay__skeleton stackless-pay__skeleton--wallet"></span>
					<span class="stackless-pay__skeleton stackless-pay__skeleton--label"></span>
					<span class="stackless-pay__skeleton"></span>
					<span class="stackless-pay__skeleton stackless-pay__skeleton--label"></span>
					<span class="stackless-pay__skeleton stackless-pay__skeleton--tall"></span>
					<span class="stackless-pay__skeleton stackless-pay__skeleton--button"></span>
				</div>
				<div class="stackless-pay__error" role="alert">
					<p class="stackless-pay__error-text"></p>
					<div class="stackless-pay__actions">
						<button type="button" class="stackless-pay__button" data-stackless-retry><?php echo wp_kses($stackless_icon($stackless_paths['refresh']), $stackless_svg); ?><?php esc_html_e('Try again', 'stackless-lite'); ?></button>
						<a class="stackless-pay__button stackless-pay__button--ghost" href="<?php echo esc_url((string) $config['cancelUrl']); ?>"><?php esc_html_e('Choose another payment method', 'stackless-lite'); ?></a>
					</div>
				</div>
				<div id="stackless-stripe-managed-checkout" class="stackless-pay__checkout"></div>
			</div>
			<p class="stackless-pay__foot"><?php echo wp_kses($stackless_icon($stackless_paths['lock']), $stackless_svg); ?><?php esc_html_e('Payment details go straight to Stripe and never touch this site.', 'stackless-lite'); ?></p>
		</section>
	</main>

	<script type="application/json" id="stackless-stripe-managed-config"><?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
	<script src="https://js.stripe.com/v3/"></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page; Stripe.js must load from js.stripe.com. ?>
	<script src="<?php echo esc_url($script_url); ?>"></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
</body>
</html>
