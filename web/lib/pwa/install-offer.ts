'use client';

/**
 * The offer to install the web app on a phone (`PwInstall`, PW.11).
 *
 * The browser fires `beforeinstallprompt` once, early, on Android and other
 * Chromium browsers, so `AppInstall` (always mounted) calls `startInstallOffer`
 * to catch and keep it until the More page asks for it. iPhone and iPad Safari
 * have no prompt at all: the reader is shown the three steps instead.
 *
 * The offer is for the browser only (never once installed) and is made once:
 * dismissing it, or answering the browser's prompt, is remembered in this
 * browser's local storage.
 */

import { useEffect, useState } from 'react';

export const INSTALL_DISMISSED_KEY = 'whity-install-dismissed';

export type InstallOfferKind = 'prompt' | 'ios';

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

type OfferState = {
  /** The switch for installing is on for this host. */
  enabled: boolean;
  /** The workspace's name and square icon, for the offer's wording. */
  name: string;
  iconUrl: string | null;
  deferred: BeforeInstallPromptEvent | null;
  installed: boolean;
  dismissed: boolean;
};

const state: OfferState = { enabled: false, name: 'Whity', iconUrl: null, deferred: null, installed: false, dismissed: false };
const listeners = new Set<() => void>();
let started = false;

const emit = () => listeners.forEach((listener) => listener());

function readDismissed(): boolean {
  try {
    return window.localStorage.getItem(INSTALL_DISMISSED_KEY) === '1';
  } catch {
    return false;
  }
}

/** Already running as an installed app: nothing left to offer. */
export function isStandalone(): boolean {
  try {
    if (window.matchMedia?.('(display-mode: standalone)').matches) return true;
  } catch {
    // fall through
  }
  return (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** iPhone or iPad Safari (not another browser on iOS, whose menu differs). */
export function isIosSafari(): boolean {
  const ua = navigator.userAgent;
  const ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  return ios && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|GSA/.test(ua);
}

/** Starts listening. Safe to call again; it only ever listens once. */
export function startInstallOffer(enabled: boolean, name = 'Whity', iconUrl: string | null = null): void {
  if (typeof window === 'undefined') return;
  const was = state.enabled;
  state.enabled = enabled;
  state.name = name;
  state.iconUrl = iconUrl;
  if (!started) {
    started = true;
    state.dismissed = readDismissed();
    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      state.deferred = event as BeforeInstallPromptEvent;
      emit();
    });
    window.addEventListener('appinstalled', () => {
      state.installed = true;
      state.deferred = null;
      emit();
    });
  }
  if (was !== enabled) emit();
  else if (enabled) emit();
}

/** Remembers that the reader has seen the offer, so it is not made again. */
export function dismissInstallOffer(): void {
  state.dismissed = true;
  state.deferred = null;
  try {
    window.localStorage.setItem(INSTALL_DISMISSED_KEY, '1');
  } catch {
    // Private window: it is asked again next time, which is harmless.
  }
  emit();
}

/** Shows the browser's own install prompt (Android and Chromium). */
export async function runInstallPrompt(): Promise<void> {
  const event = state.deferred;
  if (!event) return;
  state.deferred = null;
  try {
    await event.prompt();
    await event.userChoice;
  } catch {
    // The prompt can be used once; either answer ends the offer.
  }
  dismissInstallOffer();
}

/** Resets module state between tests. */
export function resetInstallOfferForTests(): void {
  state.enabled = false;
  state.deferred = null;
  state.installed = false;
  state.dismissed = false;
  started = false;
  listeners.clear();
}

/** What to offer right now: the browser's prompt, the iPhone steps, or nothing. */
export function useInstallOffer(): { kind: InstallOfferKind | null; name: string; iconUrl: string | null } {
  const [kind, setKind] = useState<InstallOfferKind | null>(null);
  const [, bump] = useState(0);

  useEffect(() => {
    const update = () => {
      bump((n) => n + 1);
      if (!state.enabled || state.installed || state.dismissed || isStandalone()) return setKind(null);
      if (state.deferred) return setKind('prompt');
      setKind(isIosSafari() ? 'ios' : null);
    };
    if (!started) state.dismissed = readDismissed();
    listeners.add(update);
    update();
    return () => {
      listeners.delete(update);
    };
  }, []);

  return { kind, name: state.name, iconUrl: state.iconUrl };
}
