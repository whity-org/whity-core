import { getAppInstall } from '@/lib/pwa/app-install';
import { buildManifest, manifestLang } from '@/lib/pwa/shared';

/**
 * `GET /manifest.webmanifest` — the web app manifest for this host (#1733).
 *
 * A route handler rather than the `app/manifest.ts` convention because it
 * answers per HOST (a tenant's custom domain installs as that tenant), per
 * language (`?lang=ar` installs under the Arabic name, right to left), and not
 * at all when the operator has switched installing off.
 */
export const dynamic = 'force-dynamic';

export async function GET(request: Request): Promise<Response> {
  const install = await getAppInstall();
  if (install.state !== 'on') {
    return new Response('Not found', { status: 404, headers: { 'Cache-Control': 'no-store' } });
  }

  const lang = manifestLang(new URL(request.url).searchParams.get('lang'));

  return new Response(JSON.stringify(buildManifest(install, lang)), {
    headers: {
      'Content-Type': 'application/manifest+json; charset=utf-8',
      // Short: a branding change should reach an installed app soon.
      'Cache-Control': 'public, max-age=300',
      Vary: 'Host',
    },
  });
}
