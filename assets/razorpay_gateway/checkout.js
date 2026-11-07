/**
 * Stackless for WooCommerce: opens Razorpay Checkout (checkout.js) with the options the server
 * prepared, and posts the signed result back to the store, which checks it with Razorpay.
 */
(function () {
	'use strict';

	var config = window.stacklessRazorpay;
	if (!config || !config.options) {
		return;
	}

	function status(message) {
		var el = document.querySelector('#stackless-razorpay-checkout .stackless-razorpay__status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function post(fields) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = config.returnUrl;
		Object.keys(fields).forEach(function (name) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = fields[name];
			form.appendChild(input);
		});
		document.body.appendChild(form);
		form.submit();
	}

	var busy = false;

	function open() {
		if (busy) {
			return;
		}
		if (typeof window.Razorpay !== 'function') {
			status(config.i18n.unavailable);
			return;
		}
		var options = Object.assign({}, config.options, {
			handler: function (response) {
				busy = true;
				status('');
				post({
					razorpay_payment_id: response.razorpay_payment_id || '',
					razorpay_order_id: response.razorpay_order_id || '',
					razorpay_signature: response.razorpay_signature || ''
				});
			},
			modal: {
				ondismiss: function () {
					if (!busy) {
						status(config.i18n.closed);
					}
				}
			}
		});
		var checkout = new window.Razorpay(options);
		checkout.on('payment.failed', function (response) {
			var error = response && response.error ? response.error : {};
			status((error.description ? error.description + ' ' : '') + config.i18n.failed);
		});
		checkout.open();
	}

	function ready() {
		var button = document.getElementById('stackless-razorpay-pay');
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
