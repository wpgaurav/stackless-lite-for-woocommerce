/* Stackless for WooCommerce: switch product detail values with the selected variation. */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}
	function apply($form, values) {
		var productId = $form.data('product_id');
		var $tables = productId ? $('[data-stackless-product-details="' + productId + '"]') : $('[data-stackless-product-details]');
		$tables.find('[data-stackless-detail]').each(function () {
			var $cell = $(this);
			var key = String($cell.data('stackless-detail'));
			var text = values && Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : String($cell.attr('data-stackless-default') || '');
			$cell.text(text);
			$cell.closest('tr').prop('hidden', text === '');
		});
	}
	$(document).on('found_variation', 'form.variations_form', function (event, variation) {
		apply($(this), variation && variation.stackless_details ? variation.stackless_details : null);
	});
	$(document).on('reset_data', 'form.variations_form', function () {
		apply($(this), null);
	});
})(window.jQuery);
