/**
 * Switching workspace, and every other change of who or where you are,
 * refreshes what is on screen and in the other tabs (owner, 2026-11-22:
 * "switching tenants, and similar actions should auto-refresh the page").
 *
 * The app has no shared query cache, so the refresh is a remount of the
 * workspace-dependent tree (`SessionScope`), keyed on the session epoch that
 * `AuthProvider` bumps. A remount re-runs every page's and provider's fetch.
 */

import React, { useEffect } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { AuthProvider, useAuth } from '@/lib/auth-context';
import { SessionScope } from '@/lib/session-scope';
import { SESSION_STORAGE_KEY, resetSessionChannelForTests, type SessionChange } from '@/lib/session-sync';

const mockGet = jest.fn();
const mockPost = jest.fn();
jest.mock('@/lib/api/client', () => ({
  SESSION_EXPIRED_EVENT: 'whity:session-expired',
  api: {
    GET: (...a: unknown[]) => mockGet(...a),
    POST: (...a: unknown[]) => mockPost(...a),
  },
}));
jest.mock('@/lib/pwa/push', () => ({ forgetPushOnSignOut: jest.fn(async () => undefined) }));

const mockReplace = jest.fn();
let mockPathname = '/contacts/42';
jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: mockReplace, push: jest.fn(), refresh: jest.fn() }),
  usePathname: () => mockPathname,
}));

const reply = (status: number, body?: unknown) => ({
  data: status < 400 ? body : undefined,
  error: status < 400 ? undefined : body,
  response: { ok: status < 400, status },
});

const me = (tenantId: number, role = 'admin') => ({
  user: { id: 7, email: 'amro@example.com', role, tenant_id: tenantId },
  memberships: [
    { tenant_id: 1, tenant_name: 'Example Bakery', role: 'admin' },
    { tenant_id: 2, tenant_name: 'Example Cafe', role: 'member' },
  ],
});

/** Stands in for a page and its fetches: counts how often it mounts. */
let mounts = 0;
function Page() {
  const { user } = useAuth();
  useEffect(() => {
    mounts += 1;
  }, []);
  return <p data-testid="page">workspace {user?.tenant_id ?? 'none'}</p>;
}

const grabbed: { auth?: ReturnType<typeof useAuth> } = {};
function Grab() {
  const auth = useAuth();
  useEffect(() => {
    grabbed.auth = auth;
  });
  return null;
}

function renderApp() {
  return render(
    <AuthProvider>
      <Grab />
      <SessionScope>
        <Page />
      </SessionScope>
    </AuthProvider>,
  );
}

/** Another tab of the app, speaking through the storage fallback (jsdom has no BroadcastChannel). */
function otherTabSays(change: Partial<SessionChange>) {
  const message: SessionChange = {
    kind: 'switched',
    identity: '7:2:member',
    from: 'other-tab',
    id: Math.random().toString(36),
    ...change,
  };
  act(() => {
    window.dispatchEvent(new StorageEvent('storage', { key: SESSION_STORAGE_KEY, newValue: JSON.stringify(message) }));
  });
}

function lastAnnouncement(): SessionChange | null {
  return JSON.parse(window.localStorage.getItem(SESSION_STORAGE_KEY) ?? 'null');
}

beforeEach(() => {
  mounts = 0;
  mockPathname = '/contacts/42';
  mockGet.mockReset();
  mockPost.mockReset();
  mockReplace.mockReset();
  window.localStorage.clear();
  delete (globalThis as { BroadcastChannel?: unknown }).BroadcastChannel;
  resetSessionChannelForTests();
});

async function signedInTo(tenantId: number) {
  mockGet.mockResolvedValue(reply(200, me(tenantId, tenantId === 1 ? 'admin' : 'member')));
  renderApp();
  await screen.findByText(`workspace ${tenantId}`);
}

