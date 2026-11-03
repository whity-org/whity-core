/**
 * The one way this app renews a session, so it only ever does it once at a time.
 *
 * Refresh tokens rotate, and the server treats a rotated token presented again as
 * theft: `auth.refresh_token_reuse_detected`, and a 401. That is right, and it means
 * the browser must not race itself. An access token lasts 15 minutes, so a screen
 * that loads several things after that gets several 401s at once. When each of them
 * refreshed on its own, the first rotated the cookie and the others had already sent
 * the old one, so they were refused, and a refused refresh signs the person out.
 *
 * So there are two rules. Everything in this tab shares the refresh in flight, and
 * other tabs wait for it behind a lock. A tab that waited then sends the cookie the
 * first one was given, so it renews normally.
 *
 * The lock is only in secure contexts (https and localhost). Without one, tabs can
 * still race, and only this tab's sharing applies.
 *
 * NOT COVERED: a refresh whose response never arrives, such as a page closed mid-call.
 * The server has rotated and the browser still holds the old cookie, so the next
 * refresh is refused and the person signs in again. Only a server-side grace window
 * could soften that, and it would weaken reuse detection.
 */

export type RefreshOutcome = 'renewed' | 'refused' | 'unreachable';

const LOCK_NAME = 'whity-session-refresh';

let inFlight: Promise<RefreshOutcome> | null = null;

const postRefresh = (): Promise<Response> =>
  fetch('/api/v1/auth/refresh', {
    method: 'POST',
    credentials: 'include',
    // CSRF defense (WC-160): the backend rejects auth POSTs without this custom
    // header, which cross-site forms cannot set.
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });

async function attempt(send: () => Promise<Response>): Promise<RefreshOutcome> {
  try {
    const response = await send();
    if (response.ok) return 'renewed';

    // Only 401 and 403 are the server saying no. The refresh route also answers 429
    // when throttled, and a deploy answers 502 or 503, and neither says anything about
    // the session, so neither should sign somebody out.
    return response.status === 401 || response.status === 403 ? 'refused' : 'unreachable';
  } catch {
    // Never reached the server, so it says nothing about the session.
    return 'unreachable';
  }
}

async function acrossTabs(send: () => Promise<Response>): Promise<RefreshOutcome> {
  const locks = typeof navigator === 'undefined' ? undefined : navigator.locks;
  if (locks === undefined) {
    return attempt(send);
  }

  return locks.request(LOCK_NAME, () => attempt(send));
}

/**
 * Renew the session, joining the refresh already running if there is one.
 *
 * @param send How to make the call. Callers joining a running refresh don't use theirs.
 */
export function refreshSession(send: () => Promise<Response> = postRefresh): Promise<RefreshOutcome> {
  if (inFlight === null) {
    inFlight = acrossTabs(send).finally(() => {
      inFlight = null;
    });
  }

  return inFlight;
}
