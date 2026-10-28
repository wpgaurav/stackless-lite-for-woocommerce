/**
 * Stackless for WooCommerce: Stripe for the Cart and Checkout blocks.
 *
 * Registers the Payment Element as a block payment method (saved tokens and the "save"
 * checkbox come from WooCommerce Blocks) and the Express Checkout Element as an express
 * payment method. No JSX / build step: wp.element.createElement only.
 */
(function (window) {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	var S = window.stacklessStripe;
	if (!S || !wc.wcBlocksRegistry || !wc.wcSettings || !wp.element) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities ? wp.htmlEntities.decodeEntities : function (v) { return v; };
	var data = wc.wcSettings.getSetting('stripe_gateway_data', {});
	var name = S.config.gatewayId || 'stripe_gateway';
	var title = decode(data.title || 'Stripe');

	function stripeAmount(billing) {
		if (!billing || !billing.cartTotal || !billing.currency) {
			return 0;
		}
		return S.fromWooMinor(billing.cartTotal.value, billing.currency.minorUnit, billing.currency.code);
	}

	function currencyOf(billing) {
		return billing && billing.currency && billing.currency.code ? billing.currency.code.toLowerCase() : 'usd';
	}

	function billingDetails(address) {
		address = address || {};
		var details = {
			name: [address.first_name, address.last_name].filter(Boolean).join(' ') || undefined,
			email: address.email || undefined,
			phone: address.phone || undefined,
			address: {
				line1: address.address_1 || undefined,
				line2: address.address_2 || undefined,
				city: address.city || undefined,
				state: address.state || undefined,
				postal_code: address.postcode || undefined,
				country: address.country || undefined
			}
		};
		return JSON.parse(JSON.stringify(details));
	}

	function subscribe(eventRegistration, names, callback) {
		for (var i = 0; i < names.length; i++) {
			if (typeof eventRegistration[names[i]] === 'function') {
				return eventRegistration[names[i]](callback);
			}
		}
		return function () {};
	}

	/** Handles 3D Secure / redirects after the order is placed (new or saved method). */
	function useConfirmation(props) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		useEffect(function () {
			return subscribe(eventRegistration, ['onCheckoutSuccess', 'onCheckoutAfterProcessingWithSuccess'], function (result) {
				var details = result && result.processingResponse ? result.processingResponse.paymentDetails : null;
				var action = S.fromPaymentDetails(details);
				if (!action) {
					return true;
				}
				return S.confirmAndVerify(action).then(function (response) {
					return { type: emitResponse.responseTypes.SUCCESS, redirectUrl: response.redirect };
				}).catch(function (error) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: S.errorMessage(error),
						messageContext: emitResponse.noticeContexts.PAYMENTS
					};
				});
			});
		}, [eventRegistration, emitResponse]);
	}

	function Content(props) {
		var ref = useRef(null);
		var stripeState = useRef({ elements: null, element: null, signature: '' });
		var errorState = useState('');
		var amount = stripeAmount(props.billing);
		var currency = currencyOf(props.billing);
		var mode = amount > 0 ? 'payment' : 'setup';
		var save = !!data.requiresSave || !!props.shouldSavePayment || mode === 'setup';
		var billingAddress = props.billing ? props.billing.billingAddress : {};

		useConfirmation(props);

		useEffect(function () {
			var stripe = S.stripe();
			if (!stripe || !ref.current) {
				return undefined;
			}
			var signature = mode + '|' + currency;
			var current = stripeState.current;
			if (current.signature !== signature) {
				if (current.element) {
					current.element.destroy();
				}
				var options = { mode: mode, currency: currency, appearance: S.appearance(ref.current) };
				if (mode === 'payment') {
					options.amount = amount;
					options.captureMethod = data.captureMethod === 'manual' ? 'manual' : 'automatic';
				}
				if (save && mode === 'payment') {
					options.setupFutureUsage = 'off_session';
				}
				try {
					current.elements = stripe.elements(options);
					current.element = current.elements.create('payment', { layout: { type: 'tabs', defaultCollapsed: false } });
					current.element.on('change', function () {
						errorState[1]('');
					});
					current.element.mount(ref.current);
					current.signature = signature;
				} catch (e) {
					errorState[1](S.errorMessage(e));
				}
			}
			return undefined;
		}, [mode, currency]);

		useEffect(function () {
			return function () {
				if (stripeState.current.element) {
					stripeState.current.element.destroy();
					stripeState.current = { elements: null, element: null, signature: '' };
				}
			};
		}, []);

		useEffect(function () {
			var current = stripeState.current;
			if (current.elements && mode === 'payment' && amount > 0) {
				current.elements.update({ amount: amount });
			}
		}, [amount]);

		useEffect(function () {
			var current = stripeState.current;
			if (current.elements && mode === 'payment') {
				try {
					current.elements.update({ setupFutureUsage: save ? 'off_session' : null });
				} catch (e) {
					// Older Stripe.js builds reject null; the server still decides whether to save.
				}
			}
		}, [save]);

		useEffect(function () {
			var emitResponse = props.emitResponse;
			return subscribe(props.eventRegistration, ['onPaymentSetup', 'onPaymentProcessing'], function () {
				var current = stripeState.current;
				if (!current.elements) {
					return { type: emitResponse.responseTypes.ERROR, message: S.i18n('enterDetails'), messageContext: emitResponse.noticeContexts.PAYMENTS };
				}
				return current.elements.submit().then(function (result) {
					if (result && result.error) {
						throw result.error;
					}
					return S.stripe().createConfirmationToken({
						elements: current.elements,
						params: { payment_method_data: { billing_details: billingDetails(billingAddress) } }
					});
				}).then(function (result) {
					if (result.error) {
						throw result.error;
					}
					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: { paymentMethodData: { stackless_stripe_confirmation_token: result.confirmationToken.id } }
					};
				}).catch(function (error) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: S.errorMessage(error),
						messageContext: emitResponse.noticeContexts.PAYMENTS
					};
				});
			});
		}, [props.eventRegistration, props.emitResponse, billingAddress]);

		var children = [];
		if (data.description) {
			children.push(el('p', { key: 'd', className: 'stackless-stripe-description' }, decode(data.description)));
		}
		if (data.testMode) {
			children.push(el('p', { key: 't', className: 'stackless-stripe-test-notice' }, S.i18n('testCard')));
		}
		children.push(el('div', { key: 'pe', ref: ref, className: 'stackless-stripe-payment-element' }));
		if (errorState[0]) {
			children.push(el('div', { key: 'e', className: 'stackless-stripe-errors', role: 'alert' }, errorState[0]));
		}
		if (data.requiresSave) {
			children.push(el('p', { key: 's', className: 'stackless-stripe-save-notice' }, S.i18n('savedForRenewals')));
		}
		return el('div', { className: 'stackless-stripe-fields stackless-stripe-fields--block' }, children);
	}

	function SavedToken(props) {
		useConfirmation(props);
		return null;
	}

	function Edit() {
		return el('div', { className: 'stackless-stripe-fields stackless-stripe-fields--editor' }, el('p', null, decode(data.description || title)));
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	function canMakePayment(args) {
		var totals = (args && args.cartTotals) || {};
		var currency = String(totals.currency_code || '').toUpperCase();
		if (currency && (data.supportedCurrencies || []).indexOf(currency) === -1) {
			return false;
		}
		var amount = S.fromWooMinor(totals.total_price || 0, totals.currency_minor_unit || 0, currency);
		if (amount <= 0) {
			return true;
		}
		var minimum = (data.minimums || {})[currency];
		return !minimum || amount >= S.toStripeAmount(minimum, currency);
	}

	wc.wcBlocksRegistry.registerPaymentMethod({
		name: name,
		label: el(Label, null),
		ariaLabel: title,
		content: el(Content, null),
		edit: el(Edit, null),
		savedTokenComponent: el(SavedToken, null),
		canMakePayment: canMakePayment,
		paymentMethodId: name,
		supports: {
			features: data.supports || ['products'],
			showSavedCards: !!data.showSavedCards,
			showSaveOption: !!data.showSaveOption
		}
	});

	/* -------------------------------------------------------------------
	 * Express Checkout Element
	 * ----------------------------------------------------------------- */

	function ExpressContent(props) {
		var ref = useRef(null);
		var handle = useRef(null);
		var amount = stripeAmount(props.billing);

		useEffect(function () {
			if (!ref.current || !window.stacklessStripeExpress) {
				return undefined;
			}
			handle.current = window.stacklessStripeExpress.mount(ref.current, {
				context: (data.express && data.express.context) || 'checkout',
				amount: amount,
				currency: currencyOf(props.billing),
				needsShipping: !!(props.shippingData && props.shippingData.needsShipping),
				requiresSave: !!data.requiresSave,
				capture: data.captureMethod
			}, {
				onClick: function () {
					if (props.onClick) {
						props.onClick();
					}
				},
				onCancel: function () {
					if (props.onClose) {
						props.onClose();
					}
					if (wp.data && wp.data.dispatch) {
						var cart = wp.data.dispatch('wc/store/cart');
						if (cart && cart.invalidateResolutionForStore) {
							cart.invalidateResolutionForStore();
						}
					}
				},
				onError: function (message) {
					if (props.setExpressPaymentError) {
						props.setExpressPaymentError(message || '');
					}
				}
			});
			return function () {
				if (handle.current) {
					handle.current.destroy();
					handle.current = null;
				}
			};
		}, []);

		useEffect(function () {
			if (handle.current && amount > 0) {
				handle.current.setAmount(amount);
			}
		}, [amount]);

		return el('div', { ref: ref, className: 'stackless-stripe-express stackless-stripe-express--block' });
	}

	function ExpressEdit() {
		return el('div', { className: 'stackless-stripe-express stackless-stripe-express--editor' }, 'Apple Pay / Google Pay / Link');
	}

	if (data.express && data.express.enabled && wc.wcBlocksRegistry.registerExpressPaymentMethod) {
		wc.wcBlocksRegistry.registerExpressPaymentMethod({
			name: name + '_express',
			title: title,
			description: 'Apple Pay, Google Pay, Link',
			content: el(ExpressContent, null),
			edit: el(ExpressEdit, null),
			canMakePayment: function (args) {
				return canMakePayment(args) && stripeAmount({
					cartTotal: { value: (args.cartTotals || {}).total_price || 0 },
					currency: { minorUnit: (args.cartTotals || {}).currency_minor_unit || 0, code: (args.cartTotals || {}).currency_code || '' }
				}) > 0;
			},
			paymentMethodId: name,
			gatewayId: name,
			supports: { features: data.supports || ['products'] }
		});
	}
})(window);
