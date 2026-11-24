/**
 * Requests to the host (SDK 1.165, decision D8): the page walker, moved from the
 * web app's `lib/api/fetch-all-pages.ts` so a plugin frontend and the desktop
 * template walk pages the same way.
 *
 * Every core list endpoint is paginated (25 per page by default, 100 at most)
 * and answers `{ data, pagination }`. A screen that needs the WHOLE set can't
 * get it from one request, and asking for `per_page=100` only moves the cliff:
 * at 101 rows it starts dropping data silently. So this walks
 * `pagination.totalPages` to the end and reports whether it got there.
 * Callers MUST branch on `complete`: a truncated set is never the full one.
 */

/** Requests one URL, as `fetch` does: the host app passes its own, with its session. */
export type Requester = (url: string, init?: RequestInit) => Promise<Response>;

/** The `pagination` block every paginated core endpoint returns. */
export interface PaginationEnvelope {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export type FetchAllPagesResult<T> =
  | { complete: true; items: T[]; total: number }
  /**
   * A page request failed, or the walk hit its safety cap. `items` holds what
   * did arrive and `total` is the server's row count, or null if even the
   * first page failed.
   */
  | { complete: false; items: T[]; total: number | null };

/** The server clamps `per_page` to this. */
export const MAX_PER_PAGE = 100;

/**
 * At most this many requests for one list: a million rows at the maximum page
 * size. Hitting it means `totalPages` never terminates, so the walk returns an
 * incomplete result instead of hanging.
 */
export const MAX_REQUESTS = 100;

/** Whether a response's `pagination` field is a pagination envelope. */
export function isPaginationEnvelope(value: unknown): value is PaginationEnvelope {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as PaginationEnvelope).totalPages === 'number' &&
    typeof (value as PaginationEnvelope).total === 'number'
  );
}

/** One page as the walk needs to see it, whichever client fetched it. */
export type PageOutcome<T> = { ok: false } | { ok: true; rows: T[] | undefined; pagination: unknown };

/**
 * The walk itself: request pages until the envelope says there are no more,
 * then decide whether the set is whole. Exported for a client that fetches
 * pages its own way (a typed client, a cache).
 */
export async function walkPages<T>(
  fetchPage: (page: number, perPage: number) => Promise<PageOutcome<T>>,
  perPage: number = MAX_PER_PAGE,
): Promise<FetchAllPagesResult<T>> {
  const items: T[] = [];
  let total: number | null = null;
  let totalPages = 1;

  for (let page = 1; page <= totalPages; page++) {
    if (page > MAX_REQUESTS) {
      return { complete: false, items, total };
    }

    const outcome = await fetchPage(page, perPage);
    if (!outcome.ok) {
      return { complete: false, items, total };
    }

    const { rows, pagination } = outcome;
    items.push(...(Array.isArray(rows) ? rows : []));

    if (!isPaginationEnvelope(pagination)) {
      // No envelope: the endpoint isn't paginated, so this is all there is.
      return { complete: true, items, total: items.length };
    }

    total = pagination.total;
    totalPages = pagination.totalPages;

    // A page that claims successors but delivers no rows would spin until the cap.
    if (!Array.isArray(rows) || rows.length === 0) {
      break;
    }
  }

  // The row count is the contract, not the page count: a concurrent insert can
  // grow `total` mid-walk, leaving the set a page short.
  if (total !== null && items.length < total) {
    return { complete: false, items, total };
  }

  return { complete: true, items, total: total ?? items.length };
}

/**
 * Fetch every page of `path` with `request` and concatenate the `data` arrays.
 * An existing query string on `path` is kept.
 */
