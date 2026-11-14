/**
 * The web app's API client: a thin wrapper over `@whity/api-client`.
 *
 * The package builds the typed openapi-fetch client (cookie mode: httpOnly
 * cookies, the `X-Requested-With` CSRF header, one shared refresh on a 401 and
 * a single replay). This module only adds what belongs to the browser app, all
 * of it in the `fetch` the package is handed:
 *
 * 1. The refresh goes through {@link refreshSession}, so every refresh in this
 *    tab and in other tabs is one refresh (rotated refresh tokens must not race).
 * 2. A REFUSED refresh announces {@link SESSION_EXPIRED_EVENT}; `AuthProvider`
 *    listens and signs the person out. A refresh that never reached the server
 *    says nothing about the session and announces nothing.
 * 3. A 402 (the payment wall) sends the reader to `/billing`, once per page load,
 *    never away from billing itself.
 * 4. The reference of the last 5xx is kept for the error page
 *    ({@link latestErrorReference}).
 *
 * Responses are never thrown; callers branch on `{ data, error, response }`.
 * {@link apiFetch} is the same path for the few URLs the spec can't name
 * (plugin routes built at run time); it returns the `Response`.
 *
 * The characterization tests in `__tests__/typed-api-client.test.ts` pin this
 * contract — change them only when the auth flow itself changes.
 */

import { createApiClient as createPackageClient } from '@whity/api-client';
import { refreshSession } from '@/lib/session-refresh';

export type { paths, components, operations } from '@whity/api-client';

/**
 * Fired once when a token refresh is REFUSED — the session is over.
 *
 * A browser event rather than a callback or a React import, because this module
 * is framework-free and is also used outside a provider tree (tests, the desktop
 * host). `AuthProvider` listens and clears the user, which is what lets
 * `AuthGate` do the redirect it already knows how to do.
 */
export const SESSION_EXPIRED_EVENT = 'whity:session-expired';

const REFRESH_PATH = '/api/v1/auth/refresh';

/** Paths whose 401 means wrong credentials, not an expired session. */
const NO_REFRESH_PATHS = [
  '/api/v1/login',
  '/api/v1/login/2fa',
  '/api/v1/auth/select-tenant',
  REFRESH_PATH,
  '/api/v1/auth/logout',
];

/**
 * Where a walled tenant is sent. The billing routes are exempt from the payment
 * wall, so that a tenant who can use nothing else can still reach the page that
 * un-walls them.
 */
const BILLING_PATH = '/billing';

/** A dashboard fires a dozen requests at once; redirect for the first 402 only. */
let walledRedirectStarted = false;

function redirectToBilling(): void {
  if (typeof window === 'undefined' || walledRedirectStarted) {
    return;
  }
  // A 402 from an unrelated call made on a billing page must not bounce the
  // reader off the page they need.
  if (window.location.pathname.startsWith(BILLING_PATH)) {
    return;
  }
  walledRedirectStarted = true;
  // `replace`: the page they could not use does not belong in history.
  window.location.replace(BILLING_PATH);
}

/**
 * The reference the server gave its last 5xx. The 500 page has no response to
 * read, so a crash after a failed call would otherwise have nothing to quote.
 */
let lastErrorReference: string | null = null;

export function latestErrorReference(): string | null {
  return lastErrorReference;
}

function announceSessionExpired(): void {
  // Guarded: this module is imported during SSR and in node tests.
  if (typeof window === 'undefined') {
    return;
  }
  window.dispatchEvent(new CustomEvent(SESSION_EXPIRED_EVENT));
}

const pathOf = (url: string): string => new URL(url, 'http://x').pathname;

type Transport = (request: Request) => Promise<Response>;

/** The browser app's rules, wrapped around a plain transport. */
function appFetch(send: Transport): Transport {
  return async (request) => {
    if (request.method === 'POST' && pathOf(request.url).endsWith(REFRESH_PATH)) {
      const outcome = await refreshSession(() => send(request));
      if (outcome === 'refused') {
        announceSessionExpired();
        return new Response(null, { status: 401 });
      }
      if (outcome === 'unreachable') {
        // The package treats a thrown refresh as "not renewed" and keeps the 401.
        throw new TypeError('The session refresh did not reach the server.');
      }
      return new Response(null, { status: 204 });
    }

    const response = await send(request);
    if (response.status >= 500) {
      lastErrorReference = response.headers?.get?.('X-Error-Reference') ?? lastErrorReference;
    }
    if (response.status === 402) {
      redirectToBilling();
    }
    return response;
  };
}

export interface CreateApiClientOptions {
  /**
   * Base URL prepended to the spec paths. The default empty string keeps
   * `/api/*` requests relative so they go through the Next.js proxy.
   */
  baseUrl?: string;
  /** Injectable fetch for tests; defaults to the global fetch. */
  fetch?: Transport;
}

const globalTransport: Transport = (request) => globalThis.fetch(request);

export function createApiClient(options: CreateApiClientOptions = {}) {
  return createPackageClient({
    baseUrl: options.baseUrl ?? '',
    fetch: appFetch(options.fetch ?? globalTransport),
  });
}

export type ApiClient = ReturnType<typeof createApiClient>;

/** The app-wide typed client. Import this from screens and hooks. */
export const api = createApiClient();

/**
 * The same session rules for a URL the spec can't name — a plugin's routes, or
 * a path assembled at run time. Prefer {@link api}: it types the path, the
 * parameters and the answer. Returns the `Response`, never throws on a status.
 */
export async function apiFetch(url: string, init: RequestInit = {}): Promise<Response> {
  return requestWith(globalTransport, url, init);
}

/** {@link apiFetch} over an injected transport (tests). */
export async function requestWith(
  transport: Transport,
  url: string,
  init: RequestInit = {}
): Promise<Response> {
  const send = appFetch(transport);
  const build = (): Request => {
    const headers = new Headers(init.headers);
    if (!headers.has('X-Requested-With')) {
      headers.set('X-Requested-With', 'XMLHttpRequest');
    }
    return new Request(new URL(url, base()), { ...init, headers, credentials: 'include' });
  };

  const first = build();
  const replay = first.clone();
  const response = await send(first);
  if (response.status !== 401 || NO_REFRESH_PATHS.some((p) => pathOf(first.url).endsWith(p))) {
    return response;
  }

  let renewed = false;
  try {
    const refreshed = await send(
      new Request(new URL(REFRESH_PATH, base()), {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
    );
    renewed = refreshed.ok;
  } catch {
    renewed = false;
  }

  return renewed ? send(replay) : response;
}

/** `Request` needs an absolute URL; in the browser that is this origin. */
function base(): string {
  return typeof window !== 'undefined' && window.location?.origin
    ? window.location.origin
    : 'http://localhost';
}
