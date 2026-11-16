/* whity.dev small details (WS.10). Everything here is optional: without it every page reads and works.
   - Sections ease in once as they come into view (10px, 400 ms), never for people who ask for less motion.
   - The hero: approving on the phone updates the web Home behind it, and Undo puts it back.
   - Works locally: the receipt switches between English and Arabic.
   - The nav gets a line under it once the page scrolls. */
(function () {
  'use strict';
  var d = document, root = d.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var still = !!window.WHITY_LOTTIE_STATIC;
  if (still) root.classList.add('is-still');

  // Reveal on scroll
  if (!reduce && !still && 'IntersectionObserver' in window) {
    root.classList.add('js-reveal');
    var targets = d.querySelectorAll('.s-sec .s-wrap > *, .s-final .s-wrap > *');
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
    }, { rootMargin: '0px 0px -8% 0px' });
    targets.forEach(function (t, i) { t.classList.add('s-reveal'); t.style.transitionDelay = (Math.min(i % 4, 3) * 60) + 'ms'; io.observe(t); });
    setTimeout(function () { targets.forEach(function (t) { t.classList.add('is-in'); }); }, 4000); // never leave anything hidden
  }

  // Hero: phone and web stay in step
  var hero = d.querySelector('[data-hero-sync]');
  if (hero) {
    var btn = hero.querySelector('[data-hero-approve]'), card = hero.querySelector('[data-hero-card]');
    var row = hero.querySelector('[data-hero-row]'), count = hero.querySelector('[data-hero-count]'), rowBtn = hero.querySelector('[data-hero-rowbtn]');
    var label = btn ? btn.textContent : '', undo = root.lang === 'ar' || (hero.closest('[lang="ar"]')) ? 'تراجع' : 'Undo';
    var live = d.createElement('span'); live.className = 's-sr'; live.setAttribute('aria-live', 'polite'); hero.appendChild(live);
    function set(on) {
      hero.setAttribute('data-approved', on ? 'true' : 'false');
      if (btn) btn.textContent = on ? undo : label;
      if (count) count.textContent = on ? '2' : '3';
      if (rowBtn) rowBtn.textContent = on ? '✓' : label;
      live.textContent = on ? (undo === 'Undo' ? 'Approved. The web Home updated too.' : 'تمت الموافقة. وتحدّثت الصفحة الرئيسية على الويب أيضاً.') : '';
    }
    if (btn) btn.addEventListener('click', function () { set(hero.getAttribute('data-approved') !== 'true'); });
  }

  // Receipt language
  d.querySelectorAll('.s-langtry').forEach(function (box) {
    box.querySelectorAll('[data-lang-set]').forEach(function (b) {
      b.addEventListener('click', function () {
        var l = b.getAttribute('data-lang-set');
        box.setAttribute('data-lang', l);
        box.querySelectorAll('[data-lang-set]').forEach(function (o) { o.setAttribute('aria-checked', o === b ? 'true' : 'false'); });
      });
    });
  });


  // Builders terminal: types once when seen; Copy copies the real commands
  d.querySelectorAll('[data-term]').forEach(function (t) {
    var copy = t.querySelector('[data-copy]'), lab = t.querySelector('[data-copy-label]'), again = t.querySelector('[data-term-again]');
    if (copy) copy.addEventListener('click', function () {
      var text = copy.getAttribute('data-copy'), done = function () { var o = lab.textContent; lab.textContent = root.lang === 'ar' || t.closest('[lang="ar"]:not([data-term])') ? 'نُسخ' : 'Copied'; setTimeout(function () { lab.textContent = o; }, 1600); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done); else { var a = d.createElement('textarea'); a.value = text; d.body.appendChild(a); a.select(); try { d.execCommand('copy'); } catch (e) {} a.remove(); done(); }
    });
    var cmds = [].slice.call(t.querySelectorAll('[data-type]')), full = cmds.map(function (c) { return c.getAttribute('data-type'); });
    function finish() { cmds.forEach(function (c, i) { c.textContent = full[i]; c.classList.remove('is-caret'); c.parentNode.classList.remove('is-wait'); }); t.classList.remove('is-typing'); t.classList.add('is-done'); }
    function play() {
      if (reduce || still) { finish(); return; }
      t.classList.remove('is-done'); t.classList.add('is-typing');
      cmds.forEach(function (c) { c.textContent = ''; c.parentNode.classList.add('is-wait'); });
      var ci = 0, k = 0;
      (function step() {
        if (ci >= cmds.length) { setTimeout(finish, 500); return; }
        var c = cmds[ci]; c.parentNode.classList.remove('is-wait'); c.classList.add('is-caret');
        if (k < full[ci].length) { k += 2; c.textContent = full[ci].slice(0, k); setTimeout(step, 18); }
        else { c.classList.remove('is-caret'); ci++; k = 0; setTimeout(step, 380); }
      })();
    }
    if (again) again.addEventListener('click', play);
    if (reduce || still || !('IntersectionObserver' in window)) { finish(); return; }
    var o = new IntersectionObserver(function (es) { if (es[0].isIntersecting) { o.disconnect(); play(); } }, { threshold: 0.5 }); o.observe(t);
  });


  // Footer: live status from health.whity.dev, if it answers within 3 seconds; otherwise it stays a plain link
  d.querySelectorAll('[data-status-src]').forEach(function (a) {
    if (!window.fetch) return;
    var ar = !!a.closest('[lang="ar"]'), ctrl = window.AbortController ? new AbortController() : null, tm = setTimeout(function () { ctrl && ctrl.abort(); }, 3000);
    var words = { ok: ['All of Whity is working', 'كل أجزاء Whity تعمل'], degraded: ['Some parts are slow', 'بعض الأجزاء بطيئة'], down: ['Some parts aren’t working', 'بعض الأجزاء لا تعمل'] };
    fetch(a.getAttribute('data-status-src'), { headers: { Accept: 'application/json' }, credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (s) { clearTimeout(tm); var k = s && s.status; if (!words[k]) return; a.setAttribute('data-status', k); a.querySelector('[data-status-text]').textContent = words[k][ar ? 1 : 0]; })
      .catch(function () { clearTimeout(tm); });
  });


  // Copy an address
  d.querySelectorAll('[data-copy-text]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy-text'), lab = b.querySelector('[data-copy-label]'), o = lab ? lab.textContent : '';
      var done = function () { if (lab) { lab.textContent = b.closest('[lang="ar"]') ? 'نُسخ' : 'Copied'; setTimeout(function () { lab.textContent = o; }, 1600); } };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, done); else done();
    });
  });


  // Partners: try your own brand on the sign-in page your clients would see
  d.querySelectorAll('[data-brandtry]').forEach(function (box) {
    var inp = box.querySelector('[data-bt-input]'), card = box.querySelector('[data-bt-card]'), def = box.getAttribute('data-bt-default');
    function bump() { if (reduce || still || !card) return; card.classList.remove('is-set'); void card.offsetWidth; card.classList.add('is-set'); }
    function name() {
      var v = (inp.value || '').trim() || def;
      box.querySelectorAll('[data-bt-name]').forEach(function (n) { n.textContent = v; });
      var ini = box.querySelector('[data-bt-initial]'); if (ini) ini.textContent = Array.from(v)[0].toUpperCase();
      var slug = v.toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9]+/g, '').slice(0, 20) || 'youragency';
      var s = box.querySelector('[data-bt-slug]'); if (s) s.textContent = slug;
    }
    var t; if (inp) { inp.addEventListener('input', function () { name(); clearTimeout(t); t = setTimeout(bump, 350); }); }
    box.querySelectorAll('[data-bt-colour]').forEach(function (b) {
      b.addEventListener('click', function () {
        box.style.setProperty('--bt', b.getAttribute('data-bt-colour'));
        box.querySelectorAll('[data-bt-colour]').forEach(function (o) { o.setAttribute('aria-checked', o === b ? 'true' : 'false'); });
        bump();
      });
    });
  });


  // Contact: who are you (tabs with a sliding highlight)
  d.querySelectorAll('[data-router]').forEach(function (r) {
    var btns = [].slice.call(r.querySelectorAll('[data-route-btn]')), ind = r.querySelector('.s-who__ind'), tabs = r.querySelector('.s-who__tabs');
    r.classList.add('is-js');
    function place(b) { if (!ind) return; ind.style.width = b.offsetWidth + 'px'; ind.style.height = b.offsetHeight + 'px'; ind.style.transform = 'translate(' + b.offsetLeft + 'px,' + b.offsetTop + 'px)'; }
    function pick(b, focus, first) {
      var k = b.getAttribute('data-route-btn');
      btns.forEach(function (o) { var on = o === b; o.setAttribute('aria-selected', on ? 'true' : 'false'); o.tabIndex = on ? 0 : -1; });
      r.querySelectorAll('[data-route]').forEach(function (p) { var on = p.getAttribute('data-route') === k; p.hidden = !on; if (on && !first) { p.classList.remove('is-new'); void p.offsetWidth; p.classList.add('is-new'); } });
      place(b); if (focus) b.focus();
      var f = d.querySelector('[data-contact-form]'), map = { partner: 'partners', 'new': 'question' };
      if (f && !first && map[k]) { var t = f.querySelector('input[name="topic"][value="' + map[k] + '"]'); if (t) t.checked = true; }
    }
    btns.forEach(function (b, i) {
      b.addEventListener('click', function () { pick(b); });
      b.addEventListener('keydown', function (e) {
        var rtl = getComputedStyle(r).direction === 'rtl', n = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (rtl && e.key === 'ArrowRight') ? i - 1 : i + 1;
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (rtl && e.key === 'ArrowLeft') ? i + 1 : i - 1;
        if (e.key === 'Home') n = 0; if (e.key === 'End') n = btns.length - 1;
        if (n !== null) { e.preventDefault(); pick(btns[(n + btns.length) % btns.length], true); }
      });
    });
    pick(btns[0], false, true);
    window.addEventListener('resize', function () { var s = r.querySelector('[aria-selected="true"]'); if (s) place(s); });
  });
  d.querySelectorAll('[data-topic-go]').forEach(function (a) { a.addEventListener('click', function () { var t = d.querySelector('input[name="topic"][value="' + a.getAttribute('data-topic-go') + '"]'); if (t) t.checked = true; }); });

  // Contact: the message form. Checks gently, sends, and keeps the message if sending fails.
  d.querySelectorAll('[data-contact-form]').forEach(function (f) {
    var sent = f.parentNode.querySelector('[data-sent]'), fail = f.querySelector('[data-fail]'), lab = f.querySelector('[data-send-label]'), lab0 = lab ? lab.textContent : '';
    var ar = !!f.closest('[lang="ar"]') || root.lang === 'ar';
    function bad(name, on) { var el = f.elements[name], box = el.closest('.s-field'), err = box.querySelector('.s-field__err'); box.classList.toggle('is-bad', on); el.setAttribute('aria-invalid', on ? 'true' : 'false'); err.textContent = on ? f.getAttribute('data-err-' + name) : ''; return on; }
    ['name', 'contact', 'message'].forEach(function (n) { f.elements[n].addEventListener('input', function () { if (f.elements[n].closest('.s-field').classList.contains('is-bad') && f.elements[n].value.trim()) bad(n, false); }); });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var first = null;
      ['name', 'contact', 'message'].forEach(function (n) { if (bad(n, !f.elements[n].value.trim()) && !first) first = f.elements[n]; });
      if (first) { first.focus(); return; }
      if (f.elements.website && f.elements.website.value) return;
      var data = {}; new FormData(f).forEach(function (v, k) { data[k] = v; });
      f.classList.add('is-sending'); fail.hidden = true; if (lab) lab.textContent = ar ? 'جارٍ الإرسال…' : 'Sending…';
      var ctrl = window.AbortController ? new AbortController() : null, tm = setTimeout(function () { ctrl && ctrl.abort(); }, 10000);
      fetch(f.action, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data), credentials: 'omit', signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { if (!r.ok) throw 0; clearTimeout(tm);
          var t = sent.querySelector('[data-sent-title]'), nm = (data.name || '').trim().split(/\s+/)[0];
          if (t && nm) t.textContent = t.getAttribute('data-tpl').replace('{name}', nm);
          f.hidden = true; sent.hidden = false; sent.focus(); f.reset(); })
        .catch(function () { clearTimeout(tm); fail.hidden = false; })
        .then(function () { f.classList.remove('is-sending'); if (lab) lab.textContent = lab0; });
    });
    var again = sent && sent.querySelector('[data-again]');
    if (again) again.addEventListener('click', function () { sent.hidden = true; f.hidden = false; f.elements.name.focus(); });
  });

  // Amman's time, wherever it's shown
  if (window.Intl) d.querySelectorAll('[data-amman]').forEach(function (tEl) {
    if (tEl.closest('[data-mega]')) return;
    var fmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Amman', hour: '2-digit', minute: '2-digit', hour12: false });
    var tick = function () { tEl.textContent = fmt.format(new Date()); }; tick(); setInterval(tick, 15000);
  });

  // Mega footer: sets down when it comes into view, a soft light follows the pointer, Amman's time, language and colours
  var mega = d.querySelector('[data-mega]');
  if (mega) {
    var parts = mega.querySelectorAll('.s-mega__strip, .s-mega__word');
    if (still || reduce || !('IntersectionObserver' in window)) parts.forEach(function (p) { p.classList.add('is-in'); });
    else {
      var mo = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('is-in'); mo.unobserve(e.target); } }); }, { threshold: 0.45 });
      parts.forEach(function (p) { mo.observe(p); });
      window.addEventListener('scroll', function late() { if (window.innerHeight + window.scrollY >= d.documentElement.scrollHeight - 4) { parts.forEach(function (p) { p.classList.add('is-in'); }); window.removeEventListener('scroll', late); } }, { passive: true });
    }
    if (!reduce && window.matchMedia && window.matchMedia('(pointer: fine)').matches) mega.addEventListener('pointermove', function (e) { var r = mega.getBoundingClientRect(); mega.style.setProperty('--mx', (e.clientX - r.left) + 'px'); mega.style.setProperty('--my', (e.clientY - r.top) + 'px'); });
    var tEl = mega.querySelector('[data-amman]');
    if (tEl && window.Intl) { var fmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Amman', hour: '2-digit', minute: '2-digit', hour12: false }); var last = ''; var tick = function () { var v = fmt.format(new Date()); if (v !== last) { tEl.textContent = v; if (last) { tEl.classList.remove('is-tick'); void tEl.offsetWidth; tEl.classList.add('is-tick'); } last = v; } }; tick(); setInterval(tick, 15000); }
    var navLang = d.querySelector('.s-nav .s-lang'), fl = mega.querySelector('[data-mirror-lang]');
    if (fl) { if (navLang) fl.href = navLang.getAttribute('href'); else fl.style.display = 'none'; }
    var KEY = 'whity.theme', saved = null; try { saved = localStorage.getItem(KEY); } catch (e) {}
    function applyTheme(k) { if (k === 'light' || k === 'dark') root.setAttribute('data-theme', k); else root.removeAttribute('data-theme'); mega.querySelectorAll('[data-theme-set]').forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-theme-set') === (k || 'auto') ? 'true' : 'false'); }); }
    if (saved === 'light' || saved === 'dark') applyTheme(saved);
    else { var cur = root.getAttribute('data-theme'); mega.querySelectorAll('[data-theme-set]').forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-theme-set') === (cur === 'light' || cur === 'dark' ? cur : 'auto') ? 'true' : 'false'); }); }
    mega.querySelectorAll('[data-theme-set]').forEach(function (b) { b.addEventListener('click', function () { var k = b.getAttribute('data-theme-set'); try { if (k === 'auto') localStorage.removeItem(KEY); else localStorage.setItem(KEY, k); } catch (e) {} applyTheme(k); }); });
  }

  // Nav line once scrolled
  var nav = d.querySelector('.s-nav');
  if (nav) {
    var onScroll = function () { nav.classList.toggle('is-scrolled', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }
})();
