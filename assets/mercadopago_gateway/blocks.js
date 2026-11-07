/**
 * Stackless for WooCommerce: Mercado Pago in the Checkout block.
 * Mercado Pago is a hosted checkout, so Place order sends the buyer there to pay.
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
	var data = wcSettings.getSetting('stackless_mercadopago_data', null);
	if (!data || !data.gatewayId) {
		return;
	}
	var title = decode(data.title || 'Mercado Pago');

	function Content() {
		return el('div', { className: 'stackless-mercadopago stackless-mercadopago--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			data.testmode ? el('p', { className: 'stackless-mercadopago__testmode' }, el('strong', null, data.i18n.testmode)) : null
		);
	}

	function Label(props) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el(PaymentMethodLabel, { text: title }) : el('span', null, title);
	}

	registry.registerPaymentMethod({
		name: data.gatewayId,
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Content, null),
		ariaLabel: title,
		canMakePayment: function (arg) {
			var code = arg && arg.cartTotals ? String(arg.cartTotals.currency_code || '').toUpperCase() : '';
			return !code || (data.currencies || []).indexOf(code) !== -1;
		},
		supports: { features: data.supports || ['products'] }
	});
})();
