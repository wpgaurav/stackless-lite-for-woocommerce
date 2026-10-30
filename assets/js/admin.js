/* Stackless dashboard: instant module switches, search and filters. */
(function () {
  'use strict';
  var cfg = window.stacklessAdmin || {};
  var root = document.querySelector('.stackless-dashboard');
  if (!root) {
    return;
  }
  var search = document.getElementById('stackless-module-search');
  var count = document.getElementById('stackless-module-count');
  var empty = root.querySelector('.stackless-empty');
  var filter = 'all';

  function apply() {
    var q = search ? search.value.trim().toLowerCase() : '';
    var shown = 0;
    root.querySelectorAll('.stackless-group').forEach(function (group) {
      var visible = 0;
      group.querySelectorAll('.stackless-card').forEach(function (card) {
        var match = (q === '' || card.dataset.search.indexOf(q) !== -1) && (filter === 'all' || card.dataset.state === filter);
        card.hidden = !match;
        if (match) { visible++; }
      });
      group.hidden = visible === 0;
      shown += visible;
    });
    if (empty) { empty.hidden = shown !== 0; }
  }

  if (search) { search.addEventListener('input', apply); }
  root.querySelectorAll('.stackless-filter').forEach(function (btn) {
    btn.addEventListener('click', function () {
      filter = btn.dataset.filter;
      root.querySelectorAll('.stackless-filter').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      apply();
    });
  });

  root.addEventListener('click', function (e) {
    var sw = e.target.closest('.stackless-switch');
    if (!sw || sw.getAttribute('aria-busy') === 'true') {
      return;
    }
    var card = sw.closest('.stackless-card');
    var next = sw.getAttribute('aria-checked') !== 'true';
    sw.setAttribute('aria-busy', 'true');
    sw.setAttribute('aria-checked', next ? 'true' : 'false');

    var body = new URLSearchParams();
    body.append('action', 'stackless_toggle_module');
    body.append('nonce', cfg.nonce || '');
    body.append('module', sw.dataset.module);
    body.append('enabled', next ? '1' : '');

    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) { throw new Error('save'); }
        var on = !!res.data.enabled;
        card.classList.toggle('is-on', on);
        card.dataset.state = on ? 'on' : 'off';
        var link = card.querySelector('.stackless-card__link');
        if (link) {
          link.hidden = !res.data.settingsUrl;
          if (res.data.settingsUrl) { link.href = res.data.settingsUrl; }
        }
        var blocked = card.querySelector('.stackless-card__blocked');
        if (blocked && !on) { blocked.remove(); }
        if (count && cfg.i18n) {
          count.textContent = cfg.i18n.count.replace('%1$d', res.data.count).replace('%2$d', res.data.total);
        }
        apply();
        // A toast confirms the switch when the toast script is on this screen.
        var title = card.querySelector('.stackless-card__title');
        if (window.StacklessToast && title && cfg.i18n && cfg.i18n.turnedOn) {
          window.StacklessToast.success((on ? cfg.i18n.turnedOn : cfg.i18n.turnedOff).replace('%s', title.textContent.trim()));
        }
      })
      .catch(function () {
        sw.setAttribute('aria-checked', next ? 'false' : 'true');
        var message = (cfg.i18n && cfg.i18n.failed) || 'Could not save.';
        if (window.StacklessToast) { window.StacklessToast.error(message); } else { window.alert(message); }
      })
      .finally(function () { sw.removeAttribute('aria-busy'); });
  });
})();
