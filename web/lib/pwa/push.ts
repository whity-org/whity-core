'use client';

/**
 * Web push on this device (WHIT-1075, PW.11).
 *
 * The browser's permission prompt only ever follows a tap ({@link turnOnPush}
 * is called from a button), never a page load. Push needs the app's service
 * worker, so it works where installing is switched on. On iPhone and iPad,
 * Safari offers push only to an app added to the Home Screen (iOS 16.4 and
 * later), so there the answer is "install first".
 *
 * WHAT "ON" MEANS. A device is on for a workspace when the server lists this
 * browser's subscription for the person in that workspace. The browser's own
 * subscription is shared by every workspace on this site, so turning one
 * workspace off only removes that workspace's row; signing out unsubscribes
 * the browser itself, so a shared device stops getting anyone's
 * notifications.
 */

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { isIosSafari, isStandalone } from '@/lib/pwa/install-offer';

export type PushSupport = 'ready' | 'needs-install' | 'denied' | 'unsupported';

/**
 * Where a person turns notifications on and chooses kinds: the Preferences
 * tab, scrolled to the Notifications card (WHIT-1078). Every entry links here:
 * More on a phone, the account menu, Ctrl+K.
 */
export const NOTIFICATIONS_HREF = '/settings?tab=preferences#notifications';

/** The Notifications card's anchor. */
export const NOTIFICATIONS_ANCHOR = 'notifications';

export type PushDevice = {
  id: number;
  endpoint_hash: string;
  user_agent: string;
  created_at: string;
  last_success_at: string | null;
  failing: boolean;
};

/** "Don't ask again" on the ask card, remembered in this browser (WHIT-1077). */
export const PUSH_ASK_NEVER_KEY = 'whity-push-ask-never';

/** "Not now": the time (ms) the card may show again. */
export const PUSH_ASK_SNOOZE_KEY = 'whity-push-ask-snoozed-until';

/** How long "Not now" waits before asking again. */
export const PUSH_ASK_SNOOZE_MS = 14 * 24 * 60 * 60 * 1000;

/** What this browser can do about push, before anything is asked. */
export function pushSupport(): PushSupport {
  if (typeof window === 'undefined') return 'unsupported';
  const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
  if (ios && !isStandalone()) return isIosSafari() ? 'needs-install' : 'unsupported';
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
  if (Notification.permission === 'denied') return 'denied';
  return 'ready';
}

/** The application server key as the browser wants it. */
export function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const padded = base64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64url.length % 4)) % 4);
  const raw = atob(padded);
  const out = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

/** SHA-256 of the endpoint, hex: how the server lists this browser. */
export async function endpointHash(endpoint: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
  if (!('serviceWorker' in navigator)) return null;
  const existing = await navigator.serviceWorker.getRegistration();
  return existing ?? null;
}

async function currentSubscription(): Promise<PushSubscription | null> {
  try {
    const reg = await registration();
    return reg ? await reg.pushManager.getSubscription() : null;
  } catch {
    return null;
  }
}

/** Whether a key the browser was subscribed with is the server's key. */
function sameKey(subscription: PushSubscription, publicKey: string): boolean {
  const key = subscription.options?.applicationServerKey;
  if (!key) return true;
  const a = new Uint8Array(key);
  const b = keyBytes(publicKey);
  return a.length === b.length && a.every((v, i) => v === b[i]);
}

/**
 * Turn push on for this device. Call only from a tap: it may show the
 * browser's permission prompt. Resolves to the person's devices, or an
 * error word the caller shows in plain language.
 */
