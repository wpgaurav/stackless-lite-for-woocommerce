/* Stackless for WooCommerce: Pay What You Like on the product page, classic cart and classic checkout. No build step. */
jQuery(function ($) {
    'use strict';
    var cfg = window.stacklessPayWhatYouLike || {};
    var i18n = cfg.i18n || {};
    var decimalSep = cfg.decimalSep || '.';
    var timer = null;

    // Same reading rules as the server (Amounts::parse): "12.50", "12,50", "1,234.50", "1.234,50".
    function toMinor(raw, decimals) {
        var value = String(raw == null ? '' : raw).replace(/[\s  ']+/g, '');
        if (value === '' || value.indexOf('-') !== -1) return null;
        value = value.replace(/^[^\d.,]+|[^\d.,]+$/g, '');
        if (value === '' || !/^[\d.,]+$/.test(value)) return null;
        var intPart = value, frac = '';
        var pos = Math.max(value.lastIndexOf('.'), value.lastIndexOf(','));
        if (pos >= 0) {
            var sep = value.charAt(pos), after = value.slice(pos + 1);
            var single = value.split(sep).length === 2;
            var isDecimal = sep === decimalSep ? single && !(decimals === 0 && after.length === 3) : single && after.length !== 3;
            if (isDecimal) { intPart = value.slice(0, pos); frac = after; }
        }
        if (/[.,]/.test(intPart) && !/^\d{1,3}([.,])\d{3}(\1\d{3})*$/.test(intPart)) return null;
        intPart = intPart.replace(/[.,]/g, '') || '0';
        if (!/^\d+$/.test(intPart) || (frac !== '' && !/^\d+$/.test(frac))) return null;
        if (frac.length > decimals) {
            if (/[1-9]/.test(frac.slice(decimals))) return null;
            frac = frac.slice(0, decimals);
        }
        if (intPart.replace(/^0+/, '').length > 12) return null;
        while (frac.length < decimals) frac += '0';
        return parseInt(intPart, 10) * Math.pow(10, decimals) + (decimals ? parseInt(frac, 10) : 0);
    }

    function fieldOf(node) { return $(node).closest('[data-stackless-pwyl]'); }

    function check($field) {
        if (!$field.length || $field.prop('hidden')) return '';
        var decimals = parseInt($field.attr('data-decimals'), 10) || 0;
        var $input = $field.find('[data-stackless-pwyl-amount]');
        var amount = toMinor($input.val(), decimals);
        var min = toMinor($field.attr('data-min'), decimals);
        var max = toMinor($field.attr('data-max'), decimals);
        var message = '';
        if (amount === null) message = i18n.invalid || '';
        else if (min !== null && amount < min) message = (i18n.tooLow || '%s').replace('%s', $field.attr('data-min-label') || $field.attr('data-min'));
        else if (max !== null && amount > max) message = (i18n.tooHigh || '%s').replace('%s', $field.attr('data-max-label') || $field.attr('data-max'));
        $input.attr('aria-invalid', message ? 'true' : 'false');
        $field.find('[data-stackless-pwyl-preset]').each(function () {
            this.setAttribute('aria-pressed', toMinor(this.getAttribute('data-stackless-pwyl-preset'), decimals) === amount ? 'true' : 'false');
        });
        $field.find('[data-stackless-pwyl-status]').text(message).toggleClass('is-error', !!message);
        return message;
    }

    function firstInvalid($scope) {
        var bad = null;
        $scope.find('[data-stackless-pwyl]').each(function () {
            if (!bad && check($(this))) bad = $(this).find('[data-stackless-pwyl-amount]').get(0);
        });
        return bad;
    }

    function commit($field, now) {
        var context = $field.attr('data-context');
        clearTimeout(timer);
        if (check($field)) return;
        if (context === 'checkout') {
            // The order review refresh posts the whole form; the server applies the amount first.
            timer = setTimeout(function () { $(document.body).trigger('update_checkout'); }, now ? 0 : 700);
        } else if (context === 'cart' && now) {
            var $button = $field.closest('form').find('[name="update_cart"]');
            $button.prop('disabled', false).attr('aria-disabled', 'false').trigger('click');
        }
    }

    $(document.body).on('input', '[data-stackless-pwyl-amount]', function () {
        var $field = fieldOf(this);
        if ($field.attr('data-context') === 'checkout') commit($field, false);
        else check($field);
    });
    $(document.body).on('change', '[data-stackless-pwyl-amount]', function () {
        var $field = fieldOf(this);
        if ($field.attr('data-context') === 'checkout') commit($field, true);
        else check($field);
    });
    // Enter updates the total instead of placing the order (checkout) or submitting nothing (cart).
    $(document.body).on('keydown', '[data-stackless-pwyl-amount]', function (event) {
        var $field = fieldOf(this);
        var context = $field.attr('data-context');
        if (event.key !== 'Enter' || (context !== 'cart' && context !== 'checkout')) return;
        event.preventDefault();
        commit($field, true);
    });
    $(document.body).on('click', '[data-stackless-pwyl-preset]', function (event) {
        event.preventDefault();
        var $field = fieldOf(this);
        $field.find('[data-stackless-pwyl-amount]').val(this.getAttribute('data-stackless-pwyl-preset'));
        commit($field, true);
    });

    // Product page: stop the add to cart while an amount is out of range.
    $(document.body).on('submit', 'form.cart', function (event) {
        var bad = firstInvalid($(this));
        if (bad) { event.preventDefault(); event.stopImmediatePropagation(); bad.focus(); }
    });

    // Classic checkout: Place order waits for a valid amount.
    $('form.checkout').on('checkout_place_order', function () {
        var bad = firstInvalid($(this));
        if (bad) { bad.focus(); return false; }
        return true;
    });

    // Variable products: each variation brings its own limits (or none for a fixed price).
    $(document.body).on('found_variation', 'form.variations_form', function (event, variation) {
        var $field = $(this).find('[data-stackless-pwyl][data-variable]');
        if (!$field.length) return;
        var data = variation && variation.stackless_pwyl;
        if (!data) { $field.prop('hidden', true).find('[data-stackless-pwyl-amount]').prop('disabled', true); return; }
        $field.attr({ 'data-min': data.min, 'data-max': data.max, 'data-decimals': data.decimals, 'data-min-label': data.minLabel, 'data-max-label': data.maxLabel });
        $field.find('[data-stackless-pwyl-amount]').prop('disabled', false).val(data.value);
        $field.find('[data-stackless-pwyl-help]').text(data.help || '');
        var $presets = $field.find('[data-stackless-pwyl-presets]').empty();
        (data.presets || []).forEach(function (preset) {
            $('<button type="button" class="stackless-pwyl__preset"></button>')
                .attr({ 'data-stackless-pwyl-preset': preset.value, 'aria-pressed': preset.pressed ? 'true' : 'false' })
                .text(preset.label)
                .appendTo($presets);
        });
        $presets.prop('hidden', !(data.presets || []).length);
        $field.prop('hidden', false);
        check($field);
    });
    $(document.body).on('reset_data hide_variation', 'form.variations_form', function () {
        $(this).find('[data-stackless-pwyl][data-variable]').prop('hidden', true).find('[data-stackless-pwyl-amount]').prop('disabled', true);
    });
    $('[data-stackless-pwyl][data-variable] [data-stackless-pwyl-amount]').prop('disabled', true);
});
