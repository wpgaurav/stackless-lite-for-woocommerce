/* Stackless for WooCommerce: product details editor (Product data → Details). */
(function ($) {
	'use strict';
	if (!$) {
		return;
	}
	function sync() {
		var preset = $('#stackless_pe_preset').val();
		$('.stackless-pe-panel .stackless-pe-field').each(function () {
			var presets = String($(this).data('presets') || '').split(' ');
			$(this).toggle(presets.indexOf(preset) !== -1);
		});
		$('.stackless-pe-fields').toggle($('.stackless-pe-panel .stackless-pe-field:visible').length > 0 || preset !== 'custom');
	}
	$(document).on('change', '#stackless_pe_preset', sync);
	$(document).on('change', '.stackless-pe-hide input', function () {
		$(this).closest('.stackless-pe-field').toggleClass('is-hidden', this.checked);
	});
	$(function () {
		sync();
		$('.stackless-pe-hide input:checked').each(function () {
			$(this).closest('.stackless-pe-field').addClass('is-hidden');
		});
	});
})(window.jQuery);
