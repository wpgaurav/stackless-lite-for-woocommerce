/**
 * Stackless for WooCommerce: mounts Stripe's embedded Checkout (Managed Payments) for one
 * Checkout Session on the store's pay page. Stripe sends the buyer to the session's return
 * URL when the payment completes.
 *
 * Page states live on <body data-stackless-state>: loading → ready, or error with a retry.
 */
(function () {
	'use strict';

	var body = document.body;
	var node = document.getElementById('stackless-stripe-managed-config');
	var cfg = {};
	try {
		cfg = JSON.parse(node ? node.textContent : '{}');
	} catch (e) {
		cfg = {};
	}

	function state(value) {
		body.setAttribute('data-stackless-state', value);
	}

	function fail() {
		state('error');
		// Fill the alert after it is shown so screen readers announce it.
		var text = document.querySelector('.stackless-pay__error-text');
		if (text) {
			text.textContent = (cfg.i18n && cfg.i18n.failed) || 'Checkout could not load.';
		}
	}

	var retry = document.querySelector('[data-stackless-retry]');
	if (retry) {
		retry.addEventListener('click', function () {
			window.location.reload();
		});
	}

	// The order summary is open beside the checkout on wide screens and folds into a
	// tappable "Order #… · total" row on phones.
	var details = document.querySelector('.stackless-pay__details');
	if (details && window.matchMedia) {
		var wide = window.matchMedia('(min-width: 901px)');
		var toggle = details.querySelector('summary');
		var sync = function () {
			details.open = wide.matches;
			if (toggle) {
				// On wide screens the summary is a heading, not a control: no tab stop.
				toggle.tabIndex = wide.matches ? -1 : 0;
			}
		};
		sync();
		if (wide.addEventListener) {
			wide.addEventListener('change', sync);
		}
		details.addEventListener('click', function (event) {
			if (wide.matches && event.target.closest('summary')) {
				event.preventDefault(); // Always open beside the checkout.
			}
		});
	}

	if (typeof window.Stripe !== 'function' || !cfg.publishableKey || !cfg.clientSecret) {
		fail();
		return;
	}

	var stripe = window.Stripe(cfg.publishableKey);
	// Stripe.js names the embedded Checkout initializer initEmbeddedCheckout; newer builds
	// may expose createEmbeddedCheckoutPage instead.
	var init = stripe.initEmbeddedCheckout || stripe.createEmbeddedCheckoutPage;
	if (typeof init !== 'function') {
		fail();
		return;
	}

	// Stripe's iframe renders after mount; give it a moment before hiding the placeholder.
	var timer = window.setTimeout(fail, 20000);
	init.call(stripe, {
		fetchClientSecret: function () {
			return Promise.resolve(cfg.clientSecret);
		}
	}).then(function (checkout) {
		checkout.mount('#stackless-stripe-managed-checkout');
		window.clearTimeout(timer);
		var container = document.getElementById('stackless-stripe-managed-checkout');
		var frame = container ? container.querySelector('iframe') : null;
		if (frame && !frame.complete) {
			frame.addEventListener('load', function () {
				state('ready');
			});
			window.setTimeout(function () {
				state('ready');
			}, 1500);
		} else {
			state('ready');
		}
	}).catch(function () {
		window.clearTimeout(timer);
		fail();
	});
})();
