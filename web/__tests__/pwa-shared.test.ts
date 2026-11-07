/**
 * @jest-environment node
 */
import {
  buildManifest,
  installName,
  killSwitchWorkerSource,
  manifestLang,
  serviceWorkerSource,
  type AppInstall,
} from '@/lib/pwa/shared';

const install: AppInstall = {
  state: 'on',
  siteName: 'Whity',
  themeColor: '#0961bb',
  logoSquareUrl: null,
  version: 'abc123',
};

describe('the web app manifest (#1733)', () => {
  it('installs standalone with the brand colour and maskable icons', () => {
    const m = buildManifest(install, 'en');
    expect(m).toMatchObject({ name: 'Whity', display: 'standalone', start_url: '/', scope: '/', theme_color: '#0961bb', lang: 'en', dir: 'ltr' });
    const icons = m.icons as Array<{ purpose: string; sizes: string; src: string }>;
    expect(icons.filter((i) => i.purpose === 'maskable').map((i) => i.sizes)).toEqual(['192x192', '512x512']);
    expect(icons.every((i) => i.src.endsWith('?v=abc123'))).toBe(true);
  });

  it('installs under the Arabic name, right to left, in Arabic', () => {
    expect(buildManifest(install, 'ar')).toMatchObject({ name: 'وايتي', short_name: 'وايتي', lang: 'ar', dir: 'rtl' });
  });

  it('keeps a workspace name as the workspace wrote it', () => {
    expect(installName('Example Bakery', 'ar')).toBe('Example Bakery');
    expect(buildManifest({ ...install, siteName: 'Example Bakery', themeColor: '#b74c05' }, 'en')).toMatchObject({
      name: 'Example Bakery',
      theme_color: '#b74c05',
    });
  });

  it('reads only Arabic as Arabic', () => {
    expect(manifestLang('ar')).toBe('ar');
    expect(manifestLang('ar-JO')).toBe('ar');
    expect(manifestLang('en')).toBe('en');
    expect(manifestLang(null)).toBe('en');
    expect(manifestLang('fr')).toBe('en');
  });
});

type Listener = (event: unknown) => void;

/** Runs a worker's source against a fake `self`, returning its listeners. */
function loadWorker(source: string, network: (req: Request) => Promise<Response>) {
  const listeners: Record<string, Listener> = {};
  const stored = new Map<string, Response>();
  const cache = {
    match: async (req: Request) => stored.get(req.url)?.clone(),
    put: async (req: Request, res: Response) => void stored.set(req.url, res),
  };
  const self = {
    location: { origin: 'https://app.whity.dev' },
    addEventListener: (type: string, fn: Listener) => (listeners[type] = fn),
    skipWaiting: jest.fn(),
    clients: { claim: jest.fn() },
    registration: { unregister: jest.fn(async () => true) },
  };
  const caches = {
    open: async () => cache,
    keys: async () => ['whity-static-old', 'someone-else'],
    delete: jest.fn(async () => true),
  };
  new Function('self', 'caches', 'fetch', source)(self, caches, network);

  const fetchEvent = async (url: string, mode: RequestMode = 'cors') => {
    let responded: Promise<Response> | undefined;
    const request = new Request(url);
    Object.defineProperty(request, 'mode', { value: mode });
    listeners.fetch({ request, respondWith: (p: Promise<Response>) => (responded = p) });
    return responded;
  };

  return { listeners, stored, self, caches, fetchEvent };
}

describe('the service worker (#1733)', () => {
  const source = serviceWorkerSource({ version: 'v1', cacheStatic: true, name: { en: 'Whity', ar: 'وايتي' }, themeColor: '#0961bb' });

  it('never answers an API request, so nothing per account is ever stored', async () => {
    const network = jest.fn(async () => new Response('{"me":"amal"}'));
    const w = loadWorker(source, network);
    expect(await w.fetchEvent('https://app.whity.dev/api/v1/auth/me')).toBeUndefined();
    expect(await w.fetchEvent('https://app.whity.dev/api/v1/branding/asset/1/logo.png')).toBeUndefined();
    expect(w.stored.size).toBe(0);
  });

  it('serves pages from the network and never stores them', async () => {
    const w = loadWorker(source, async () => new Response('<html>signed in</html>'));
    const res = await w.fetchEvent('https://app.whity.dev/dashboard', 'navigate');
    expect(await res?.text()).toBe('<html>signed in</html>');
    expect(w.stored.size).toBe(0);
  });

  it('shows the offline page when a page cannot be reached', async () => {
    const w = loadWorker(source, async () => {
      throw new TypeError('offline');
    });
    const res = await w.fetchEvent('https://app.whity.dev/dashboard', 'navigate');
    expect(res?.status).toBe(503);
    const html = (await res?.text()) ?? '';
    expect(html).toContain('You are offline');
    expect(html).toContain('أنت غير متصل بالإنترنت');
  });

  it('keeps hashed build files, and only those', async () => {
    const sameOrigin = () => {
      const res = new Response('js');
      Object.defineProperty(res, 'type', { value: 'basic' });
      return res;
    };
    const w = loadWorker(source, async () => sameOrigin());
    await w.fetchEvent('https://app.whity.dev/_next/static/chunks/app.js');
    expect([...w.stored.keys()]).toEqual(['https://app.whity.dev/_next/static/chunks/app.js']);
    expect(await w.fetchEvent('https://app.whity.dev/favicon.ico')).toBeUndefined();
  });

  it('escapes the workspace name on the offline page', async () => {
    const hostile = serviceWorkerSource({ version: 'v1', cacheStatic: false, name: { en: '<img src=x onerror=alert(1)>', ar: 'x' }, themeColor: '#0961bb' });
    const w = loadWorker(hostile, async () => {
      throw new TypeError('offline');
    });
    const html = (await (await w.fetchEvent('https://app.whity.dev/', 'navigate'))?.text()) ?? '';
    expect(html).not.toContain('<img src=x');
    expect(html).toContain('&lt;img src=x');
  });

  it('waits for the reader before swapping in a new version', () => {
    const w = loadWorker(source, async () => new Response(''));
    w.listeners.install({});
    expect(w.self.skipWaiting).not.toHaveBeenCalled();
    w.listeners.message({ data: { type: 'SKIP_WAITING' } });
    expect(w.self.skipWaiting).toHaveBeenCalled();
  });
});

describe('the switched-off worker (#1733)', () => {
  it('clears only the app’s caches and unregisters itself', async () => {
    const w = loadWorker(killSwitchWorkerSource(), async () => new Response(''));
    let done: Promise<unknown> = Promise.resolve();
    w.listeners.activate({ waitUntil: (p: Promise<unknown>) => (done = p) });
    await done;
    expect(w.caches.delete).toHaveBeenCalledWith('whity-static-old');
    expect(w.caches.delete).not.toHaveBeenCalledWith('someone-else');
    expect(w.self.registration.unregister).toHaveBeenCalled();
    expect(w.listeners.fetch).toBeUndefined();
  });
});
