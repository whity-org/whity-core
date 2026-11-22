'use client';

import { Fragment, useEffect, useRef, type ReactNode } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { landingPath } from '@/lib/session-sync';

/**
 * Everything that depends on WHO is signed in and WHERE, refreshed as one when
 * that changes (owner, 2026-11-22). See lib/session-sync.ts for the why.
 *
 * The app has no shared query cache: each provider, banner and page fetches
 * its own data in effects. So rather than chase every cache, this keys the
 * whole workspace-dependent tree on the session epoch. A new epoch remounts it
 * and every one of those fetches runs again for the new session, with no
 * document reload and no white flash. The shell keeps its place on screen; the
 * parts that were loading show their own loading states, as on any navigation.
 *
 * Kept OUTSIDE this scope on purpose: the language, direction, theme and toast
 * providers. They follow the identity on their own, and remounting them would
 * flip the page's language or drop the toast that says what just happened.
 *
 * After a change of workspace the tab stays on its page when the new workspace
 * can have it, and moves to the list or the dashboard when the page named one
 * of the old workspace's records ({@link landingPath}).
 */
export function SessionScope({ children }: { children: ReactNode }) {
  const { session } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const handled = useRef(session.epoch);

  useEffect(() => {
    if (handled.current === session.epoch) return;
    handled.current = session.epoch;
    if (!session.land || pathname === null) return;
    const target = landingPath(pathname);
    if (target !== pathname) router.replace(target);
  }, [session.epoch, session.land, pathname, router]);

  return <Fragment key={session.epoch}>{children}</Fragment>;
}
