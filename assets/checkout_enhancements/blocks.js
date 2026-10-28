/* Stackless for WooCommerce: Checkout Enhancements on the Checkout block.
 * Place order label: "Get it for free" (or the merchant's text) while the order total is zero,
 * otherwise the merchant's own label, otherwise WooCommerce's or the payment method's. */
(function () {
    'use strict';
    var checkout = window.wc && window.wc.blocksCheckout;
    var settings = window.wc && window.wc.wcSettings;
    if (!checkout || !checkout.registerCheckoutFilters || !settings) {
        return;
    }
    var data = settings.getSetting('stackless-checkout-enhancements_data', {}) || {};
    var custom = typeof data.button === 'string' ? data.button : '';
    var free = typeof data.freeButton === 'string' ? data.freeButton : '';
    if (!custom && !free) {
        return;
    }
    var namespace = 'stackless-checkout-enhancements';
    var cartStore = 'wc/store/cart';
    var wpData = window.wp && window.wp.data;

    // The server works out "nothing to pay" (zero total after coupons, no subscription billed later)
    // and sends it with every cart response, so a coupon that brings the total to zero flips it.
    function zeroTotal() {
        var store = wpData && wpData.select ? wpData.select(cartStore) : null;
        var cart = store && store.getCartData ? store.getCartData() : null;
        var ext = cart && cart.extensions ? cart.extensions.stackless_checkout_enhancements : null;
        if (ext && typeof ext.zero_total === 'boolean') {
            return ext.zero_total;
        }
        return !!data.zeroTotal;
    }

    var zero = free ? zeroTotal() : false;

    // WooCommerce caches a filter's result until filters are registered again, so registering
    // again is how a changed total reaches the button.
    function register() {
        checkout.registerCheckoutFilters(namespace, {
            placeOrderButtonLabel: function (label) {
                if (free && zero) {
                    return free;
                }
                return custom || label;
            }
        });
    }
    register();

    if (free && wpData && wpData.subscribe) {
        wpData.subscribe(function () {
            var now = zeroTotal();
            if (now !== zero) {
                zero = now;
                register();
            }
        }, cartStore);
    }
}());
