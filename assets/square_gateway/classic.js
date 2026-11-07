/**
 * Stackless for WooCommerce: Square on the classic checkout, the order-pay page and My Account →
 * Add payment method. The card form is mounted from the settings in #stackless-square-form
 * (re-read after every checkout refresh); Place order tokenizes first, then submits the token.
 */
(function ($) {
	'use strict';

	var S = window.StacklessSquare;
	if (!S || !$) {
		return;
	}

	var GATEWAY = 'stackless_square';
	var state = { container: null, card: null, config: null, submitting: false };

	function config() {
		var el = document.getElementById('stackless-square-form');
		if (!el) {
			return null;
		}
		try {
			return JSON.parse(el.getAttribute('data-config') || '{}');
		} catch (e) {
			return null;
		}
	}

	function status(message) {
		var el = document.querySelector('#stackless-square-form .stackless-square__status');
		if (el) {
			el.textContent = message || '';
		}
	}

	function field(name) {
		return document.querySelector('#stackless-square-form input[name="' + name + '"]');
	}

	function selected() {
		var radio = document.querySelector('input[name="payment_method"]:checked');
		return !radio || radio.value === GATEWAY;
	}

	function usingSavedCard() {
		var saved = document.querySelector('input[name="wc-' + GATEWAY + '-payment-token"]:checked');
		return !!(saved && saved.value && saved.value !== 'new');
	}

	function wantsSave(cfg) {
		if (cfg.requiresSave || cfg.flow === 'add') {
			return true;
		}
		var box = document.getElementById('wc-' + GATEWAY + '-new-payment-method');
		return !!(box && box.checked);
	}

	function billing(cfg) {
		if (cfg.flow !== 'checkout') {
			return cfg.billing || {};
		}
		var get = function (id) {
			var el = document.getElementById(id);
			return el ? el.value : '';
		};
		return {
			givenName: get('billing_first_name'),
			familyName: get('billing_last_name'),
			email: get('billing_email'),
			phone: get('billing_phone'),
			addressLines: [get('billing_address_1'), get('billing_address_2')].filter(Boolean),
			city: get('billing_city'),
			state: get('billing_state'),
			postalCode: get('billing_postcode'),
			countryCode: get('billing_country')
		};
	}

	function mount() {
		var cfg = config();
		var container = document.getElementById('stackless-square-card');
		if (!cfg || !container) {
			return;
		}
		state.config = cfg;
		if (state.container === container && state.card) {
			return;
		}
		if (state.card && state.card.destroy) {
			state.card.destroy();
		}
		state.container = container;
		state.card = null;
		status(cfg.i18n.loading);
		S.payments(cfg).then(function (payments) {
			return payments.card().then(function (card) {
				return card.attach('#stackless-square-card').then(function () {
					state.card = card;
					status('');
					mountWallets(payments, cfg);
				});
			});
		}).catch(function () {
			status(cfg.i18n.failed);
		});
	}

	function mountWallets(payments, cfg) {
		if (!cfg.wallets || !cfg.wallets.length || Number(cfg.amount) <= 0) {
			return;
		}
		var request = S.paymentRequest(payments, cfg, cfg.amount);
		if (cfg.wallets.indexOf('google_pay') !== -1 && document.getElementById('stackless-square-google-pay')) {
			payments.googlePay(request).then(function (googlePay) {
				return googlePay.attach('#stackless-square-google-pay').then(function () {
					document.getElementById('stackless-square-google-pay').addEventListener('click', function (event) {
						event.preventDefault();
						payWithWallet(googlePay, 'google_pay');
					});
				});
			}).catch(function () { /* Google Pay is not available on this device. */ });
		}
		var appleButton = document.getElementById('stackless-square-apple-pay');
		if (cfg.wallets.indexOf('apple_pay') !== -1 && appleButton) {
			payments.applePay(request).then(function (applePay) {
				appleButton.hidden = false;
				appleButton.addEventListener('click', function (event) {
					event.preventDefault();
					payWithWallet(applePay, 'apple_pay');
				});
			}).catch(function () { /* Apple Pay is not available here. */ });
		}
	}

	function payWithWallet(wallet, method) {
		S.tokenize(wallet, null).then(function (token) {
			submitWith(token, method);
		}).catch(function (error) {
			status(error && error.message ? error.message : state.config.i18n.invalid);
		});
	}

	function form() {
		return $('form.checkout, form#order_review, form#add_payment_method').first();
	}

	function submitWith(token, method) {
		var tokenField = field(state.config.fields.token);
		var methodField = field(state.config.fields.method);
		if (!tokenField) {
			return;
		}
		tokenField.value = token;
		if (methodField) {
			methodField.value = method;
		}
		state.submitting = true;
		form().trigger('submit');
	}

	/** Tokenize the card form; resolves when the token field is filled. */
	function tokenizeCard() {
		var cfg = state.config || config();
		if (!state.card || !cfg) {
			status(cfg ? cfg.i18n.failed : '');
			return Promise.reject(new Error('not ready'));
		}
		var amount = cfg.amount;
		var save = wantsSave(cfg);
		var intent = S.intentFor(cfg, amount, save);
		return S.tokenize(state.card, S.details(cfg, amount, intent, billing(cfg))).then(function (token) {
			field(cfg.fields.token).value = token;
			field(cfg.fields.method).value = 'card';
		}).catch(function (error) {
			status(error && error.message ? error.message : cfg.i18n.invalid);
			throw error;
		});
	}

	function needsToken() {
		var tokenField = state.config ? field(state.config.fields.token) : null;
		return selected() && !usingSavedCard() && tokenField && !tokenField.value;
	}

	// Classic checkout: WooCommerce asks every gateway before it posts the form.
	$(document.body).on('checkout_place_order_' + GATEWAY, function () {
		if (state.submitting || !needsToken()) {
			state.submitting = false;
			return true;
		}
		tokenizeCard().then(function () {
			state.submitting = true;
			form().trigger('submit');
		}).catch(function () { /* message shown */ });
		return false;
	});

	// Order-pay and Add payment method post the form directly.
	$(document).on('submit', 'form#order_review, form#add_payment_method', function (event) {
		if (state.submitting || !needsToken()) {
			state.submitting = false;
			return true;
		}
		event.preventDefault();
		tokenizeCard().then(function () {
			state.submitting = true;
			form().trigger('submit');
		}).catch(function () { /* message shown */ });
		return false;
	});

	// A failed attempt needs a fresh token next time.
	$(document.body).on('checkout_error', function () {
		var tokenField = state.config ? field(state.config.fields.token) : null;
		if (tokenField) {
			tokenField.value = '';
		}
		state.submitting = false;
	});

	$(document.body).on('updated_checkout payment_method_selected', mount);
	$(mount);
})(window.jQuery);
