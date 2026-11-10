'use client';

/**
 * The browser side of installing the web app (#1733).
 *
 *  - `on`: registers `/sw.js`, and when a new version has installed and is
 *    waiting, offers to reload into it. The swap only happens when the reader
 *    says so: reloading under an open form would lose what they typed.
 *  - `off`: unregisters the app's worker and clears its caches, so switching
 *    installing off takes effect on the next page a reader opens.
 *  - `unknown` (the server could not ask the backend): touches nothing.
 *
 * It also points the manifest link at the reader's language, which only the
 * browser knows (it lives on their profile and in local storage), so an Arabic
 * reader installs the app under its Arabic name, right to left.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';

const SW_URL = '/sw.js';
const MANIFEST_PATH = '/manifest.webmanifest';
const CACHE_PREFIX = 'whity-';
/** How often an open app checks for a new version, at most. */
const UPDATE_CHECK_MS = 30 * 60 * 1000;

export type AppInstallState = 'on' | 'off' | 'unknown';

function isOurWorker(reg: ServiceWorkerRegistration): boolean {
  const worker = reg.active ?? reg.waiting ?? reg.installing;
  try {
    return worker !== null && new URL(worker.scriptURL).pathname === SW_URL;
  } catch {
    return false;
  }
}

async function removeInstalledApp(): Promise<void> {
  const regs = await navigator.serviceWorker.getRegistrations();
  await Promise.all(regs.filter(isOurWorker).map((reg) => reg.unregister()));
  if ('caches' in window) {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith(CACHE_PREFIX)).map((k) => caches.delete(k)));
  }
}

function useManifestLanguage(enabled: boolean): void {
  useEffect(() => {
    if (!enabled) return;
    const root = document.documentElement;
    const apply = () => {
      const link = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
      if (!link) return;
      const href = root.lang.toLowerCase().startsWith('ar') ? `${MANIFEST_PATH}?lang=ar` : MANIFEST_PATH;
      if (link.getAttribute('href') !== href) link.setAttribute('href', href);
    };
    apply();
    const observer = new MutationObserver(apply);
    observer.observe(root, { attributes: true, attributeFilter: ['lang'] });
    observer.observe(document.head, { childList: true });
    return () => observer.disconnect();
  }, [enabled]);
}

export function AppInstall({ state }: { state: AppInstallState }) {
  const t = useTranslation('common');
  const [waiting, setWaiting] = useState<ServiceWorker | null>(null);
  const reloading = useRef(false);

  useManifestLanguage(state === 'on');

  useEffect(() => {
    if (state === 'unknown' || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;

    if (state === 'off') {
      removeInstalledApp().catch(() => {});
      return;
    }

    let cancelled = false;
    let lastCheck = Date.now();
    let registration: ServiceWorkerRegistration | null = null;

    const offer = (worker: ServiceWorker | null) => {
      // Only an UPDATE waits for the reader. The first install has nothing to
      // replace, so there is no controller and nothing to ask.
      if (!cancelled && worker && navigator.serviceWorker.controller) setWaiting(worker);
    };

    const onControllerChange = () => {
      if (reloading.current) {
        window.location.reload();
      }
    };
    navigator.serviceWorker.addEventListener('controllerchange', onControllerChange);

    const onVisible = () => {
      if (document.visibilityState === 'visible' && registration && Date.now() - lastCheck > UPDATE_CHECK_MS) {
        lastCheck = Date.now();
        registration.update().catch(() => {});
      }
    };
    document.addEventListener('visibilitychange', onVisible);

    navigator.serviceWorker
      .register(SW_URL, { scope: '/', updateViaCache: 'none' })
      .then((reg) => {
        registration = reg;
        offer(reg.waiting);
        reg.addEventListener('updatefound', () => {
          const installing = reg.installing;
          installing?.addEventListener('statechange', () => {
            if (installing.state === 'installed') offer(reg.waiting ?? installing);
          });
        });
      })
      .catch(() => {
        // Not installable here (an insecure origin, a private window): the app
        // still works as a website.
      });

    return () => {
      cancelled = true;
      navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [state]);

  const reload = useCallback(() => {
    if (!waiting) return;
    reloading.current = true;
    waiting.postMessage({ type: 'SKIP_WAITING' });
  }, [waiting]);

  if (!waiting) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      className="fixed inset-x-4 z-50 mx-auto flex max-w-md items-center gap-3 rounded-card border bg-popover p-3 text-popover-foreground shadow-lg"
      style={{ bottom: 'calc(1rem + env(safe-area-inset-bottom))' }}
    >
      <p className="flex-1 text-sm">{t('pwa.update.ready', 'A new version is ready.')}</p>
      <Button variant="ghost" size="sm" onClick={() => setWaiting(null)}>
        {t('pwa.update.later', 'Later')}
      </Button>
      {/* Outline, not filled: the screen underneath keeps its one main button. */}
      <Button variant="outline" size="sm" onClick={reload}>
        {t('pwa.update.reload', 'Reload')}
      </Button>
    </div>
  );
}
