/**
 * Stackless for WooCommerce: Square in the Checkout block. The card form mounts inside the
 * payment method; Place order tokenizes it (with Square's buyer verification) and hands the
 * token to the server. Google Pay and Apple Pay tokenize on their own button and place the order.
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	var S = window.StacklessSquare;
	if (!registry || !wcSettings || !window.wp || !window.wp.element || !S) {
		return;
	}

	var el = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var useState = window.wp.element.useState;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
	var data = wcSettings.getSetting('stackless_square_data', null);
	if (!data || !data.appId) {
		return;
	}
	var title = decode(data.title || 'Square');

	function amountOf(billing) {
		if (!billing || !billing.cartTotal || !billing.currency) {
			return data.amount;
		}
		var minor = billing.currency.minorUnit || 0;
		return (Number(billing.cartTotal.value) / Math.pow(10, minor)).toFixed(minor);
	}

	function contactOf(address) {
		address = address || {};
		return {
			givenName: address.first_name,
			familyName: address.last_name,
			email: address.email,
			phone: address.phone,
			addressLines: [address.address_1, address.address_2].filter(Boolean),
			city: address.city,
			state: address.state,
			postalCode: address.postcode,
			countryCode: address.country
		};
	}

	function Content(props) {
		var cardRef = useRef(null);
		var walletToken = useRef(null);
		var statusState = useState('');
		var status = statusState[0];
		var setStatus = statusState[1];
		var appleState = useState(false);
		var showApple = appleState[0];
		var setShowApple = appleState[1];
		var appleRef = useRef(null);
		var googleId = 'stackless-square-google-pay-blocks';
		var cardId = 'stackless-square-card-blocks';
		var billing = props.billing;
		var amount = amountOf(billing);
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var onSubmit = props.onSubmit;
		var shouldSave = !!props.shouldSavePayment;

		useEffect(function () {
			var cancelled = false;
			var card = null;
			setStatus(data.i18n.loading);
			S.payments(data).then(function (payments) {
				return payments.card().then(function (instance) {
					card = instance;
					return instance.attach('#' + cardId);
				}).then(function () {
					if (cancelled) {
						return;
					}
					cardRef.current = card;
					setStatus('');
					if (!data.wallets || !data.wallets.length || Number(amount) <= 0) {
						return;
					}
					var request = S.paymentRequest(payments, data, amount);
					if (data.wallets.indexOf('google_pay') !== -1) {
						payments.googlePay(request).then(function (googlePay) {
							return googlePay.attach('#' + googleId).then(function () {
								var button = document.getElementById(googleId);
								if (button) {
									button.addEventListener('click', function (event) {
										event.preventDefault();
										S.tokenize(googlePay, null).then(function (token) {
											walletToken.current = { token: token, method: 'google_pay' };
											onSubmit();
										}).catch(function (error) { setStatus(error && error.message ? error.message : data.i18n.invalid); });
									});
								}
							});
						}).catch(function () { /* not available */ });
					}
					if (data.wallets.indexOf('apple_pay') !== -1) {
						payments.applePay(request).then(function (applePay) {
							appleRef.current = applePay;
							setShowApple(true);
						}).catch(function () { /* not available */ });
					}
				});
			}).catch(function () {
				if (!cancelled) {
					setStatus(data.i18n.failed);
				}
			});
			return function () {
				cancelled = true;
				if (card && card.destroy) {
					card.destroy();
				}
				cardRef.current = null;
			};
		// Mount once; amounts are read again at submit time.
		}, []);

		useEffect(function () {
			return eventRegistration.onPaymentSetup(function () {
				var fields = {};
				if (walletToken.current) {
					fields[data.fields.token] = walletToken.current.token;
					fields[data.fields.method] = walletToken.current.method;
					walletToken.current = null;
					return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: fields } };
				}
				if (!cardRef.current) {
					return { type: emitResponse.responseTypes.ERROR, message: data.i18n.failed };
				}
				var save = data.requiresSave || shouldSave;
				var intent = S.intentFor(data, amount, save);
				var details = S.details(data, amount, intent, contactOf(billing && billing.billingAddress));
				return S.tokenize(cardRef.current, details).then(function (token) {
					fields[data.fields.token] = token;
					fields[data.fields.method] = 'card';
					return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: fields } };
				}).catch(function (error) {
					return { type: emitResponse.responseTypes.ERROR, message: error && error.message ? error.message : data.i18n.invalid };
				});
			});
		}, [eventRegistration.onPaymentSetup, emitResponse.responseTypes, amount, shouldSave, billing]);

		var wallets = null;
		if (data.wallets && data.wallets.length) {
			wallets = el('div', { className: 'stackless-square__wallets' },
				showApple ? el('button', {
					type: 'button',
					className: 'stackless-square__apple-pay',
					'aria-label': 'Apple Pay',
					onClick: function (event) {
						event.preventDefault();
						if (!appleRef.current) {
							return;
						}
						S.tokenize(appleRef.current, null).then(function (token) {
							walletToken.current = { token: token, method: 'apple_pay' };
							onSubmit();
						}).catch(function (error) { setStatus(error && error.message ? error.message : data.i18n.invalid); });
					}
				}) : null,
				data.wallets.indexOf('google_pay') !== -1 ? el('div', { id: googleId, className: 'stackless-square__google-pay' }) : null
			);
		}

		return el('div', { className: 'stackless-square stackless-square--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			wallets,
			el('div', { id: cardId, className: 'stackless-square__card' }),
			status ? el('p', { className: 'stackless-square__status', role: 'alert' }, status) : null,
			data.testmode ? el('p', { className: 'stackless-square__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	function Edit() {
		return el('div', { className: 'stackless-square stackless-square--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: 'stackless_square',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Edit, null),
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
