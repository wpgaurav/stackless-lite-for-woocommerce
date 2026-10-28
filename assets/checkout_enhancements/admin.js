/* Stackless for WooCommerce: Checkout Enhancements settings (consent editor and media picker). */
(function ($) {
    'use strict';
    var cfg = window.stacklessCeAdmin || {};
    var list = document.querySelector('[data-ce-consents]');
    var template = document.getElementById('stackless-ce-consent-template');

    function refreshEmpty() {
        var empty = document.querySelector('[data-ce-empty]');
        var rows = list ? list.querySelectorAll('[data-ce-consent]:not([hidden])').length : 0;
        if (empty) {
            empty.hidden = rows > 0;
        }
        document.querySelectorAll('[data-ce-add]').forEach(function (button) {
            button.disabled = rows >= (cfg.max || 10);
        });
    }

    function bind(row) {
        var type = row.querySelector('[data-ce-type]');
        var scope = row.querySelector('[data-ce-scope]');
        var product = row.querySelector('[data-ce-product]');
        var required = row.querySelector('[data-ce-required-toggle]');
        var badge = row.querySelector('[data-ce-required]');
        var title = row.querySelector('[data-ce-consent-title]');
        type.addEventListener('change', function () {
            title.textContent = (cfg.types || {})[type.value] || type.value;
        });
        scope.addEventListener('change', function () {
            product.hidden = scope.value !== 'product';
        });
        required.addEventListener('change', function () {
            badge.hidden = !required.checked;
        });
        row.querySelector('[data-ce-remove]').addEventListener('click', function () {
            if (!window.confirm(cfg.confirmRemove || 'Remove?')) {
                return;
            }
            row.querySelector('[data-ce-delete]').value = '1';
            row.hidden = true;
            refreshEmpty();
        });
    }

    if (list && template) {
        list.querySelectorAll('[data-ce-consent]').forEach(bind);
        var counter = Date.now();
        document.querySelectorAll('[data-ce-add]').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = 'new' + (counter++);
                var html = template.innerHTML.replace(/__index__/g, key);
                var wrap = document.createElement('div');
                wrap.innerHTML = html;
                var row = wrap.firstElementChild;
                var preset = (cfg.presets || {})[button.dataset.ceAdd] || {};
                row.querySelector('[data-ce-type]').value = button.dataset.ceAdd;
                row.querySelector('[data-ce-consent-title]').textContent = (cfg.types || {})[button.dataset.ceAdd] || '';
                row.querySelector('[data-ce-label]').value = preset.label || '';
                row.querySelector('[data-ce-scope]').value = preset.scope || 'all';
                row.querySelector('[data-ce-required-toggle]').checked = !!preset.required;
                row.querySelector('[data-ce-required]').hidden = !preset.required;
                var search = row.querySelector('.stackless-ce-product-search');
                if (search) {
                    search.classList.remove('stackless-ce-product-search');
                    search.classList.add('wc-product-search');
                }
                list.appendChild(row);
                bind(row);
                $(document.body).trigger('wc-enhanced-select-init');
                refreshEmpty();
                row.querySelector('[data-ce-label]').focus();
            });
        });
        refreshEmpty();
    }

    var media = document.querySelector('[data-ce-media]');
    if (media && window.wp && wp.media) {
        var frame;
        var input = media.querySelector('[data-ce-media-id]');
        var preview = media.querySelector('[data-ce-media-preview]');
        var clear = media.querySelector('[data-ce-media-clear]');
        media.querySelector('[data-ce-media-choose]').addEventListener('click', function () {
            if (!frame) {
                frame = wp.media({ title: cfg.mediaTitle, library: { type: 'image' }, button: { text: cfg.mediaButton }, multiple: false });
                frame.on('select', function () {
                    var selected = frame.state().get('selection').first().toJSON();
                    input.value = selected.id;
                    preview.src = selected.sizes && selected.sizes.medium ? selected.sizes.medium.url : selected.url;
                    preview.hidden = false;
                    clear.hidden = false;
                });
            }
            frame.open();
        });
        clear.addEventListener('click', function () {
            input.value = '0';
            preview.removeAttribute('src');
            preview.hidden = true;
            clear.hidden = true;
        });
    }
}(jQuery));
