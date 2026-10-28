/**
 * Stackless for WooCommerce: PayPal JS SDK loader and button renderer.
 * Shared by the classic checkout (classic.js) and the Checkout block (blocks.js).
 * Plain ES2017, no build step.
 */
(function (window, document) {
	'use strict';

	var cache = {};

	function hash(text) {
		var h = 0;
		for (var i = 0; i < text.length; i++) {
			h = ((h << 5) - h + text.charCodeAt(i)) | 0;
		}
		return Math.abs(h).toString(36);
	}

	function sdkUrl(data, opts) {
		var params = {
			'client-id': data.clientId,
			currency: String(opts.currency || 'USD').toUpperCase(),
			intent: opts.intent === 'authorize' ? 'authorize' : 'capture'
		};
		var extra = Object.assign({}, data.sdkParams || {}, opts.mode === 'setup' ? (data.sdkSetupParams || {}) : {});
		Object.keys(extra).forEach(function (key) {
			if (extra[key] !== '' && extra[key] !== null && extra[key] !== undefined) {
				params[key] = String(extra[key]);
			}
		});
		var query = Object.keys(params).map(function (key) {
			return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
		}).join('&');
		return data.sdkBase + '?' + query;
	}

	/** Load the SDK once per URL, each under its own namespace so currencies/intents can differ. */
	function load(data, opts) {
		var url = sdkUrl(data, opts);
		if (cache[url]) {
			return cache[url];
		}
		var namespace = 'stacklessPayPalSdk_' + hash(url);
		cache[url] = new Promise(function (resolve, reject) {
			if (window[namespace]) {
				resolve(window[namespace]);
				return;
			}
			var script = document.createElement('script');
			script.src = url;
			script.async = true;
			script.setAttribute('data-namespace', namespace);
			script.onload = function () {
				if (window[namespace]) {
					resolve(window[namespace]);
				} else {
					delete cache[url];
					reject(new Error('PayPal SDK did not initialise'));
				}
			};
			script.onerror = function () {
				delete cache[url];
				reject(new Error('PayPal SDK failed to load'));
			};
			document.head.appendChild(script);
		});
		return cache[url];
	}

	function post(data, url, fields) {
		var body = new URLSearchParams();
		body.append('nonce', data.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			if (fields[key] !== undefined && fields[key] !== null) {
				body.append(key, String(fields[key]));
			}
		});
		return window.fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json().catch(function () {
				return null;
			});
		}).then(function (json) {
			if (!json || !json.success || !json.data || !json.data.id) {
				var message = json && json.data && json.data.message ? json.data.message : data.i18n.error;
				throw new Error(message);
			}
			return json.data;
		});
	}

	/**
	 * Render PayPal buttons into el.
	 *
	 * opts: mode ('order'|'setup'), vault, intent, currency, context, orderId, orderKey,
	 *       amount (number, for Pay Later messages), messagesEl,
	 *       onApproved(type, id), onError(message), onCancel()
	 *
	 * @return Promise resolving to the Buttons instance, or null when not eligible.
	 */
	function render(data, el, opts) {
		var mode = opts.mode === 'setup' ? 'setup' : 'order';
		return load(data, {
			currency: opts.currency,
			intent: mode === 'setup' || opts.vault ? 'capture' : opts.intent,
			mode: mode
		}).then(function (paypal) {
			var funding = 'paypal';
			var lastError = '';
			var common = {
				context: opts.context || 'checkout',
				order_id: opts.orderId || '',
				order_key: opts.orderKey || ''
			};
			var config = {
				style: data.style || {},
				onClick: function (details) {
					funding = details && details.fundingSource ? String(details.fundingSource) : 'paypal';
					lastError = '';
				},
				onCancel: function () {
					if (opts.onCancel) {
						opts.onCancel();
					}
				},
				onError: function (err) {
					if (opts.onError) {
						opts.onError(lastError || (err && err.message && err.message.length < 200 ? err.message : data.i18n.error));
					}
				}
			};

			if (mode === 'setup') {
				config.createVaultSetupToken = function () {
					return post(data, data.ajax.createSetupToken, common).then(function (result) {
						return result.id;
					}).catch(function (error) {
						lastError = error.message;
						throw error;
					});
				};
				config.onApprove = function (details) {
					opts.onApproved('setup', details.vaultSetupToken);
				};
			} else {
				config.createOrder = function () {
					return post(data, data.ajax.createOrder, Object.assign({ funding: funding }, common)).then(function (result) {
						return result.id;
					}).catch(function (error) {
						lastError = error.message;
						throw error;
					});
				};
				config.onApprove = function (details) {
					opts.onApproved('order', details.orderID);
				};
			}

			// Saved accounts are PayPal wallets only; other funding sources can't be vaulted here.
			if (mode === 'setup' || opts.vault) {
				config.fundingSource = paypal.FUNDING.PAYPAL;
			}

			var buttons = paypal.Buttons(config);
			if (buttons.isEligible && !buttons.isEligible()) {
				return null;
			}
			el.innerHTML = '';
			buttons.render(el);

			if (opts.messagesEl) {
				opts.messagesEl.innerHTML = '';
				if (data.payLater && mode === 'order' && !opts.vault && paypal.Messages && opts.amount > 0) {
					paypal.Messages({
						amount: opts.amount,
						placement: 'payment',
						style: { layout: 'text' }
					}).render(opts.messagesEl);
				}
			}
			return buttons;
		});
	}

	window.stacklessPayPal = { load: load, post: post, render: render, sdkUrl: sdkUrl };
})(window, document);
