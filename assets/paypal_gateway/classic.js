/**
 * Stackless for WooCommerce: PayPal buttons on the classic checkout,
 * the order-pay page and My Account → Add payment method.
 *
 * After PayPal approves, the order id (or vault setup token) goes into a hidden
 * field and the WooCommerce form is submitted; the server captures and checks
 * the amount. Without JavaScript, Place order falls back to a PayPal redirect.
 */
jQuery(function ($) {
	'use strict';

	var data = window.stacklessPayPalData;
	if (!data || !window.stacklessPayPal) {
		return;
	}

	var approval = null; // { type: 'order'|'setup'|'fastlane', id: string, mode: string, currency: string, funding: string }
	var extras = { card: null, fastlane: null, busy: false }; // card fields / Fastlane controllers for the rendered form

	function box() {
		return document.getElementById('stackless-paypal-buttons');
	}

	function form() {
		var $form = $('form.checkout');
		if (!$form.length) {
			$form = $('form#order_review');
		}
		if (!$form.length) {
			$form = $('form#add_payment_method');
		}
		return $form;
	}

	function selected() {
		var $radios = $('input[name="payment_method"]');
		if (!$radios.length) {
			return !!box();
		}
		return $radios.filter(':checked').val() === data.gatewayId;
	}

	function status(message) {
		$('.stackless-paypal__status').text(message || '');
	}

	function writeHidden() {
		$('input[name="stackless_paypal_order_id"]').val(approval && approval.type === 'order' ? approval.id : '');
		$('input[name="stackless_paypal_setup_token"]').val(approval && approval.type === 'setup' ? approval.id : '');
		$('input[name="stackless_paypal_fastlane_token"]').val(approval && approval.type === 'fastlane' ? approval.id : '');
		$('input[name="stackless_paypal_funding"]').val(approval && approval.funding ? approval.funding : '');
	}

	function cardReady() {
		return !!(extras.card || extras.fastlane);
	}

	/** Billing address for card verification, from the checkout form when there is one. */
	function billingAddress() {
		var get = function (name) {
			var $field = $('[name="billing_' + name + '"]');
			return $field.length ? String($field.val() || '') : '';
		};
		if (!$('[name="billing_country"]').length) {
			return undefined;
		}
		return {
			addressLine1: get('address_1'),
			addressLine2: get('address_2'),
			adminArea2: get('city'),
			adminArea1: get('state'),
			postalCode: get('postcode'),
			countryCode: get('country')
		};
	}

	/** Card fields (or Fastlane for guests) and Apple Pay / Google Pay, once per rendered form. */
	function renderExtras(d, key) {
		var cardBox = document.getElementById('stackless-paypal-card');
		var fieldsEl = document.getElementById('stackless-paypal-card-fields');
		var walletsEl = document.getElementById('stackless-paypal-wallets');
		if (d.mode !== 'order') {
			extras.card = null;
			extras.fastlane = null;
			if (cardBox) {
				cardBox.hidden = true;
			}
			return;
		}
		var common = {
			currency: d.currency,
			intent: d.intent,
			vault: d.vault === '1',
			context: d.context,
			orderId: d.orderId,
			orderKey: d.orderKey
		};
		if (fieldsEl && fieldsEl.getAttribute('data-rendered') !== key) {
			fieldsEl.setAttribute('data-rendered', key);
			extras.card = null;
			extras.fastlane = null;
			var useFastlane = data.fastlane && d.context === 'checkout';
			var start = useFastlane ? window.stacklessPayPal.fastlane(data, Object.assign({ el: fieldsEl }, common)) : Promise.resolve(null);
			start.then(function (fast) {
				if (fast) {
					extras.fastlane = fast;
					fast.lookup($('#billing_email').val());
					return true;
				}
				return window.stacklessPayPal.cardFields(data, Object.assign({ el: fieldsEl }, common)).then(function (card) {
					extras.card = card;
					return !!card;
				});
			}).then(function (shown) {
				if (cardBox) {
					cardBox.hidden = !shown;
				}
				toggle();
			}).catch(function () {
				if (cardBox) {
					cardBox.hidden = true;
				}
				toggle();
			});
		}
		if (walletsEl && walletsEl.getAttribute('data-rendered') !== key) {
			walletsEl.setAttribute('data-rendered', key);
			window.stacklessPayPal.wallets(data, Object.assign({
				el: walletsEl,
				amount: parseFloat(d.total) || 0,
				onApproved: function (orderId, funding) {
					approval = { type: 'order', id: orderId, mode: d.mode, currency: d.currency, funding: funding };
					writeHidden();
					status(data.i18n.approved);
					toggle();
					form().trigger('submit');
				},
				onError: function (message) {
					status(message);
				}
			}, common)).catch(function () {
				walletsEl.innerHTML = '';
			});
		}
	}

	/**
	 * Place order with the card fields (or Fastlane) filled in: run the card payment first,
	 * then submit the form with the approved PayPal order. Returns false to hold the submit.
	 */
	function beforeSubmit() {
		if (!selected() || approval || !cardReady()) {
			return true;
		}
		if (extras.busy) {
			return false;
		}
		extras.busy = true;
		status('');
		var d = box() ? box().dataset : {};
		var pay = extras.fastlane
			? extras.fastlane.token(billingAddress()).then(function (id) {
				approval = { type: 'fastlane', id: id, mode: d.mode, currency: d.currency, funding: 'fastlane' };
			})
			: extras.card.submit(billingAddress()).then(function (id) {
				approval = { type: 'order', id: id, mode: d.mode, currency: d.currency, funding: 'card' };
			});
		pay.then(function () {
			extras.busy = false;
			writeHidden();
			status(data.i18n.approved);
			form().trigger('submit');
		}).catch(function (error) {
			extras.busy = false;
			approval = null;
			writeHidden();
			status(error && error.message && error.message.length < 200 ? error.message : data.i18n.cardIncomplete);
		});
		return false;
	}

	function toggle() {
		var el = box();
		var ready = el && el.getAttribute('data-ready') === '1';
		var $button = $('#place_order');
		if (selected() && ready && !approval && !cardReady()) {
			$button.hide();
		} else {
			$button.show();
		}
	}

	function render() {
		var el = box();
		if (!el) {
			toggle();
			return;
		}
		var d = el.dataset;
		var key = [d.context, d.mode, d.vault, d.intent, d.currency, d.total].join('|');

		// A saved approval only survives refreshes that keep the same flow and currency.
		if (approval && (approval.mode !== d.mode || approval.currency !== d.currency)) {
			approval = null;
		}
		writeHidden();

		if (el.getAttribute('data-rendered') === key) {
			toggle();
			return;
		}
		el.setAttribute('data-rendered', key);
		el.removeAttribute('data-ready');

		if ((data.currencies || []).indexOf(String(d.currency).toUpperCase()) === -1) {
			status(data.i18n.unavailable);
			toggle();
			return;
		}
		status(approval ? data.i18n.approved : (d.mode === 'setup' ? data.i18n.saving : ''));

		renderExtras(d, key);

		window.stacklessPayPal.render(data, el, {
			mode: d.mode,
			vault: d.vault === '1',
			intent: d.intent,
			currency: d.currency,
			context: d.context,
			orderId: d.orderId,
			orderKey: d.orderKey,
			amount: parseFloat(d.total) || 0,
			messagesEl: document.getElementById('stackless-paypal-messages'),
			onApproved: function (type, id) {
				approval = { type: type, id: id, mode: d.mode, currency: d.currency };
				writeHidden();
				status(data.i18n.approved);
				toggle();
				form().trigger('submit');
			},
			onError: function (message) {
				status(message);
			},
			onCancel: function () {
				status(data.i18n.cancelled);
			}
		}).then(function (buttons) {
			if (buttons) {
				el.setAttribute('data-ready', '1');
			}
			toggle();
		}).catch(function () {
			el.removeAttribute('data-rendered');
			status(data.i18n.error);
			toggle();
		});
	}

	$('form.checkout').on('checkout_place_order_' + data.gatewayId, beforeSubmit);
	$('form#order_review').on('submit', function (event) {
		if (beforeSubmit() === false) {
			event.preventDefault();
			event.stopImmediatePropagation();
			$(this).unblock();
			return false;
		}
		return true;
	});
	$(document.body).on('change', '#billing_email', function () {
		if (extras.fastlane) {
			extras.fastlane.lookup($(this).val());
		}
	});
	$(document.body).on('checkout_error', function () {
		// A declined or expired card approval can't be reused; the buyer pays again.
		if (approval && (approval.funding === 'card' || approval.type === 'fastlane')) {
			approval = null;
			writeHidden();
		}
	});
	$(document.body).on('updated_checkout payment_method_selected', render);
	$(document.body).on('change', 'input[name="payment_method"]', function () {
		render();
		toggle();
	});
	$(document.body).on('checkout_error', toggle);

	render();
});
