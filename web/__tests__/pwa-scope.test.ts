/**
 * #2386: the installed app on an origin Whity shares with another app.
 */
import { DEFAULT_PWA_SCOPE, ownTopLevelPaths, pwaScope } from '@/lib/pwa/scope';
import { buildManifest, serviceWorkerSource } from '@/lib/pwa/shared';

describe('pwaScope', () => {
  it.each([
    [undefined, '/'],
    ['', '/'],
    ['/', '/'],
    ['/admin/', '/admin/'],
    ['/whity/app/', '/whity/app/'],
    ['/admin', DEFAULT_PWA_SCOPE],
    ['admin/', DEFAULT_PWA_SCOPE],
    ['//evil/', DEFAULT_PWA_SCOPE],
    ['https://evil.example/', DEFAULT_PWA_SCOPE],
  ])('%p is %p', (raw, expected) => {
    expect(pwaScope(raw)).toBe(expected);
  });
});

describe('ownTopLevelPaths', () => {
  it('lists Whity’s page prefixes from the route manifest, not the root or files', () => {
    const own = ownTopLevelPaths();
    expect(own).toEqual(expect.arrayContaining(['admin', 'dashboard', 'login', 'calendar', 'f', 'settings']));
    expect(own).not.toContain('');
    expect(own).not.toContain('api');
    expect(own).not.toContain('sw.js');
    expect(own).not.toContain('_next');
  });
});

describe('the manifest and the worker follow the scope', () => {
  const install = { state: 'on', siteName: 'Whity', themeColor: '#1d4ed8', version: 'v1' } as unknown as Parameters<typeof buildManifest>[0];

  it('narrows id, start_url and scope', () => {
    expect(buildManifest(install, 'en')).toMatchObject({ id: '/', start_url: '/', scope: '/' });
    expect(buildManifest(install, 'en', '/admin/')).toMatchObject({ id: '/admin/', start_url: '/admin/', scope: '/admin/' });
  });

  it('answers navigations only under Whity’s own paths', () => {
    const source = serviceWorkerSource({
      version: 'v1',
      cacheStatic: true,
      name: { en: 'Whity', ar: 'Whity' },
      themeColor: '#1d4ed8',
      ownPaths: ['admin', 'login'],
    });
    expect(source).toContain('"own":["admin","login"]');
    expect(source).toContain('!CONFIG.own.includes(first)');
  });
});
