/**
 * Stackless for WooCommerce: on the Checkout block, hide the payment section while the
 * only available method is a hosted checkout (Polar, Paddle, Dodo Payments or a copy).
 */
(function () {
	'use strict';

	var data = window.wp && window.wp.data;
	if (!data || !data.select || !data.subscribe) {
		return;
	}
	var families = window.stacklessHostedGateways || [];
	var style = document.createElement('style');
	style.textContent = 'body.stackless-hosted-only .wp-block-woocommerce-checkout-payment-block{display:none!important}';
	document.head.appendChild(style);

	function hosted(id) {
		return families.some(function (family) {
			return id === family || new RegExp('^' + family.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '_copy\\d+$').test(id);
		});
	}

	var last = null;
	function update() {
		var store = data.select('wc/store/payment');
		if (!store || !store.getAvailablePaymentMethods) {
			return;
		}
		var ids = Object.keys(store.getAvailablePaymentMethods() || {});
		var hide = ids.length === 1 && hosted(ids[0]);
		if (hide !== last) {
			last = hide;
			document.body.classList.toggle('stackless-hosted-only', hide);
		}
	}

	data.subscribe(update);
	update();
})();
