/**
 * Installing the web app as a phone app (#1733): the pure parts — the manifest,
 * the service worker's source and the offline page — kept free of request APIs
 * so they can be tested directly.
 *
 * WHAT THE SERVICE WORKER IS ALLOWED TO KEEP
 * ------------------------------------------
 * Only build output under `/_next/static/`, which is content-hashed, public,
 * and identical for every account. It never stores a page, an `/api` response,
 * an uploaded file or anything else that could carry one reader's data into
 * another's session on a shared phone. Pages always come from the network; when
 * the network is not there, the reader gets the offline page, which is drawn
 * from the worker's own source and holds nothing but the app's name and colour.
 */

/** Whity Blue, the colour an unbranded installation uses (decision register). */
export const WHITY_BLUE = '#0961bb';

/** The background an installed app shows while it starts. */
export const LAUNCH_BACKGROUND = '#ffffff';

/** The prefix of every cache the worker owns, so it can clear only its own. */
export const CACHE_PREFIX = 'whity-';

/** The Arabic name of the product (the same word the Arabic catalogue uses). */
export const WHITY_ARABIC_NAME = 'وايتي';

export type AppInstall = {
  /** `unknown`: the backend did not answer, so nothing should change. */
  state: 'on' | 'off' | 'unknown';
  siteName: string;
  themeColor: string;
  logoSquareUrl: string | null;
  /** Changes when the name, colour or logo does; busts cached icons. */
  version: string;
};

/** The icons the icon route draws, and their pixel sizes. */
export const APP_ICONS = {
  '180': { size: 180, maskable: false },
  '192': { size: 192, maskable: false },
  '512': { size: 512, maskable: false },
  'maskable-192': { size: 192, maskable: true },
  'maskable-512': { size: 512, maskable: true },
} as const;

export type AppIconName = keyof typeof APP_ICONS;

export function isAppIconName(value: string): value is AppIconName {
  return Object.prototype.hasOwnProperty.call(APP_ICONS, value);
}

export function appIconUrl(name: AppIconName, version: string): string {
  return `/app-icon/${name}${version ? `?v=${version}` : ''}`;
}

/**
 * The name to install under in a language. A workspace's own name is the
 * workspace's to write and is shown as written; only the product's own name
 * has an Arabic form.
 */
export function installName(siteName: string, lang: 'en' | 'ar'): string {
  return lang === 'ar' && siteName.trim() === 'Whity' ? WHITY_ARABIC_NAME : siteName;
}

/** `ar…` is Arabic; anything else installs in English. */
export function manifestLang(raw: string | null | undefined): 'en' | 'ar' {
  return typeof raw === 'string' && raw.toLowerCase().startsWith('ar') ? 'ar' : 'en';
}

const DESCRIPTION = {
  en: 'Your workspace: forms, documents, approvals and people.',
  ar: 'مساحة عملك: النماذج والمستندات والموافقات والأشخاص.',
} as const;

/** The web app manifest for this host in one language. */
export function buildManifest(install: AppInstall, lang: 'en' | 'ar'): Record<string, unknown> {
  const name = installName(install.siteName, lang);
  const icon = (n: AppIconName, purpose: 'any' | 'maskable') => ({
    src: appIconUrl(n, install.version),
    sizes: `${APP_ICONS[n].size}x${APP_ICONS[n].size}`,
    type: 'image/png',
    purpose,
  });

  return {
    id: '/',
    name,
    // A home-screen label is cut at about 12 characters; a longer workspace
    // name is still the right one, so it is kept whole rather than guessed at.
    short_name: name,
    description: DESCRIPTION[lang],
    lang,
    dir: lang === 'ar' ? 'rtl' : 'ltr',
    start_url: '/',
    scope: '/',
    display: 'standalone',
    orientation: 'any',
    background_color: LAUNCH_BACKGROUND,
    theme_color: install.themeColor,
    icons: [
      icon('192', 'any'),
      icon('512', 'any'),
      icon('maskable-192', 'maskable'),
      icon('maskable-512', 'maskable'),
    ],
  };
}

/**
 * The installed worker, as JavaScript source.
 *
 * `version` is the web build: a new deploy changes the bytes, the browser
 * installs the new worker next to the old one, and it WAITS there until the
 * reader agrees to reload (the update prompt) — swapping code under an open
 * form would lose what they typed.
 *
 * `cacheStatic` is off in development, where build output is not hashed and a
 * cached copy would hide the change being worked on.
 */
