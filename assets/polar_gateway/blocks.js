/**
 * Stackless for WooCommerce: Polar (and its copies) in the Checkout block.
 * Polar is a hosted checkout, so Place order redirects the buyer there.
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

	function eligible(arg) {
		var extensions = arg && arg.cart && arg.cart.extensions ? arg.cart.extensions : null;
		if (!extensions && window.wp.data && window.wp.data.select) {
			var store = window.wp.data.select('wc/store/cart');
			extensions = store && store.getCartData ? store.getCartData().extensions : null;
		}
		var polar = extensions ? extensions.stackless_polar_gateway : null;
		return !polar || polar.eligible !== false;
	}

	function register(id) {
		var data = wcSettings.getSetting(id + '_data', null);
		if (!data || !data.gatewayId) {
			return;
		}
		var title = decode(data.title || 'Polar');

		function Content() {
			return el('div', { className: 'stackless-polar stackless-polar--blocks' },
				data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
				data.sandbox ? el('p', { className: 'stackless-polar__sandbox' }, el('strong', null, data.i18n.sandbox)) : null
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
				if (code && (data.currencies || []).indexOf(code) === -1) {
					return false;
				}
				return eligible(arg);
			},
			supports: { features: data.supports || ['products'] }
		});
	}

	(window.stacklessPolarGateways || ['stackless_polar']).forEach(register);
})();
