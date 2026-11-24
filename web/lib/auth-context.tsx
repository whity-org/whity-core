'use client';

import { createContext, useContext, useState, useEffect, useCallback, useRef, type ReactNode } from 'react';
import { WorkspaceSwitchRefused } from '@/lib/workspace-switch';
import { api, SESSION_EXPIRED_EVENT } from './api/client';
import { forgetPushOnSignOut } from '@/lib/pwa/push';
import { oneEntryPerWorkspace } from '@/lib/workspace-memberships';
import {
  announceSessionChange,
  identityOf,
  subscribeSessionChanges,
  tenantOf,
  type SessionChangeKind,
} from '@/lib/session-sync';

interface User {
  id: number;
  email: string;
  /** Absent until `/api/me` has answered (login responses don't carry it). */
  display_name?: string | null;
  role: string;
  tenant_id: number;
}

/**
 * A single tenant membership belonging to the authenticated profile.
 * Returned by GET /api/me (WC-f8164c87) for the sidenav tenant-switcher.
 */
export interface Membership {
  tenant_id: number;
  /** The workspace's original name. */
  tenant_name: string;
  /** The original name's language, and the name in every language it has (#821). */
  tenant_name_language?: string;
  tenant_names?: Record<string, string>;
  role: string;
  /** Every role held in the workspace, the primary one first. */
  roles?: string[];
  /**
   * `customer` when the person joined through the workspace's own sign-up
   * (WHIT-915). The server caps a customer's permissions, so admin screens
   * and their navigation never reach them; this is for wording only.
   */
  kind?: 'member' | 'customer';
  /** True when the person owns this workspace (WHIT-1169). */
  is_owner?: boolean;
}

export interface SwitchOptions {
  /**
   * False when the caller goes somewhere itself right after the switch (a new
   * workspace's next step, the owners page). Default true: stay on the page
   * when it exists in the new workspace, else its list or the dashboard.
   */
  land?: boolean;
}

/** See {@link AuthContextType.session}. */
export interface SessionEpoch {
  epoch: number;
  /** Move off a page the new workspace may not have (`landingPath`). */
  land: boolean;
}

interface AuthContextType {
  user: User | null;
  /** The authenticated profile's active memberships (WC-f8164c87). Empty array
   * for unauthenticated sessions. */
  memberships: Membership[];
  isLoading: boolean;
  error: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAuthenticated: () => boolean;
  refreshAuth: () => Promise<void>;
  /**
   * Switch the active tenant for the current session (WC-f8164c87).
   * POSTs to /api/v1/auth/switch-tenant and re-fetches /api/v1/me on success,
   * so user and memberships are updated without a full page reload, and
   * everything else on screen refreshes through {@link session}.
   */
  switchTenant: (tenantId: number, options?: SwitchOptions) => Promise<void>;
  /**
   * Same person, same workspace, but what they may see changed: a plan change,
   * a finished checkout, an accepted invitation. Re-reads `/api/v1/me`,
   * refreshes everything on screen and tells the other tabs.
   *
   * `{ here: false }` for a one-time page whose answer would be lost by a
   * remount (accepting an invitation, the payment return): this tab only
   * re-reads `/me`, and the other tabs refresh.
   */
  sessionChanged: (options?: { here?: boolean }) => Promise<void>;
  /**
   * Bumped whenever the session on screen became a different one (another
   * workspace, another account, another role) or {@link sessionChanged} ran,
   * here or in another tab. `SessionScope` keys the workspace-dependent tree
   * on it, so every fetch runs again for the new session (lib/session-sync.ts).
   */
  session: SessionEpoch;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

/** What `GET /api/v1/me` carries that this provider mirrors into state. */
interface MeBody {
  user?: User;
  memberships?: unknown;
}

interface JWTPayload {
  exp?: number;
  /**
   * Canonical identity claims (ADR 0005 §5): profile_id is the identity and
   * active_tenant_id the session's tenant. The legacy {user_id, tenant_id}
   * dual-claim window was removed by the identity hard cutover (WC-idcut-E/F).
   */
  profile_id?: number;
  active_tenant_id?: number;
  email?: string;
  role?: string;
  [key: string]: unknown;
}

function decodeJWT(token: string): JWTPayload | null {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) {
      return null;
    }
    const payload = parts[1];
    const decoded = atob(payload);
    return JSON.parse(decoded);
  } catch {
    return null;
  }
}

function membershipsOf(data: MeBody): Membership[] {
  return Array.isArray(data.memberships) ? oneEntryPerWorkspace(data.memberships as Membership[]) : [];
}

interface CommitOptions {
  /** Tell the other tabs, as this kind of change, when something changed. */
  announce?: SessionChangeKind | null;
  /** Announce even when nothing changed (a page load saying who it is). */
  announceAlways?: boolean;
  /** Refresh everything even when the identity is the same. */
  force?: boolean;
  /** False: stay on the page whatever the workspace (see {@link SwitchOptions}). */
  land?: boolean;
}

