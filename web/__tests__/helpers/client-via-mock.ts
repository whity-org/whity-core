/**
 * Test double for `@/lib/api/client` that forwards every typed call to ONE
 * `(url, init) => Response-like` mock, the shape these tests were written
 * against when screens used `useAuth().apiClient`. Only the transport is
 * adapted: the URL (path template filled in, query appended), the method and
 * the JSON body still reach the mock, so a test keeps asserting on them.
 *
 * Use:
 *   const mockApiClient = jest.fn();
 *   jest.mock('@/lib/api/client', () => jest.requireActual('./helpers/client-via-mock').clientVia(() => mockApiClient));
 */
type Opts = {
  params?: { path?: Record<string, unknown>; query?: Record<string, unknown> };
  body?: unknown;
  headers?: Record<string, string>;
  parseAs?: string;
};

type Res = { ok: boolean; status: number; json?: () => Promise<unknown>; blob?: () => Promise<unknown> };
type Mock = (url: string, init?: RequestInit) => Promise<Res> | Res;

export function clientVia(getMock: () => Mock) {
  const call = async (method: string, path: string, opts: Opts = {}) => {
    let url = path.replace(/\{(\w+)\}/g, (_m, key: string) => encodeURIComponent(String(opts.params?.path?.[key])));
    const query = Object.entries(opts.params?.query ?? {}).filter(([, v]) => v !== undefined);
    if (query.length > 0) {
      url += `?${query.map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`).join('&')}`;
    }
    // A plain GET is called with the URL alone, as `apiClient(url)` was.
    const init: RequestInit | undefined =
      method === 'GET' && opts.body === undefined ? undefined : { method };
    if (init && opts.body !== undefined) {
      init.body = JSON.stringify(opts.body);
    }
    const mock = getMock();
    const response = await (init === undefined ? mock(url) : mock(url, init));
    let body: unknown;
    try {
      if (opts.parseAs === 'blob') {
        body = response?.blob ? await response.blob() : undefined;
      } else {
        body = response?.json ? await response.json() : undefined;
      }
    } catch {
      body = undefined;
    }
    const ok = response?.ok ?? false;
    return {
      data: ok ? body : undefined,
      error: ok ? undefined : body,
      response: response as unknown as Response,
    };
  };

  return {
    api: {
      GET: (p: string, o?: Opts) => call('GET', p, o),
      POST: (p: string, o?: Opts) => call('POST', p, o),
      PUT: (p: string, o?: Opts) => call('PUT', p, o),
      PATCH: (p: string, o?: Opts) => call('PATCH', p, o),
      DELETE: (p: string, o?: Opts) => call('DELETE', p, o),
    },
    apiFetch: (url: string, init?: RequestInit) => (init === undefined ? getMock()(url) : getMock()(url, init)),
  };
}
