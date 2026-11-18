'use client';

import type { PluginFeature } from '@/lib/plugin-features';
import { AdminHeader } from '@/components/admin/admin-header';
import { useTranslation } from '@whity/features/i18n';

/**
 * Generic "embed" screen (WC-246): iframes the plugin's own declared GET
 * route (`feature.embed.path`) into the admin shell — the sanctioned way for
 * a backend-only, deploy-copied plugin to ship a bespoke UI with ZERO edits
 * to this checkout (unlike `screen: 'custom'`, which requires registering a
 * component here).
 *
 * `feature.embed.path` is already the host-rewritten, versioned, same-origin
 * API path (e.g. `/api/v1/bom/tool`) — the browser requests it through the
 * SAME Next `/api/[...path]` proxy every other plugin call already goes
 * through, so auth cookies attach normally with zero new plumbing. The
 * framed response can be any self-contained HTML document (inline
 * `<script>`/`<style>`, its own CSP) — see
 * `Whity\Http\SecurityHeaders::respectingHandlerCsp()` on the backend, which
 * lets a handler-set CSP (and, when its `frame-ancestors` explicitly allows
 * it, the legacy `X-Frame-Options`) survive instead of being silently
 * overwritten by the strict JSON-API default.
 *
 * ISOLATED, NOT TRUSTED (WHIT-1217; finding 4 of
 * docs/design/VERIFIED-PLUGINS-SECURITY.md). The frame allows scripts and
 * forms and nothing that gives an origin back: no `allow-same-origin`. With
 * it, `allow-scripts` on a same-origin page let the plugin's page remove its
 * own sandbox, reach this page and call any API with the person's cookies, so
 * the "sandbox" was not one. Without it the page has an opaque origin: it
 * cannot read `parent.document`, the app's cookies or storage, and its own
 * calls to the API arrive with no session. The backend adds the same sandbox
 * to the page's CSP (`SecurityHeaders::respectingHandlerCsp()`), so opening
 * the route on its own in a tab does not run it as the app either.
 *
 * The frame's FIRST load is still the person's own authenticated GET, so a
 * plugin renders what it needs into the HTML on the server. A page that wants
 * to call the API after loading needs the postMessage bridge (a later step),
 * or should be a `screen: 'blocks'` screen. No first-party plugin declares an
 * embed screen.
 */

/** The only sandbox tokens an embedded plugin page gets. Exported for the test that pins it. */
export const EMBED_SANDBOX = 'allow-scripts allow-forms';
export function EmbedScreen({ feature }: { feature: PluginFeature }) {
  // Called before the early return: a hook must run on every render.
  const t = useTranslation('plugin');

  if (feature.embed === null) {
    return null;
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title={feature.label}
        description={t('feature.providedBy', 'Provided by the {plugin} plugin.', {
          plugin: feature.plugin,
        })}
      />
      <iframe
        src={feature.embed.path}
        title={feature.label}
        className="h-[70vh] w-full rounded-lg border border-border"
        sandbox={EMBED_SANDBOX}
        referrerPolicy="no-referrer"
      />
    </div>
  );
}
