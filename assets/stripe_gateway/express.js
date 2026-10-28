/**
 * Stackless for WooCommerce: Stripe Express Checkout Element (Apple Pay, Google Pay, Link).
 *
 * Used on classic product/cart/checkout pages (auto-mounted on .stackless-stripe-express) and by
 * the Cart/Checkout blocks (window.stacklessStripeExpress.mount). The wallet sheet's address and
 * shipping choices are synced into the WooCommerce cart through wc-ajax endpoints, and the
 * order is placed through WooCommerce's own checkout with a ConfirmationToken.
 */
(function (window, document) {
	'use strict';

	var S = window.stacklessStripe;
	if (!S) {
		return;
	}
	var cfg = S.config;

	function productForm(el) {
		var scope = el.closest('.product') || document;
		return scope.querySelector('form.cart') || document.querySelector('form.cart');
	}

	function productSelection(el, config) {
		var form = productForm(el);
		var selection = { product_id: config.productId, quantity: 1, variation_id: 0, attributes: {} };
		if (!form) {
			return selection;
		}
		var qty = form.querySelector('input[name="quantity"]');
		selection.quantity = Math.max(1, parseInt(qty && qty.value, 10) || 1);
		var variation = form.querySelector('input[name="variation_id"]');
		selection.variation_id = parseInt(variation && variation.value, 10) || 0;
		Array.prototype.forEach.call(form.querySelectorAll('[name^="attribute_"]'), function (field) {
			selection.attributes[field.name] = field.value;
		});
		return selection;
	}

	function selectionKey(selection) {
		return JSON.stringify([selection.product_id, selection.variation_id, selection.quantity, selection.attributes]);
	}

	/**
	 * @param {HTMLElement} el      Mount point.
	 * @param {Object}      config  { context, amount, currency, needsShipping, requiresSave, productId, productType }
	 * @param {Object}      hooks   Optional { onClick, onCancel, onError, onReady }
	 */
	function mount(el, config, hooks) {
		hooks = hooks || {};
		var stripe = S.stripe();
		if (!stripe || !el || el.getAttribute('data-stackless-mounted') === '1') {
			return null;
		}
		el.setAttribute('data-stackless-mounted', '1');

		var isProduct = config.context === 'product';
		var unitAmount = parseInt(config.amount, 10) || 0;
		var state = {
			amount: Math.max(unitAmount, 1),
			prepared: !isProduct,
			needsShipping: !!config.needsShipping,
			rates: [],
			lineItems: [],
			known: {}
		};
		var wrap = el.closest('.stackless-stripe-express-wrap');
		var errorBox = wrap ? wrap.querySelector('.stackless-stripe-express-errors') : null;

		function showError(message) {
			if (hooks.onError) {
				hooks.onError(message);
			} else if (errorBox) {
				errorBox.textContent = message || '';
			}
		}

		var elementsOptions = {
			mode: 'payment',
			amount: state.amount,
			currency: config.currency,
			appearance: S.appearance(el)
		};
		if (config.requiresSave) {
			elementsOptions.setupFutureUsage = 'off_session';
		}
		if (config.capture === 'manual') {
			elementsOptions.captureMethod = 'manual';
		}
		var elements = stripe.elements(elementsOptions);
		var buttonType = cfg.buttonType || 'buy';
		var element = elements.create('expressCheckout', {
			buttonType: { applePay: buttonType, googlePay: buttonType },
			buttonHeight: 44,
			paymentMethods: { applePay: 'auto', googlePay: 'auto', link: 'auto', amazonPay: 'never', paypal: 'never', klarna: 'never' },
			layout: { maxColumns: 3, maxRows: 1, overflow: 'auto' }
		});

		function setAmount(amount) {
			amount = parseInt(amount, 10) || 0;
			if (amount > 0 && amount !== state.amount) {
				state.amount = amount;
				elements.update({ amount: amount });
			}
		}

		function payload(extra) {
			var data = { nonce: cfg.expressNonce, context: config.context };
			if (isProduct) {
				var selection = productSelection(el, config);
				data.product_id = selection.product_id;
				data.quantity = selection.quantity;
				data.variation_id = selection.variation_id;
				data.attributes = selection.attributes;
				if (!state.prepared) {
					data.prepare = 1;
				}
			}
			Object.keys(extra || {}).forEach(function (key) {
				data[key] = extra[key];
			});
			return data;
		}

		function update(extra) {
			return S.post('stackless_stripe_gateway_express_update', payload(extra)).then(function (response) {
				if (!response || !response.success) {
					throw new Error((response && response.data && response.data.message) || S.i18n('genericError'));
				}
				state.prepared = true;
				var data = response.data;
				state.rates = data.shippingRates || [];
				state.lineItems = data.lineItems || [];
				state.needsShipping = !!data.needsShipping;
				if (isProduct) {
					state.known[selectionKey(productSelection(el, config))] = data.amount;
				}
				setAmount(data.amount);
				return data;
			});
		}

		function estimateProductAmount() {
			var selection = productSelection(el, config);
			var known = state.known[selectionKey(selection)];
			setAmount(known || unitAmount * selection.quantity);
		}

		element.on('ready', function (event) {
			var available = event && event.availablePaymentMethods;
			var any = !!available && Object.keys(available).some(function (key) {
				return available[key];
			});
			if (wrap) {
				wrap.hidden = !any;
			}
			if (hooks.onReady) {
				hooks.onReady(any);
			}
		});

		element.on('click', function (event) {
			showError('');
			if (isProduct) {
				var selection = productSelection(el, config);
				if (config.productType === 'variable' && !selection.variation_id) {
					showError(S.i18n('chooseOptions'));
					return;
				}
				estimateProductAmount();
			}
			if (hooks.onClick) {
				hooks.onClick();
			}
			var options = {
				emailRequired: true,
				phoneNumberRequired: !!cfg.requirePhone,
				billingAddressRequired: true,
				shippingAddressRequired: state.needsShipping,
				business: { name: cfg.storeName || '' }
			};
			if (state.needsShipping) {
				options.shippingRates = state.rates.length ? state.rates : [{ id: 'pending', displayName: S.i18n('shippingPending'), amount: 0 }];
				if (cfg.allowedCountries && cfg.allowedCountries.length) {
					options.allowedShippingCountries = cfg.allowedCountries;
				}
			}
			if (state.lineItems.length && !isProduct) {
				options.lineItems = state.lineItems;
			}
			event.resolve(options);
		});

		element.on('shippingaddresschange', function (event) {
			update({ address: event.address || {} }).then(function (data) {
				if (!data.shippingRates || !data.shippingRates.length) {
					event.reject();
					return;
				}
				var result = { shippingRates: data.shippingRates };
				if (data.lineItems && data.lineItems.length) {
					result.lineItems = data.lineItems;
				}
				event.resolve(result);
			}).catch(function () {
				event.reject();
			});
		});

		element.on('shippingratechange', function (event) {
			update({ rate: event.shippingRate && event.shippingRate.id }).then(function (data) {
				var result = {};
				if (data.lineItems && data.lineItems.length) {
					result.lineItems = data.lineItems;
				}
				event.resolve(result);
			}).catch(function () {
				event.reject();
			});
		});

		element.on('cancel', function () {
			if (isProduct && state.prepared) {
				state.prepared = false;
				S.post('stackless_stripe_gateway_express_cancel', { nonce: cfg.expressNonce });
			}
			if (hooks.onCancel) {
				hooks.onCancel();
			}
		});

		element.on('confirm', function (event) {
			var sheetAmount = state.amount;
			var fail = function (error) {
				event.paymentFailed({ reason: 'fail' });
				showError(S.errorMessage(error));
			};

			elements.submit().then(function (result) {
				if (result && result.error) {
					throw result.error;
				}
				// Final server total: the charged amount must equal what the wallet showed.
				return update({});
			}).then(function (data) {
				if (data.amount !== sheetAmount) {
					throw new Error(S.i18n('totalChanged'));
				}
				var params = { payment_method_data: { billing_details: event.billingDetails || {} } };
				if (event.shippingAddress && event.shippingAddress.address) {
					params.shipping = { name: event.shippingAddress.name || '', address: event.shippingAddress.address };
				}
				return stripe.createConfirmationToken({ elements: elements, params: params });
			}).then(function (result) {
				if (result.error) {
					throw result.error;
				}
				var billing = event.billingDetails || {};
				var shipping = event.shippingAddress || {};
				return S.post('stackless_stripe_gateway_express_checkout', payload({
					confirmation_token: result.confirmationToken.id,
					billing: { name: billing.name || '', email: billing.email || '', phone: billing.phone || '', address: billing.address || {} },
					shipping: { name: shipping.name || '', address: shipping.address || {} },
					rate: event.shippingRate ? event.shippingRate.id : ''
				}));
			}).then(function (response) {
				if (!response || response.result !== 'success' || !response.redirect) {
					var div = document.createElement('div');
					div.innerHTML = (response && (response.messages || response.message)) || '';
					throw new Error((div.textContent || '').trim() || S.i18n('genericError'));
				}
				var action = S.parseConfirmHash(response.redirect.indexOf('#') !== -1 ? response.redirect.slice(response.redirect.indexOf('#')) : '');
				if (!action) {
					window.location.href = response.redirect;
					return null;
				}
				return S.confirmAndVerify(action).then(function (verified) {
					window.location.href = verified.redirect;
				});
			}).catch(fail);
		});

		element.mount(el);

		var form = isProduct ? productForm(el) : null;
		if (form && window.jQuery) {
			window.jQuery(form).on('found_variation', function (e, variation) {
				if (variation && variation.display_price !== undefined) {
					unitAmount = S.toStripeAmount(variation.display_price, config.currency);
					estimateProductAmount();
				}
			}).on('change input', 'input[name="quantity"]', estimateProductAmount);
		} else if (form) {
			form.addEventListener('change', estimateProductAmount);
		}

		return {
			element: element,
			elements: elements,
			setAmount: setAmount,
			refresh: function () {
				return update({}).catch(function () {});
			},
			destroy: function () {
				element.destroy();
				el.removeAttribute('data-stackless-mounted');
			}
		};
	}

	function mountAll() {
		Array.prototype.forEach.call(document.querySelectorAll('.stackless-stripe-express[data-config]'), function (el) {
			if (el.getAttribute('data-stackless-mounted') === '1') {
				return;
			}
			var config;
			try {
				config = JSON.parse(el.getAttribute('data-config'));
			} catch (e) {
				return;
			}
			var handle = mount(el, config);
			if (handle && config.context === 'checkout' && window.jQuery) {
				window.jQuery(document.body).on('updated_checkout', handle.refresh);
			}
		});
	}

	window.stacklessStripeExpress = { mount: mount, mountAll: mountAll };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mountAll);
	} else {
		mountAll();
	}
	if (window.jQuery) {
		// The classic cart replaces its totals (and our buttons) after every update.
		window.jQuery(document.body).on('updated_cart_totals updated_wc_div', mountAll);
	}
})(window, document);
