/**
 * Stackless for WooCommerce: Airwallex in the Checkout block.
 * Airwallex is a hosted payment page, so Place order redirects the buyer there.
 */
(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	if (!registry || !wcSettings || !window.wp || !window.wp.element) {
		return;
	}

	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
	var data = wcSettings.getSetting('stackless_airwallex_data', null);
	if (!data) {
		return;
	}
	var title = decode(data.title || 'Airwallex');

	function Content() {
		return el('div', { className: 'stackless-airwallex stackless-airwallex--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			data.requiresSave ? el('p', { className: 'stackless-airwallex__save-note' }, data.i18n.save) : null,
			data.testmode ? el('p', { className: 'stackless-airwallex__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	function Edit() {
		return el('div', { className: 'stackless-airwallex stackless-airwallex--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: 'stackless_airwallex',
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
			showSavedCards: false,
			showSaveOption: false
		}
	});
})();
