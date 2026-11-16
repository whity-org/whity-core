/**
 * The notifications ask (PW.11) and Preferences › Notifications (WHIT-1075):
 * the system prompt only after a tap, asked once, iPhone only once installed,
 * this device on and off, other devices, kinds.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { PushOffer } from '@/components/pwa/push-offer';
import { PushSettings } from '@/components/settings/push-settings';
import { PUSH_ASK_NEVER_KEY, PUSH_ASK_SNOOZE_KEY, PUSH_ASK_SNOOZE_MS } from '@/lib/pwa/push';

jest.mock('@/lib/api/client', () => ({ api: { GET: jest.fn(), POST: jest.fn(), PUT: jest.fn(), DELETE: jest.fn() } }));
// eslint-disable-next-line @typescript-eslint/no-require-imports
const mockApi = (require('@/lib/api/client') as { api: Record<'GET' | 'POST' | 'PUT' | 'DELETE', jest.Mock> }).api;

const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const KEY = 'BAhoaDLCVPT4njlMR7WTEzFhgvPGD80C2bpXILnKIzRz3zF_a_LspwKezjpNf2oM7cxBWLsCqaBa_mEz_ls_2Pw';
const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/this-phone';

let permission: NotificationPermission;
let subscription: { endpoint: string; options: { applicationServerKey: null }; toJSON: () => unknown; unsubscribe: jest.Mock } | null;
const requestPermission = jest.fn(async () => {
  permission = 'granted';
  return permission;
});
const subscribe = jest.fn(async () => {
  subscription = {
    endpoint: ENDPOINT,
    options: { applicationServerKey: null },
    toJSON: () => ({ endpoint: ENDPOINT, keys: { p256dh: 'p', auth: 'a' } }),
    unsubscribe: jest.fn(async () => true),
  };
  return subscription;
});

async function hashOf(text: string): Promise<string> {
  const { createHash } = await import('node:crypto');
  return createHash('sha256').update(text).digest('hex');
}

function setUserAgent(ua: string, standalone = false) {
  Object.defineProperty(window.navigator, 'userAgent', { value: ua, configurable: true });
  window.matchMedia = ((query: string) => ({
    matches: standalone && query.includes('standalone'),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  })) as unknown as typeof window.matchMedia;
}

beforeAll(async () => {
  const { webcrypto } = await import('node:crypto');
  Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });
  const { TextEncoder } = await import('node:util');
  Object.defineProperty(globalThis, 'TextEncoder', { value: TextEncoder, configurable: true });
});

beforeEach(() => {
  jest.clearAllMocks();
  window.localStorage.clear();
  permission = 'default';
  subscription = null;
  setUserAgent(ANDROID);
  const registration = { pushManager: { getSubscription: async () => subscription, subscribe } };
  Object.defineProperty(window.navigator, 'serviceWorker', {
    value: { getRegistration: async () => registration, ready: Promise.resolve(registration) },
    configurable: true,
  });
  Object.defineProperty(window, 'PushManager', { value: function PushManager() {}, configurable: true });
  const NotificationMock = function Notification() {} as unknown as Record<string, unknown>;
  Object.defineProperty(NotificationMock, 'permission', { get: () => permission, configurable: true });
  NotificationMock.requestPermission = requestPermission;
  Object.defineProperty(window, 'Notification', { value: NotificationMock, configurable: true });
  mockApi.GET.mockImplementation(async (path: string) =>
    path === '/api/v1/me/push-subscriptions'
      ? { data: { public_key: KEY, data: [] } }
      : { data: { data: [], transactional_prefixes: [], plugin_types: [] } }
  );
});

describe('the notifications ask (PW.11)', () => {
  it('asks only after a tap, and stores this phone', async () => {
    mockApi.POST.mockResolvedValue({ data: { id: 1, public_key: KEY, data: [] } });
    render(<PushOffer />);
    await screen.findByTestId('push-offer');
    expect(requestPermission).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole('button', { name: 'Turn on notifications' }));

    expect(requestPermission).toHaveBeenCalledTimes(1);
    expect(subscribe).toHaveBeenCalledWith(expect.objectContaining({ userVisibleOnly: true }));
    expect(mockApi.POST).toHaveBeenCalledWith('/api/v1/me/push-subscriptions', {
      body: { endpoint: ENDPOINT, keys: { p256dh: 'p', auth: 'a' } },
    });
    await waitFor(() => expect(screen.queryByTestId('push-offer')).toBeNull());
    expect(window.localStorage.getItem(PUSH_ASK_NEVER_KEY)).toBe('1');
  });

  it('is worded around chat', async () => {
    render(<PushOffer />);
    expect(await screen.findByText('Get notified when someone messages you in chat?')).toBeTruthy();
  });

  it('Not now waits 14 days, then asks again', async () => {
    const { unmount } = render(<PushOffer />);
    await userEvent.click(await screen.findByRole('button', { name: 'Not now' }));
    expect(screen.queryByTestId('push-offer')).toBeNull();
    const until = Number(window.localStorage.getItem(PUSH_ASK_SNOOZE_KEY));
    expect(until - Date.now()).toBeGreaterThan(PUSH_ASK_SNOOZE_MS - 60_000);
    unmount();

    const again = render(<PushOffer />);
    await waitFor(() => expect(mockApi.GET).toHaveBeenCalled());
    expect(screen.queryByTestId('push-offer')).toBeNull();
    again.unmount();

    window.localStorage.setItem(PUSH_ASK_SNOOZE_KEY, String(Date.now() - 1));
    render(<PushOffer />);
    expect(await screen.findByTestId('push-offer')).toBeTruthy();
    expect(requestPermission).not.toHaveBeenCalled();
  });

  it("Don't ask again stops it", async () => {
    const { unmount } = render(<PushOffer />);
    await userEvent.click(await screen.findByRole('button', { name: "Don't ask again" }));
    expect(window.localStorage.getItem(PUSH_ASK_NEVER_KEY)).toBe('1');
    unmount();
    render(<PushOffer />);
    await waitFor(() => expect(mockApi.GET).toHaveBeenCalled());
    expect(screen.queryByTestId('push-offer')).toBeNull();
  });

  it('is not shown once the browser was answered', async () => {
    permission = 'denied';
    render(<PushOffer />);
    await waitFor(() => expect(mockApi.GET).toHaveBeenCalled());
    expect(screen.queryByTestId('push-offer')).toBeNull();
  });

  it('is not shown on an iPhone that has not installed the app', async () => {
    setUserAgent(IPHONE, false);
    render(<PushOffer />);
    await waitFor(() => expect(mockApi.GET).toHaveBeenCalled());
    expect(screen.queryByTestId('push-offer')).toBeNull();
  });

  it('is shown in the installed iPhone app', async () => {
    setUserAgent(IPHONE, true);
    render(<PushOffer />);
    expect(await screen.findByTestId('push-offer')).toBeTruthy();
  });
});

describe('Preferences › Notifications', () => {
  it('scrolls into view when reached by its link (WHIT-1078)', async () => {
    const scroll = jest.fn();
    Element.prototype.scrollIntoView = scroll;
    window.history.replaceState(null, '', '/settings?tab=preferences#notifications');
    render(<PushSettings />);
    expect(document.getElementById('notifications')).not.toBeNull();
    await waitFor(() => expect(scroll).toHaveBeenCalledWith({ block: 'start' }));
    window.history.replaceState(null, '', '/');
  });

  it('says to install first on an iPhone in Safari', async () => {
    setUserAgent(IPHONE, false);
    render(<PushSettings />);
    await waitFor(() => expect(screen.getByTestId('push-this-device-help').textContent).toMatch(/Home Screen first/));
    expect(screen.getByTestId('push-this-device')).toHaveProperty('disabled', true);
  });

  it('shows this device on, lists the others, and turns one off', async () => {
    permission = 'granted';
    await subscribe();
    const mine = await hashOf(ENDPOINT);
    const devices = [
      { id: 1, endpoint_hash: mine, user_agent: ANDROID, created_at: '2026-11-15 09:00:00', last_success_at: null, failing: false },
      { id: 2, endpoint_hash: 'other', user_agent: 'Mozilla/5.0 (Windows NT 10.0) Chrome/131 Safari/537.36', created_at: '2026-11-14 09:00:00', last_success_at: null, failing: false },
    ];
    mockApi.GET.mockImplementation(async (path: string) =>
      path === '/api/v1/me/push-subscriptions'
        ? { data: { public_key: KEY, data: devices } }
        : { data: { data: [{ type: 'document.comment.mention', channel: 'push', enabled: false }], transactional_prefixes: [], plugin_types: [] } }
    );
    mockApi.DELETE.mockResolvedValue({ data: { public_key: KEY, data: [devices[0]] } });
    mockApi.PUT.mockResolvedValue({ data: { data: [], transactional_prefixes: [], plugin_types: [] } });

    render(<PushSettings />);
    await waitFor(() => expect(screen.getByTestId('push-this-device').getAttribute('aria-checked')).toBe('true'));
    expect(screen.getByText('Chrome on Windows')).toBeTruthy();

    // N3: each kind is a row with its Push switch first, then Email.
    const mention = screen.getByText('Mentions in comments').closest('tr')!.querySelector('[role="switch"]')!;
    expect(mention.getAttribute('aria-checked')).toBe('false');
    await userEvent.click(mention);
    expect(mockApi.PUT).toHaveBeenCalledWith('/api/v1/me/notification-preferences', {
      body: { preferences: [{ type: 'document.comment.mention', channel: 'push', enabled: true }] },
    });

    await userEvent.click(screen.getByRole('button', { name: 'Turn off' }));
    expect(mockApi.DELETE).toHaveBeenCalledWith('/api/v1/me/push-subscriptions/{id}', { params: { path: { id: 2 } } });
    await waitFor(() => expect(screen.queryByText('Chrome on Windows')).toBeNull());
  });
});
