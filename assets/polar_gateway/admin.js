/**
 * Stackless for WooCommerce: "Create in Polar" buttons in the product editor
 * (simple products and each variation).
 */
jQuery(function ($) {
	'use strict';

	var cfg = window.stacklessPolarAdmin;
	if (!cfg) {
		return;
	}

	$(document).on('click', '.stackless-polar-product__create', function (event) {
		event.preventDefault();
		var $button = $(this);
		var $box = $button.closest('.stackless-polar-product');
		var mode = $button.data('mode');
		var productId = $box.data('product-id');
		var $status = $box.find('.stackless-polar-product__status');
		var $input = $box.find('.stackless-polar-product__id[data-mode="' + mode + '"]');

		if (!productId) {
			$status.text(cfg.i18n.saveFirst);
			return;
		}

		$button.prop('disabled', true);
		$status.text(cfg.i18n.creating);

		$.post(cfg.ajaxUrl, {
			action: 'stackless_polar_create_product',
			nonce: cfg.nonce,
			product_id: productId,
			mode: mode,
			billing: $box.find('.stackless-polar-product__billing').val()
		}).done(function (response) {
			if (response && response.success && response.data && response.data.id) {
				$input.val(response.data.id).trigger('change');
				$status.text(cfg.i18n.created);
			} else {
				$status.text(response && response.data && response.data.message ? response.data.message : cfg.i18n.failed);
			}
		}).fail(function (xhr) {
			var json = xhr && xhr.responseJSON;
			$status.text(json && json.data && json.data.message ? json.data.message : cfg.i18n.failed);
		}).always(function () {
			$button.prop('disabled', false);
		});
	});
});
