/**
 * Stackless for WooCommerce: Revolut in the Checkout block.
 * Place order redirects the buyer to Revolut's hosted page, or to the page with Revolut's
 * checkout window when the card has to be saved.
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
	var data = wcSettings.getSetting('stackless_revolut_data', null);
	if (!data) {
		return;
	}
	var title = decode(data.title || 'Revolut');

	function Content() {
		return el('div', { className: 'stackless-revolut stackless-revolut--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			data.requiresSave ? el('p', { className: 'stackless-revolut__save-note' }, data.i18n.save) : null,
			data.testmode ? el('p', { className: 'stackless-revolut__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: 'stackless_revolut',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Content, null),
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
