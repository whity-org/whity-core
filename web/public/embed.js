/*
 * Whity booking widget (WHIT-1445, design section 39 §9.1, AP.24).
 *
 * Paste on your website:
 *   <script src="https://app.example.com/embed.js" data-workspace="@example-bakery" async></script>
 *
 * It adds a "Book a time" button where the tag is (data-button="none" leaves it
 * out; any element with data-whity-book opens it too), and opens the booking
 * page in an overlay. The page shows only on the sites the workspace listed
 * under Settings > Calendar > Your website. Nothing is read from your page.
 *
 * window.WhityBooking.open() and .close() are there for your own buttons.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script || window.WhityBooking) return;
  var handle = (script.getAttribute('data-workspace') || '').replace(/^@/, '').toLowerCase();
  if (!/^[a-z0-9][a-z0-9-]{0,254}$/.test(handle)) return;
  var base;
  try {
    base = new URL(script.src).origin;
  } catch (e) {
    return;
  }
  var lang = (script.getAttribute('data-lang') || document.documentElement.lang || '').slice(0, 2).toLowerCase() === 'ar' ? 'ar' : 'en';
  var words = {
    en: { book: 'Book a time', close: 'Close', title: 'Book a time' },
    ar: { book: 'احجز موعدًا', close: 'إغلاق', title: 'احجز موعدًا' }
  }[lang];
  var overlay = null;
  var opener = null;

  function close() {
    if (!overlay) return;
    overlay.parentNode.removeChild(overlay);
    overlay = null;
    document.removeEventListener('keydown', onKey, true);
    if (opener && opener.focus) opener.focus();
  }

  function onKey(e) {
    if (e.key === 'Escape') close();
  }

  function open() {
    if (overlay) return;
    opener = document.activeElement;
    overlay = document.createElement('div');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', words.title);
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);padding:16px;';
    var frame = document.createElement('iframe');
    frame.src = base + '/book/' + handle + '/embed';
    frame.title = words.title;
    frame.setAttribute('allow', 'payment');
    frame.style.cssText = 'width:100%;max-width:720px;height:100%;max-height:900px;border:0;border-radius:12px;background:Canvas;';
    var shut = document.createElement('button');
    shut.type = 'button';
    shut.textContent = words.close;
    shut.setAttribute('aria-label', words.close);
    shut.style.cssText = 'position:absolute;top:12px;inset-inline-end:12px;min-height:44px;min-width:44px;padding:0 16px;font:inherit;font-size:16px;border-radius:8px;cursor:pointer;';
    shut.addEventListener('click', close);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });
    overlay.appendChild(frame);
    overlay.appendChild(shut);
    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKey, true);
    shut.focus();
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== base || !e.data || e.data.type !== 'whity-booking') return;
    if (e.data.event === 'close') close();
  });

  document.addEventListener('click', function (e) {
    var target = e.target && e.target.closest ? e.target.closest('[data-whity-book]') : null;
    if (target) {
      e.preventDefault();
      open();
    }
  });

  if (script.getAttribute('data-button') !== 'none') {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'whity-book-button';
    button.textContent = script.getAttribute('data-label') || words.book;
    button.style.cssText = 'min-height:44px;padding:0 20px;font:inherit;font-size:16px;border-radius:8px;cursor:pointer;';
    button.addEventListener('click', open);
    script.parentNode.insertBefore(button, script.nextSibling);
  }

  window.WhityBooking = { open: open, close: close };
})();
