/* Stackless for WooCommerce: per-variation add to cart button text. */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}
	function button($form) {
		return $form.find('.single_add_to_cart_button');
	}
	function remember($button) {
		if ($button.length && typeof $button.data('stacklessDefaultText') === 'undefined') {
			$button.data('stacklessDefaultText', $button.text());
		}
	}
	$(document).on('found_variation', 'form.variations_form', function (event, variation) {
		var $button = button($(this));
		remember($button);
		var text = variation && variation.stackless_button_text ? String(variation.stackless_button_text) : $button.data('stacklessDefaultText');
		if (typeof text === 'string') {
			$button.text(text);
		}
	});
	$(document).on('reset_data', 'form.variations_form', function () {
		var $button = button($(this));
		var text = $button.data('stacklessDefaultText');
		if (typeof text === 'string') {
			$button.text(text);
		}
	});
})(window.jQuery);
