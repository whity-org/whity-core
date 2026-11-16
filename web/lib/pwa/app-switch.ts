'use client';

/**
 * Using Whity in a phone's browser instead of the app (WHIT-1155). The owner:
 * "If the website is open from a phone not a PWA ask the user to switch the app
 * if installed and if not offer him to install".
 *
 * WHAT IT OFFERS, in order:
 *  - **open**: the app is installed on this phone (Chrome on Android tells us
 *    through `navigator.getInstalledRelatedApps()`, which needs the manifest to
 *    list itself under `related_applications`). A web page can't open an
 *    installed web app from a browser tab, so the sheet says how: from the home
 *    screen, or Chrome's own "Open in app". No button pretends to do it.
 *  - **install**: Android and other Chromium browsers, with the browser's own
 *    install prompt (the `beforeinstallprompt` event `install-offer.ts` keeps).
 *  - **ios**: iPhone and iPad Safari, which has no prompt: the three steps, and
 *    that notifications on iPhone only work from the installed app.
 *
 * WHEN. Only on a phone, only in the browser (never in the installed app), only
 * where installing is switched on, after sign-in (the sheet lives in the signed-in
 * shell, so public pages never show it). "Not now" waits 7 days on this device;
 * it never shows twice in one visit.
 */

import { useEffect, useState } from 'react';
import { isIosSafari, isStandalone, runInstallPrompt, useInstallOffer } from '@/lib/pwa/install-offer';

export type AppSwitchOffer = 'open' | 'install' | 'ios';

/** "Not now": the time (ms) the sheet may show again, per device. */
export const APP_SWITCH_SNOOZE_KEY = 'whity-app-switch-snoozed-until';
export const APP_SWITCH_SNOOZE_MS = 7 * 24 * 60 * 60 * 1000;

/** Shown once this visit (a tab's session). */
export const APP_SWITCH_SHOWN_KEY = 'whity-app-switch-shown';

export type AppSwitchFacts = {
  /** Installing is switched on for this host. */
  enabled: boolean;
  /** Running as the installed app. */
  standalone: boolean;
  /** A phone: a touch screen under 768px wide, or a phone's browser. */
  phone: boolean;
  /** The app is installed on this device; null when the browser can't tell. */
  installed: boolean | null;
  /** The browser's install prompt is ready. */
  canPrompt: boolean;
  /** iPhone or iPad Safari. */
  iosSafari: boolean;
  /** When "Not now" lets the sheet show again (ms), or null. */
  snoozedUntil: number | null;
  /** Already shown in this visit. */
  shownThisVisit: boolean;
  now: number;
};

/** What to offer, if anything. Pure, so the rules are tested directly. */
export function decideAppSwitch(f: AppSwitchFacts): AppSwitchOffer | null {
  if (!f.enabled || f.standalone || !f.phone || f.shownThisVisit) return null;
  if (f.snoozedUntil !== null && f.snoozedUntil > f.now) return null;
  if (f.installed === true) return 'open';
  if (f.canPrompt) return 'install';
  if (f.iosSafari) return 'ios';
  return null;
}

/** A phone: coarse pointer and narrow, or a phone's user agent. */
export function isPhone(): boolean {
  if (typeof window === 'undefined') return false;
  let coarse = false;
  try {
    coarse = window.matchMedia?.('(pointer: coarse)').matches ?? false;
  } catch {
    coarse = false;
  }
  const ua = navigator.userAgent;
  const phoneUa = /Android.+Mobile|iPhone|iPod/.test(ua);
  return (coarse && window.innerWidth < 768) || phoneUa;
}

type RelatedApp = { platform: string; url?: string; id?: string };

/** Whether this web app is installed here, when the browser can say (Chrome on Android). */
export async function isAppInstalled(): Promise<boolean | null> {
  const nav = navigator as Navigator & { getInstalledRelatedApps?: () => Promise<RelatedApp[]> };
  if (typeof nav.getInstalledRelatedApps !== 'function') return null;
  try {
    const apps = await nav.getInstalledRelatedApps();
    return apps.some((app) => app.platform === 'webapp');
  } catch {
    return null;
  }
}

function readNumber(key: string): number | null {
  try {
    const raw = window.localStorage.getItem(key);
    const n = raw === null ? NaN : Number(raw);
    return Number.isFinite(n) ? n : null;
  } catch {
    return null;
  }
}

function shownThisVisit(): boolean {
  try {
    return window.sessionStorage.getItem(APP_SWITCH_SHOWN_KEY) === '1';
  } catch {
    return false;
  }
}

/** Remember the sheet was shown in this visit. */
export function markAppSwitchShown(): void {
  try {
    window.sessionStorage.setItem(APP_SWITCH_SHOWN_KEY, '1');
  } catch {
    // Without session storage it may show again after a reload; harmless.
  }
}

/** "Not now": 7 days on this device. */
export function snoozeAppSwitch(now = Date.now()): void {
  try {
    window.localStorage.setItem(APP_SWITCH_SNOOZE_KEY, String(now + APP_SWITCH_SNOOZE_MS));
  } catch {
    // Private window: asked again next visit, which is harmless.
  }
}

/**
 * The offer for this visit, decided once the browser has answered whether the
 * app is installed. Stays the same for the visit once shown.
 */
export function useAppSwitch(): { offer: AppSwitchOffer | null; name: string; iconUrl: string | null; install: () => Promise<void> } {
  const install = useInstallOffer();
  const [installed, setInstalled] = useState<boolean | null | undefined>(undefined);
  const [offer, setOffer] = useState<AppSwitchOffer | null>(null);

  useEffect(() => {
    let alive = true;
    void isAppInstalled().then((answer) => alive && setInstalled(answer));
    return () => {
      alive = false;
    };
  }, []);

  useEffect(() => {
    if (installed === undefined || offer !== null) return;
    // Decided in a task, not during the effect, so React renders once.
    let alive = true;
    const timer = window.setTimeout(() => {
      if (!alive) return;
      const decided = decideAppSwitch({
        enabled: install.enabled,
        standalone: isStandalone(),
        phone: isPhone(),
        installed,
        canPrompt: install.canPrompt,
        iosSafari: isIosSafari(),
        snoozedUntil: readNumber(APP_SWITCH_SNOOZE_KEY),
        shownThisVisit: shownThisVisit(),
        now: Date.now(),
      });
      if (decided !== null) {
        markAppSwitchShown();
        setOffer(decided);
      }
    }, 0);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [installed, install.enabled, install.canPrompt, offer]);

  return { offer, name: install.name, iconUrl: install.iconUrl, install: runInstallPrompt };
}
