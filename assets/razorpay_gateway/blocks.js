/**
 * Stackless for WooCommerce: Razorpay in the Checkout block. Place order goes to the order's
 * payment page, where Razorpay Checkout opens. Subscription carts first pick the renewal
 * method (card, UPI Autopay or eNACH).
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if (!registry || !wcSettings || !window.wp || !window.wp.element) {
		return;
	}

	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
	var data = wcSettings.getSetting('stackless_razorpay_data', null);
	if (!data) {
		return;
	}
	var title = decode(data.title || 'Razorpay');

	function Content(props) {
		var methods = data.methods || [];
		var methodState = useState(methods.length ? methods[0].id : '');
		var method = methodState[0];
		var setMethod = methodState[1];
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;

		useEffect(function () {
			if (!eventRegistration || !eventRegistration.onPaymentSetup) {
				return undefined;
			}
			return eventRegistration.onPaymentSetup(function () {
				var paymentMethodData = {};
				paymentMethodData[data.field] = method;
				return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: paymentMethodData } };
			});
		}, [eventRegistration, emitResponse, method]);

		var picker = null;
		if (data.requiresSave && methods.length) {
			picker = el('fieldset', { className: 'stackless-razorpay__methods' },
				el('legend', null, data.i18n.method),
				methods.map(function (item) {
					return el('label', { key: item.id, className: 'stackless-razorpay__method', style: { display: 'block' } },
						el('input', {
							type: 'radio',
							name: 'stackless-razorpay-method',
							value: item.id,
							checked: method === item.id,
							onChange: function () { setMethod(item.id); }
						}),
						' ' + decode(item.label)
					);
				}),
				el('small', null, data.i18n.rupees)
			);
		}

		return el('div', { className: 'stackless-razorpay stackless-razorpay--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			picker,
			data.testmode ? el('p', { className: 'stackless-razorpay__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	function Edit() {
		return el('div', { className: 'stackless-razorpay stackless-razorpay--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: 'stackless_razorpay',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Edit, null),
		ariaLabel: title,
		canMakePayment: function (arg) {
			var code = arg && arg.cartTotals ? String(arg.cartTotals.currency_code || '').toUpperCase() : '';
			if (code && (data.currencies || []).indexOf(code) === -1) {
				return false;
			}
			if (data.requiresSave && (code !== 'INR' || !(data.methods || []).length)) {
				return false;
			}
			return true;
		},
		supports: { features: data.supports || ['products'] }
	});
})();
