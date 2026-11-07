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
		if (opts.components) {
			extra.components = opts.components;
		}
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

	/**
	 * Load the SDK once per URL (and script attributes), each under its own namespace so
	 * currencies, intents and components can differ. opts.attributes adds data-* attributes,
	 * such as Fastlane's data-sdk-client-token.
	 */
	function load(data, opts) {
		var url = sdkUrl(data, opts);
		var attributes = opts.attributes || {};
		var cacheKey = url + '|' + JSON.stringify(attributes);
		if (cache[cacheKey]) {
			return cache[cacheKey];
		}
		var namespace = 'stacklessPayPalSdk_' + hash(cacheKey);
		cache[cacheKey] = new Promise(function (resolve, reject) {
			if (window[namespace]) {
				resolve(window[namespace]);
				return;
			}
			var script = document.createElement('script');
			script.src = url;
			script.async = true;
			script.setAttribute('data-namespace', namespace);
			Object.keys(attributes).forEach(function (name) {
				script.setAttribute(name, String(attributes[name]));
			});
			script.onload = function () {
				if (window[namespace]) {
					resolve(window[namespace]);
				} else {
					delete cache[cacheKey];
					reject(new Error('PayPal SDK did not initialise'));
				}
			};
			script.onerror = function () {
				delete cache[cacheKey];
				reject(new Error('PayPal SDK failed to load'));
			};
			document.head.appendChild(script);
		});
		return cache[cacheKey];
	}

	/** Load a third-party script (Apple Pay JS, Google Pay JS) once. */
	var external = {};
	function loadScript(src) {
		if (external[src]) {
			return external[src];
		}
		external[src] = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = src;
			script.async = true;
			script.onload = resolve;
			script.onerror = function () {
				delete external[src];
				reject(new Error('Script failed to load'));
			};
			document.head.appendChild(script);
		});
		return external[src];
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

	/**
	 * Advanced Card Fields. Resolves to a controller { submit(billingAddress) → Promise<orderID> },
	 * or null when PayPal says the store or buyer is not eligible (the fields stay hidden).
	 *
	 * opts: currency, intent, vault, context, orderId, orderKey, el (container), onError(message)
	 */
	function cardFields(data, opts) {
		if (!data.cardFields || !opts.el) {
			return Promise.resolve(null);
		}
		return load(data, { currency: opts.currency, intent: opts.vault ? 'capture' : opts.intent, mode: 'order' }).then(function (paypal) {
			if (!paypal.CardFields) {
				return null;
			}
			var pending = null;
			var common = { context: opts.context || 'checkout', order_id: opts.orderId || '', order_key: opts.orderKey || '', funding: 'card' };
			var config = {
				createOrder: function () {
					return post(data, data.ajax.createOrder, common).then(function (result) {
						return result.id;
					}).catch(function (error) {
						if (pending) {
							pending.reject(error);
							pending = null;
						}
						throw error;
					});
				},
				onApprove: function (approved) {
					if (pending) {
						pending.resolve(approved && approved.orderID ? approved.orderID : '');
						pending = null;
					}
				},
				onError: function (error) {
					if (pending) {
						pending.reject(error || new Error(data.i18n.cardDeclined));
						pending = null;
					}
				}
			};
			if (data.cardStyle && typeof data.cardStyle === 'object' && !Array.isArray(data.cardStyle)) {
				config.style = data.cardStyle;
			}
			var fields = paypal.CardFields(config);
			if (!fields.isEligible || !fields.isEligible()) {
				return null;
			}
			opts.el.innerHTML = '';
			opts.el.classList.add('stackless-paypal__card-fields--grid');
			var parts = [['NameField', data.i18n.cardName], ['NumberField', data.i18n.cardNumber], ['ExpiryField', data.i18n.cardExpiry], ['CVVField', data.i18n.cardCvv]];
			parts.forEach(function (part) {
				var slot = document.createElement('div');
				slot.className = 'stackless-paypal__card-field stackless-paypal__card-field--' + part[0].replace('Field', '').toLowerCase();
				opts.el.appendChild(slot);
				fields[part[0]]({ placeholder: part[1] }).render(slot);
			});
			return {
				submit: function (billingAddress) {
					return new Promise(function (resolve, reject) {
						pending = { resolve: resolve, reject: reject };
						var args = billingAddress ? { billingAddress: billingAddress } : undefined;
						fields.submit(args).catch(function (error) {
							if (pending) {
								pending.reject(error || new Error(data.i18n.cardIncomplete));
								pending = null;
							}
						});
					}).then(function (orderID) {
						if (!orderID) {
							throw new Error(data.i18n.cardVerify);
						}
						return orderID;
					});
				}
			};
		});
	}

	/**
	 * Fastlane for guests. Resolves to a controller { lookup(email), token() → Promise<string> },
	 * or null when Fastlane is off or fails to load (the card fields take over).
	 */
	function fastlane(data, opts) {
		if (!data.fastlane || !opts.el) {
			return Promise.resolve(null);
		}
		return post(data, data.ajax.fastlaneToken, {}).then(function (result) {
			var components = String((data.sdkParams && data.sdkParams.components) || 'buttons') + ',fastlane';
			return load(data, { currency: opts.currency, intent: 'capture', mode: 'order', components: components, attributes: { 'data-sdk-client-token': result.token } });
		}).then(function (paypal) {
			if (!paypal.Fastlane) {
				return null;
			}
			return paypal.Fastlane({}).then(function (instance) {
				var Component = instance.FastlanePaymentComponent || instance.FastlaneCardComponent;
				if (!Component) {
					return null;
				}
				return Promise.resolve(Component({})).then(function (component) {
					opts.el.innerHTML = '';
					opts.el.classList.remove('stackless-paypal__card-fields--grid');
					component.render(opts.el);
					var lastEmail = '';
					return {
						lookup: function (email) {
							email = String(email || '').trim();
							if (!email || email === lastEmail || !instance.identity) {
								return Promise.resolve(false);
							}
							lastEmail = email;
							return instance.identity.lookupCustomerByEmail(email).then(function (found) {
								if (!found || !found.customerContextId) {
									return false;
								}
								return instance.identity.triggerAuthenticationFlow(found.customerContextId).then(function (auth) {
									return !!(auth && auth.authenticationState === 'succeeded');
								});
							}).catch(function () {
								return false;
							});
						},
						token: function (billingAddress) {
							return Promise.resolve(component.getPaymentToken(billingAddress ? { billingAddress: billingAddress } : undefined)).then(function (token) {
								var id = token && (token.id || token.paymentTokenId);
								if (!id) {
									throw new Error(data.i18n.cardIncomplete);
								}
								return id;
							});
						}
					};
				});
			});
		}).catch(function () {
			return null;
		});
	}

	/**
	 * Apple Pay and Google Pay buttons through PayPal (one-time payments only).
	 * opts: currency, amount (number), context, orderId, orderKey, el, onApproved(orderId, funding), onError(message)
	 */
	function wallets(data, opts) {
		if ((!data.applePay && !data.googlePay) || !opts.el || opts.vault) {
			return Promise.resolve(0);
		}
		opts.el.innerHTML = '';
		return load(data, { currency: opts.currency, intent: opts.intent, mode: 'order' }).then(function (paypal) {
			var tasks = [];
			if (data.googlePay && paypal.Googlepay) {
				tasks.push(googlePay(data, paypal, opts).catch(function () {
					return false;
				}));
			}
			if (data.applePay && paypal.Applepay) {
				tasks.push(applePay(data, paypal, opts).catch(function () {
					return false;
				}));
			}
			return Promise.all(tasks).then(function (shown) {
				return shown.filter(Boolean).length;
			});
		});
	}

	function walletOrder(data, opts, funding) {
		return post(data, data.ajax.createOrder, { context: opts.context || 'checkout', order_id: opts.orderId || '', order_key: opts.orderKey || '', funding: funding }).then(function (result) {
			return result.id;
		});
	}

	function amountString(opts) {
		var decimals = ['HUF', 'JPY', 'TWD'].indexOf(String(opts.currency).toUpperCase()) === -1 ? 2 : 0;
		return Number(opts.amount || 0).toFixed(decimals);
	}

	function googlePay(data, paypal, opts) {
		return loadScript('https://pay.google.com/gp/p/js/pay.js').then(function () {
			var googlepay = paypal.Googlepay();
			return googlepay.config().then(function (config) {
				var client = new window.google.payments.api.PaymentsClient({ environment: data.sandbox ? 'TEST' : 'PRODUCTION' });
				var base = { apiVersion: 2, apiVersionMinor: 0, allowedPaymentMethods: config.allowedPaymentMethods };
				return client.isReadyToPay(base).then(function (ready) {
					if (!ready || !ready.result) {
						return false;
					}
					var button = client.createButton({
						buttonSizeMode: 'fill',
						allowedPaymentMethods: config.allowedPaymentMethods,
						onClick: function () {
							var request = Object.assign({}, base, {
								merchantInfo: config.merchantInfo,
								transactionInfo: {
									currencyCode: String(opts.currency).toUpperCase(),
									countryCode: data.storeCountry || 'US',
									totalPriceStatus: 'FINAL',
									totalPrice: amountString(opts)
								}
							});
							client.loadPaymentData(request).then(function (paymentData) {
								return walletOrder(data, opts, 'googlepay').then(function (orderId) {
									return googlepay.confirmOrder({ orderId: orderId, paymentMethodData: paymentData.paymentMethodData }).then(function (confirmed) {
										if (confirmed && confirmed.status === 'PAYER_ACTION_REQUIRED') {
											return googlepay.initiatePayerAction({ orderId: orderId }).then(function () {
												return orderId;
											});
										}
										return orderId;
									});
								});
							}).then(function (orderId) {
								opts.onApproved(orderId, 'googlepay');
							}).catch(function (error) {
								if (error && error.statusCode === 'CANCELED') {
									return;
								}
								opts.onError(data.i18n.walletError);
							});
						}
					});
					var slot = document.createElement('div');
					slot.className = 'stackless-paypal__wallet stackless-paypal__wallet--googlepay';
					slot.appendChild(button);
					opts.el.appendChild(slot);
					return true;
				});
			});
		});
	}

	function applePay(data, paypal, opts) {
		return loadScript('https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js').then(function () {
			if (!window.ApplePaySession || !window.ApplePaySession.canMakePayments || !window.ApplePaySession.canMakePayments()) {
				return false;
			}
			var applepay = paypal.Applepay();
			return applepay.config().then(function (config) {
				if (!config || !config.isEligible) {
					return false;
				}
				var button = document.createElement('apple-pay-button');
				button.setAttribute('buttonstyle', 'black');
				button.setAttribute('type', 'buy');
				button.setAttribute('locale', (document.documentElement.lang || 'en-US'));
				button.addEventListener('click', function () {
					var session = new window.ApplePaySession(4, {
						countryCode: config.countryCode,
						currencyCode: String(opts.currency).toUpperCase(),
						merchantCapabilities: config.merchantCapabilities,
						supportedNetworks: config.supportedNetworks,
						requiredBillingContactFields: ['postalAddress'],
						total: { label: data.storeName || 'Total', type: 'final', amount: amountString(opts) }
					});
					session.onvalidatemerchant = function (event) {
						applepay.validateMerchant({ validationUrl: event.validationURL, displayName: data.storeName || '' }).then(function (result) {
							session.completeMerchantValidation(result.merchantSession);
						}).catch(function () {
							session.abort();
							opts.onError(data.i18n.walletError);
						});
					};
					session.onpaymentauthorized = function (event) {
						walletOrder(data, opts, 'applepay').then(function (orderId) {
							return applepay.confirmOrder({ orderId: orderId, token: event.payment.token, billingContact: event.payment.billingContact }).then(function () {
								return orderId;
							});
						}).then(function (orderId) {
							session.completePayment(window.ApplePaySession.STATUS_SUCCESS);
							opts.onApproved(orderId, 'applepay');
						}).catch(function () {
							session.completePayment(window.ApplePaySession.STATUS_FAILURE);
							opts.onError(data.i18n.walletError);
						});
					};
					session.begin();
				});
				var slot = document.createElement('div');
				slot.className = 'stackless-paypal__wallet stackless-paypal__wallet--applepay';
				slot.appendChild(button);
				opts.el.appendChild(slot);
				return true;
			});
		});
	}

	window.stacklessPayPal = { load: load, post: post, render: render, sdkUrl: sdkUrl, cardFields: cardFields, fastlane: fastlane, wallets: wallets };
})(window, document);
