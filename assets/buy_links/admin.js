/* Stackless for WooCommerce: Buy link box on the product screen. */
jQuery(function ($) {
    'use strict';
    var cfg = window.stacklessBuyLinks;
    var box = document.querySelector('[data-stackless-bl]');
    if (!cfg || !box) {
        return;
    }
    var status = box.querySelector('[data-bl-status]');
    var result = box.querySelector('[data-bl-result]');
    var output = box.querySelector('[data-bl-url]');
    var sign = box.querySelector('[data-bl-sign]');
    var expRow = box.querySelector('[data-bl-exp-row]');
    function say(text) {
        status.textContent = text || '';
    }
    function syncExpiry() {
        expRow.hidden = !(sign.checked || sign.disabled);
    }
    sign.addEventListener('change', syncExpiry);
    syncExpiry();

    box.querySelector('[data-bl-generate]').addEventListener('click', function () {
        var button = this;
        var variation = box.querySelector('[data-bl-variation]');
        var extra = $(box.querySelector('[data-bl-extra]')).val() || [];
        button.disabled = true;
        say('');
        $.post(cfg.ajaxUrl, {
            action: cfg.action,
            nonce: cfg.nonce,
            product_id: box.getAttribute('data-product'),
            variation_id: variation ? variation.value : '',
            qty: box.querySelector('[data-bl-qty]').value,
            extra: extra,
            coupon: box.querySelector('[data-bl-coupon]').value,
            clear: box.querySelector('[data-bl-clear]').value,
            to: box.querySelector('[data-bl-to]').value,
            sign: sign.checked || sign.disabled ? '1' : '0',
            exp: box.querySelector('[data-bl-exp]').value
        }).done(function (response) {
            if (response && response.success) {
                output.value = response.data.url;
                result.hidden = false;
                say(response.data.message);
                output.focus();
                output.select();
            } else {
                say((response && response.data && response.data.message) || cfg.error);
            }
        }).fail(function (xhr) {
            say((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || cfg.error);
        }).always(function () {
            button.disabled = false;
        });
    });

    box.querySelector('[data-bl-copy]').addEventListener('click', function () {
        var done = function () { say(cfg.copied); };
        var failed = function () { output.select(); say(cfg.copyFailed); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(output.value).then(done, failed);
            return;
        }
        output.select();
        try {
            if (document.execCommand('copy')) {
                done();
            } else {
                failed();
            }
        } catch (e) {
            failed();
        }
    });
});
