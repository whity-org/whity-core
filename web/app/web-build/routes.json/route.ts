import manifest from '@/routes.json';
import { buildInfo } from '@/lib/build-info';

/**
 * `GET /web-build/routes.json` — every route this web build serves
 * (whity-org/whity-core#2386).
 *
 * The file is `web/routes.json`, checked in and verified against `app/` by
 * `scripts/route-manifest.mjs --check` before every build, and imported here so
 * it is part of the bundle: the image cannot serve a list that disagrees with
 * its own pages. The build identity rides along so a reader knows which
 * release the list describes.
 *
 * Unauthenticated, like `/web-build`: it names paths the app already answers
 * on, and a proxy in front of a shared origin needs it before anyone signs in.
 * The format is stable across minor releases (docs/wiki/Stable-Routes-And-Ids.md).
 */
export const dynamic = 'force-static';

export async function GET(): Promise<Response> {
  const build = buildInfo();
  return Response.json(
    {
      schema: manifest.schema,
      core_version: build.core_version,
      commit: build.commit,
      routes: manifest.routes,
    },
    { headers: { 'Cache-Control': 'public, max-age=300' } },
  );
}