interface AuthProviderProps {
  children: ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [user, setUser] = useState<User | null>(null);
  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [session, setSession] = useState<SessionEpoch>({ epoch: 0, land: false });
  /** The identity on screen (`identityOf`); undefined until the first `/me` answered. */
  const identityRef = useRef<string | null | undefined>(undefined);

  /**
   * Put a session on screen. A different signed-in session from the one already
   * there (or `force`) bumps the epoch, so everything on screen fetches again.
   * From signed out to signed in there is nothing stale to clear, and on the way
   * to signed out `AuthGate` takes the tab to sign-in, so neither bumps it.
   */
  const commit = useCallback(
    (nextUser: User | null, nextMemberships: Membership[] | null, opts: CommitOptions = {}): void => {
      setUser(nextUser);
      if (nextMemberships !== null) setMemberships(nextMemberships);
      const next = identityOf(nextUser);
      const prev = identityRef.current;
      identityRef.current = next;
      const changed = prev !== undefined && next !== prev;
      if (prev !== undefined && prev !== null && next !== null && (changed || opts.force === true)) {
        const land = opts.land !== false && tenantOf(prev) !== tenantOf(next);
        setSession((s) => ({ epoch: s.epoch + 1, land }));
      }
      if (opts.announce && (changed || opts.force === true || opts.announceAlways === true)) {
        announceSessionChange(opts.announce, next);
      }
    },
    [],
  );

  // Initialize user from /api/v1/me on mount (uses httpOnly cookies)
  useEffect(() => {
    const initializeAuth = async () => {
      try {
        // access_token has a 15-min TTL; the client silently refreshes and
        // replays on a 401, so a stored browser session (e.g. Playwright
        // storageState) survives longer test runs without a fresh login.
        const { data: body, response } = await api.GET('/api/v1/me');

        if (response.ok) {
          const data = (body ?? {}) as MeBody;
          // Announced on every load: a tab still showing another account or
          // workspace follows this one. That is what catches a sign-in that was
          // a full-page redirect (Whity ID, choosing another account).
          commit(data.user ?? null, membershipsOf(data), { announce: 'signed-in', announceAlways: true });
        } else {
          identityRef.current = null;
          // Only a refused session, never an outage, signs the other tabs out.
          if (response.status === 401) announceSessionChange('signed-out', null);
        }
      } catch (error) {
        console.error('Failed to initialize auth:', error);
      } finally {
        if (identityRef.current === undefined) identityRef.current = null;
        setIsLoading(false);
      }
    };

    initializeAuth();
  }, [commit]);

