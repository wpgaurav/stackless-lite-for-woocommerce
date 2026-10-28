/* Stackless for WooCommerce: merchant wording for WooCommerce's block checkout strings.
 * Loaded in the head, before the Checkout block script reads its default headings. */
(function () {
    'use strict';
    var map = window.stacklessCheckoutText;
    if (!map || !window.wp || !wp.hooks) {
        return;
    }
    wp.hooks.addFilter('i18n.gettext_woocommerce', 'stackless/checkout-enhancements', function (translation, text) {
        return Object.prototype.hasOwnProperty.call(map, text) ? map[text] : translation;
    });
}());
