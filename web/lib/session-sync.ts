/**
 * Who you are and where you are, kept in step across the page and every tab
 * (owner, 2026-11-22: "switching tenants, and similar actions should
 * auto-refresh the page").
 *
 * THE PROBLEM. The session lives in httpOnly cookies shared by every tab of the
 * app. Switching workspace, signing in again or signing out changes those
 * cookies for ALL tabs at once, but each tab kept rendering what it had fetched
 * for the previous workspace: the switcher label, Billing, banners, settings.
 * Worse than stale, a stale tab would save into the NEW workspace while showing
 * the old one.
 *
 * THE APPROACH, in two halves:
 *
 * 1. In the tab that made the change, `AuthProvider` bumps a session epoch, and
 *    `SessionScope` (root layout) keys every workspace-dependent provider and
 *    the page on it. A new key remounts them, so every fetch runs again for the
 *    new session: a soft refresh, no document reload and no white flash. The
 *    page then lands on the same path when it can exist in the new workspace,
 *    or on its list page when it named a record of the old one
 *    ({@link landingPath}).
 *
 * 2. Every other tab hears about it here: a BroadcastChannel message, or a
 *    `storage` event where BroadcastChannel is missing. A tab whose identity
 *    differs from the one announced re-reads `/api/v1/me` and does the same
 *    remount. A tab also announces its identity whenever it loads, which is
 *    what catches a sign-in that went through a full-page redirect (Whity ID,
 *    choosing another account) rather than through this code.
 *
 * Framework-free on purpose: no React, no Next, so it is unit-tested alone.
 */

/** What happened, for the receiving tab's choice of reaction. */
export type SessionChangeKind =
  /** The active workspace changed. */
  | 'switched'
  /** A page loaded or a sign-in finished: this is who is signed in now. */
  | 'signed-in'
  /** Nobody is signed in any more. */
  | 'signed-out'
  /** Same person, same workspace, but what they may see changed (plan, role, membership). */
  | 'changed';

export interface SessionChange {
  kind: SessionChangeKind;
  /** {@link identityOf} of the session after the change; null when signed out. */
  identity: string | null;
  /** The announcing tab, so a tab ignores its own messages. */
  from: string;
  /** Unique per message, so a message delivered twice is acted on once. */
  id: string;
}

export const SESSION_CHANNEL = 'whity:session';
/** The localStorage key used as the fallback transport. */
export const SESSION_STORAGE_KEY = 'whity:session-change';

interface IdentityUser {
  id: number;
  tenant_id: number;
  role?: string;
}

/**
 * The parts of a session that decide what the screen may show: the person, the
 * active workspace and their role there. A display-name edit changes none of
 * these and so refreshes nothing. Changes this cannot see (ownership, plan,
 * extra roles) are announced explicitly with `sessionChanged()`.
 */
export function identityOf(user: IdentityUser | null): string | null {
  if (user === null) return null;
  return [user.id, user.tenant_id, user.role ?? ''].join(':');
}

/** The workspace part of an identity, or null. */
export function tenantOf(identity: string | null): string | null {
  if (identity === null) return null;
  return identity.split(':')[1] ?? null;
}

/**
 * A segment that names one record: a number, a uuid, or a long opaque token
 * with a digit in it (a ULID). Words joined by hyphens (`email-addresses`) are
 * sections, not records.
 */
const RECORD_SEGMENT = /^(\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|(?=[A-Za-z_]*\d)[A-Za-z0-9_]{16,})$/i;

/** Pages that only make sense for the workspace they were opened in. */
const WORKSPACE_BOUND = ['/ownership-offers', '/workspaces/new', '/setup', '/billing/return'];

export const HOME_PATH = '/dashboard';

/**
 * Where a tab lands after the workspace changed under it.
 *
 * The same page when it can exist in any workspace (a list, Billing, a settings
 * section), its list when it named one of the old workspace's records
 * (`/contacts/42` → `/contacts`), and the dashboard for pages tied to the old
 * workspace itself (an ownership offer, a payment return).
 */
export function landingPath(pathname: string): string {
  const path = pathname.replace(/\/+$/, '') || '/';
  if (WORKSPACE_BOUND.some((p) => path === p || path.startsWith(`${p}/`))) return HOME_PATH;
  const segments = path.split('/').filter(Boolean);
  const at = segments.findIndex((s) => RECORD_SEGMENT.test(s));
  if (at === -1) return path === '/' ? HOME_PATH : path;
  return at === 0 ? HOME_PATH : `/${segments.slice(0, at).join('/')}`;
}

function newId(): string {
  try {
    return globalThis.crypto.randomUUID();
  } catch {
    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }
}

/** This tab, for the lifetime of the page. */
export const TAB_ID = newId();

let channel: BroadcastChannel | null | undefined;

function getChannel(): BroadcastChannel | null {
  if (channel !== undefined) return channel;
  channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(SESSION_CHANNEL) : null;
  return channel;
}

/** For tests: forget the cached channel. */
export function resetSessionChannelForTests(): void {
  channel?.close();
  channel = undefined;
}

/** Tell every other tab what the session is now. Never throws. */
export function announceSessionChange(kind: SessionChangeKind, identity: string | null): void {
  if (typeof window === 'undefined') return;
  const message: SessionChange = { kind, identity, from: TAB_ID, id: newId() };
  try {
    const ch = getChannel();
    if (ch !== null) {
      ch.postMessage(message);
      return;
    }
  } catch {
    // Fall through to storage.
  }
  try {
    // A storage event fires in every OTHER tab of the origin on each write.
    window.localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(message));
  } catch {
    // Private windows may refuse storage; the other tabs catch up on their next request.
  }
}

function isSessionChange(value: unknown): value is SessionChange {
  if (typeof value !== 'object' || value === null) return false;
  const v = value as Record<string, unknown>;
  return (
    typeof v.kind === 'string' &&
    (typeof v.identity === 'string' || v.identity === null) &&
    typeof v.from === 'string' &&
    typeof v.id === 'string'
  );
}

/** Listen for other tabs' announcements. Returns the unsubscribe. */
export function subscribeSessionChanges(handler: (change: SessionChange) => void): () => void {
  if (typeof window === 'undefined') return () => undefined;
  const seen = new Set<string>();
  const deliver = (value: unknown): void => {
    if (!isSessionChange(value) || value.from === TAB_ID || seen.has(value.id)) return;
    seen.add(value.id);
    handler(value);
  };

  const onStorage = (event: StorageEvent): void => {
    if (event.key !== SESSION_STORAGE_KEY || event.newValue === null) return;
    try {
      deliver(JSON.parse(event.newValue));
    } catch {
      // Not ours.
    }
  };
  window.addEventListener('storage', onStorage);

  const ch = getChannel();
  const onMessage = (event: MessageEvent): void => deliver(event.data);
  ch?.addEventListener('message', onMessage);

  return () => {
    window.removeEventListener('storage', onStorage);
    ch?.removeEventListener('message', onMessage);
  };
}
