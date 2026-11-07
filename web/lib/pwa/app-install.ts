import 'server-only';
import { createHash } from 'node:crypto';
import { cache } from 'react';
import { fetchBranding } from '@/lib/branding';
import { type AppInstall, WHITY_BLUE } from '@/lib/pwa/shared';

/**
 * Whether this request's host offers the web app as an installable app
 * (#1733), and the branding the manifest, icons and offline page are drawn
 * from.
 *
 * Read from `GET /api/v1/branding`, which `getBranding()` already fetches for
 * the layout, so it costs no extra round trip. The switch is an INSTANCE
 * setting (`app.installable`, else `PWA_ENABLED`, else on); the name, colour
 * and logo follow the host, so a tenant's custom domain installs as that
 * tenant's app.
 *
 * `unknown` when the backend did not answer. That is deliberately not "off":
 * a page rendered during a backend blip must not unregister every reader's
 * service worker, and it must not install one the operator switched off.
 * Nothing changes until an answer comes back.
 */
export const getAppInstall = cache(async (): Promise<AppInstall> => {
  const d = await fetchBranding();
  if (!d || typeof d.siteName !== 'string') {
    return { state: 'unknown', siteName: 'Whity', themeColor: WHITY_BLUE, logoSquareUrl: null, version: '' };
  }

  const mainColor = typeof d.mainColor === 'string' && /^#[0-9a-fA-F]{6}$/.test(d.mainColor) ? d.mainColor.toLowerCase() : null;
  const logoSquareUrl = typeof d.logoSquareUrl === 'string' ? d.logoSquareUrl : null;
  const themeColor = mainColor ?? WHITY_BLUE;

  return {
    // An older backend does not send the field at all. Absent means the switch
    // predates it, and the default is on.
    state: d.installable === false ? 'off' : 'on',
    siteName: d.siteName,
    themeColor,
    logoSquareUrl,
    // Changes whenever anything drawn on the icons changes, so an installed
    // app's icon URLs change with the branding instead of staying cached.
    version: createHash('sha256').update(`${d.siteName}|${themeColor}|${logoSquareUrl ?? ''}`).digest('hex').slice(0, 10),
  };
});
