'use client';

/**
 * A plugin screen's customer token in the customer area (SDK 1.173,
 * WHIT-1424; design section 41 §9, CA.18).
 *
 * The area never calls a plugin with the person's session cookie: each
 * screen asks core for a five-minute token bound to this workspace that
 * opens only the plugin's routes declared `'customer' => true`. While the
 * screen is open, {@link useScreenAuthorization} makes `apiFetch` send that
 * token on exactly those routes, so the plugin's blocks work unchanged.
 */

import { useEffect, useState } from 'react';
import { addScopedAuthorization, api, type ScopedAuthorization } from '@/lib/api/client';

/** Renew this long before the token runs out. */
const MARGIN_SECONDS = 30;

/** `/api/v1/courses/mine/{id}` as a matcher; `/api/` and `/api/v1/` are the same route. */
export function pathMatcher(patterns: string[]): (path: string) => boolean {
  const bare = (p: string) => p.replace(/^\/api\/v\d+\//, '/api/').replace(/\/+$/, '');
  const regexes = patterns.map((p) => {
    const escaped = bare(p)
      .split(/(\{[^}]+\})/)
      .map((part) => (part.startsWith('{') ? '[^/]+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
      .join('');
    return new RegExp(`^${escaped}$`);
  });
  return (path) => {
    const candidate = bare(path);
    return regexes.some((r) => r.test(candidate));
  };
}

/** The token for one screen, cached until shortly before it expires. */
export function screenAuthorization(handle: string, screenId: string, apiPaths: string[], now: () => number = () => Date.now() / 1000): ScopedAuthorization {
  const matches = pathMatcher(apiPaths);
  let cached: { token: string; until: number } | null = null;
  let pending: Promise<string | null> | null = null;

  return {
    matches,
    token: async (fresh) => {
      if (!fresh && cached && cached.until - MARGIN_SECONDS > now()) return cached.token;
      if (!pending) {
        pending = api
          .POST('/api/v1/customer-area/{handle}/x/{screen}/token', { params: { path: { handle, screen: screenId } } })
          .then(({ data, response }) => {
            if (!response.ok || !data) return null;
            const issued = (data as { data: { token: string; expires_in: number } }).data;
            cached = { token: issued.token, until: now() + issued.expires_in };
            return issued.token;
          })
          .catch(() => null)
          .finally(() => {
            pending = null;
          });
      }
      return pending;
    },
  };
}

/**
 * Send the screen's token on its plugin's customer routes while mounted.
 * Answers true once registered, so the screen renders only then and its
 * first request already carries the token.
 */
export function useScreenAuthorization(handle: string, screenId: string | null, apiPaths: string[]): boolean {
  const [ready, setReady] = useState(false);
  const key = apiPaths.join('|');
  useEffect(() => {
    if (screenId === null) return undefined;
    const remove = addScopedAuthorization(screenAuthorization(handle, screenId, key === '' ? [] : key.split('|')));
    const flip = Promise.resolve().then(() => setReady(true));
    void flip;
    return () => {
      remove();
      setReady(false);
    };
  }, [handle, screenId, key]);

  return ready;
}
