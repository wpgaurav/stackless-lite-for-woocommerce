/**
 * Stackless for WooCommerce: Braintree in the Checkout block. Hosted Fields mount inside the
 * payment method; Place order verifies the card with 3-D Secure (saved cards too) and hands the
 * one-time nonce to the server.
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	var B = window.StacklessBraintree;
	if (!registry || !wcSettings || !window.wp || !window.wp.element || !B) {
		return;
	}

	var GATEWAY = 'stackless_braintree';
	var el = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var useState = window.wp.element.useState;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
	var data = wcSettings.getSetting(GATEWAY + '_data', null);
	if (!data || !data.endpoint) {
		return;
	}
	var title = decode(data.title || 'Braintree');

	function currencyOf(billing) {
		return billing && billing.currency && billing.currency.code ? String(billing.currency.code).toUpperCase() : data.currency;
	}

	/** The block's total in Braintree's decimals, which is what the order will be charged. */
	function amountOf(billing) {
		if (!billing || !billing.cartTotal || !billing.currency) {
			return null;
		}
		var decimals = (data.decimals || {})[currencyOf(billing)];
		if (decimals === undefined) {
			return null;
		}
		return (Number(billing.cartTotal.value) / Math.pow(10, billing.currency.minorUnit || 0)).toFixed(decimals);
	}

	function billingOf(billing) {
		var address = (billing && (billing.billingAddress || billing.billingData)) || {};
		return {
			first: address.first_name,
			last: address.last_name,
			email: address.email,
			line1: address.address_1,
			line2: address.address_2,
			city: address.city,
			region: address.state,
			postcode: address.postcode,
			country: address.country
		};
	}

	/** Mounts a controller per currency: the client token names that currency's merchant account. */
	function useController(currency, rootRef, enabled) {
		var controllerRef = useRef(null);
		var statusState = useState('');
		var setStatus = statusState[1];
		useEffect(function () {
			if (!enabled) {
				return undefined;
			}
			var active = true;
			var controller = B.create(data, rootRef ? rootRef.current : null);
			controllerRef.current = controller;
			setStatus(data.i18n.loading);
			controller.ready.then(function () {
				if (active) {
					setStatus('');
				}
			}).catch(function (error) {
				if (active) {
					setStatus(B.message(error, data));
				}
			});
			return function () {
				active = false;
				controller.destroy();
				controllerRef.current = null;
			};
		}, [currency, enabled]);
		return { ref: controllerRef, status: statusState[0] };
	}

	function Content(props) {
		var rootRef = useRef(null);
		var billing = props.billing;
		var mounted = useController(currencyOf(billing), rootRef, true);
		var register = props.eventRegistration.onPaymentSetup;
		var types = props.emitResponse.responseTypes;
		var save = !!props.shouldSavePayment;

		useEffect(function () {
			return register(function () {
				if (!mounted.ref.current) {
					return { type: types.ERROR, message: data.i18n.failed };
				}
				return mounted.ref.current.pay({ amount: amountOf(billing), save: save, billing: billingOf(billing) }).then(function (result) {
					var fields = {};
					fields[data.fields.nonce] = result.nonce;
					fields[data.fields.device] = result.device;
					return { type: types.SUCCESS, meta: { paymentMethodData: fields } };
				}).catch(function (error) {
					return { type: types.ERROR, message: B.message(error, data) };
				});
			});
		}, [register, types, billing, save]);

		function cardField(name, label, wide) {
			return el('div', { key: name, className: 'stackless-braintree__field' + (wide ? ' stackless-braintree__field--wide' : '') },
				el('span', { className: 'stackless-braintree__label' }, label),
				el('div', { className: 'stackless-braintree__input', 'data-stackless-braintree': name })
			);
		}

		return el('div', { className: 'stackless-braintree stackless-braintree--blocks', ref: rootRef },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			el('div', { className: 'stackless-braintree__card' },
				cardField('number', data.i18n.number, true),
				cardField('expiration', data.i18n.expiry, false),
				cardField('cvv', data.i18n.cvv, false)
			),
			mounted.status ? el('p', { className: 'stackless-braintree__status', role: 'alert' }, mounted.status) : null,
			data.testmode ? el('p', { className: 'stackless-braintree__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	/** A saved card still needs 3-D Secure, so it verifies before the order is placed. */
	function SavedCard(props) {
		var billing = props.billing;
		var mounted = useController(currencyOf(billing), null, !!data.secure);
		var register = props.eventRegistration.onPaymentSetup;
		var types = props.emitResponse.responseTypes;
		var token = String(props.token || '');

		useEffect(function () {
			return register(function () {
				if (!data.secure) {
					return { type: types.SUCCESS };
				}
				if (!mounted.ref.current || !token) {
					return { type: types.ERROR, message: data.i18n.failed };
				}
				return mounted.ref.current.pay({ saved: token, amount: amountOf(billing), billing: billingOf(billing) }).then(function (result) {
					// This replaces the block's payment data, so the saved card's own keys go along.
					var fields = { token: token, payment_method: GATEWAY, isSavedToken: true };
					fields['wc-' + GATEWAY + '-payment-token'] = token;
					fields[data.fields.nonce] = result.nonce;
					fields[data.fields.device] = result.device;
					return { type: types.SUCCESS, meta: { paymentMethodData: fields } };
				}).catch(function (error) {
					return { type: types.ERROR, message: B.message(error, data) };
				});
			});
		}, [register, types, billing, token]);

		return mounted.status ? el('p', { className: 'stackless-braintree__status', role: 'alert' }, mounted.status) : null;
	}

	function Edit() {
		return el('div', { className: 'stackless-braintree stackless-braintree--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: GATEWAY,
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Edit, null),
		savedTokenComponent: el(SavedCard, null),
		ariaLabel: title,
		canMakePayment: function (arg) {
			var code = arg && arg.cartTotals ? String(arg.cartTotals.currency_code || '').toUpperCase() : '';
			return !code || (data.currencies || []).indexOf(code) !== -1;
		},
		supports: {
			features: data.supports || ['products'],
			showSavedCards: !!data.showSavedCards,
			showSaveOption: !!data.showSaveOption
		}
	});
})();
