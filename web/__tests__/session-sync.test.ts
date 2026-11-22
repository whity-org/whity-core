/**
 * The pieces of "switching workspace refreshes the page" that need no React
 * (owner, 2026-11-22): what counts as a different session, where a tab lands,
 * and how the other tabs hear about it.
 */

import {
  announceSessionChange,
  identityOf,
  landingPath,
  resetSessionChannelForTests,
  SESSION_STORAGE_KEY,
  subscribeSessionChanges,
  TAB_ID,
  tenantOf,
  type SessionChange,
} from '@/lib/session-sync';

/** A same-process BroadcastChannel: every instance with the name hears every other. */
class FakeChannel {
  static open: FakeChannel[] = [];
  private listeners = new Set<(e: MessageEvent) => void>();
  constructor(readonly name: string) {
    FakeChannel.open.push(this);
  }
  postMessage(data: unknown): void {
    for (const other of FakeChannel.open) {
      if (other !== this && other.name === this.name) {
        other.listeners.forEach((l) => l({ data } as MessageEvent));
      }
    }
  }
  addEventListener(_: 'message', l: (e: MessageEvent) => void): void {
    this.listeners.add(l);
  }
  removeEventListener(_: 'message', l: (e: MessageEvent) => void): void {
    this.listeners.delete(l);
  }
  close(): void {
    FakeChannel.open = FakeChannel.open.filter((c) => c !== this);
  }
}

const fromOtherTab = (over: Partial<SessionChange> = {}): SessionChange => ({
  kind: 'switched',
  identity: '7:2:admin',
  from: 'another-tab',
  id: Math.random().toString(36),
  ...over,
});

describe('identityOf', () => {
  it('is the person, the workspace and the role', () => {
    expect(identityOf({ id: 7, tenant_id: 2, role: 'admin' })).toBe('7:2:admin');
    expect(tenantOf('7:2:admin')).toBe('2');
  });

  it('is null when nobody is signed in', () => {
    expect(identityOf(null)).toBeNull();
    expect(tenantOf(null)).toBeNull();
  });

  it('does not change for a display-name edit', () => {
    const before = identityOf({ id: 7, tenant_id: 2, role: 'admin' });
    const after = identityOf({ id: 7, tenant_id: 2, role: 'admin', display_name: 'Amro' } as never);
    expect(after).toBe(before);
  });
});

describe('landingPath', () => {
  it.each([
    ['/contacts', '/contacts'],
    ['/billing', '/billing'],
    ['/admin/settings/email-addresses', '/admin/settings/email-addresses'],
    ['/contacts/42', '/contacts'],
    ['/admin/document-library/12/edit', '/admin/document-library'],
    ['/forms/0b6f1c1e-6d1c-4f7a-9d55-2f0c7c1d9a10', '/forms'],
    ['/sales/quotes/01JD3Z8Q4X2N7M5K9P1R6T0V3W', '/sales/quotes'],
    ['/ownership-offers/5', '/dashboard'],
    ['/workspaces/new', '/dashboard'],
    ['/billing/return', '/dashboard'],
    ['/', '/dashboard'],
    ['/contacts/', '/contacts'],
  ])('%s → %s', (from, to) => {
    expect(landingPath(from)).toBe(to);
  });
});

describe('announce and subscribe, over BroadcastChannel', () => {
  const original = (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel;

  beforeEach(() => {
    (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel = FakeChannel;
    resetSessionChannelForTests();
  });

  afterEach(() => {
    resetSessionChannelForTests();
    FakeChannel.open = [];
    (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel = original;
  });

  it('posts what changed, from this tab', () => {
    const otherTab = new FakeChannel('whity:session');
    const heard: unknown[] = [];
    otherTab.addEventListener('message', (e) => heard.push(e.data));

    announceSessionChange('switched', '7:3:member');

    expect(heard).toEqual([
      expect.objectContaining({ kind: 'switched', identity: '7:3:member', from: TAB_ID }),
    ]);
  });

  it('delivers another tab’s message once, and never this tab’s own', () => {
    const handler = jest.fn();
    const stop = subscribeSessionChanges(handler);
    const otherTab = new FakeChannel('whity:session');

    const message = fromOtherTab();
    otherTab.postMessage(message);
    otherTab.postMessage(message);
    otherTab.postMessage(fromOtherTab({ from: TAB_ID }));

    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler).toHaveBeenCalledWith(message);

    stop();
    otherTab.postMessage(fromOtherTab());
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('ignores anything that is not a session message', () => {
    const handler = jest.fn();
    subscribeSessionChanges(handler);
    const otherTab = new FakeChannel('whity:session');
    otherTab.postMessage({ hello: 'there' });
    otherTab.postMessage('switched');
    expect(handler).not.toHaveBeenCalled();
  });
});

describe('announce and subscribe, over storage events', () => {
  const original = (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel;

  beforeEach(() => {
    delete (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel;
    resetSessionChannelForTests();
    window.localStorage.clear();
  });

  afterEach(() => {
    (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel = original;
    resetSessionChannelForTests();
  });

  it('writes the message where the other tabs’ storage event reads it', () => {
    announceSessionChange('signed-out', null);
    const stored = JSON.parse(window.localStorage.getItem(SESSION_STORAGE_KEY) ?? 'null');
    expect(stored).toEqual(expect.objectContaining({ kind: 'signed-out', identity: null, from: TAB_ID }));
  });

  it('hears another tab through the storage event', () => {
    const handler = jest.fn();
    subscribeSessionChanges(handler);
    const message = fromOtherTab({ kind: 'signed-in' });

    window.dispatchEvent(new StorageEvent('storage', { key: SESSION_STORAGE_KEY, newValue: JSON.stringify(message) }));
    window.dispatchEvent(new StorageEvent('storage', { key: 'something-else', newValue: JSON.stringify(fromOtherTab()) }));
    window.dispatchEvent(new StorageEvent('storage', { key: SESSION_STORAGE_KEY, newValue: 'not json' }));

    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler).toHaveBeenCalledWith(message);
  });
});
