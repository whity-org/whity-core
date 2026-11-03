/**
 * @jest-environment node
 *
 * The browser must not race itself into the server's refresh-token reuse detection.
 * The server rotates on the first refresh and refuses the old token after that, and
 * a refused refresh signs the person out, so these count refreshes. In the node
 * environment because openapi-fetch builds real `Request` objects.
 */

import { apiClient } from '@/lib/api-client';
import { createApiClient, SESSION_EXPIRED_EVENT } from '@/lib/api/client';
import { refreshSession } from '@/lib/session-refresh';

const REFRESH = '/api/v1/auth/refresh';

const urlOf = (input: RequestInfo | URL): string =>
  typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;

/**
 * Behaves as the server does: the access cookie is dead until a refresh succeeds, and
 * a refresh sent before the rotation carries the old token and is refused.
 */
function serverWithExpiredAccessToken() {
  let generation = 0;
  let renewed = false;
  const stats = { refreshes: 0, refused: 0 };

  const handler = async (input: RequestInfo | URL): Promise<Response> => {
    if (urlOf(input).endsWith(REFRESH)) {
      stats.refreshes += 1;
      const presented = generation;
      await new Promise((resolve) => setTimeout(resolve, 5));
      if (presented !== generation) {
        stats.refused += 1;

        return new Response('{}', { status: 401 });
      }
      generation += 1;
      renewed = true;

      return new Response('{}', { status: 200 });
    }

    return new Response('{}', { status: renewed ? 200 : 401 });
  };

  return { handler, stats };
}

function setGlobal(name: 'window' | 'navigator', value: unknown): void {
  Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
}

describe('renewing a session', () => {
  const realFetch = global.fetch;
  const realNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
  let expired: jest.Mock;

  beforeEach(() => {
    expired = jest.fn();
    const target = new EventTarget();
    target.addEventListener(SESSION_EXPIRED_EVENT, expired);
    setGlobal('window', target);
    setGlobal('navigator', {});
  });

  afterEach(() => {
    global.fetch = realFetch;
    Reflect.deleteProperty(globalThis, 'window');
    Reflect.deleteProperty(globalThis, 'navigator');
    if (realNavigator !== undefined) Object.defineProperty(globalThis, 'navigator', realNavigator);
  });

  it('refreshes once when a screen gets several 401s at once', async () => {
    const server = serverWithExpiredAccessToken();
    global.fetch = jest.fn(server.handler) as typeof fetch;

    const responses = await Promise.all([apiClient('/api/v1/a'), apiClient('/api/v1/b'), apiClient('/api/v1/c')]);

    expect(server.stats.refreshes).toBe(1);
    expect(server.stats.refused).toBe(0);
    expect(responses.map((response) => response.status)).toEqual([200, 200, 200]);
  });

  it('does not sign anybody out when the typed client gets several 401s at once', async () => {
    const server = serverWithExpiredAccessToken();
    const api = createApiClient({ baseUrl: 'http://localhost', fetch: (request) => server.handler(request) });

    const results = await Promise.all([
      api.GET('/api/v1/me' as never),
      api.GET('/api/v1/navigation' as never),
      api.GET('/api/v1/settings/tabs' as never),
    ]);

    expect(server.stats.refreshes).toBe(1);
    expect(server.stats.refused).toBe(0);
    expect(expired).not.toHaveBeenCalled();
    expect(results.map((result) => result.response.status)).toEqual([200, 200, 200]);
  });

  it('shares the refresh between the two clients', async () => {
    const server = serverWithExpiredAccessToken();
    global.fetch = jest.fn(server.handler) as typeof fetch;
    const api = createApiClient({ baseUrl: 'http://localhost', fetch: (request) => server.handler(request) });

    await Promise.all([apiClient('/api/v1/a'), api.GET('/api/v1/navigation' as never)]);

    expect(server.stats.refreshes).toBe(1);
    expect(server.stats.refused).toBe(0);
    expect(expired).not.toHaveBeenCalled();
  });

  it('can refresh again once the last one has finished', async () => {
    const send = jest.fn(async () => new Response('{}', { status: 200 }));

    await refreshSession(send);
    await refreshSession(send);

    expect(send).toHaveBeenCalledTimes(2);
  });

  it('still signs out when the server really refuses', async () => {
    const api = createApiClient({
      baseUrl: 'http://localhost',
      fetch: async () => new Response('{}', { status: 401 }),
    });

    await api.GET('/api/v1/me' as never);

    expect(expired).toHaveBeenCalled();
  });

  it('does not sign out when the refresh never reached the server', async () => {
    const api = createApiClient({
      baseUrl: 'http://localhost',
      fetch: async (request) => {
        if (request.url.endsWith(REFRESH)) throw new TypeError('offline');

        return new Response('{}', { status: 401 });
      },
    });

    await api.GET('/api/v1/me' as never);

    expect(expired).not.toHaveBeenCalled();
  });

  it.each([429, 500, 502, 503])('does not sign out when the refresh answers %i', async (status) => {
    const api = createApiClient({
      baseUrl: 'http://localhost',
      fetch: async (request) => new Response('{}', { status: request.url.endsWith(REFRESH) ? status : 401 }),
    });

    await api.GET('/api/v1/me' as never);

    expect(expired).not.toHaveBeenCalled();
  });

  it('holds a lock across tabs when the browser has one', async () => {
    const request = jest.fn((_name: string, callback: () => Promise<unknown>) => callback());
    setGlobal('navigator', { locks: { request } });

    await expect(refreshSession(async () => new Response('{}', { status: 200 }))).resolves.toBe('renewed');

    expect(request).toHaveBeenCalledTimes(1);
    expect(request.mock.calls[0][0]).toBe('whity-session-refresh');
  });

  it('a second tab waiting behind the lock sends the rotated cookie, so it renews', async () => {
    const server = serverWithExpiredAccessToken();
    global.fetch = jest.fn(server.handler) as typeof fetch;
    // One lock shared by two "tabs": the second only starts once the first is done.
    let queue: Promise<unknown> = Promise.resolve();
    const request = (_name: string, callback: () => Promise<unknown>) => {
      const run = queue.then(callback);
      queue = run;

      return run;
    };
    setGlobal('navigator', { locks: { request } });

    const firstTab = refreshSession();
    let secondTab: Promise<string> = Promise.resolve('not started');
    await jest.isolateModulesAsync(async () => {
      const other = await import('@/lib/session-refresh');
      secondTab = other.refreshSession();
    });

    await expect(firstTab).resolves.toBe('renewed');
    await expect(secondTab).resolves.toBe('renewed');
    expect(server.stats.refreshes).toBe(2);
    expect(server.stats.refused).toBe(0);
  });
});
