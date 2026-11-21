/**
 * #1688 — the postMessage bridge between an embedded plugin screen and the app.
 *
 * Pins what the app does for a frame: answers only its own frame, makes API
 * calls only to `/api/` paths, with the scoped frame token and never the
 * cookies, keeps the token to itself, and clamps or refuses the rest.
 */

import {
  FRAME_PROTOCOL,
  FRAME_MAX_HEIGHT,
  FRAME_MIN_HEIGHT,
  apiBaseOf,
  createFrameBridge,
  framePath,
  navigationTarget,
  type FrameBridgeOptions,
} from '@/lib/plugin-frame-bridge';

type Posted = { message: Record<string, unknown>; origin: string };

function setup(overrides: Partial<FrameBridgeOptions> = {}) {
  const posted: Posted[] = [];
  const frame = {
    postMessage: (message: Record<string, unknown>, origin: string) => posted.push({ message, origin }),
  } as unknown as Window;
  let clock = 1_000;
  const fetchToken = jest.fn(async () => ({ token: `t${fetchToken.mock.calls.length}`, expiresIn: 300 }));
  const send = jest.fn<Promise<Response>, [string, RequestInit]>(
    async () =>
      new Response(JSON.stringify({ data: [1] }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  );
  const onResize = jest.fn();
  const onNavigate = jest.fn();
  const onToast = jest.fn();
  const listener = createFrameBridge({
    frameWindow: () => frame,
    embedPath: '/api/v1/bom/designer',
    fetchToken,
    send,
    context: () => ({ locale: 'ar', dir: 'rtl', theme: 'dark' }),
    onResize,
    onNavigate,
    onToast,
    now: () => clock,
    ...overrides,
  });
  const say = (data: Record<string, unknown>, source: unknown = frame) =>
    listener({ data: { whity: FRAME_PROTOCOL, ...data }, source } as unknown as MessageEvent);
  const settle = () => new Promise((r) => setTimeout(r, 0));
  return {
    posted, frame, fetchToken, send, onResize, onNavigate, onToast, say, settle,
    tick: (s: number) => { clock += s; },
  };
}

describe('plugin frame bridge (#1688)', () => {
  it('answers ready with the context, never a token', () => {
    const b = setup();
    b.say({ type: 'ready' });
    expect(b.posted).toEqual([
      {
        message: { whity: FRAME_PROTOCOL, type: 'init', locale: 'ar', dir: 'rtl', theme: 'dark', apiBase: '/api/v1' },
        origin: '*',
      },
    ]);
    expect(b.fetchToken).not.toHaveBeenCalled();
  });

  it('ignores messages from any other window and other protocols', () => {
    const b = setup();
    b.say({ type: 'ready' }, {} as Window);
    b.say({ type: 'ready' }, null);
    expect(b.posted).toHaveLength(0);
  });

  it('makes a request with the frame token and no cookies, and returns the JSON', async () => {
    const b = setup();
    b.say({ type: 'request', id: 'r1', method: 'post', path: '/api/bom/parts', body: { name: 'Bolt' } });
    await b.settle();

    expect(b.send).toHaveBeenCalledTimes(1);
    const [url, init] = b.send.mock.calls[0];
    expect(url).toBe('/api/v1/bom/parts');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('omit');
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer t1');
    expect(init.body).toBe('{"name":"Bolt"}');
    expect(b.posted[0].message).toEqual({
      whity: FRAME_PROTOCOL, type: 'response', id: 'r1', ok: true, status: 200, body: { data: [1] },
    });
    expect(JSON.stringify(b.posted)).not.toContain('t1');
  });

  it('reuses the token until it nearly runs out, then asks again', async () => {
    const b = setup();
    b.say({ type: 'request', id: 1, path: '/api/v1/bom/parts' });
    await b.settle();
    b.say({ type: 'request', id: 2, path: '/api/v1/bom/parts' });
    await b.settle();
    expect(b.fetchToken).toHaveBeenCalledTimes(1);

    b.tick(280);
    b.say({ type: 'request', id: 3, path: '/api/v1/bom/parts' });
    await b.settle();
    expect(b.fetchToken).toHaveBeenCalledTimes(2);
  });

  it.each([
    ['another site', 'https://evil.example/api/x'],
    ['protocol-relative', '//evil.example/api/x'],
    ['not the API', '/admin/users'],
    ['traversal', '/api/v1/bom/../users'],
    ['encoded traversal', '/api/v1/bom/%2e%2e/users'],
    ['backslash', '/api/v1/bom\\..\\users'],
    ['whitespace', '/api/v1/bom/ parts'],
  ])('refuses %s without a network call', async (_name, path) => {
    const b = setup();
    b.say({ type: 'request', id: 'x', path });
    await b.settle();
    expect(b.send).not.toHaveBeenCalled();
    expect(b.fetchToken).not.toHaveBeenCalled();
    expect(b.posted[0].message).toMatchObject({ type: 'response', id: 'x', ok: false, status: 0, error: 'refused' });
  });

  it('refuses a method outside the list', async () => {
    const b = setup();
    b.say({ type: 'request', id: 'x', method: 'TRACE', path: '/api/v1/bom/parts' });
    await b.settle();
    expect(b.send).not.toHaveBeenCalled();
  });

  it('drops a cached token after a 401', async () => {
    const send = jest.fn(async () => new Response('', { status: 401 }));
    const b = setup({ send });
    b.say({ type: 'request', id: 1, path: '/api/v1/bom/parts' });
    await b.settle();
    b.say({ type: 'request', id: 2, path: '/api/v1/bom/parts' });
    await b.settle();
    expect(b.fetchToken).toHaveBeenCalledTimes(2);
  });

  it('answers a failed token request as a network error', async () => {
    const b = setup({ fetchToken: jest.fn(async () => { throw new Error('no'); }) });
    b.say({ type: 'request', id: 'n', path: '/api/v1/bom/parts' });
    await b.settle();
    await b.settle();
    expect(b.posted[0].message).toMatchObject({ id: 'n', ok: false, error: 'network' });
  });

  it('clamps resize, keeps navigation in the app and shortens toasts', () => {
    const b = setup();
    b.say({ type: 'resize', height: 5 });
    b.say({ type: 'resize', height: 1e9 });
    b.say({ type: 'resize', height: 'tall' });
    expect(b.onResize.mock.calls).toEqual([[FRAME_MIN_HEIGHT], [FRAME_MAX_HEIGHT]]);

    b.say({ type: 'navigate', to: '/admin/x/bom-parts' });
    b.say({ type: 'navigate', to: 'https://evil.example' });
    b.say({ type: 'navigate', to: '//evil.example' });
    b.say({ type: 'navigate', to: 'javascript:alert(1)' });
    expect(b.onNavigate.mock.calls).toEqual([['/admin/x/bom-parts']]);

    b.say({ type: 'toast', message: 'x'.repeat(500), tone: 'loud' });
    b.say({ type: 'toast', message: '   ' });
    expect(b.onToast).toHaveBeenCalledTimes(1);
    expect(b.onToast.mock.calls[0][0]).toHaveLength(300);
    expect(b.onToast.mock.calls[0][1]).toBe('info');
  });

  it('works out paths and bases', () => {
    expect(apiBaseOf('/api/v2/x/y')).toBe('/api/v2');
    expect(apiBaseOf('/api/x/y')).toBe('/api');
    expect(framePath('/api/v1/a', '/api/v2/x')).toBe('/api/v1/a');
    expect(framePath('/api/a?b=1', '/api/v2/x')).toBe('/api/v2/a?b=1');
    expect(navigationTarget('/api/v1/x')).toBeNull();
  });
});
