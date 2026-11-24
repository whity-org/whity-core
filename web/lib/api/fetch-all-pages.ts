/**
 * Exhaust a paginated list endpoint.
 *
 * Every core list endpoint is paginated (`PaginationParams`: 25 per page by
 * default, 100 maximum) and answers with `{ data, pagination }`. A screen that
 * needs the WHOLE set — a hierarchy, a picker, anything it will present as
 * complete — cannot get it from one request, and the common workaround of
 * asking for `per_page=100` only moves the cliff: at 101 rows it starts
 * dropping data again, silently, because a short page is indistinguishable
 * from a small dataset once the envelope is discarded.
 *
 * So this walks `pagination.totalPages` to the end and, crucially, reports
 * whether it got there. Callers MUST branch on `complete` — the whole point is
 * that a truncated set is never mistaken for the full one.
 *
 * Two entry points, one walk: {@link fetchAllPages} for a path the spec can't name
 * (a plugin's list, or one built at run time), {@link fetchAllPagesTyped} for the openapi-fetch client in
 * `lib/api/client`. They differ only in how a page is requested and how failure
 * is signalled (a non-ok `Response` versus an undefined `data`), so the
 * termination and completeness rules — the parts that are easy to get subtly
 * wrong — exist exactly once, in `walkPages` (now in @whity/plugin-sdk).
 */

import { apiFetch } from '@/lib/api/client';
import { MAX_PER_PAGE, walkPages, type FetchAllPagesResult } from '@whity/plugin-sdk';

/*
 * The walk, the envelope test and the limits live in @whity/plugin-sdk
 * (SDK 1.165, decision D8), so the desktop template and plugin frontends walk
 * pages exactly as the app does. This module keeps the app's two entry points:
 * `apiFetch` as the default requester, and the typed client.
 */
export { MAX_PER_PAGE, isPaginationEnvelope } from '@whity/plugin-sdk';
export type { FetchAllPagesResult, PaginationEnvelope } from '@whity/plugin-sdk';

/** A page requester: `apiFetch`, or a wrapper that adds a signal or a guard. */
type ApiClient = (url: string, options?: RequestInit) => Promise<Response>;

/**
 * Fetch every page of `path` and concatenate the `data` arrays.
 *
 * Pages go through `apiFetch` (same session rules as the typed client). A
 * caller that needs to add to each request — an abort signal, a hang guard —
 * passes its own requester first. A requester that is the old `useAuth()`
 * `apiClient` still works, so screens can drop it one at a time.
 *
 * @param path      List endpoint path; an existing query string is preserved.
 * @param perPage   Rows per request. Defaults to the server maximum.
 */
export async function fetchAllPages<T>(
  path: string,
  perPage?: number
): Promise<FetchAllPagesResult<T>>;
export async function fetchAllPages<T>(
  requester: ApiClient | null | undefined,
  path: string,
  perPage?: number
): Promise<FetchAllPagesResult<T>>;
export async function fetchAllPages<T>(
  first: string | ApiClient | null | undefined,
  second?: string | number,
  third?: number
): Promise<FetchAllPagesResult<T>> {
  const request: ApiClient = typeof first === 'function' ? first : apiFetch;
  const path = (typeof first === 'string' ? first : second) as string;
  const perPage =
    (typeof first === 'string' ? (second as number | undefined) : third) ?? MAX_PER_PAGE;
  const separator = path.includes('?') ? '&' : '?';

  return walkPages<T>(async (page, size) => {
    const response = await request(`${path}${separator}page=${page}&per_page=${size}`);
    if (!response.ok) {
      return { ok: false };
    }

    const body: unknown = await response.json();
    return {
      ok: true,
      rows: (body as { data?: T[] } | null)?.data,
      pagination: (body as { pagination?: unknown } | null)?.pagination,
    };
  }, perPage);
}

/**
 * What one `api.GET(...)` call resolves to. The typed client never throws on an
 * HTTP error — it leaves `data` undefined and puts the parsed body in `error` —
 * so an absent `data` is the only failure signal there is.
 */
export interface TypedPageResult<T> {
  data?: { data?: T[]; pagination?: unknown };
}

/**
 * Same walk for the typed client. It takes the request as a closure rather than
 * a path because openapi-fetch derives the response type from the path
 * literal — `api.GET('/api/v1/ous', …)` typed at the call site keeps that, a
 * path passed through this helper as a string would not.
 *
 * ```ts
 * const result = await fetchAllPagesTyped<Ou>((query) =>
 *   api.GET('/api/v1/ous', { params: { query } })
 * );
 * ```
 *
 * @param fetchPage Issues one request for the given page.
 * @param perPage   Rows per request. Defaults to the server maximum.
 */
export async function fetchAllPagesTyped<T>(
  fetchPage: (query: {
    page: number;
    per_page: number;
  }) => Promise<TypedPageResult<T>>,
  perPage: number = MAX_PER_PAGE
): Promise<FetchAllPagesResult<T>> {
  return walkPages<T>(async (page, size) => {
    const { data } = await fetchPage({ page, per_page: size });
    if (data === undefined) {
      return { ok: false };
    }
    return { ok: true, rows: data.data, pagination: data.pagination };
  }, perPage);
}
