/**
 * Stackless for WooCommerce: Braintree on the classic checkout, the order-pay page and My
 * Account → Add payment method. The form is mounted from #stackless-braintree-form (re-read
 * after every checkout refresh); Place order gets a verified nonce first, then submits.
 */
(function ($) {
	'use strict';

	var B = window.StacklessBraintree;
	if (!B || !$) {
		return;
	}

	var GATEWAY = 'stackless_braintree';
	var state = { root: null, controller: null, config: null, working: false, submitting: false };

	function root() {
		return document.getElementById('stackless-braintree-form');
	}

	function status(message) {
		var el = document.querySelector('#stackless-braintree-form .stackless-braintree__status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function field(name) {
		return state.config ? document.querySelector('#stackless-braintree-form input[name="' + state.config.fields[name] + '"]') : null;
	}

	function selected() {
		var radio = document.querySelector('input[name="payment_method"]:checked');
		return !radio || radio.value === GATEWAY;
	}

	function savedCard() {
		var saved = document.querySelector('input[name="wc-' + GATEWAY + '-payment-token"]:checked');
		return saved && saved.value && saved.value !== 'new' ? saved.value : '';
	}

	function toggleCard() {
		var card = document.querySelector('#stackless-braintree-form .stackless-braintree__card');
		if (card) {
			card.hidden = !!savedCard();
		}
	}

	function wantsSave() {
		var box = document.getElementById('wc-' + GATEWAY + '-new-payment-method');
		return !!(box && box.checked);
	}

	function billing() {
		if (state.config.flow !== 'checkout') {
			return state.config.billing || {};
		}
		var get = function (id) {
			var el = document.getElementById('billing_' + id);
			return el ? el.value : '';
		};
		return {
			first: get('first_name'),
			last: get('last_name'),
			email: get('email'),
			line1: get('address_1'),
			line2: get('address_2'),
			city: get('city'),
			region: get('state'),
			postcode: get('postcode'),
			country: get('country')
		};
	}

	function clearNonce() {
		['nonce', 'device'].forEach(function (name) {
			var input = field(name);
			if (input) {
				input.value = '';
			}
		});
	}

	function mount() {
		var el = root();
		if (!el || !selected()) {
			return;
		}
		toggleCard();
		if (state.root === el && state.controller) {
			return;
		}
		if (state.controller) {
			state.controller.destroy();
		}
		try {
			state.config = JSON.parse(el.getAttribute('data-config') || '{}');
		} catch (e) {
			return;
		}
		state.root = el;
		status(state.config.i18n.loading);
		var controller = B.create(state.config, el);
		state.controller = controller;
		controller.ready.then(function () {
			if (controller === state.controller) {
				status('');
			}
		}).catch(function (error) {
			if (controller === state.controller) {
				status(B.message(error, state.config));
			}
		});
	}

	function form() {
		return $('form.checkout, form#order_review, form#add_payment_method').first();
	}

	function needsNonce() {
		if (!selected() || !root() || !state.config) {
			return false;
		}
		if (savedCard() && !state.config.secure) {
			return false;
		}
		var input = field('nonce');
		return !!input && !input.value;
	}

	function unblock() {
		var $form = form();
		$form.removeClass('processing');
		if ($form.unblock) {
			$form.unblock();
		}
	}

	function prepare() {
		if (state.working || !state.controller) {
			unblock();
			return;
		}
		state.working = true;
		status('');
		var started = state.root;
		state.controller.pay({ saved: savedCard(), save: wantsSave(), billing: billing() }).then(function (result) {
			state.working = false;
			if (started !== root()) {
				// The checkout refreshed during verification: the total may have changed.
				unblock();
				status(state.config.i18n.refreshed);
				return;
			}
			field('nonce').value = result.nonce;
			field('device').value = result.device;
			unblock();
			state.submitting = true;
			form().trigger('submit');
		}).catch(function (error) {
			state.working = false;
			status(B.message(error, state.config));
			unblock();
		});
	}

	function pass() {
		if (state.submitting || !needsNonce()) {
			state.submitting = false;
			return true;
		}
		return false;
	}

	// Classic checkout asks the chosen gateway with triggerHandler(), which does not bubble, so
	// the handler has to sit on the form itself.
	$(function () {
		$('form.checkout').on('checkout_place_order_' + GATEWAY, function () {
			if (pass()) {
				return true;
			}
			prepare();
			return false;
		});
	});

	// Order-pay and Add payment method post the form directly.
	$(document).on('submit', 'form#order_review, form#add_payment_method', function (event) {
		if (pass()) {
			return true;
		}
		event.preventDefault();
		prepare();
		return false;
	});

	// A failed attempt needs a fresh nonce next time.
	$(document.body).on('checkout_error', function () {
		clearNonce();
		state.submitting = false;
	});
	$(document.body).on('change', 'input[name="wc-' + GATEWAY + '-payment-token"], #wc-' + GATEWAY + '-new-payment-method', function () {
		clearNonce();
		toggleCard();
	});

	$(document.body).on('updated_checkout payment_method_selected', mount);
	$(document).on('change', 'input[name="payment_method"]', mount);
	$(mount);
})(window.jQuery);
