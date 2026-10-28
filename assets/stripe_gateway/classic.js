/**
 * Stackless for WooCommerce: Stripe Payment Element on the classic (shortcode) checkout,
 * the order-pay page, My Account → Add payment method and change-payment-method pages.
 *
 * Flow: mount the Payment Element in deferred mode (amount + currency, no intent yet) →
 * on submit create a ConfirmationToken → put its id in a hidden field → let the form post →
 * the server creates and confirms the intent. If the bank needs 3D Secure the server answers
 * with a #stackless-stripe-confirm/... fragment, handled here with stripe.handleNextAction().
 */
jQuery(function ($) {
	'use strict';

	var S = window.stacklessStripe;
	if (!S || !S.stripe()) {
		return;
	}
	var cfg = S.config;
	var gatewayId = cfg.gatewayId;
	var state = { elements: null, element: null, mountedOn: null, signature: '', complete: false, submitting: false };

	function container() {
		return document.getElementById('stackless-stripe-payment-element');
	}

	function context() {
		var el = container();
		if (!el) {
			return null;
		}
		return {
			flow: el.getAttribute('data-flow') || 'checkout',
			mode: el.getAttribute('data-mode') === 'setup' ? 'setup' : 'payment',
			amount: parseInt(el.getAttribute('data-amount'), 10) || 0,
			currency: el.getAttribute('data-currency') || 'usd',
			requiresSave: el.getAttribute('data-requires-save') === '1',
			capture: el.getAttribute('data-capture') === 'manual' ? 'manual' : 'automatic'
		};
	}

	function wantsSave(ctx) {
		if (ctx.requiresSave || ctx.mode === 'setup') {
			return true;
		}
		var box = document.getElementById('wc-' + gatewayId + '-new-payment-method');
		return !!(box && box.checked);
	}

	function elementsOptions(ctx) {
		var options = {
			mode: ctx.mode,
			currency: ctx.currency,
			appearance: S.appearance(container())
		};
		if (ctx.mode === 'payment') {
			options.amount = ctx.amount;
			options.captureMethod = ctx.capture;
			if (wantsSave(ctx)) {
				options.setupFutureUsage = 'off_session';
			}
		}
		return options;
	}

	function form() {
		var $checkout = $('form.checkout');
		if ($checkout.length) {
			return $checkout;
		}
		var $pay = $('form#order_review');
		if ($pay.length) {
			return $pay;
		}
		return $('form#add_payment_method');
	}

	function selected() {
		var checked = $('input[name="payment_method"]:checked');
		// The add-payment-method page with a single gateway may render no radio at all.
		return checked.length ? checked.val() === gatewayId : $('input[name="payment_method"][value="' + gatewayId + '"]').length > 0;
	}

	function usingSavedToken() {
		var token = $('input[name="wc-' + gatewayId + '-payment-token"]:checked').val();
		return !!token && token !== 'new';
	}

	function tokenField() {
		return $('input.stackless-stripe-confirmation-token');
	}

	function mount() {
		var el = container();
		var ctx = context();
		if (!el || !ctx) {
			return;
		}
		var signature = [ctx.mode, ctx.currency, ctx.capture].join('|');
		try {
			if (!state.elements || state.signature !== signature) {
				if (state.element) {
					state.element.destroy();
				}
				state.elements = S.stripe().elements(elementsOptions(ctx));
				state.element = state.elements.create('payment', { layout: { type: 'tabs', defaultCollapsed: false } });
				state.element.on('change', function (event) {
					state.complete = !!event.complete;
					showFieldError('');
				});
				state.signature = signature;
				state.mountedOn = null;
			} else if (ctx.mode === 'payment') {
				state.elements.update({ amount: ctx.amount });
				syncSave(ctx);
			}
			if (state.mountedOn !== el) {
				if (state.mountedOn) {
					state.element.unmount();
				}
				state.element.mount(el);
				state.mountedOn = el;
			}
		} catch (e) {
			showFieldError(S.errorMessage(e));
		}
	}

	/** Keeps the Element's setupFutureUsage in step with the "save" checkbox (must match the server). */
	function syncSave(ctx) {
		try {
			state.elements.update({ setupFutureUsage: wantsSave(ctx) ? 'off_session' : null });
		} catch (e) {
			// Older Stripe.js builds reject null; the server still decides whether to save.
		}
	}

	function showFieldError(message) {
		$('.stackless-stripe-errors').text(message || '');
	}

	function showError(message) {
		var $form = form();
		$('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .stackless-stripe-notice').remove();
		$form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout stackless-stripe-notice"><ul class="woocommerce-error" role="alert"><li>' + S.escapeHtml(message) + '</li></ul></div>');
		$form.removeClass('processing');
		if ($.fn.unblock) {
			$form.unblock();
		}
		$('input#place_order, button#place_order').prop('disabled', false);
		var top = $form.offset();
		if (top) {
			$('html, body').animate({ scrollTop: top.top - 100 }, 300);
		}
		$(document.body).trigger('checkout_error', [message]);
	}

	function block() {
		var $form = form();
		$form.addClass('processing');
		if ($.fn.block) {
			$form.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
		}
	}

	function billingDetails() {
		var val = function (name) {
			var field = document.getElementById(name);
			return field && field.value ? field.value : undefined;
		};
		var name = [val('billing_first_name'), val('billing_last_name')].filter(Boolean).join(' ');
		var details = {
			name: name || undefined,
			email: val('billing_email'),
			phone: val('billing_phone'),
			address: {
				line1: val('billing_address_1'),
				line2: val('billing_address_2'),
				city: val('billing_city'),
				state: val('billing_state'),
				postal_code: val('billing_postcode'),
				country: val('billing_country')
			}
		};
		Object.keys(details.address).forEach(function (key) {
			if (details.address[key] === undefined) {
				delete details.address[key];
			}
		});
		if (!Object.keys(details.address).length) {
			delete details.address;
		}
		Object.keys(details).forEach(function (key) {
			if (details[key] === undefined) {
				delete details[key];
			}
		});
		return details;
	}

	/** elements.submit() must run synchronously inside the submit gesture for wallets. */
	function createToken() {
		if (!state.elements) {
			return Promise.reject(new Error(S.i18n('enterDetails')));
		}
		var ctx = context();
		if (ctx && ctx.mode === 'payment') {
			syncSave(ctx);
		}
		return state.elements.submit().then(function (result) {
			if (result && result.error) {
				throw result.error;
			}
			return S.stripe().createConfirmationToken({
				elements: state.elements,
				params: { payment_method_data: { billing_details: billingDetails() } }
			});
		}).then(function (result) {
			if (result.error) {
				throw result.error;
			}
			tokenField().val(result.confirmationToken.id);
			return result.confirmationToken.id;
		});
	}

	/* -------------------------------------------------------------------
	 * Classic checkout
	 * ----------------------------------------------------------------- */

	$(document.body).on('updated_checkout payment_method_selected', function () {
		mount();
	});

	$('form.checkout').on('checkout_place_order_' + gatewayId, function () {
		if (usingSavedToken() || tokenField().val()) {
			return true;
		}
		var $form = $(this);
		// No "processing" class here: WooCommerce ignores submits while the form has it.
		if ($.fn.block) {
			$form.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
		}
		createToken().then(function () {
			if ($.fn.unblock) {
				$form.unblock();
			}
			$form.trigger('submit');
		}).catch(function (error) {
			showError(S.errorMessage(error));
		});
		return false;
	});

	$(document.body).on('change', '#wc-' + gatewayId + '-new-payment-method', function () {
		var ctx = context();
		if (state.elements && ctx && ctx.mode === 'payment') {
			syncSave(ctx);
		}
	});

	$(document.body).on('checkout_error', function () {
		// A ConfirmationToken is single-use: get a fresh one on the next attempt.
		tokenField().val('');
	});

	/* -------------------------------------------------------------------
	 * Order-pay and add-payment-method forms
	 * ----------------------------------------------------------------- */

	$('form#order_review, form#add_payment_method').on('submit', function (event) {
		if (!selected() || usingSavedToken() || state.submitting) {
			return;
		}
		if (tokenField().val()) {
			state.submitting = true;
			return;
		}
		event.preventDefault();
		var formEl = this;
		block();
		createToken().then(function () {
			state.submitting = true;
			HTMLFormElement.prototype.submit.call(formEl);
		}).catch(function (error) {
			showError(S.errorMessage(error));
		});
	});

	$('form#order_review, form#add_payment_method').on('change', 'input[name="payment_method"]', function () {
		mount();
	});

	/* -------------------------------------------------------------------
	 * 3D Secure / redirect confirmation fragments
	 * ----------------------------------------------------------------- */

	function handleHash() {
		var action = S.parseConfirmHash(window.location.hash);
		if (!action) {
			return;
		}
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', window.location.pathname + window.location.search);
		}
		block();
		S.confirmAndVerify(action).then(function (response) {
			window.location.href = response.redirect;
		}).catch(function (error) {
			tokenField().val('');
			state.submitting = false;
			showError(S.errorMessage(error));
			if ($('form.checkout').length) {
				$(document.body).trigger('update_checkout');
			}
		});
	}

	window.addEventListener('hashchange', handleHash);

	// Order-pay and add-payment-method pages render the fields without updated_checkout.
	if (!$('form.checkout').length) {
		$(document.body).trigger('wc-credit-card-form-init');
		mount();
	}
	handleHash();
});
