/**
 * Stackless for WooCommerce: PayPal in the Checkout block.
 *
 * The buttons render inside the payment method. After approval the block's own
 * Place order flow is triggered with the PayPal order id (or vault setup token)
 * in paymentMethodData; the server captures it. Clicking Place order without
 * the buttons falls back to a redirect to PayPal.
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if (!registry || !wcSettings || !window.wp || !window.wp.element || !window.stacklessPayPal) {
		return;
	}

	var el = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var useState = window.wp.element.useState;
	var useSelect = window.wp.data.useSelect;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
	var data = wcSettings.getSetting('stackless_paypal_data', {});
	var title = decode(data.title || 'PayPal');

	function requiresVault(cart) {
		var ext = cart && cart.extensions ? cart.extensions.stackless_paypal_gateway : null;
		return !!(ext && ext.requires_vault);
	}

	function currencySupported(code) {
		return (data.currencies || []).indexOf(String(code || '').toUpperCase()) !== -1;
	}

	function Content(props) {
		var container = useRef(null);
		var messages = useRef(null);
		var cardEl = useRef(null);
		var walletsEl = useRef(null);
		var approval = useRef(null);
		var extras = useRef({ card: null, fastlane: null });
		var cardState = useState(false);
		var cardShown = cardState[0];
		var setCardShown = cardState[1];
		var statusState = useState('');
		var status = statusState[0];
		var setStatus = statusState[1];

		var cart = useSelect(function (select) {
			return select('wc/store/cart').getCartData();
		}, []);
		var vault = requiresVault(cart);
		var currency = props.billing && props.billing.currency ? props.billing.currency.code : '';
		var minor = props.billing && props.billing.currency ? props.billing.currency.minorUnit : 2;
		var total = props.billing && props.billing.cartTotal ? props.billing.cartTotal.value : 0;
		var mode = vault && Number(total) === 0 ? 'setup' : 'order';
		var amount = Number(total) / Math.pow(10, minor || 0);

		var onSubmit = props.onSubmit;
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var email = props.billing && props.billing.billingAddress ? props.billing.billingAddress.email : '';
		var address = props.billing && props.billing.billingAddress ? props.billing.billingAddress : {};

		// Card fields (or Fastlane for guests) and Apple Pay / Google Pay, for paid orders only.
		useEffect(function () {
			extras.current = { card: null, fastlane: null };
			setCardShown(false);
			if (mode !== 'order' || !currencySupported(currency)) {
				return undefined;
			}
			var cancelled = false;
			var common = { currency: currency, intent: data.intent, vault: vault, context: 'checkout' };
			if (cardEl.current) {
				var start = data.fastlane ? window.stacklessPayPal.fastlane(data, Object.assign({ el: cardEl.current }, common)) : Promise.resolve(null);
				start.then(function (fast) {
					if (cancelled) {
						return false;
					}
					if (fast) {
						extras.current.fastlane = fast;
						return true;
					}
					return window.stacklessPayPal.cardFields(data, Object.assign({ el: cardEl.current }, common)).then(function (card) {
						if (!cancelled) {
							extras.current.card = card;
						}
						return !!card;
					});
				}).then(function (shown) {
					if (!cancelled) {
						setCardShown(!!shown);
					}
				}).catch(function () {
					if (!cancelled) {
						setCardShown(false);
					}
				});
			}
			if (walletsEl.current) {
				window.stacklessPayPal.wallets(data, Object.assign({
					el: walletsEl.current,
					amount: amount,
					onApproved: function (orderId, funding) {
						approval.current = { type: 'order', id: orderId, mode: mode, funding: funding };
						setStatus(data.i18n.approved);
						onSubmit();
					},
					onError: function (message) {
						setStatus(message);
					}
				}, common)).catch(function () {
					if (walletsEl.current) {
						walletsEl.current.innerHTML = '';
					}
				});
			}
			return function () {
				cancelled = true;
			};
		}, [mode, vault, currency]);

		useEffect(function () {
			if (extras.current.fastlane && email) {
				extras.current.fastlane.lookup(email);
			}
		}, [email, cardShown]);

		useEffect(function () {
			if (approval.current && approval.current.mode !== mode) {
				approval.current = null;
			}
			if (!container.current) {
				return undefined;
			}
			if (!currencySupported(currency)) {
				setStatus(data.i18n.unavailable);
				return undefined;
			}
			var cancelled = false;
			var buttons = null;
			setStatus(mode === 'setup' ? data.i18n.saving : '');
			window.stacklessPayPal.render(data, container.current, {
				mode: mode,
				vault: vault,
				intent: data.intent,
				currency: currency,
				context: 'checkout',
				amount: amount,
				messagesEl: messages.current,
				onApproved: function (type, id) {
					approval.current = { type: type, id: id, mode: mode };
					setStatus(data.i18n.approved);
					onSubmit();
				},
				onError: function (message) {
					setStatus(message);
				},
				onCancel: function () {
					setStatus(data.i18n.cancelled);
				}
			}).then(function (instance) {
				buttons = instance;
				if (cancelled && buttons && buttons.close) {
					buttons.close();
				}
			}).catch(function () {
				if (!cancelled) {
					setStatus(data.i18n.error);
				}
			});
			return function () {
				cancelled = true;
				if (buttons && buttons.close) {
					buttons.close();
				}
			};
		// Re-render only when the flow or currency changes; totals are re-checked on the server.
		}, [mode, vault, currency]);

		useEffect(function () {
			function success() {
				var paymentMethodData = { stackless_paypal_context: 'checkout' };
				if (approval.current && approval.current.type === 'order') {
					paymentMethodData.stackless_paypal_order_id = approval.current.id;
				} else if (approval.current && approval.current.type === 'setup') {
					paymentMethodData.stackless_paypal_setup_token = approval.current.id;
				} else if (approval.current && approval.current.type === 'fastlane') {
					paymentMethodData.stackless_paypal_fastlane_token = approval.current.id;
				}
				if (approval.current && approval.current.funding) {
					paymentMethodData.stackless_paypal_funding = approval.current.funding;
				}
				return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: paymentMethodData } };
			}
			return eventRegistration.onPaymentSetup(function () {
				var ex = extras.current;
				if (approval.current || (!ex.card && !ex.fastlane)) {
					return success();
				}
				// Place order with the card fields (or Fastlane): pay by card first, then check out.
				var billing = {
					addressLine1: address.address_1 || '',
					addressLine2: address.address_2 || '',
					adminArea2: address.city || '',
					adminArea1: address.state || '',
					postalCode: address.postcode || '',
					countryCode: address.country || ''
				};
				var pay = ex.fastlane
					? ex.fastlane.token(billing).then(function (id) {
						approval.current = { type: 'fastlane', id: id, mode: mode, funding: 'fastlane' };
					})
					: ex.card.submit(billing).then(function (id) {
						approval.current = { type: 'order', id: id, mode: mode, funding: 'card' };
					});
				return pay.then(success).catch(function (error) {
					approval.current = null;
					return {
						type: emitResponse.responseTypes.ERROR,
						message: error && error.message && error.message.length < 200 ? error.message : data.i18n.cardIncomplete
					};
				});
			});
		}, [eventRegistration.onPaymentSetup, emitResponse.responseTypes.SUCCESS, emitResponse.responseTypes.ERROR, mode, address.postcode, address.country, address.city, address.address_1]);

		useEffect(function () {
			return eventRegistration.onCheckoutFail(function () {
				// A declined or expired approval can't be reused; the buyer starts again.
				approval.current = null;
				return true;
			});
		}, [eventRegistration.onCheckoutFail]);

		return el('div', { className: 'stackless-paypal stackless-paypal--blocks' },
			data.description ? el('div', { className: 'stackless-paypal__description', dangerouslySetInnerHTML: { __html: data.description } }) : null,
			vault ? el('p', { className: 'stackless-paypal__vault-note' }, data.i18n.saving) : null,
			el('div', { className: 'stackless-paypal__buttons', ref: container }),
			el('div', { className: 'stackless-paypal__messages', ref: messages }),
			el('div', { className: 'stackless-paypal__wallets', ref: walletsEl }),
			el('div', { className: 'stackless-paypal__card', hidden: !cardShown },
				el('p', { className: 'stackless-paypal__or' }, data.i18n.orCard),
				el('div', { className: 'stackless-paypal__card-fields', ref: cardEl })
			),
			el('p', { className: 'stackless-paypal__status', role: 'status', 'aria-live': 'polite' }, status)
		);
	}

	function Edit() {
		return el('div', { className: 'stackless-paypal stackless-paypal--edit' }, title);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: data.gatewayId || 'stackless_paypal',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Edit, null),
		ariaLabel: title,
		canMakePayment: function (arg) {
			var code = arg && arg.cartTotals ? arg.cartTotals.currency_code : '';
			return currencySupported(code);
		},
		supports: { features: data.supports || ['products'] }
	});
})();
