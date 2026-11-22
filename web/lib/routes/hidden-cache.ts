import { backendUrl } from '@/lib/backend-url';
import { NOTHING_HIDDEN, parseHiddenIds, type HiddenIds } from '@/lib/routes/hidden';

/**
 * The hidden ids as the route guard (proxy.ts) sees them (#2386).
 *
 * Read from the backend's public `GET /api/v1/navigation/hidden` and kept for
 * HIDDEN_TTL_MS, so a change on the settings screen reaches the guard within
 * that time without a request to the backend per page.
 *
 * Fails open: when the backend cannot be asked, the last list it gave is used,
 * and before it has given one nothing is hidden. Hiding is not access control.
 * Every page's data stays behind its own permission checks, and a backend that
 * cannot answer this cannot serve the page's data either.
 */
export const HIDDEN_TTL_MS = 30_000;
const FETCH_TIMEOUT_MS = 2_000;
/** After a failed read, ask again this much sooner than a full interval. */
const RETRY_AFTER_MS = 5_000;

let cached: { ids: HiddenIds; at: number } | null = null;
let inFlight: Promise<HiddenIds> | null = null;

export async function hiddenIdsForGuard(): Promise<HiddenIds> {
  if (cached !== null && Date.now() - cached.at < HIDDEN_TTL_MS) return cached.ids;
  inFlight ??= (async () => {
    try {
      const response = await fetch(`${backendUrl()}/api/v1/navigation/hidden`, {
        signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
        cache: 'no-store',
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const ids = parseHiddenIds(await response.json());
      cached = { ids, at: Date.now() };
      return ids;
    } catch {
      const ids = cached?.ids ?? NOTHING_HIDDEN;
      cached = { ids, at: Date.now() - HIDDEN_TTL_MS + RETRY_AFTER_MS };
      return ids;
    } finally {
      inFlight = null;
    }
  })();
  return inFlight;
}

/** Test seam: forget the cached list. */
export function resetHiddenIdsCache(): void {
  cached = null;
  inFlight = null;
}
