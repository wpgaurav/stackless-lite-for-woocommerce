/* Stackless for WooCommerce: per-variation quantity step and limits note. */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}
	$(document).on('found_variation', 'form.variations_form', function (event, variation) {
		var $form = $(this);
		var $qty = $form.find('input.qty');
		var $note = $form.find('.stackless-qty-limits');
		if (variation && variation.stackless_qty_step) {
			$qty.attr('step', variation.stackless_qty_step);
		}
		if ($note.length) {
			var text = variation && variation.stackless_qty_note ? String(variation.stackless_qty_note) : '';
			$note.text(text).prop('hidden', text === '');
		}
	});
	$(document).on('reset_data', 'form.variations_form', function () {
		var $form = $(this);
		$form.find('input.qty').attr('step', 1);
		$form.find('.stackless-qty-limits').text('').prop('hidden', true);
	});
})(window.jQuery);
