/**
 * Stackless for WooCommerce: Stripe Managed Payments in the Checkout block.
 * A redirect method: Place order sends the buyer to the store's pay page, which embeds
 * Stripe Checkout. Hidden when the cart is not eligible (physical products, or more than
 * one subscription schedule), as reported by the stackless_stripe_managed cart extension.
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
	var data = wcSettings.getSetting('stripe_managed_data', null);
	if (!data || !data.gatewayId) {
		return;
	}
	var title = decode(data.title || 'Stripe');

	function eligible(arg) {
		var extensions = arg && arg.cart && arg.cart.extensions ? arg.cart.extensions : null;
		if (!extensions && window.wp.data && window.wp.data.select) {
			var store = window.wp.data.select('wc/store/cart');
			extensions = store && store.getCartData ? store.getCartData().extensions : null;
		}
		var managed = extensions ? extensions.stackless_stripe_managed : null;
		return !managed || managed.eligible !== false;
	}

	function Content() {
		return el('div', { className: 'stackless-stripe-managed stackless-stripe-managed--blocks' },
			data.description ? el('div', { dangerouslySetInnerHTML: { __html: data.description } }) : null,
			data.testMode ? el('p', { className: 'stackless-stripe-test-notice' }, data.i18n.testMode) : null
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
})();