export function serviceWorkerSource(opts: {
  version: string;
  cacheStatic: boolean;
  name: { en: string; ar: string };
  themeColor: string;
}): string {
  const config = JSON.stringify({
    version: opts.version || 'dev',
    cacheStatic: opts.cacheStatic,
    prefix: CACHE_PREFIX,
    name: opts.name,
    color: opts.themeColor,
  });

  return `/* Whity app worker (#1733). Generated per host; do not cache. */
'use strict';
const CONFIG = ${config};
const STATIC_CACHE = CONFIG.prefix + 'static-' + CONFIG.version;

self.addEventListener('install', () => {
  // Wait: the page asks for the swap (SKIP_WAITING) when the reader agrees.
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys
      .filter((k) => k.startsWith(CONFIG.prefix) && k !== STATIC_CACHE)
      .map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  if (req.mode === 'navigate') {
    // Pages are never stored: always the network, else the offline page.
    event.respondWith(fetch(req).catch(() => offlinePage()));
    return;
  }

  if (CONFIG.cacheStatic && url.pathname.startsWith('/_next/static/')) {
    event.respondWith(staticFirst(req));
  }
  // Everything else, /api above all, goes straight to the network untouched.
});

async function staticFirst(req) {
  const cache = await caches.open(STATIC_CACHE);
  const hit = await cache.match(req);
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok && res.type === 'basic') cache.put(req, res.clone());
  return res;
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function offlinePage() {
  const en = esc(CONFIG.name.en);
  const ar = esc(CONFIG.name.ar);
  const color = /^#[0-9a-f]{6}$/i.test(CONFIG.color) ? CONFIG.color : '#0961bb';
  const html = '<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
    + '<meta name="theme-color" content="' + color + '"><title>' + en + '</title>'
    + '<style>'
    + ':root{color-scheme:light dark;--bg:#fff;--fg:#0a0a0a;--muted:#5b5f66}'
    + '@media (prefers-color-scheme:dark){:root{--bg:#0d0f12;--fg:#f2f3f5;--muted:#a6abb3}}'
    + 'html,body{height:100%;margin:0}'
    + 'body{background:var(--bg);color:var(--fg);font:15px/1.5 "Noto Sans","Noto Sans Arabic",system-ui,sans-serif;display:flex;align-items:center;justify-content:center;'
    + 'padding:max(24px,env(safe-area-inset-top)) max(24px,env(safe-area-inset-right)) max(24px,env(safe-area-inset-bottom)) max(24px,env(safe-area-inset-left));box-sizing:border-box}'
    + 'main{max-width:28rem;text-align:start}'
    + '.mark{width:48px;height:48px;border-radius:12px;background:' + color + ';margin-block-end:24px}'
    + 'h1{font-size:20px;font-weight:600;margin:0 0 8px}'
    + 'p{margin:0 0 24px;color:var(--muted)}'
    + 'button{font:inherit;font-weight:500;min-height:40px;padding:0 14px;border:0;border-radius:8px;background:' + color + ';color:#fff;cursor:pointer}'
    + '[lang=ar]{line-height:1.7}'
    + '</style></head><body><main>'
    + '<div class="mark" aria-hidden="true"></div>'
    + '<div data-l="en"><h1>You are offline</h1><p>' + en + ' needs a connection to open this page. Check your connection, then try again.</p></div>'
    + '<div data-l="ar" lang="ar" dir="rtl" hidden><h1>أنت غير متصل بالإنترنت</h1><p>يحتاج ' + ar + ' إلى اتصال لفتح هذه الصفحة. تحقّق من اتصالك ثم حاول مرة أخرى.</p></div>'
    + '<button type="button" onclick="location.reload()"><span data-l="en">Try again</span><span data-l="ar" lang="ar" hidden>حاول مرة أخرى</span></button>'
    + '</main><script>'
    + 'try{var l=localStorage.getItem("i18n_language")||navigator.language||"";'
    + 'if(/^ar/i.test(l)){document.documentElement.lang="ar";document.documentElement.dir="rtl";document.title=' + JSON.stringify(CONFIG.name.ar) + ';'
    + 'document.querySelectorAll("[data-l]").forEach(function(e){e.hidden=e.getAttribute("data-l")!=="ar"})}}catch(e){}'
    + 'addEventListener("online",function(){location.reload()});'
    + '</script></body></html>';
  return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });
}
`;
}

/**
 * The worker served once the operator has switched installing off: it clears
 * the caches the app owned and unregisters itself, so a phone that installed
 * the app before stops running any of it — even one that never opens a page
 * that could unregister it.
 */
export function killSwitchWorkerSource(): string {
  return `/* Whity app worker: installing is switched off on this installation (#1733). */
'use strict';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith(${JSON.stringify(CACHE_PREFIX)})).map((k) => caches.delete(k)));
    await self.registration.unregister();
  })());
});
`;
}
