/**
 * Stackless for WooCommerce: Mollie in the Checkout block.
 * Mollie is a hosted checkout, so Place order redirects the buyer there. The optional method
 * picker sends the buyer straight to the chosen method.
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
	var data = wcSettings.getSetting('stackless_mollie_data', null);
	if (!data) {
		return;
	}
	var title = decode(data.title || 'Mollie');

	function Content(props) {
		var methodState = useState('');
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
		if (data.methods && data.methods.length) {
			picker = el('div', { className: 'wc-block-components-select-input stackless-mollie__method' },
				el('label', { htmlFor: 'stackless-mollie-method' }, data.i18n.method),
				el('select', {
					id: 'stackless-mollie-method',
					value: method,
					onChange: function (event) { setMethod(event.target.value); }
				},
					[el('option', { key: '', value: '' }, data.i18n.any)].concat(data.methods.map(function (item) {
						return el('option', { key: item.id, value: item.id }, decode(item.label));
					}))
				)
			);
		}

		return el('div', { className: 'stackless-mollie stackless-mollie--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			picker,
			data.testmode ? el('p', { className: 'stackless-mollie__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	function Edit() {
		return el('div', { className: 'stackless-mollie stackless-mollie--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: 'stackless_mollie',
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
