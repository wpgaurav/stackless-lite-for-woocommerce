/* Stackless for WooCommerce: Free Price Text for the Cart, Checkout and Mini-Cart blocks. */
(function () {
	'use strict';
	var checkout = window.wc && window.wc.blocksCheckout;
	if (!checkout || typeof checkout.registerCheckoutFilters !== 'function') {
		return;
	}
	checkout.registerCheckoutFilters('stackless-free-price-text', {
		cartItemClass: function (value, extensions) {
			var data = extensions && extensions.stackless_free_price_text;
			if (data && data.free) {
				return value ? value + ' stackless-free-price-item' : 'stackless-free-price-item';
			}
			return value;
		}
	});
})();
