/**
 * Stackless for WooCommerce: Square Web Payments SDK helpers shared by the classic and block
 * checkouts. Loads square.js once, keeps one payments object per application and location,
 * and turns the card form or a wallet into a one-time token.
 */
(function () {
	'use strict';

	var sdkPromise = null;
	var paymentsCache = {};

	function loadSdk(url) {
		if (window.Square && window.Square.payments) {
			return Promise.resolve(window.Square);
		}
		if (!sdkPromise) {
			sdkPromise = new Promise(function (resolve, reject) {
				var script = document.createElement('script');
				script.src = url;
				script.async = true;
				script.onload = function () {
					if (window.Square && window.Square.payments) {
						resolve(window.Square);
					} else {
						reject(new Error('Square SDK unavailable'));
					}
				};
				script.onerror = function () {
					sdkPromise = null;
					reject(new Error('Square SDK failed to load'));
				};
				document.head.appendChild(script);
			});
		}
		return sdkPromise;
	}

	function payments(config) {
		return loadSdk(config.sdk).then(function (Square) {
			var key = config.appId + '|' + config.locationId;
			if (!paymentsCache[key]) {
				paymentsCache[key] = Square.payments(config.appId, config.locationId);
			}
			return paymentsCache[key];
		});
	}

	/** CHARGE, CHARGE_AND_STORE or STORE for this payment. */
	function intentFor(config, amount, save) {
		if (config.flow === 'add') {
			return 'STORE';
		}
		if (Number(amount) <= 0) {
			return save ? 'STORE' : 'CHARGE';
		}
		return save ? 'CHARGE_AND_STORE' : 'CHARGE';
	}

	/** verificationDetails for card.tokenize(): Square runs 3-D Secure / SCA from these. */
	function details(config, amount, intent, billing) {
		var result = {
			currencyCode: config.currency,
			intent: intent,
			billingContact: clean(billing || config.billing || {}),
			customerInitiated: true,
			sellerKeyedIn: false
		};
		if (intent !== 'STORE') {
			result.amount = String(amount);
		}
		return result;
	}

	function clean(contact) {
		var out = {};
		Object.keys(contact).forEach(function (key) {
			var value = contact[key];
			if (Array.isArray(value) ? value.length : (value !== undefined && value !== null && value !== '')) {
				out[key] = value;
			}
		});
		return out;
	}

	function tokenize(source, verification) {
		var call = verification ? source.tokenize(verification) : source.tokenize();
		return call.then(function (result) {
			if (result && result.status === 'OK' && result.token) {
				return result.token;
			}
			var message = result && result.errors && result.errors[0] && result.errors[0].message ? result.errors[0].message : '';
			throw new Error(message);
		});
	}

	function paymentRequest(paymentsObject, config, amount) {
		return paymentsObject.paymentRequest({
			countryCode: config.country || 'US',
			currencyCode: config.currency,
			total: { amount: String(amount), label: config.storeName || config.i18n.total }
		});
	}

	window.StacklessSquare = {
		loadSdk: loadSdk,
		payments: payments,
		intentFor: intentFor,
		details: details,
		tokenize: tokenize,
		paymentRequest: paymentRequest
	};
})();
