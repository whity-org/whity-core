/* Whity plans (WS.5): the plans section is built into the page; this script refreshes it from Whity's
   backend. Order: live data, then the last good copy saved in this browser (up to 14 days), then the built-in
   page. Nothing here is needed for the page to work. */
(function () {
  'use strict';
  var section = document.querySelector('[data-plans-src]');
  if (!section) return;
  var SRC = section.getAttribute('data-plans-src');
  var LANG = section.getAttribute('data-plans-lang') === 'ar' ? 'ar' : 'en';
  var KEY = 'whity.plans.v1';
  var MAX_AGE = 14 * 24 * 3600 * 1000;
  var TIMEOUT = 3000;

  var T = {
    en: { month: 'a month', people: function (n) { return 'Up to ' + num(n) + ' people'; }, customers: function (n) { return num(n) + ' customers a month'; } },
    ar: { month: 'شهرياً', people: function (n) { return 'حتى ' + num(n) + ' ' + arCount(n, 'شخص', 'أشخاص', 'شخصاً'); }, customers: function (n) { return num(n) + ' ' + arCount(n, 'عميل', 'عملاء', 'عميلاً') + ' شهرياً'; } }
  };

  // Western digits in every language (brand rule).
  function num(n) { return Number(n).toLocaleString('en-US'); }
  // Arabic counted nouns: 3–10 take the plural, 11–99 the accusative singular, hundreds and thousands the singular.
  function arCount(n, one, few, many) {
    var r = n % 100;
    if (n >= 3 && r >= 3 && r <= 10) return few;
    if (r >= 11 && r <= 99) return many;
    return one;
  }
  function money(v, data) {
    if (v === null || v === undefined) return data.currency + ' [price]';
    if (Number(v) === 0) return data.currency + ' 0';
    return data.currency + ' ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: data.decimals, maximumFractionDigits: data.decimals });
  }
  function t(o) { return o && typeof o === 'object' ? (o[LANG] || o.en || '') : ''; }
  function isText(o) { return o && typeof o.en === 'string' && o.en.length > 0 && o.en.length < 120; }
  function isPrice(v) { return v === null || (typeof v === 'string' && /^\d{1,6}(\.\d{1,3})?$/.test(v)); }

  function valid(d) {
    if (!d || d.version !== 1 || typeof d.currency !== 'string' || !/^[A-Z]{3}$/.test(d.currency)) return false;
    if (typeof d.decimals !== 'number' || d.decimals < 0 || d.decimals > 3) return false;
    if (!Array.isArray(d.plans) || d.plans.length < 1 || d.plans.length > 4) return false;
    return d.plans.every(function (p) {
      return typeof p.id === 'string' && isText(p.name) && isText(p.tagline) && p.price && isPrice(p.price.monthly) &&
        p.limits && Number.isInteger(p.limits.staff) && Number.isInteger(p.limits.customersPerMonth) &&
        Array.isArray(p.features) && p.features.length <= 6 && p.features.every(isText) &&
        p.cta && isText(p.cta.label) && typeof p.cta.href === 'string' && /^https:\/\//.test(p.cta.href);
    }) && (!d.dedicatedHosting || isPrice(d.dedicatedHosting.fromMonthly));
  }

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  function render(d, source) {
    var box = section.querySelector('.s-plans');
    if (!box) return;
    var frag = document.createDocumentFragment();
    d.plans.forEach(function (p) {
      var card = el('div', 's-plan' + (p.highlight ? ' is-pop' : ''));
      card.setAttribute('data-plan', p.id);
      card.appendChild(el('h3', null, t(p.name)));
      var tag = el('p', null, t(p.tagline)); tag.style.color = 'var(--muted-foreground)'; card.appendChild(tag);
      var price = el('div', 's-price', money(p.price.monthly, d) + ' '); price.setAttribute('dir', 'ltr');
      price.appendChild(el('small', null, T[LANG].month)); card.appendChild(price);
      var ul = el('ul');
      ul.appendChild(el('li', null, T[LANG].people(p.limits.staff)));
      ul.appendChild(el('li', null, T[LANG].customers(p.limits.customersPerMonth)));
      p.features.forEach(function (f) { ul.appendChild(el('li', null, t(f))); });
      card.appendChild(ul);
      var a = el('a', 's-btn' + (p.highlight ? ' is-main' : ''), t(p.cta.label)); a.href = p.cta.href; card.appendChild(a);
      frag.appendChild(card);
    });
    box.textContent = '';
    box.appendChild(frag);
    var host = section.querySelector('[data-bind="hosting-from"]');
    if (host && d.dedicatedHosting) host.textContent = money(d.dedicatedHosting.fromMonthly, d);
    section.setAttribute('data-plans-source', source);
  }

  function saved() {
    try {
      var s = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (s && Date.now() - s.at < MAX_AGE && valid(s.data)) return s.data;
    } catch (e) {}
    return null;
  }
  function save(d) { try { localStorage.setItem(KEY, JSON.stringify({ at: Date.now(), data: d })); } catch (e) {} }
  function fallback() { var s = saved(); if (s) render(s, 'saved'); else section.setAttribute('data-plans-source', 'built-in'); }

  if (!window.fetch) { fallback(); return; }
  var ctrl = window.AbortController ? new AbortController() : null;
  var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, TIMEOUT);
  fetch(SRC, { headers: { Accept: 'application/json' }, credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (d) { clearTimeout(timer); if (!valid(d)) throw new Error('invalid'); render(d, 'live'); save(d); })
    .catch(function () { clearTimeout(timer); fallback(); });
})();
