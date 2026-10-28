/* Stackless for WooCommerce: back-in-stock sign-up form. No build step. */
(function () {
	'use strict';

	var config = window.stacklessBackInStock || {};

	function post(form, extra) {
		var body = new window.FormData(form);
		body.append('action', 'stackless_back_in_stock');
		Object.keys(extra || {}).forEach(function (key) {
			body.set(key, extra[key]);
		});
		return window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.json().then(function (json) {
					return { ok: response.ok && json && json.success, status: response.status, data: (json && json.data) || {} };
				});
			});
	}

	function submit(event) {
		var form = event.target.closest('.stackless-stock-alert-form');
		if (!form) {
			return;
		}
		event.preventDefault();
		var status = form.querySelector('.stackless-stock-alert-status');
		var button = form.querySelector('button[type="submit"]');
		if (!form.reportValidity()) {
			return;
		}
		button.disabled = true;
		var send = function (retry) {
			return post(form).then(function (result) {
				if (!result.ok && result.data.retry && result.data.nonce && retry) {
					form.querySelector('input[name="nonce"]').value = result.data.nonce;
					return send(false);
				}
				status.textContent = result.data.message || config.error;
				status.classList.toggle('is-error', !result.ok);
				if (result.ok) {
					form.querySelector('input[name="consent"]').checked = false;
				}
			});
		};
		send(true).catch(function () {
			status.textContent = config.error;
		}).then(function () {
			button.disabled = false;
		});
	}

	function bindVariations() {
		if (!window.jQuery) {
			return;
		}
		var $ = window.jQuery;
		$(document.body).on('found_variation', '.variations_form', function (event, variation) {
			var section = document.querySelector('[data-stackless-stock-alert][data-variable="1"]');
			if (!section) {
				return;
			}
			var ids = JSON.parse(section.getAttribute('data-options') || '[]');
			var id = variation && variation.variation_id ? Number(variation.variation_id) : 0;
			var show = id && ids.indexOf(id) !== -1 && !variation.is_in_stock;
			section.hidden = !show;
			section.querySelector('input[name="product_id"]').value = show ? String(id) : '0';
			var label = section.querySelector('[data-stackless-option-label]');
			if (label) {
				var names = [];
				$(this).find('.variations select').each(function () {
					var text = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
					if (this.value) {
						names.push(text);
					}
				});
				label.textContent = names.join(', ');
			}
		});
		$(document.body).on('reset_data hide_variation', '.variations_form', function () {
			var section = document.querySelector('[data-stackless-stock-alert][data-variable="1"]');
			if (section) {
				section.hidden = true;
				section.querySelector('input[name="product_id"]').value = '0';
			}
		});
	}

	function init() {
		var forms = document.querySelectorAll('.stackless-stock-alert-form');
		if (!forms.length) {
			return;
		}
		document.addEventListener('submit', submit);
		bindVariations();
		// Cached pages carry a stale nonce; fetch a fresh one.
		var body = new window.FormData();
		body.append('action', 'stackless_back_in_stock');
		body.append('op', 'nonce');
		window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success && json.data.nonce) {
					forms.forEach(function (form) {
						form.querySelector('input[name="nonce"]').value = json.data.nonce;
					});
				}
			}).catch(function () {});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
