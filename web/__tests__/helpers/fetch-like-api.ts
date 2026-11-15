/**
 * Test double for `@/lib/api/client` that forwards every typed call to
 * `globalThis.fetch(url, init)`, so a test can keep stubbing one fetch-like
 * function and asserting on the URL and the JSON body. Only the transport is
 * adapted; the paths, bodies and branches under test are the page's own.
 *
 * Use: jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/fetch-like-api').fetchLikeClient());
 */
type Opts = {
  params?: { path?: Record<string, unknown>; query?: Record<string, unknown> };
  body?: unknown;
  headers?: Record<string, string>;
  credentials?: RequestCredentials;
  cache?: RequestCache;
};

type Res = { ok: boolean; status: number; json?: () => Promise<unknown> };

async function call(method: string, path: string, opts: Opts = {}) {
  let url = path.replace(/\{(\w+)\}/g, (_m, key: string) => encodeURIComponent(String(opts.params?.path?.[key])));
  const q = Object.entries(opts.params?.query ?? {});
  if (q.length > 0) {
    url += `?${q.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&')}`;
  }
  const init: RequestInit = { method, headers: opts.headers, credentials: opts.credentials, cache: opts.cache };
  if (opts.body !== undefined) {
    init.body = opts.body instanceof FormData ? opts.body : JSON.stringify(opts.body);
  }
  const response = (await (globalThis.fetch as unknown as (u: string, i: RequestInit) => Promise<Res>)(url, init)) as Res;
  let body: unknown;
  try {
    body = response?.json ? await response.json() : undefined;
  } catch {
    body = undefined;
  }
  const ok = response?.ok ?? false;
  return { data: ok ? body : undefined, error: ok ? undefined : body, response: response as unknown as Response };
}

export function fetchLikeClient() {
  return {
    api: {
      GET: (p: string, o?: Opts) => call('GET', p, o),
      POST: (p: string, o?: Opts) => call('POST', p, o),
      PUT: (p: string, o?: Opts) => call('PUT', p, o),
      PATCH: (p: string, o?: Opts) => call('PATCH', p, o),
      DELETE: (p: string, o?: Opts) => call('DELETE', p, o),
    },
    apiFetch: (url: string, init?: RequestInit) => (globalThis.fetch as unknown as (u: string, i?: RequestInit) => Promise<Response>)(url, init),
  };
}