export async function fetchAllPages<T>(
  request: Requester,
  path: string,
  perPage: number = MAX_PER_PAGE,
): Promise<FetchAllPagesResult<T>> {
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

// ---- the plugin route client ----

/** What one call to a plugin route resolves to. It never throws on an HTTP error. */
export type RouteResult<T> =
  | { ok: true; status: number; data: T; body: unknown }
  | { ok: false; status: number; error: RouteError | null; body: unknown };

/** The error envelope core and plugin routes answer with. */
export interface RouteError {
  code?: string;
  message?: string;
  details?: unknown;
}

export interface PluginClientOptions {
  /** The host app's requester, carrying its session (cookie or token). */
  request: Requester;
  /** The API version paths are rewritten to, as the host does (`/api/x` becomes `/api/v1/x`). */
  version?: string;
}

export interface PluginClient {
  get<T>(path: string, query?: Record<string, string | number | boolean>): Promise<RouteResult<T>>;
  post<T>(path: string, body?: unknown): Promise<RouteResult<T>>;
  put<T>(path: string, body?: unknown): Promise<RouteResult<T>>;
  patch<T>(path: string, body?: unknown): Promise<RouteResult<T>>;
  delete<T>(path: string, body?: unknown): Promise<RouteResult<T>>;
  /** Every page of a list route, with {@link fetchAllPages}'s completeness rules. */
  all<T>(path: string, perPage?: number): Promise<FetchAllPagesResult<T>>;
}

/**
 * The versioned path the host serves a route at: `/api/cake-orders/today`
 * becomes `/api/v1/cake-orders/today`, as `Router::versionedPath()` does. A
 * path that already carries a version is left alone.
 */
export function versionedPath(path: string, version: string = 'v1'): string {
  if (version === '' || /^\/[^/]+\/v\d+(\/|$)/.test(path)) {
    return path;
  }
  const match = /^(\/[^/]+)(\/.*)?$/.exec(path);
  if (match === null) {
    return path;
  }
  return match[2] === undefined ? `/${version}${match[1]}` : `${match[1]}/${version}${match[2]}`;
}

/**
 * A client for a plugin's own routes (SDK 1.165, decision D8), on the host's
 * requester so the session rules are the host's:
 *
 * ```ts
 * const routes = createPluginClient({ request: apiFetch });
 * const today = await routes.get<Order[]>('/api/cake-orders/today');
 * if (today.ok) show(today.data);
 * ```
 *
 * A JSON response's `data` is unwrapped; a body without `data` is returned as
 * it came.
 */
export function createPluginClient(options: PluginClientOptions): PluginClient {
  const version = options.version ?? 'v1';

  const call = async <T>(method: string, path: string, body?: unknown): Promise<RouteResult<T>> => {
    const init: RequestInit = { method, headers: { Accept: 'application/json' } };
    if (body !== undefined) {
      init.body = JSON.stringify(body);
      init.headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
    }
    const response = await options.request(versionedPath(path, version), init);
    const text = await response.text();
    let parsed: unknown = null;
    if (text !== '') {
      try {
        parsed = JSON.parse(text);
      } catch {
        parsed = text;
      }
    }
    if (!response.ok) {
      const error = typeof parsed === 'object' && parsed !== null && 'error' in parsed
        ? ((parsed as { error: unknown }).error as RouteError | null)
        : null;
      return { ok: false, status: response.status, error: typeof error === 'object' ? error : null, body: parsed };
    }
    const data = typeof parsed === 'object' && parsed !== null && 'data' in parsed ? (parsed as { data: unknown }).data : parsed;

    return { ok: true, status: response.status, data: data as T, body: parsed };
  };

  return {
    get: <T>(path: string, query?: Record<string, string | number | boolean>) => {
      if (query === undefined || Object.keys(query).length === 0) {
        return call<T>('GET', path);
      }
      const search = new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)]));
      return call<T>('GET', `${path}${path.includes('?') ? '&' : '?'}${search.toString()}`);
    },
    post: <T>(path: string, body?: unknown) => call<T>('POST', path, body),
    put: <T>(path: string, body?: unknown) => call<T>('PUT', path, body),
    patch: <T>(path: string, body?: unknown) => call<T>('PATCH', path, body),
    delete: <T>(path: string, body?: unknown) => call<T>('DELETE', path, body),
    all: <T>(path: string, perPage?: number) => fetchAllPages<T>(options.request, versionedPath(path, version), perPage),
  };
}