  /**
   * A session that ends MID-VISIT, rather than one that was already over at
   * mount.
   *
   * `initializeAuth` above answers "is there a session now"; `AuthGate` reads
   * the result and redirects when there is not. Neither runs again. So a token
   * that expired while somebody was working left them in a fully-rendered
   * shell where every request 401'd and the sidebar — which turns its own
   * failed fetch into an empty list — showed no navigation at all. The product
   * looked featureless rather than signed out.
   *
   * The API client fires this only when a refresh is explicitly REFUSED, never
   * when one fails to reach the server, so a flaky connection cannot sign
   * anybody out. Clearing the user is all that is needed: `AuthGate` already
   * knows what a null user means.
   */
  useEffect(() => {
    const onSessionExpired = (): void => {
      commit(null, [], { announce: 'signed-out' });
    };

    window.addEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);

    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, onSessionExpired);
  }, [commit]);

  /**
   * Re-read `/api/v1/me` and put the answer on screen. `announce: false` is for
   * a tab following another tab's message, so two tabs never echo each other.
   */
  const readSession = useCallback(
    async (opts: { announce: boolean; force?: boolean; land?: boolean; kind?: SessionChangeKind }): Promise<void> => {
      const prev = identityRef.current ?? null;
      try {
        const { data: body, response } = await api.GET('/api/v1/me');

        if (response.ok) {
          const data = (body ?? {}) as MeBody;
          const nextUser = data.user ?? null;
          const kind: SessionChangeKind =
            opts.kind ??
            (prev === null
              ? 'signed-in'
              : nextUser !== null && String(nextUser.tenant_id) !== tenantOf(prev)
                ? 'switched'
                : 'changed');
          commit(nextUser, membershipsOf(data), {
            announce: opts.announce ? kind : null,
            force: opts.force,
            land: opts.land,
          });
        } else {
          commit(null, [], { announce: opts.announce ? 'signed-out' : null });
        }
      } catch (error) {
        console.error('Failed to refresh auth:', error);
        commit(null, [], {});
      }
    },
    [commit],
  );

  /**
   * Another tab changed the session. Follow it when it differs from ours, or
   * when only what we may see changed, without announcing back.
   */
  useEffect(() => {
    return subscribeSessionChanges((change) => {
      if (identityRef.current === undefined) return; // Our own first read is still out.
      if (change.kind !== 'changed' && change.identity === identityRef.current) return;
      if (change.identity === null) {
        commit(null, [], {});
        return;
      }
      void readSession({ announce: false, force: change.kind === 'changed' });
    });
  }, [commit, readSession]);

  const login = async (email: string, password: string): Promise<void> => {
    setIsLoading(true);
    setError(null);

    try {
      const { data: body, error: errorBody, response } = await api.POST('/api/v1/login', {
        body: { email, password },
      });

      if (!response.ok) {
        const errorData = (errorBody ?? {}) as { message?: string };
        throw new Error(errorData.message || 'Login failed');
      }

      const data = (body ?? {}) as { user?: User; token?: unknown };

      // Authentication state lives entirely in the server-set httpOnly cookies
      // (#51); the JWT is never persisted client-side. We only mirror the user
      // profile into React state for rendering. Prefer the response `user`
      // object; fall back to decoding the response token purely to populate that
      // profile (it is not stored).
      if (data.user) {
        commit(data.user, null, { announce: 'signed-in' });
      } else if (typeof data.token === 'string') {
        const payload = decodeJWT(data.token);
        // Canonical claims only (ADR 0005 §5): profile_id is the identity,
        // active_tenant_id the session's tenant. Used purely to populate the
        // in-memory profile for rendering; the token is never persisted.
        const profileId = payload?.profile_id;
        const tenantId =
          typeof payload?.active_tenant_id === 'number' ? payload.active_tenant_id : 0;
        if (payload && profileId && payload.email) {
          commit(
            {
              id: profileId,
              email: payload.email,
              role: payload.role || '',
              tenant_id: tenantId,
            },
            null,
            { announce: 'signed-in' },
          );
        }
      }
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : 'Login failed';
      setError(errorMessage);
      throw err;
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async (): Promise<void> => {
    // WHIT-1075: a signed-out browser gets nobody's notifications.
    await forgetPushOnSignOut().catch(() => undefined);
    try {
      await api.POST('/api/v1/auth/logout');
    } catch (error) {
      console.error('Logout request failed:', error);
    } finally {
      // Every tab of the app signs out with this one.
      commit(null, [], { announce: 'signed-out', announceAlways: true });
      setError(null);
    }
  };

  const refreshAuth = async (): Promise<void> => {
    await readSession({ announce: true });
  };

  const sessionChanged = async (options: { here?: boolean } = {}): Promise<void> => {
    if (options.here === false) {
      await readSession({ announce: false });
      if (identityRef.current) announceSessionChange('changed', identityRef.current);
      return;
    }
    await readSession({ announce: true, force: true, land: false, kind: 'changed' });
  };

  /**
   * Switch the active tenant (WC-f8164c87).
   *
   * POSTs to /api/v1/auth/switch-tenant with the chosen tenant_id, then
   * refetches /api/v1/me so user state (including the new tenant_id and
   * memberships) reflects the new session without a page reload. The session
   * epoch bump refreshes every page, banner and menu for the new workspace, and
   * the other tabs follow (lib/session-sync.ts).
   */
  const switchTenant = async (tenantId: number, options: SwitchOptions = {}): Promise<void> => {
    let outcome;
    try {
      outcome = await api.POST('/api/v1/auth/switch-tenant', { body: { tenant_id: tenantId } });
    } catch {
      // Never reached the server: still a refusal the switcher must explain.
      throw new WorkspaceSwitchRefused(0, null);
    }
    const { error: switchError, response: switchResponse } = outcome;

    if (!switchResponse.ok) {
      // Typed, so the switcher can say why in plain words (2026-11-22).
      const errData = (switchError ?? {}) as Record<string, unknown>;
      const message = typeof errData['error'] === 'string' ? errData['error'] : null;
      throw new WorkspaceSwitchRefused(switchResponse.status, message);
    }

    await readSession({ announce: true, force: true, land: options.land !== false, kind: 'switched' });
  };

  const isAuthenticated = useCallback((): boolean => {
    return !!user;
  }, [user]);

  const value: AuthContextType = {
    user,
    memberships,
    isLoading,
    error,
    login,
    logout,
    isAuthenticated,
    refreshAuth,
    switchTenant,
    sessionChanged,
    session,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}

/**
 * The same context, or undefined outside an `AuthProvider`: for public pages
 * (an invitation, a payment return) that tell a signed-in reader's other tabs
 * about a change but render perfectly well without a session.
 */
export function useOptionalAuth(): AuthContextType | undefined {
  return useContext(AuthContext);
}
