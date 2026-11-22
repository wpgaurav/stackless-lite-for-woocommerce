/**
 * Stackless: Braintree Hosted Fields and 3-D Secure, shared by every checkout. Loads the pinned
 * SDK files with their integrity hashes, mounts the fields, and turns a card into a one-time
 * nonce verified for the amount being paid. The server decides whether that verification holds.
 */
(function () {
	'use strict';

	var loading = {};

	function loadScript(script, version) {
		var current = window.braintree && window.braintree[script.name];
		if (current && current.VERSION === version) {
			return Promise.resolve();
		}
		if (!loading[script.src]) {
			loading[script.src] = new Promise(function (resolve, reject) {
				var tag = document.createElement('script');
				tag.src = script.src;
				tag.integrity = script.integrity;
				tag.crossOrigin = 'anonymous';
				tag.async = true;
				tag.onload = function () {
					resolve();
				};
				tag.onerror = function () {
					delete loading[script.src];
					tag.remove();
					reject(new Error('Braintree SDK failed to load'));
				};
				document.head.appendChild(tag);
			});
		}
		return loading[script.src];
	}

	function loadSdk(config) {
		var scripts = config.scripts || [];
		// The client has to be there before the components that use it.
		return loadScript(scripts[0], config.version).then(function () {
			return Promise.all(scripts.slice(1).map(function (script) {
				return loadScript(script, config.version);
			}));
		}).then(function () {
			return window.braintree;
		});
	}

	function shown(message) {
		var error = new Error(message);
		error.forShopper = true;
		return error;
	}

	function request(config, extra) {
		var body = new URLSearchParams();
		var values = { security: config.security, flow: config.flow, order: config.order || '', key: config.orderKey || '' };
		if (config.action) {
			values.action = config.action;
		}
		Object.keys(extra || {}).forEach(function (name) {
			values[name] = extra[name];
		});
		Object.keys(values).forEach(function (name) {
			if (values[name] !== undefined && values[name] !== null) {
				body.append(name, String(values[name]));
			}
		});
		return fetch(config.endpoint, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
			return response.json();
		}).then(function (json) {
			if (json && json.success && json.data) {
				return json.data;
			}
			throw shown(json && json.data && json.data.message ? json.data.message : config.i18n.failed);
		});
	}

	/** 3-D Secure rejects non-ASCII names and addresses, so those are left out. */
	function ascii(value, max) {
		var text = String(value || '').trim();
		return text && text.length <= max && /^[\x20-\x7E]+$/.test(text) ? text : '';
	}

	function verificationAddress(billing) {
		var address = {
			givenName: ascii(billing.first, 50),
			surname: ascii(billing.last, 50),
			streetAddress: ascii(billing.line1, 50),
			extendedAddress: ascii(billing.line2, 50),
			locality: ascii(billing.city, 50),
			postalCode: ascii(billing.postcode, 10),
			countryCodeAlpha2: /^[A-Za-z]{2}$/.test(billing.country || '') ? String(billing.country).toUpperCase() : '',
			region: /^[A-Za-z0-9]{1,3}$/.test(billing.region || '') ? String(billing.region) : ''
		};
		Object.keys(address).forEach(function (key) {
			if (!address[key]) {
				delete address[key];
			}
		});
		return address;
	}

	function hostedOptions(client, root) {
		var fields = {};
		var map = { number: 'number', expiration: 'expirationDate', cvv: 'cvv', postal: 'postalCode' };
		Object.keys(map).forEach(function (name) {
			var container = root.querySelector('[data-stackless-braintree="' + name + '"]');
			if (container) {
				fields[map[name]] = { container: container };
				if (name === 'expiration') {
					fields[map[name]].placeholder = 'MM/YY';
				}
			}
		});
		return { client: client, styles: { input: { 'font-size': '16px' } }, fields: fields };
	}

	/**
	 * @param {Object} config What the server printed for this form.
	 * @param {Element|null} root Holds the field containers; null for a saved card.
	 * @param {Object} [options] values(): extra values for the server.
	 */
	function create(config, root, options) {
		var destroyed = false;
		var parts = {};
		var mounted = '';
		var settings = options || {};

		function extras(values) {
			var out = settings.values ? settings.values() : {};
			Object.keys(values || {}).forEach(function (name) {
				out[name] = values[name];
			});
			return out;
		}

		function release() {
			['fields', 'secure', 'collector'].forEach(function (name) {
				var part = parts[name];
				delete parts[name];
				if (part && part.teardown) {
					Promise.resolve(part.teardown()).catch(function () { /* already gone */ });
				}
			});
		}

		var ready = request(config, extras()).then(function (session) {
			mounted = session.currency;
			return loadSdk(config).then(function (braintree) {
				return braintree.client.create({ authorization: session.token }).then(function (client) {
					if (destroyed) {
						return;
					}
					var work = [];
					if (root) {
						work.push(braintree.hostedFields.create(hostedOptions(client, root)).then(function (instance) {
							parts.fields = instance;
						}));
					}
					if (config.secure) {
						work.push(braintree.threeDSecure.create({ client: client, version: 2 }).then(function (instance) {
							parts.secure = instance;
						}));
					}
					if (config.deviceData) {
						work.push(braintree.dataCollector.create({ client: client }).then(function (instance) {
							parts.collector = instance;
						}).catch(function () { /* fraud data is extra: the server still charges */ }));
					}
					return Promise.all(work);
				});
			});
		}).then(function () {
			if (destroyed) {
				release();
			}
		});
		// Callers report a failed start; this keeps it from also being an unhandled rejection.
		ready.catch(function () { /* handled by the caller */ });

		/**
		 * @param {Object} payment saved (token id), amount (when the checkout knows it), save, billing.
		 * @return {Promise<{nonce:string, device:string}>}
		 */
		function pay(payment) {
			var billing = payment.billing || config.billing || {};
			var session;
			var amount;
			var nonce = '';
			return ready.catch(function (error) {
				throw error && error.forShopper ? error : shown(config.i18n.failed);
			}).then(function () {
				return request(config, extras());
			}).then(function (fresh) {
				session = fresh;
				if (session.currency !== mounted) {
					throw shown(config.i18n.changed);
				}
				amount = payment.amount !== undefined && payment.amount !== null ? String(payment.amount) : session.amount;
				if (payment.saved) {
					if (!config.secure || Number(amount) <= 0) {
						return null;
					}
					return request(config, extras({ saved: payment.saved }));
				}
				if (!parts.fields) {
					throw shown(config.i18n.failed);
				}
				return parts.fields.tokenize().then(function (payload) {
					return { nonce: payload.nonce, bin: payload.details && payload.details.bin };
				}, function () {
					throw shown(config.i18n.invalid);
				});
			}).then(function (source) {
				if (!source) {
					return;
				}
				nonce = source.nonce;
				if (!config.secure) {
					return;
				}
				if (!parts.secure) {
					throw shown(config.i18n.failed);
				}
				var details = {
					amount: String(amount),
					nonce: source.nonce,
					bin: source.bin,
					collectDeviceData: true,
					billingAddress: verificationAddress(billing),
					onLookupComplete: function (data, next) {
						next();
					}
				};
				if (session.requiresSave || payment.save) {
					// Strong authentication now is what allows later charges without the customer.
					details.challengeRequested = true;
				}
				if (ascii(billing.email, 255)) {
					details.email = ascii(billing.email, 255);
				}
				return parts.secure.verifyCard(details).then(function (verified) {
					if (!verified || !verified.nonce) {
						throw shown(config.i18n.verify);
					}
					nonce = verified.nonce;
				}, function () {
					throw shown(config.i18n.verify);
				});
			}).then(function () {
				if (!parts.collector || !parts.collector.getDeviceData) {
					return '';
				}
				return parts.collector.getDeviceData().catch(function () {
					return '';
				});
			}).then(function (device) {
				return { nonce: nonce, device: device || '' };
			});
		}

		return {
			ready: ready,
			pay: pay,
			destroy: function () {
				destroyed = true;
				release();
			}
		};
	}

	function message(error, config) {
		return error && error.forShopper && error.message ? error.message : config.i18n.failed;
	}

	window.StacklessBraintree = { create: create, message: message };
})();