export async function turnOnPush(): Promise<{ devices: PushDevice[] } | { error: 'denied' | 'unsupported' | 'failed' }> {
  const support = pushSupport();
  if (support !== 'ready') return { error: support === 'denied' ? 'denied' : 'unsupported' };
  const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
  if (permission !== 'granted') return { error: permission === 'denied' ? 'denied' : 'failed' };

  const listed = await api.GET('/api/v1/me/push-subscriptions');
  const publicKey = listed.data?.public_key;
  if (!publicKey) return { error: 'failed' };

  try {
    const reg = (await registration()) ?? (await navigator.serviceWorker.ready);
    let subscription = await reg.pushManager.getSubscription();
    if (subscription && !sameKey(subscription, publicKey)) {
      await subscription.unsubscribe();
      subscription = null;
    }
    subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
    const json = subscription.toJSON();
    const { data, error } = await api.POST('/api/v1/me/push-subscriptions', {
      body: { endpoint: json.endpoint ?? '', keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' } },
    });
    if (error || !data) return { error: 'failed' };
    return { devices: data.data };
  } catch {
    return { error: 'failed' };
  }
}

/** Stop push to one of the person's devices in this workspace. */
export async function removePushDevice(id: number): Promise<PushDevice[] | null> {
  const { data, error } = await api.DELETE('/api/v1/me/push-subscriptions/{id}', { params: { path: { id } } });
  return error || !data ? null : data.data;
}

/**
 * Signing out: this browser stops getting anyone's notifications. The server
 * rows go when the push services report the subscription gone.
 */
export async function forgetPushOnSignOut(): Promise<void> {
  const subscription = await currentSubscription();
  try {
    await subscription?.unsubscribe();
  } catch {
    // Nothing to undo: the browser keeps no other state for it.
  }
}

/** Whether the card is waiting: "Don't ask again", or "Not now" less than 14 days ago. */
export function pushAskDismissed(now = Date.now()): boolean {
  try {
    if (window.localStorage.getItem(PUSH_ASK_NEVER_KEY) === '1') return true;
    const until = Number(window.localStorage.getItem(PUSH_ASK_SNOOZE_KEY) ?? '');
    return Number.isFinite(until) && until > now;
  } catch {
    return false;
  }
}

/** "Not now" snoozes for 14 days; "Don't ask again" (or any answer to the browser) stops it. */
export function dismissPushAsk(how: 'snooze' | 'never', now = Date.now()): void {
  try {
    if (how === 'never') window.localStorage.setItem(PUSH_ASK_NEVER_KEY, '1');
    else window.localStorage.setItem(PUSH_ASK_SNOOZE_KEY, String(now + PUSH_ASK_SNOOZE_MS));
  } catch {
    // Private window: asked again next time, which is harmless.
  }
}

export type PushState = {
  loading: boolean;
  support: PushSupport;
  /** This browser is on for the person in this workspace. */
  on: boolean;
  /** The id of this browser's row, when on. */
  currentId: number | null;
  devices: PushDevice[];
  busy: boolean;
  error: 'denied' | 'unsupported' | 'failed' | null;
  turnOn: () => Promise<boolean>;
  turnOff: () => Promise<void>;
  remove: (id: number) => Promise<void>;
};

/** This device's push state and the person's devices, for the active workspace. */
export function usePush(): PushState {
  const [loading, setLoading] = useState(true);
  const [support, setSupport] = useState<PushSupport>('unsupported');
  const [devices, setDevices] = useState<PushDevice[]>([]);
  const [currentHash, setCurrentHash] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<PushState['error']>(null);

  const refreshCurrent = useCallback(async () => {
    const subscription = await currentSubscription();
    setCurrentHash(subscription ? await endpointHash(subscription.endpoint) : null);
  }, []);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const [listed] = await Promise.all([api.GET('/api/v1/me/push-subscriptions'), refreshCurrent()]);
      if (!alive) return;
      setSupport(pushSupport());
      setDevices(listed?.data?.data ?? []);
      setLoading(false);
    })();
    return () => {
      alive = false;
    };
  }, [refreshCurrent]);

  const current = currentHash ? devices.find((d) => d.endpoint_hash === currentHash) ?? null : null;

  const turnOn = useCallback(async () => {
    setBusy(true);
    setError(null);
    const result = await turnOnPush();
    setSupport(pushSupport());
    if ('error' in result) {
      setError(result.error);
      setBusy(false);
      return false;
    }
    setDevices(result.devices);
    await refreshCurrent();
    setBusy(false);
    return true;
  }, [refreshCurrent]);

  const remove = useCallback(async (id: number) => {
    setBusy(true);
    const next = await removePushDevice(id);
    if (next) setDevices(next);
    else setError('failed');
    setBusy(false);
  }, []);

  const turnOff = useCallback(async () => {
    if (current) await remove(current.id);
  }, [current, remove]);

  return { loading, support, on: current !== null, currentId: current?.id ?? null, devices, busy, error, turnOn, turnOff, remove };
}

/** "Chrome on Android" from a user agent, for the devices list. */
export function deviceLabel(userAgent: string): { browser: string; os: string } {
  const ua = userAgent;
  const browser = /Edg\//.test(ua)
    ? 'Edge'
    : /SamsungBrowser/.test(ua)
      ? 'Samsung Internet'
      : /Firefox\//.test(ua)
        ? 'Firefox'
        : /OPR\//.test(ua)
          ? 'Opera'
          : /Chrome\//.test(ua)
            ? 'Chrome'
            : /Safari\//.test(ua)
              ? 'Safari'
              : '';
  const os = /Android/.test(ua)
    ? 'Android'
    : /iPhone|iPad|iPod/.test(ua)
      ? 'iPhone'
      : /Windows/.test(ua)
        ? 'Windows'
        : /Mac OS X|Macintosh/.test(ua)
          ? 'Mac'
          : /Linux/.test(ua)
            ? 'Linux'
            : '';
  return { browser, os };
}
