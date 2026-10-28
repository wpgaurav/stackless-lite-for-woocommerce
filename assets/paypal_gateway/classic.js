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

	var approval = null; // { type: 'order'|'setup', id: string, mode: string, currency: string }

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
	}

	function toggle() {
		var el = box();
		var ready = el && el.getAttribute('data-ready') === '1';
		var $button = $('#place_order');
		if (selected() && ready && !approval) {
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

	$(document.body).on('updated_checkout payment_method_selected', render);
	$(document.body).on('change', 'input[name="payment_method"]', function () {
		render();
		toggle();
	});
	$(document.body).on('checkout_error', toggle);

	render();
});
