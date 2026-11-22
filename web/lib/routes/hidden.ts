/**
 * Which pages the operator hid, and whether a path is one of them
 * (whity-org/whity-core#2386).
 *
 * The server owns the list (`navigation.hidden_ids` and `WHITY_HIDDEN_NAV_IDS`,
 * see src/Core/Navigation/HiddenNavIds.php) and already leaves hidden items
 * out of `GET /api/navigation` and the settings tabs. This module answers the
 * other question, for a PATH: the route guard (proxy.ts) refuses a hidden page
 * with 404, and the screens that link to a page without going through the
 * server's menu (the palette's "New …" commands, the Settings area's own
 * links) drop the link.
 *
 * Paths map to ids through the route manifest (web/routes.json), so the
 * guard and the manifest a product reads can never disagree.
 */
import manifest from '@/routes.json';
import stableIds from '@/lib/routes/stable-ids.json';

export interface HiddenIds {
  /** Menu item ids, as on GET /api/navigation. */
  nav: readonly string[];
  /** Settings page ids, without the `settings.` prefix. */
  settings: readonly string[];
}

export const NOTHING_HIDDEN: HiddenIds = { nav: [], settings: [] };

interface ManifestRoute {
  path: string;
  area: string;
  nav_ids: string[];
  settings_ids: string[];
}

interface CompiledRoute extends ManifestRoute {
  re: RegExp;
  /** Literal segments first, so `/admin/x/forms-builder` wins over `/admin/x/:featureId`. */
  rank: number[];
}

function compile(route: ManifestRoute): CompiledRoute {
  const segments = route.path.split('/').filter(Boolean);
  const pattern = segments
    .map((seg) => {
      if (/^:[^*?]+\*\?$/.test(seg)) return '(?:/[^/]+)*';
      if (/^:[^*?]+\*$/.test(seg)) return '(?:/[^/]+)+';
      if (seg.startsWith(':')) return '/[^/]+';
      return '/' + seg.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    })
    .join('');
  return {
    ...route,
    re: new RegExp(`^${pattern || '/'}/?$`),
    rank: segments.map((seg) => (seg.startsWith(':') ? 0 : 1)),
  };
}

const ROUTES: CompiledRoute[] = (manifest.routes as ManifestRoute[])
  .filter((route) => route.area === 'protected' || route.area === 'editor')
  .map(compile)
  .sort((a, b) => {
    for (let i = 0; i < Math.max(a.rank.length, b.rank.length); i++) {
      const d = (b.rank[i] ?? -1) - (a.rank[i] ?? -1);
      if (d !== 0) return d;
    }
    return 0;
  });

const NAV_HREFS: Readonly<Record<string, string>> = stableIds.nav;

/** The manifest route a signed-in path belongs to, or null. */
export function routeFor(pathname: string): ManifestRoute | null {
  const path = pathname.split('?')[0].split('#')[0];
  return ROUTES.find((route) => route.re.test(path)) ?? null;
}

/**
 * Whether the page at `pathname` is hidden: one of its menu or settings ids is
 * hidden. A plugin screen (`/admin/x/<featureId>`) is also hidden by
 * `plugin-<featureId>`, and a core screen there by the core id whose link it is.
 */
export function isPathHidden(pathname: string, hidden: HiddenIds): boolean {
  if (hidden.nav.length === 0 && hidden.settings.length === 0) return false;
  const route = routeFor(pathname);
  if (route === null) return false;
  if (route.nav_ids.some((id) => hidden.nav.includes(id))) return true;
  if (route.settings_ids.some((id) => hidden.settings.includes(id))) return true;

  const screen = /^\/admin\/x\/([^/?#]+)/.exec(pathname)?.[1];
  if (screen !== undefined) {
    if (hidden.nav.includes(`plugin-${screen}`)) return true;
    const href = `/admin/x/${screen}`;
    if (Object.entries(NAV_HREFS).some(([id, h]) => h === href && hidden.nav.includes(id))) return true;
  }
  return false;
}

/** Parse the API body; anything unexpected reads as nothing hidden. */
export function parseHiddenIds(body: unknown): HiddenIds {
  const data = (body as { data?: { nav?: unknown; settings?: unknown } } | null)?.data;
  const list = (v: unknown) => (Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []);
  return { nav: list(data?.nav), settings: list(data?.settings) };
}
