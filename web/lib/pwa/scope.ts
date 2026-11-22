import manifest from '@/routes.json';

/**
 * Where the installed app's service worker and web manifest reach
 * (whity-org/whity-core#2386).
 *
 * The worker used to register for the whole origin (`/`). On an origin Whity
 * shares with another app, that worker also sat in front of the other app's
 * pages. Two changes:
 *
 *  - `WHITY_PWA_SCOPE` on the web container narrows the worker and the
 *    manifest to one path prefix, such as `/admin/`, so another app on the
 *    origin can register its own worker for `/`. Pages outside the prefix
 *    still work; they are just not part of the installed app. Turning the
 *    installable app off altogether stays `app.installable` / `PWA_ENABLED`.
 *  - The worker only ever answers navigations to Whity's own top-level paths
 *    ({@link ownTopLevelPaths}, from the route manifest). Anything else on the
 *    origin goes to the network as if no worker were there, and never gets
 *    Whity's offline page.
 *
 * Server-only in practice (it reads the environment); the scope reaches the
 * browser as a prop from the root layout.
 */
export const DEFAULT_PWA_SCOPE = '/';

/** `/` or a path prefix that starts and ends with `/`; anything else is the default. */
export function pwaScope(raw: string | undefined = process.env.WHITY_PWA_SCOPE): string {
  const value = raw?.trim() ?? '';
  return /^\/(?:[A-Za-z0-9._~-]+\/)*$/.test(value) ? value : DEFAULT_PWA_SCOPE;
}

/**
 * The first path segments Whity's pages live under (`admin`, `calendar`,
 * `login`, …), so the worker leaves every other path alone. The root page is
 * not one of them: on a shared origin `/` belongs to the other app.
 */
export function ownTopLevelPaths(): string[] {
  const segments = new Set<string>();
  for (const route of manifest.routes as { path: string; kind: string }[]) {
    if (route.kind !== 'page') continue;
    const first = route.path.split('/')[1];
    if (first && !first.startsWith(':')) segments.add(first);
  }
  return [...segments].sort();
}
