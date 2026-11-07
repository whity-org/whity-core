import { buildInfo } from '@/lib/build-info';
import { getAppInstall } from '@/lib/pwa/app-install';
import { installName, killSwitchWorkerSource, serviceWorkerSource } from '@/lib/pwa/shared';

/**
 * `GET /sw.js` — the app's service worker (#1733), served from the root so its
 * scope is the whole app.
 *
 * When the operator has switched installing off, this serves a worker that
 * clears its caches and unregisters itself: browsers re-fetch the worker script
 * on their own, so this reaches phones that never open a page again.
 *
 * When the backend did not answer, 503: a failed update leaves whatever worker
 * a browser already has exactly as it was, which is the safe thing to do
 * without knowing which way the switch is set.
 */
export const dynamic = 'force-dynamic';

const HEADERS = {
  'Content-Type': 'text/javascript; charset=utf-8',
  // The browser must see each deploy's worker, so it is never cached.
  'Cache-Control': 'no-cache, no-store, must-revalidate',
  'Service-Worker-Allowed': '/',
};

export async function GET(): Promise<Response> {
  const install = await getAppInstall();

  if (install.state === 'unknown') {
    return new Response('/* backend unavailable */', { status: 503, headers: HEADERS });
  }
  if (install.state === 'off') {
    return new Response(killSwitchWorkerSource(), { headers: HEADERS });
  }

  const build = buildInfo();
  const source = serviceWorkerSource({
    // The build plus the branding: either changing ships a new worker.
    version: `${build.commit ?? 'dev'}-${install.version}`,
    cacheStatic: process.env.NODE_ENV === 'production',
    name: { en: installName(install.siteName, 'en'), ar: installName(install.siteName, 'ar') },
    themeColor: install.themeColor,
  });

  return new Response(source, { headers: HEADERS });
}
