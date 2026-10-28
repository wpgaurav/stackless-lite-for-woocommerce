/* Stackless for WooCommerce: show the Service tab only for service products. */
(function ($) {
	'use strict';
	$(function () {
		var $box = $('#_stackless_service');
		function toggle() {
			var on = $box.is(':checked');
			$('.stackless-service-tab').toggle(on);
			if (!on && $('.stackless-service-tab').hasClass('active')) {
				$('.general_options a').trigger('click');
			}
		}
		$box.on('change', toggle);
		// WooCommerce re-shows show_if_* tabs on type changes; apply our rule after it.
		$(document.body).on('woocommerce-product-type-change', function () {
			window.setTimeout(toggle, 0);
		});
		$('input#_virtual, input#_downloadable').on('change', function () {
			window.setTimeout(toggle, 0);
		});
		toggle();
	});
})(window.jQuery);
