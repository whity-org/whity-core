<?php

declare(strict_types=1);

namespace Whity\Sdk\Frontend;

/**
 * The page side of the embedded-screen bridge (SDK 1.150, #1688).
 *
 * An `embed` screen ({@see \Whity\Sdk\PluginFrontendInterface}) runs in a
 * sandboxed frame with an opaque origin: it cannot reach the app, the person's
 * cookies or storage, and a `fetch()` of its own to the API arrives with no
 * session. After its first load it talks to the app through the bridge instead,
 * protocol {@see self::PROTOCOL}. Put {@see self::script()} in the page, inside a
 * `<script>` element (the page's CSP must allow it, e.g. with a nonce), and use
 * the global it defines:
 *
 * ```js
 * const ctx = await whity.ready();          // { locale, dir, theme, apiBase }
 * const res = await whity.request('GET', '/api/bom/parts');
 * if (res.ok) render(res.body);
 * await whity.request('POST', '/api/bom/parts', { name: 'Bolt' });
 * whity.resize();                           // fit the frame to the page
 * whity.toast('Saved', 'success');          // success | error | warning | info
 * whity.navigate('/admin/x/bom-parts');     // an app page, never another site
 * ```
 *
 * What a request can reach: only the plugin's OWN routes. The app makes the call
 * with a five-minute token bound to the plugin that registered the screen's page,
 * and without the person's cookies; the route's own permission is checked against
 * the person as usual. A core route or another plugin's route answers 401. An
 * `/api/...` path without a version is sent at the same version as the screen's
 * page. The token never reaches the page.
 *
 * The answer is `{ ok, status, body }`: `body` is the parsed JSON when the route
 * answered JSON, else text. A path the app refuses, or a network failure, answers
 * `{ ok: false, status: 0, body: null, error }`. Requests time out after 30s.
 *
 * Keep the frame on the plugin's own pages: the app answers whatever page is in
 * the frame, so a page that sends the frame to another site hands that site the
 * same bridge.
 */
final class FrameBridge
{
    /** The `whity` field every bridge message carries. */
    public const PROTOCOL = 'frame/1';

    private function __construct()
    {
    }

    /**
     * The bridge client, as JavaScript for an inline `<script>`. It defines
     * `window.whity` with `ready()`, `request()`, `resize()`, `navigate()` and
     * `toast()`, and says `ready` to the app as soon as it runs.
     */
    public static function script(): string
    {
        $protocol = json_encode(self::PROTOCOL, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<JS
(function () {
  'use strict';
  var P = {$protocol}, parentWin = window.parent, seq = 0, waiting = {}, ctx = null, onCtx = [];
  function post(m) { m.whity = P; parentWin.postMessage(m, '*'); }
  window.addEventListener('message', function (e) {
    var d = e.data;
    if (e.source !== parentWin || !d || d.whity !== P) return;
    if (d.type === 'init') {
      ctx = { locale: d.locale, dir: d.dir, theme: d.theme, apiBase: d.apiBase };
      onCtx.splice(0).forEach(function (f) { f(ctx); });
    } else if (d.type === 'response' && waiting[d.id]) {
      var w = waiting[d.id]; delete waiting[d.id]; clearTimeout(w.t);
      w.resolve({ ok: !!d.ok, status: d.status, body: d.body, error: d.error });
    }
  });
  window.whity = {
    ready: function () {
      return ctx ? Promise.resolve(ctx) : new Promise(function (r) { onCtx.push(r); });
    },
    request: function (method, path, body) {
      var id = 'r' + (++seq);
      return new Promise(function (resolve) {
        var t = setTimeout(function () {
          delete waiting[id]; resolve({ ok: false, status: 0, body: null, error: 'timeout' });
        }, 30000);
        waiting[id] = { resolve: resolve, t: t };
        post({ type: 'request', id: id, method: method, path: path, body: body });
      });
    },
    resize: function (height) {
      var h = typeof height === 'number' ? height : document.documentElement.scrollHeight;
      post({ type: 'resize', height: h });
    },
    navigate: function (to) { post({ type: 'navigate', to: to }); },
    toast: function (message, tone) { post({ type: 'toast', message: message, tone: tone }); }
  };
  post({ type: 'ready' });
})();
JS;
    }
}
