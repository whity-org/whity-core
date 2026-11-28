import { backendUrl } from '@/lib/backend-url';

/**
 * Who may show a workspace's booking page in their own pages (WHIT-1445,
 * design section 39 §9.1, AP.24): the `frame-ancestors` of
 * `/book/{workspace}/embed`, from the sites the workspace lists in
 * Settings › Calendar › Your website.
 *
 * Read from the backend's public `GET /api/v1/public/booking/{workspace}/embed`
 * and kept for EMBED_TTL_MS per workspace. Fails closed: when the backend
 * cannot be asked, or answers something that isn't a list of https origins,
 * the page may be framed by nobody (`'none'`).
 */
export const EMBED_TTL_MS = 60_000;
const FETCH_TIMEOUT_MS = 2_000;
const NOBODY = "'none'";
const ORIGIN = /^https:\/\/[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+(?::\d{1,5})?$/;

const cache = new Map<string, { value: string; at: number }>();

/** `/book/example-bakery/embed` → `example-bakery`; null for any other path. */
export function embedWorkspace(pathname: string): string | null {
  const match = /^\/book\/([a-z0-9][a-z0-9-]{0,254})\/embed\/?$/.exec(pathname);
  return match === null ? null : match[1];
}

/**
 * The source list as the backend gave it, kept only when every entry is an
 * https origin; anything else is `'none'`.
 */
export function cleanAncestors(raw: unknown): string {
  if (typeof raw !== 'string') return NOBODY;
  const value = raw.trim();
  if (value === NOBODY) return NOBODY;
  const origins = value.split(/\s+/).filter((o) => o !== '');
  if (origins.length === 0 || origins.length > 10 || !origins.every((o) => ORIGIN.test(o))) return NOBODY;
  return origins.join(' ');
}

export async function frameAncestorsFor(workspace: string): Promise<string> {
  const hit = cache.get(workspace);
  if (hit !== undefined && Date.now() - hit.at < EMBED_TTL_MS) return hit.value;
  let value = NOBODY;
  try {
    const response = await fetch(`${backendUrl()}/api/v1/public/booking/${encodeURIComponent(workspace)}/embed`, {
      signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
      cache: 'no-store',
    });
    if (response.ok) {
      const body = (await response.json()) as { data?: { frame_ancestors?: unknown } };
      value = cleanAncestors(body.data?.frame_ancestors);
    }
  } catch {
    value = NOBODY;
  }
  if (cache.size > 500) cache.clear();
  cache.set(workspace, { value, at: Date.now() });
  return value;
}

/** Test seam: forget what was read. */
export function resetEmbedPolicyCache(): void {
  cache.clear();
}
