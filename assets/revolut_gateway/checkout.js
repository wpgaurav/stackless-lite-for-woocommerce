/**
 * Stackless: opens Revolut's checkout window for a payment that saves the card for merchant
 * use. The store reads the result from Revolut on the return URL; nothing here marks it paid.
 */
(function () {
	'use strict';

	var config = window.stacklessRevolut;
	if (!config || !config.token) {
		return;
	}

	var done = false;
	var instance = null;

	function status(message) {
		var el = document.querySelector('#stackless-revolut-checkout .stackless-revolut__status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function open() {
		if (done) {
			return;
		}
		if (typeof window.RevolutCheckout !== 'function') {
			status(config.i18n.unavailable);
			return;
		}
		status('');
		var options = Object.assign({}, config.options, {
			onSuccess: function () {
				done = true;
				window.location.assign(config.returnUrl);
			},
			onError: function (error) {
				var message = error && error.message ? String(error.message) + ' ' : '';
				status(message + config.i18n.failed);
			},
			onCancel: function () {
				if (!done) {
					status(config.i18n.closed);
				}
			}
		});
		// embed.js of the order's environment is loaded, so the token is all it needs.
		Promise.resolve()
			.then(function () {
				if (instance && instance.destroy) {
					instance.destroy();
					instance = null;
				}
				return window.RevolutCheckout(config.token);
			})
			.then(function (checkout) {
				instance = checkout;
				checkout.payWithPopup(options);
			})
			.catch(function () {
				status(config.i18n.unavailable);
			});
	}

	function ready() {
		var button = document.getElementById('stackless-revolut-pay');
		if (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				open();
			});
		}
		open();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}
})();