describe('switching workspace in this tab', () => {
  it('remounts the page for the new workspace and lands on the list', async () => {
    await signedInTo(1);
    expect(mounts).toBe(1);
    // The tab says who it is when it loads, so stale tabs can follow.
    expect(lastAnnouncement()).toEqual(expect.objectContaining({ kind: 'signed-in', identity: '7:1:admin' }));

    mockPost.mockResolvedValueOnce(reply(200, {}));
    mockGet.mockResolvedValue(reply(200, me(2, 'member')));
    await act(async () => {
      await grabbed.auth!.switchTenant(2);
    });

    expect(screen.getByTestId('page')).toHaveTextContent('workspace 2');
    expect(mounts).toBe(2);
    // /contacts/42 was the old workspace's record.
    expect(mockReplace).toHaveBeenCalledWith('/contacts');
    expect(lastAnnouncement()).toEqual(expect.objectContaining({ kind: 'switched', identity: '7:2:member' }));
  });

  it('stays on a page every workspace has', async () => {
    mockPathname = '/billing';
    await signedInTo(1);

    mockPost.mockResolvedValueOnce(reply(200, {}));
    mockGet.mockResolvedValue(reply(200, me(2, 'member')));
    await act(async () => {
      await grabbed.auth!.switchTenant(2);
    });

    expect(mounts).toBe(2);
    expect(mockReplace).not.toHaveBeenCalled();
  });

  it('leaves the navigation to a caller that goes somewhere itself', async () => {
    await signedInTo(1);

    mockPost.mockResolvedValueOnce(reply(200, {}));
    mockGet.mockResolvedValue(reply(200, me(2, 'member')));
    await act(async () => {
      await grabbed.auth!.switchTenant(2, { land: false });
    });

    expect(mounts).toBe(2);
    expect(mockReplace).not.toHaveBeenCalled();
  });

  it('changes nothing when the switch is refused', async () => {
    await signedInTo(1);
    const before = lastAnnouncement();

    mockPost.mockResolvedValueOnce(reply(403, { error: 'Access to the requested tenant is forbidden' }));
    await act(async () => {
      await expect(grabbed.auth!.switchTenant(2)).rejects.toThrow();
    });

    expect(screen.getByTestId('page')).toHaveTextContent('workspace 1');
    expect(mounts).toBe(1);
    expect(lastAnnouncement()).toEqual(before);
  });
});

describe('a plan or ownership change (same workspace)', () => {
  it('refreshes the page without moving it, and tells the other tabs', async () => {
    await signedInTo(1);

    await act(async () => {
      await grabbed.auth!.sessionChanged();
    });

    expect(mounts).toBe(2);
    expect(mockReplace).not.toHaveBeenCalled();
    expect(lastAnnouncement()).toEqual(expect.objectContaining({ kind: 'changed', identity: '7:1:admin' }));
  });

  it('on a one-time page, only tells the other tabs', async () => {
    await signedInTo(1);
    window.localStorage.clear();

    await act(async () => {
      await grabbed.auth!.sessionChanged({ here: false });
    });

    expect(mounts).toBe(1);
    expect(lastAnnouncement()).toEqual(expect.objectContaining({ kind: 'changed' }));
  });

  it('does not refresh for a display-name edit', async () => {
    await signedInTo(1);
    window.localStorage.clear();

    mockGet.mockResolvedValue(reply(200, { ...me(1), user: { ...me(1).user, display_name: 'Amro Saleh' } }));
    await act(async () => {
      await grabbed.auth!.refreshAuth();
    });

    expect(mounts).toBe(1);
    expect(lastAnnouncement()).toBeNull();
  });
});

describe('another tab changed the session', () => {
  it('follows a switch made in another tab', async () => {
    await signedInTo(1);
    window.localStorage.clear();

    mockGet.mockResolvedValue(reply(200, me(2, 'member')));
    otherTabSays({ kind: 'switched', identity: '7:2:member' });

    await waitFor(() => expect(screen.getByTestId('page')).toHaveTextContent('workspace 2'));
    expect(mounts).toBe(2);
    expect(mockReplace).toHaveBeenCalledWith('/contacts');
    // Following is silent: two tabs never echo each other.
    expect(lastAnnouncement()).toBeNull();
  });

  it('follows a sign-in with another account or workspace (a new tab saying who it is)', async () => {
    await signedInTo(1);

    mockGet.mockResolvedValue(reply(200, me(2, 'member')));
    otherTabSays({ kind: 'signed-in', identity: '7:2:member' });

    await waitFor(() => expect(screen.getByTestId('page')).toHaveTextContent('workspace 2'));
  });

  it('ignores a tab that is already where this one is', async () => {
    await signedInTo(1);
    const calls = mockGet.mock.calls.length;

    otherTabSays({ kind: 'signed-in', identity: '7:1:admin' });

    expect(mockGet.mock.calls.length).toBe(calls);
    expect(mounts).toBe(1);
  });

  it('refreshes for a plan change in another tab, without moving', async () => {
    await signedInTo(1);

    otherTabSays({ kind: 'changed', identity: '7:1:admin' });

    await waitFor(() => expect(mounts).toBe(2));
    expect(mockReplace).not.toHaveBeenCalled();
  });

  it('signs out when another tab signed out', async () => {
    await signedInTo(1);

    otherTabSays({ kind: 'signed-out', identity: null });

    await waitFor(() => expect(screen.getByTestId('page')).toHaveTextContent('workspace none'));
  });
});

describe('signing out here', () => {
  it('tells every other tab', async () => {
    await signedInTo(1);
    mockPost.mockResolvedValueOnce(reply(204));

    await act(async () => {
      await grabbed.auth!.logout();
    });

    expect(lastAnnouncement()).toEqual(expect.objectContaining({ kind: 'signed-out', identity: null }));
  });
});
