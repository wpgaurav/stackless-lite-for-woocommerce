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
	var FORMS = 'form.checkout, form#order_review, form#add_payment_method';
	var state = { container: null, card: null, config: null, submitting: false, pending: null };

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

	// A wallet pays with its own token, so the form must not name a saved card as well.
	function deselectSavedCard() {
		$('input[name="wc-' + GATEWAY + '-payment-token"]').prop('checked', false)
			.filter('[value="new"]').prop('checked', true).trigger('change');
	}

	function forgetToken() {
		var cfg = state.config || config();
		if (!cfg) {
			return;
		}
		var tokenField = field(cfg.fields.token);
		var methodField = field(cfg.fields.method);
		if (tokenField) {
			tokenField.value = '';
		}
		if (methodField) {
			methodField.value = 'card';
		}
		var amountField = walletAmountField(cfg, false);
		if (amountField) {
			amountField.value = '';
		}
		state.submitting = false;
	}

	// The total a wallet's sheet showed, sent with its token: the server refuses any other total.
	function walletAmountField(cfg, create) {
		var name = cfg && cfg.fields ? cfg.fields.amount : '';
		var input = name ? field(name) : null;
		var box = document.getElementById('stackless-square-form');
		if (!input && create && name && box) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			box.appendChild(input);
		}
		return input;
	}

	// A saved card pays by itself: drop what an unfinished wallet or card attempt left in the fields.
	function clearForSavedCard() {
		if (selected() && usingSavedCard()) {
			forgetToken();
		}
	}

	// Pressing Place order pays with what is on screen, so a token from an earlier submit goes, even if
	// another script then stops this one. A press is the buyer's click, or a click their click or key
	// press causes at once (Enter, a theme's own button). A script that submits again later is none, so
	// a wallet's submit it repeats still pays with the wallet.
	var pressing = false;
	function watchPress(event) {
		if (event.isTrusted) {
			pressing = true;
			setTimeout(function () {
				pressing = false;
			}, 0);
		}
		if (!pressing || event.type !== 'click' || !event.target.closest) {
			return;
		}
		var button = event.target.closest('button, input');
		if (button && button.type === 'submit' && button.form && $(button.form).is(FORMS)) {
			forgetToken();
		}
	}
	document.addEventListener('click', watchPress, true);
	document.addEventListener('keydown', watchPress, true);

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

	function discard(method) {
		if (method && method.destroy) {
			Promise.resolve().then(function () {
				return method.destroy();
			}).catch(function () { /* Already gone with its container. */ });
		}
	}

	// One mount per container, pending or done. A failed one lets go of it, so the next call retries.
	function mount() {
		var cfg = config();
		var container = document.getElementById('stackless-square-card');
		if (!cfg || !container) {
			return;
		}
		state.config = cfg;
		if (state.container === container) {
			if (!state.card) {
				status(cfg.i18n.loading);
			}
			return;
		}
		discard(state.card);
		state.container = container;
		state.card = null;
		status(cfg.i18n.loading);

		var card = null;
		// False once a checkout refresh has replaced the container.
		function current() {
			return state.container === container && container.isConnected;
		}
		function release() {
			discard(card);
			if (state.container === container) {
				state.container = null;
			}
		}
		S.payments(cfg).then(function (payments) {
			return payments.card().then(function (created) {
				card = created;
				// attach() finds the container by id, so check that it is still this one.
				return current() && card.attach('#stackless-square-card');
			}).then(function () {
				return payments;
			});
		}).then(function (payments) {
			if (!current()) {
				release();
				return;
			}
			state.card = card;
			status('');
			mountWallets(payments, cfg);
		}, function () {
			var show = current();
			release();
			if (show) {
				status(cfg.i18n.failed);
			}
		}).catch(function () { /* Wallets are optional: the card form still works. */ });
	}

	function mountWallets(payments, cfg) {
		if (!cfg.wallets || !cfg.wallets.length || Number(cfg.amount) <= 0) {
			return;
		}
		var request = S.paymentRequest(payments, cfg, cfg.amount);
		var googleButton = document.getElementById('stackless-square-google-pay');
		if (cfg.wallets.indexOf('google_pay') !== -1 && googleButton) {
			payments.googlePay(request).then(function (googlePay) {
				// A refreshed button gets its own Google Pay, with the new amount, from the next mount.
				if (!googleButton.isConnected) {
					discard(googlePay);
					return;
				}
				return googlePay.attach('#stackless-square-google-pay').then(function () {
					if (!googleButton.isConnected) {
						discard(googlePay);
						return;
					}
					googleButton.addEventListener('click', function (event) {
						event.preventDefault();
						payWithWallet(googlePay, 'google_pay', cfg.amount);
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
					payWithWallet(applePay, 'apple_pay', cfg.amount);
				});
			}).catch(function () { /* Apple Pay is not available here. */ });
		}
	}

	function payWithWallet(wallet, method, shown) {
		S.tokenize(wallet, null).then(function (token) {
			submitWith(token, method, shown);
		}).catch(function (error) {
			status(error && error.message ? error.message : state.config.i18n.invalid);
		});
	}

	function form() {
		return $(FORMS).first();
	}

	function submitWith(token, method, shown) {
		var tokenField = field(state.config.fields.token);
		var methodField = field(state.config.fields.method);
		if (!tokenField) {
			return;
		}
		var $form = form();
		// While the wallet was open: another method picked would be the one submitted and charged, and
		// a payment already on its way (Place order, an earlier click) would become two charges.
		if (!selected() || $form.is('.processing') || $form.data('blockUI.isBlocked')) {
			return;
		}
		// Place order was pressed with a typed card and Square is still tokenizing it (checkout doesn't block).
		if (state.pending && state.pending === state.card && !usingSavedCard()) {
			return;
		}
		state.pending = null;
		deselectSavedCard();
		tokenField.value = token;
		if (methodField) {
			methodField.value = method;
		}
		var amountField = walletAmountField(state.config, true);
		if (amountField) {
			amountField.value = shown;
		}
		state.submitting = true;
		$form.trigger('submit');
	}

	/** Tokenize the card form; resolves when the token field is filled. */
	function tokenizeCard() {
		var cfg = state.config || config();
		if (!state.card || !cfg) {
			status(cfg ? cfg.i18n.failed : '');
			mount(); // shows Loading if a mount is pending, retries if it failed
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

	// Classic checkout: WooCommerce asks the selected gateway with triggerHandler(), which does not
	// bubble, so listen on the form itself. Refreshes replace fragments inside it, never the form.
	$(function () {
		$('form.checkout').on('checkout_place_order_' + GATEWAY, function () {
			clearForSavedCard();
			// The filled token field is the only go-ahead: a refresh empties it.
			if (!needsToken()) {
				return true;
			}
			// One tokenization (and one 3-D Secure prompt) per card form; a refreshed form may start again.
			if (state.pending && state.pending === state.card) {
				return false;
			}
			var card = state.pending = state.card;
			tokenizeCard().then(function () {
				var latest = state.pending === card; // not since replaced by a wallet or a newer press
				if (latest) {
					state.pending = null;
				}
				// Don't place the order if the shopper switched method or card while Square was working.
				if (latest && selected() && !usingSavedCard()) {
					form().trigger('submit');
				}
			}, function () {
				if (state.pending === card) {
					state.pending = null; // message shown
				}
			});
			return false;
		});
	});

	// Order-pay and Add payment method post the form directly.
	$(document).on('submit', 'form#order_review, form#add_payment_method', function (event) {
		clearForSavedCard();
		if (state.submitting || !needsToken()) {
			state.submitting = false;
			return true;
		}
		event.preventDefault();
		var $form = $(this);
		tokenizeCard().then(function () {
			state.submitting = true;
			form().trigger('submit');
		}).catch(function () {
			// Message shown. WooCommerce blocked the form on submit and only a page load would clear it.
			if ($.fn.unblock) {
				$form.unblock();
			}
		});
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
