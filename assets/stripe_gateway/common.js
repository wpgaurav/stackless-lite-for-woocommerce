/**
 * Stackless for WooCommerce: shared Stripe helpers for the classic checkout, the
 * Checkout block and express buttons. Plain ES2017, no build step.
 *
 * Exposes window.stacklessStripe.
 */
(function (window, document) {
	'use strict';

	var cfg = window.stacklessStripeConfig || {};
	var stripeInstance = null;

	function stripe() {
		if (!stripeInstance && window.Stripe && cfg.publishableKey) {
			stripeInstance = window.Stripe(cfg.publishableKey);
		}
		return stripeInstance;
	}

	/** Decimal places Stripe uses for the currency's integer amount. */
	function stripeDecimals(currency) {
		var code = String(currency || '').toUpperCase();
		if ((cfg.zeroDecimal || []).indexOf(code) !== -1) {
			return 0;
		}
		if ((cfg.threeDecimal || []).indexOf(code) !== -1) {
			return 3;
		}
		return 2;
	}

	/** Decimal major-unit amount to Stripe minor units (mirrors Amount::toMinor in PHP). */
	function toStripeAmount(decimal, currency) {
		var code = String(currency || '').toUpperCase();
		var value = Number(decimal) || 0;
		if ((cfg.wholeUnits || []).indexOf(code) !== -1) {
			return Math.round(value) * 100;
		}
		var places = stripeDecimals(code);
		var minor = Math.round(Number((value * Math.pow(10, places)).toFixed(4)));
		if (places === 3) {
			minor = Math.round(minor / 10) * 10;
		}
		return minor;
	}

	/** Store API integer (WooCommerce minor units) to Stripe minor units. */
	function fromWooMinor(value, minorUnit, currency) {
		var units = parseInt(minorUnit, 10) || 0;
		return toStripeAmount((parseInt(value, 10) || 0) / Math.pow(10, units), currency);
	}

	function parseColor(value) {
		var match = String(value || '').match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+))?/i);
		if (!match) {
			return null;
		}
		return { r: +match[1], g: +match[2], b: +match[3], a: match[4] === undefined ? 1 : +match[4] };
	}

	function luminance(color) {
		var channel = function (v) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		};
		return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b);
	}

	function toHex(color) {
		var hex = function (v) {
			return ('0' + Math.max(0, Math.min(255, v)).toString(16)).slice(-2);
		};
		return '#' + hex(color.r) + hex(color.g) + hex(color.b);
	}

	function backgroundOf(el) {
		var node = el;
		while (node && node.nodeType === 1) {
			var color = parseColor(window.getComputedStyle(node).backgroundColor);
			if (color && color.a > 0.5) {
				return color;
			}
			node = node.parentElement;
		}
		return parseColor(window.getComputedStyle(document.body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
	}

	/**
	 * Payment Element appearance. "site" reads the theme's computed colors and font so the
	 * form looks native, and switches to Stripe's night theme on dark backgrounds.
	 */
	function appearance(el) {
		var preset = cfg.appearance || 'site';
		if (preset !== 'site') {
			return { theme: preset };
		}
		var target = el || document.body;
		var style = window.getComputedStyle(target);
		var bg = backgroundOf(target);
		var text = parseColor(style.color);
		var dark = luminance(bg) < 0.35;
		var variables = {
			colorBackground: toHex(bg),
			fontFamily: style.fontFamily,
			borderRadius: '6px'
		};
		if (text) {
			variables.colorText = toHex(text);
		}
		var button = document.querySelector('#place_order, .wc-block-components-checkout-place-order-button, .single_add_to_cart_button, .checkout-button');
		if (button) {
			var primary = parseColor(window.getComputedStyle(button).backgroundColor);
			if (primary && primary.a > 0.5) {
				variables.colorPrimary = toHex(primary);
			}
		}
		return { theme: dark ? 'night' : 'stripe', variables: variables };
	}

	function endpoint(name) {
		return String(cfg.ajaxUrl || '').replace('%%endpoint%%', name);
	}

	/** Flattens nested objects to PHP-style keys: billing[address][city]=... */
	function toParams(data, prefix, params) {
		params = params || new URLSearchParams();
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			var name = prefix ? prefix + '[' + key + ']' : key;
			if (value === undefined || value === null) {
				return;
			}
			if (typeof value === 'object') {
				toParams(value, name, params);
			} else if (typeof value === 'boolean') {
				params.append(name, value ? '1' : '');
			} else {
				params.append(name, String(value));
			}
		});
		return params;
	}

	function post(name, data) {
		return window.fetch(endpoint(name), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: toParams(data).toString()
		}).then(function (response) {
			return response.json().catch(function () {
				return { result: 'failure', message: i18n('genericError') };
			});
		});
	}

	function i18n(key) {
		return (cfg.i18n && cfg.i18n[key]) || '';
	}

	/** Parses "#stackless-stripe-confirm/{flow}/{type}/{order_id}/{key}/{client_secret}". */
	function parseConfirmHash(hash) {
		var match = String(hash || '').match(/^#?stackless-stripe-confirm\/([^/]+)\/(pi|si)\/(\d+)\/([^/]+)\/(.+)$/);
		if (!match) {
			return null;
		}
		return {
			flow: decodeURIComponent(match[1]),
			type: match[2],
			orderId: match[3],
			orderKey: decodeURIComponent(match[4]),
			clientSecret: decodeURIComponent(match[5])
		};
	}

	function fromPaymentDetails(details) {
		if (!details || details.stackless_stripe_action !== 'confirm') {
			return null;
		}
		return {
			flow: details.stackless_stripe_flow,
			type: details.stackless_stripe_intent_type === 'si' ? 'si' : 'pi',
			orderId: details.stackless_stripe_order_id,
			orderKey: details.stackless_stripe_order_key,
			clientSecret: details.stackless_stripe_client_secret
		};
	}

	/**
	 * Runs Stripe's next action (3D Secure modal, wallet confirmation or a full-page redirect)
	 * and then asks the server to verify. Resolves to { redirect } or rejects with an Error.
	 */
	function confirmAndVerify(action) {
		var s = stripe();
		if (!s || !action || !action.clientSecret) {
			return Promise.reject(new Error(i18n('genericError')));
		}
		return s.handleNextAction({ clientSecret: action.clientSecret }).then(function () {
			// Whatever Stripe.js reports, the server re-reads the intent and decides.
			return post('stackless_stripe_gateway_verify', {
				flow: action.flow,
				type: action.type,
				order_id: action.orderId,
				order_key: action.orderKey
			});
		}).then(function (response) {
			if (response && response.result === 'success' && response.redirect) {
				return response;
			}
			throw new Error((response && response.message) || i18n('genericError'));
		});
	}

	function errorMessage(error) {
		if (!error) {
			return i18n('genericError');
		}
		if (typeof error === 'string') {
			return error;
		}
		return error.message || i18n('genericError');
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = String(text || '');
		return div.innerHTML;
	}

	window.stacklessStripe = {
		config: cfg,
		stripe: stripe,
		stripeDecimals: stripeDecimals,
		toStripeAmount: toStripeAmount,
		fromWooMinor: fromWooMinor,
		appearance: appearance,
		endpoint: endpoint,
		post: post,
		i18n: i18n,
		parseConfirmHash: parseConfirmHash,
		fromPaymentDetails: fromPaymentDetails,
		confirmAndVerify: confirmAndVerify,
		errorMessage: errorMessage,
		escapeHtml: escapeHtml
	};
})(window, document);
