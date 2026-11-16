/* Whity Lottie player (LT.2, LT.3). Needs lottie-web's light build (window.lottie).
   <div class="w-lottie" data-lottie="assets/lottie/approve-set-down.json" data-play="view|manual|loop"></div>
   - Colours: every fill/stroke named "c:<token>" takes the live CSS token (dark mode, each workspace's colour).
   - view: plays once when scrolled into view; click or Enter replays. Never loops.
   - loop: only for real loading states.
   - manual: inside [data-lottie-scope], buttons [data-lottie-do="approve"|"undo"] play it forwards or backwards.
   - Less motion asked for (prefers-reduced-motion): shows the end state, and the buttons jump between states.
   - Without the library, the page keeps its fallback (.w-lottie__fallback) and the buttons still switch states.
   - In the app (LT.4): <html data-motion="full|calm|off"> is the person's Animations setting. Calm and Off show the end state
     (Calm fades it in); loops hold still. data-once="key" plays a moment once per session, then shows its end state. */
(function () {
  'use strict';
  var TOK = { primary: '--primary', plane: '--plane-deep', card: '--card', line: '--border', muted: '--muted', ink: '--foreground',
              soft: '--muted-foreground', ok: '--success', tint: '--primary-tint', tenant: '--tenant-color', onprimary: '--primary-foreground' };
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  // "Less motion" is the device setting or the person's Animations setting (Calm or Off).
  var reduce = { get matches() { var m = document.documentElement.getAttribute('data-motion'); return mq.matches || m === 'calm' || m === 'off'; } };
  function seen(el) { var k = el.getAttribute('data-once'); if (!k) return false; try { return sessionStorage.getItem('whity.once.' + k) === '1'; } catch (e) { return false; } }
  function markSeen(el) { var k = el.getAttribute('data-once'); if (!k) return; try { sessionStorage.setItem('whity.once.' + k, '1'); } catch (e) {} }
  var cache = {}, ctx = null;

  function rgb(v) {
    if (!v) return null;
    try {
      if (!ctx) { var c = document.createElement('canvas'); c.width = c.height = 1; ctx = c.getContext('2d', { willReadFrequently: true }); }
      ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = '#000'; ctx.fillStyle = v.trim(); ctx.fillRect(0, 0, 1, 1);
      var d = ctx.getImageData(0, 0, 1, 1).data;
      return [d[0] / 255, d[1] / 255, d[2] / 255, 1];
    } catch (e) { return null; }
  }
  function recolour(data, el) {
    var cs = getComputedStyle(el), map = {};
    Object.keys(TOK).forEach(function (k) { var v = cs.getPropertyValue(TOK[k]); if (v && v.trim()) map[k] = rgb(v); });
    (function walk(o) {
      if (Array.isArray(o)) { o.forEach(walk); return; }
      if (!o || typeof o !== 'object') return;
      if ((o.ty === 'fl' || o.ty === 'st') && typeof o.nm === 'string' && o.nm.indexOf('c:') === 0) {
        var c = map[o.nm.slice(2)]; if (c && o.c && o.c.a === 0) o.c.k = c;
      }
      for (var k in o) if (o.hasOwnProperty(k) && typeof o[k] === 'object') walk(o[k]);
    })(data);
    return data;
  }
  function getData(src) {
    if (window.WHITY_LOTTIE && window.WHITY_LOTTIE[src]) return Promise.resolve(window.WHITY_LOTTIE[src]);
    if (!cache[src]) cache[src] = fetch(src).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
    return cache[src];
  }
  function scopeOf(el) { return el.closest('[data-lottie-scope]') || el.parentNode; }
  function say(scope, key) {
    var live = scope.querySelector('[data-lottie-say]');
    var t = scope.getAttribute('data-say-' + key);
    if (live && t) live.textContent = t;
  }
  function setState(scope, st) { scope.setAttribute('data-state', st); }

  function mount(el) {
    if (el.__w) return;
    el.__w = { anim: null };
    var mode = el.getAttribute('data-play') || 'view';
    var scope = scopeOf(el);
    if (mode === 'manual') bindControls(el, scope);
    if (!window.lottie) return; // fallback stays
    var src = el.getAttribute('data-lottie');
    getData(src).then(function (raw) { build(el, raw, mode, scope, 0); }).catch(function () {});
  }

  function build(el, raw, mode, scope, frame) {
    var stage = el.querySelector('.w-lottie__stage');
    if (!stage) { stage = document.createElement('div'); stage.className = 'w-lottie__stage'; stage.setAttribute('aria-hidden', 'true'); el.appendChild(stage); }
    if (el.__w.anim) { el.__w.anim.destroy(); stage.textContent = ''; }
    var anim = window.lottie.loadAnimation({ container: stage, renderer: 'svg', loop: mode === 'loop', autoplay: false,
      animationData: recolour(JSON.parse(JSON.stringify(raw)), el), rendererSettings: { preserveAspectRatio: 'xMidYMid meet' } });
    el.__w.anim = anim; el.__w.raw = raw;
    anim.addEventListener('DOMLoaded', function () {
      el.classList.add('is-ready');
      var last = anim.totalFrames - 1;
      if (window.WHITY_LOTTIE_STATIC) { anim.goToAndStop(mode === 'manual' ? 0 : last, true); return; }
      if (mode === 'manual') {
        var approved = scope.getAttribute('data-state') === 'approved';
        anim.goToAndStop(frame != null && frame > 0 ? frame : (approved ? last : 0), true);
      } else if (mode === 'loop') {
        if (reduce.matches) anim.goToAndStop(0, true); else anim.play();
      } else {
        if (el.__w.played || reduce.matches || seen(el)) { anim.goToAndStop(last, true); if (document.documentElement.getAttribute('data-motion') === 'calm') el.classList.add('is-calm'); }
        else anim.goToAndStop(0, true), watch(el);
        if (!el.__w.click) { el.__w.click = 1; el.addEventListener('click', function () { if (!reduce.matches && el.__w.anim) el.__w.anim.goToAndPlay(0, true); }); }
      }
    });
  }

  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      if (e.isIntersecting && e.intersectionRatio > 0.35) {
        var el = e.target; io.unobserve(el); el.__w.played = true; markSeen(el);
        var dl = +el.getAttribute('data-delay') || 0;
        setTimeout(function () { if (el.__w.anim) el.__w.anim.goToAndPlay(0, true); }, dl);
      }
    });
  }, { threshold: [0, 0.35, 0.6] }) : null;
  function watch(el) { if (io) io.observe(el); else if (el.__w.anim) el.__w.anim.goToAndStop(el.__w.anim.totalFrames - 1, true); }

  function bindControls(el, scope) {
    scope.querySelectorAll('[data-lottie-do]').forEach(function (b) {
      b.addEventListener('click', function () {
        var act = b.getAttribute('data-lottie-do'), a = el.__w.anim;
        if (act === 'approve' && scope.getAttribute('data-state') !== 'approved') {
          setState(scope, 'approved'); say(scope, 'approved');
          if (a) { if (reduce.matches) a.goToAndStop(a.totalFrames - 1, true); else { a.setDirection(1); a.play(); } }
        } else if (act === 'undo' && scope.getAttribute('data-state') === 'approved') {
          setState(scope, 'waiting'); say(scope, 'undone');
          if (a) { if (reduce.matches) a.goToAndStop(0, true); else { a.setDirection(-1); a.play(); } }
        }
        var next = scope.querySelector(act === 'approve' ? '[data-lottie-do="undo"]' : '[data-lottie-do="approve"]');
        if (next) next.focus();
      });
    });
  }

  // Colour pickers: <button data-lottie-colour="#1a7f5a" data-lottie-target="#brand-anim">
  function bindColours(root) {
    root.querySelectorAll('[data-lottie-colour]').forEach(function (b) {
      if (b.__w) return; b.__w = 1;
      b.addEventListener('click', function () {
        var el = document.querySelector(b.getAttribute('data-lottie-target')); if (!el) return;
        el.style.setProperty('--tenant-color', b.getAttribute('data-lottie-colour'));
        (b.closest('[role="radiogroup"]') || document).querySelectorAll('[data-lottie-colour]').forEach(function (o) { o.setAttribute('aria-checked', o === b ? 'true' : 'false'); });
        if (el.__w && el.__w.raw) { el.__w.played = true; build(el, el.__w.raw, el.getAttribute('data-play') || 'view', scopeOf(el), 0); if (!reduce.matches) setTimeout(function () { el.__w.anim && el.__w.anim.goToAndPlay(0, true); }, 30); }
      });
    });
  }

  // Theme changes: rebuild with the new colours, keeping the frame.
  function refresh() {
    document.querySelectorAll('.w-lottie').forEach(function (el) {
      if (el.__w && el.__w.anim && el.__w.raw) { var f = el.__w.anim.currentFrame; build(el, el.__w.raw, el.getAttribute('data-play') || 'view', scopeOf(el), Math.round(f)); }
    });
  }
  if (window.matchMedia) { var dq = window.matchMedia('(prefers-color-scheme: dark)'); (dq.addEventListener ? dq.addEventListener('change', refresh) : dq.addListener(refresh)); }
  new MutationObserver(refresh).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-motion'] });
  // Something finished loading: stop a loop and show its end (call WhityLottie.done(el)).
  function done(el) { if (el && el.__w && el.__w.anim) { el.__w.anim.loop = false; el.__w.anim.goToAndStop(el.__w.anim.totalFrames - 1, true); } }

  function scan(root) { root = root || document; root.querySelectorAll('.w-lottie[data-lottie]').forEach(mount); bindColours(root); }
  window.WhityLottie = { scan: scan, refresh: refresh, done: done };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); }); else scan();
})();
