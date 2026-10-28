/* Stackless for WooCommerce: "Name your price" box on the Cart and Checkout blocks. No build step: plain wp.element. */
(function () {
    'use strict';
    var wcg = window.wc || {};
    var wpg = window.wp || {};
    var checkout = wcg.blocksCheckout;
    var el = wpg.element;
    var data = wpg.data;
    if (!checkout || !el || !data || !wpg.plugins || !checkout.extensionCartUpdate || !checkout.ExperimentalOrderMeta) {
        return;
    }
    var e = el.createElement;
    var settings = (wcg.wcSettings && wcg.wcSettings.getSetting('stackless-pay-what-you-like_data', {})) || {};
    var ns = settings.namespace || 'stackless-pay-what-you-like';
    var i18n = settings.i18n || {};
    var decimalSep = settings.decimalSep || '.';
    var cartStore = (wcg.wcBlocksData && wcg.wcBlocksData.cartStore) || 'wc/store/cart';
    var validationStore = (wcg.wcBlocksData && wcg.wcBlocksData.validationStore) || 'wc/store/validation';

    // Same reading rules as the server (Amounts::parse).
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

    // Server amounts use "."; show them with the store's decimal separator.
    function display(amount) {
        return String(amount == null ? '' : amount).replace('.', decimalSep);
    }

    function messageFor(value, info) {
        var decimals = parseInt(info.decimals, 10) || 0;
        var amount = toMinor(value, decimals);
        if (amount === null) return i18n.invalid || '';
        if (amount < toMinor(info.min, decimals)) return (i18n.tooLow || '%s').replace('%s', info.min_label);
        if (amount > toMinor(info.max, decimals)) return (i18n.tooHigh || '%s').replace('%s', info.max_label);
        return '';
    }

    function Line(props) {
        var item = props.item;
        var info = props.info;
        var decimals = parseInt(info.decimals, 10) || 0;
        var errorId = 'stackless-pwyl-' + item.key;
        var inputId = 'stackless-pwyl-input-' + item.key;
        var valueState = el.useState(display(info.amount));
        var value = valueState[0];
        var setValue = valueState[1];
        var messageState = el.useState('');
        var message = messageState[0];
        var setMessage = messageState[1];
        var busyState = el.useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];
        var dirty = el.useRef(false);
        var timer = el.useRef(null);
        var storeError = data.useSelect(function (select) {
            var store = select(validationStore);
            return store && store.getValidationError ? store.getValidationError(errorId) : null;
        }, [errorId]);

        function block(text, hidden) {
            var store = data.dispatch(validationStore);
            if (store && store.setValidationErrors) {
                var errors = {};
                errors[errorId] = { message: text, hidden: !!hidden };
                store.setValidationErrors(errors);
            }
        }
        function unblock() {
            var store = data.dispatch(validationStore);
            if (store && store.clearValidationError) store.clearValidationError(errorId);
        }

        // A new amount from the server (another tab, a currency switch) replaces an untouched field.
        el.useEffect(function () {
            if (!dirty.current) setValue(display(info.amount));
        }, [info.amount]);
        el.useEffect(function () {
            if (info.error) block(info.error, false);
            else if (!dirty.current) unblock();
        }, [info.error]);
        el.useEffect(function () {
            return function () { clearTimeout(timer.current); unblock(); };
        }, []);

        function commit(next) {
            clearTimeout(timer.current);
            var problem = messageFor(next, info);
            setMessage(problem);
            if (problem) { block(problem, false); return; }
            if (toMinor(next, decimals) === toMinor(info.amount, decimals) && !info.error) {
                dirty.current = false;
                unblock();
                return;
            }
            // Hidden until Place order is pressed: the order waits for the new total.
            block(i18n.saving || '', true);
            setBusy(true);
            Promise.resolve(checkout.extensionCartUpdate({ namespace: ns, data: { key: item.key, amount: String(next) } }))
                .then(function () {
                    dirty.current = false;
                    unblock();
                    setMessage('');
                })
                .catch(function (err) {
                    var text = (err && err.message) || i18n.error || '';
                    setMessage(text);
                    block(text, false);
                })
                .then(function () { setBusy(false); });
        }

        function onChange(event) {
            var next = event.target.value;
            setValue(next);
            dirty.current = true;
            var problem = messageFor(next, info);
            setMessage(problem);
            block(problem || i18n.saving || '', !problem);
            clearTimeout(timer.current);
            if (!problem) {
                timer.current = setTimeout(function () { commit(next); }, 800);
            }
        }

        function onBlur() {
            if (dirty.current) commit(value);
        }

        var current = toMinor(value, decimals);
        var shown = message || info.error || (storeError && !storeError.hidden ? storeError.message : '');
        var symbol = info.symbol || settings.symbol || '';
        var input = e('input', {
            type: 'text',
            inputMode: 'decimal',
            autoComplete: 'off',
            id: inputId,
            value: value,
            onChange: onChange,
            onBlur: onBlur,
            'aria-invalid': shown ? 'true' : 'false',
            'aria-describedby': inputId + '-help'
        });
        return e('div', { className: 'stackless-pwyl stackless-pwyl--block', 'aria-busy': busy ? 'true' : 'false' },
            e('label', { className: 'stackless-pwyl__label', htmlFor: inputId }, item.name),
            e('div', { className: 'stackless-pwyl__input' },
                settings.symbolAfter ? null : e('span', { className: 'stackless-pwyl__symbol', 'aria-hidden': 'true' }, symbol),
                input,
                settings.symbolAfter ? e('span', { className: 'stackless-pwyl__symbol', 'aria-hidden': 'true' }, symbol) : null
            ),
            info.presets && info.presets.length ? e('div', { className: 'stackless-pwyl__presets', role: 'group', 'aria-label': i18n.quickAmounts || '' },
                info.presets.map(function (preset) {
                    return e('button', {
                        type: 'button',
                        key: preset.amount,
                        className: 'stackless-pwyl__preset',
                        disabled: busy,
                        'aria-pressed': toMinor(preset.amount, decimals) === current ? 'true' : 'false',
                        onClick: function () {
                            var next = display(preset.amount);
                            setValue(next);
                            dirty.current = true;
                            commit(next);
                        }
                    }, preset.label);
                })
            ) : null,
            e('p', { className: 'stackless-pwyl__help', id: inputId + '-help' }, info.help),
            shown ? e('p', { className: 'stackless-pwyl__status is-error', role: 'alert' }, shown) : null
        );
    }

    function Box() {
        var lines = data.useSelect(function (select) {
            var cart = select(cartStore).getCartData();
            var items = cart && Array.isArray(cart.items) ? cart.items : [];
            return items.filter(function (item) {
                var info = item.extensions ? item.extensions[ns] : null;
                return info && info.enabled;
            });
        }, []);
        if (!lines.length) {
            return null;
        }
        return e('section', { className: 'stackless-pwyl-box stackless-pwyl-box--block', 'aria-label': settings.heading || '' },
            e('p', { className: 'stackless-pwyl-box__title' }, e('strong', null, settings.heading || '')),
            settings.note ? e('p', { className: 'stackless-pwyl-box__note' }, settings.note) : null,
            lines.map(function (item) {
                return e(Line, { key: item.key, item: item, info: item.extensions[ns] });
            })
        );
    }

    // WooCommerce's order summary slot: the Cart block's totals and the Checkout block's summary.
    wpg.plugins.registerPlugin('stackless-pay-what-you-like', {
        scope: 'woocommerce-checkout',
        render: function () {
            return e(checkout.ExperimentalOrderMeta, null, e(Box));
        }
    });
}());
