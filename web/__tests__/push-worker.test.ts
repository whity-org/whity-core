/**
 * @jest-environment node
 */
import { serviceWorkerSource } from '@/lib/pwa/shared';
import { deviceLabel, keyBytes } from '@/lib/pwa/push';

type Listener = (event: unknown) => void;

/** Runs the worker against a fake `self` with notifications and windows. */
function loadWorker() {
  const listeners: Record<string, Listener> = {};
  const shown: { title: string; options: Record<string, unknown> }[] = [];
  const opened: string[] = [];
  const windows: { url: string; focus: jest.Mock; navigate: jest.Mock }[] = [];
  const self = {
    location: { origin: 'https://bakery.example' },
    addEventListener: (type: string, fn: Listener) => (listeners[type] = fn),
    skipWaiting: jest.fn(),
    clients: {
      claim: jest.fn(),
      matchAll: jest.fn(async () => windows),
      openWindow: jest.fn(async (url: string) => void opened.push(url)),
    },
    registration: {
      unregister: jest.fn(),
      showNotification: jest.fn(async (title: string, options: Record<string, unknown>) => void shown.push({ title, options })),
    },
  };
  const source = serviceWorkerSource({ version: 'v1', cacheStatic: false, name: { en: 'Example Bakery', ar: 'Example Bakery' }, themeColor: '#b74c05' });
  new Function('self', 'caches', 'fetch', source)(self, {}, jest.fn());

  const run = async (type: string, event: Record<string, unknown>) => {
    let waited: Promise<unknown> | undefined;
    listeners[type]({ ...event, waitUntil: (p: Promise<unknown>) => (waited = p) });
    await waited;
  };
  const push = (payload: unknown) => run('push', { data: { json: () => payload } });
  const click = (url: unknown) => run('notificationclick', { notification: { close: jest.fn(), data: { url } } });

  return { self, shown, opened, windows, push, click };
}

describe('web push in the service worker (WHIT-1075)', () => {
  it('shows the workspace’s notification in its language and direction', async () => {
    const w = loadWorker();
    await w.push({ title: 'Example Bakery · تم شحن الطلب', body: 'افتحه', url: '/admin/document-library/42', tag: 'n7', lang: 'ar', dir: 'rtl', icon: '/app-icon/192?v=1' });

    expect(w.shown).toHaveLength(1);
    expect(w.shown[0].title).toBe('Example Bakery · تم شحن الطلب');
    expect(w.shown[0].options).toMatchObject({ body: 'افتحه', tag: 'n7', lang: 'ar', dir: 'rtl', icon: 'https://bakery.example/app-icon/192?v=1', data: { url: '/admin/document-library/42' } });
    expect(w.shown[0].options).not.toHaveProperty('actions');
  });

  it('never takes an image or a link from another site', async () => {
    const w = loadWorker();
    await w.push({ title: 'x', url: 'https://evil.example/phish', icon: 'https://evil.example/i.png', badge: '//evil.example/b.png' });
    expect(w.shown[0].options).toMatchObject({ icon: '/app-icon/192', badge: undefined, data: { url: '/admin/inbox' } });

    for (const url of ['//evil.example', '/\\evil.example', 'javascript:alert(1)', '/ok path']) {
      await w.push({ title: 'x', url });
      expect(w.shown[w.shown.length - 1].options).toMatchObject({ data: { url: '/admin/inbox' } });
    }
  });

  it('shows something even for an unreadable push (the browser requires one)', async () => {
    const w = loadWorker();
    await w.push('not an object');
    expect(w.shown[0].title).toBe('Example Bakery');
  });

  it('opens the record in a new window when none is open', async () => {
    const w = loadWorker();
    await w.click('/admin/document-library/42');
    expect(w.opened).toEqual(['https://bakery.example/admin/document-library/42']);
  });

  it('brings an open window forward on the record instead', async () => {
    const w = loadWorker();
    const win = { url: 'https://bakery.example/dashboard', focus: jest.fn(), navigate: jest.fn(async () => undefined) };
    win.focus.mockResolvedValue(win);
    w.windows.push(win);
    await w.click('/admin/inbox');
    expect(win.navigate).toHaveBeenCalledWith('https://bakery.example/admin/inbox');
    expect(w.opened).toEqual([]);
  });

  it('opens only paths on this site when tapped', async () => {
    const w = loadWorker();
    await w.click('https://evil.example');
    expect(w.opened).toEqual(['https://bakery.example/admin/inbox']);
  });
});

describe('push helpers', () => {
  it('turns the server key into bytes', () => {
    expect(Array.from(keyBytes('AQID_w'))).toEqual([1, 2, 3, 255]);
  });

  it('names a device in plain words', () => {
    expect(deviceLabel('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131 Mobile Safari/537.36')).toEqual({ browser: 'Chrome', os: 'Android' });
    expect(deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1')).toEqual({ browser: 'Safari', os: 'iPhone' });
    expect(deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131 Safari/537.36 Edg/131')).toEqual({ browser: 'Edge', os: 'Windows' });
    expect(deviceLabel('')).toEqual({ browser: '', os: '' });
  });
});

describe('the push icon query (WHIT-1076)', () => {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const { letterFrom, colourFrom } = require('@/lib/pwa/push-icon') as typeof import('@/lib/pwa/push-icon');

  it('draws only one Latin letter or digit', () => {
    expect(letterFrom('E')).toBe('E');
    expect(letterFrom('7')).toBe('7');
    expect(letterFrom('e')).toBeNull();
    expect(letterFrom('EB')).toBeNull();
    expect(letterFrom('<')).toBeNull();
    expect(letterFrom(null)).toBeNull();
  });

  it('takes only a hex colour, else Whity Blue', () => {
    expect(colourFrom('B74C05')).toBe('#b74c05');
    expect(colourFrom('red')).toBe('#0961bb');
    expect(colourFrom('#b74c05')).toBe('#0961bb');
  });
});
