/**
 * Stackless for WooCommerce: opens Paddle's overlay checkout for one transaction
 * on the store's pay page, then sends the buyer back to the store.
 */
(function () {
	'use strict';

	var node = document.getElementById('stackless-paddle-config');
	var cfg = {};
	try {
		cfg = JSON.parse(node ? node.textContent : '{}');
	} catch (e) {
		cfg = {};
	}
	var status = document.querySelector('.stackless-paddle-pay__status');
	var error = document.querySelector('.stackless-paddle-pay__error');
	var completed = false;

	function fail() {
		if (status) {
			status.style.display = 'none';
		}
		if (error) {
			error.textContent = (cfg.i18n && cfg.i18n.failed) || 'Checkout could not load.';
			error.style.display = 'block';
		}
	}

	if (!window.Paddle || !cfg.token || !cfg.transactionId) {
		fail();
		return;
	}

	if (cfg.sandbox) {
		window.Paddle.Environment.set('sandbox');
	}

	window.Paddle.Initialize({
		token: cfg.token,
		eventCallback: function (event) {
			if (!event || !event.name) {
				return;
			}
			if (event.name === 'checkout.completed') {
				completed = true;
				// Paddle redirects to successUrl itself; this is the fallback.
				window.setTimeout(function () {
					window.location.href = cfg.successUrl;
				}, 3000);
			} else if (event.name === 'checkout.closed' && !completed) {
				window.location.href = cfg.cancelUrl;
			} else if (event.name === 'checkout.error') {
				fail();
			}
		}
	});

	var options = {
		transactionId: cfg.transactionId,
		settings: {
			displayMode: 'overlay',
			successUrl: cfg.successUrl,
			allowLogout: false,
			// The order total already carries WooCommerce coupons; a Paddle code would underpay it.
			showAddDiscounts: false
		}
	};
	if (cfg.locale) {
		options.settings.locale = cfg.locale;
	}
	if (cfg.email) {
		options.customer = { email: cfg.email };
	}
	window.Paddle.Checkout.open(options);
})();
