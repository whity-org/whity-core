'use client';

/**
 * Each person's own calendar choices (design 3.5: CV.1 the sidebar kept open,
 * CV.2 the panel's ticks, CV.4 Colour by, CV.7 the view and the date). They
 * live on the account, per workspace (`/api/v1/calendar/my-view`), so they
 * follow the person to another computer; a copy in this browser makes the
 * first paint right before the account answers.
 */

import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';

export type ViewChoices = components['schemas']['CalendarViewChoices'];
export type AlsoShow = 'team_events' | 'time_off' | 'cancelled';

/** Team events and time off are shown until a person unticks them; cancelled bookings are not. */
export const DEFAULT_ALSO_SHOW: AlsoShow[] = ['team_events', 'time_off'];

const LOCAL_KEY = 'whity.calendar.view';
const RAIL_KEY = 'whity.calendar.sidebar-open';
const RAIL_EVENT = 'whity:calendar-sidebar';
const SAVE_AFTER_MS = 600;

function readLocal(): ViewChoices {
  try {
    const raw = window.localStorage.getItem(LOCAL_KEY);
    const parsed: unknown = raw === null ? null : JSON.parse(raw);
    return parsed !== null && typeof parsed === 'object' ? (parsed as ViewChoices) : {};
  } catch {
    return {};
  }
}

function writeLocal(choices: ViewChoices): void {
  try {
    window.localStorage.setItem(LOCAL_KEY, JSON.stringify(choices));
  } catch {
    // Private windows and full storage: the account still has it.
  }
}

/**
 * The person's choices, and a way to change them. `ready` turns true once the
 * account has answered (or failed to), so a screen can wait for the view
 * before drawing the wrong one.
 */
export function useViewChoices(): {
  choices: ViewChoices;
  ready: boolean;
  update: (patch: ViewChoices) => void;
} {
  const [choices, setChoices] = useState<ViewChoices>(() => (typeof window === 'undefined' ? {} : readLocal()));
  const [ready, setReady] = useState(false);
  const pending = useRef<ViewChoices>({});
  const timer = useRef<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const { data, response } = await api.GET('/api/v1/calendar/my-view');
        if (cancelled) return;
        if (response.ok) {
          const fromAccount = ((data ?? {}) as { data?: ViewChoices }).data ?? {};
          setChoices((local) => ({ ...local, ...fromAccount }));
          writeLocal({ ...readLocal(), ...fromAccount });
          if (typeof fromAccount.sidebar_open === 'boolean') setCalendarSidebarOpenLocally(fromAccount.sidebar_open);
        }
      } catch {
        // Offline: the browser's copy stands.
      } finally {
        if (!cancelled) setReady(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const flush = useCallback(() => {
    const body = pending.current;
    pending.current = {};
    timer.current = null;
    if (Object.keys(body).length === 0) return;
    void api.PUT('/api/v1/calendar/my-view', { body }).catch(() => {
      // The next change sends it again with its own.
    });
  }, []);

  useEffect(
    () => () => {
      if (timer.current !== null) {
        window.clearTimeout(timer.current);
        flush();
      }
    },
    [flush]
  );

  const update = useCallback(
    (patch: ViewChoices) => {
      setChoices((current) => {
        const next = { ...current, ...patch };
        writeLocal(next);
        return next;
      });
      pending.current = { ...pending.current, ...patch };
      if (timer.current !== null) window.clearTimeout(timer.current);
      timer.current = window.setTimeout(flush, SAVE_AFTER_MS);
    },
    [flush]
  );

  return { choices, ready, update };
}

// ---------------------------------------------------------------------------
// CV.1: the sidebar on calendar screens.
// ---------------------------------------------------------------------------

function readRail(): boolean {
  try {
    return window.localStorage.getItem(RAIL_KEY) === 'open';
  } catch {
    return false;
  }
}

function setCalendarSidebarOpenLocally(open: boolean): void {
  try {
    window.localStorage.setItem(RAIL_KEY, open ? 'open' : 'rail');
  } catch {
    // Kept for this page only.
  }
  window.dispatchEvent(new Event(RAIL_EVENT));
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener(RAIL_EVENT, onChange);
  window.addEventListener('storage', onChange);
  return () => {
    window.removeEventListener(RAIL_EVENT, onChange);
    window.removeEventListener('storage', onChange);
  };
}

/**
 * Whether the person keeps the app's sidebar open on the calendar. It starts
 * as the 56px rail; opening it there is remembered for next time (CV.1).
 */
export function useCalendarSidebarOpen(): [boolean, (open: boolean) => void] {
  const open = useSyncExternalStore(subscribe, readRail, () => false);
  const set = useCallback((next: boolean) => {
    setCalendarSidebarOpenLocally(next);
    void api.PUT('/api/v1/calendar/my-view', { body: { sidebar_open: next } }).catch(() => {
      // The browser keeps it; the next calendar change retries the account.
    });
  }, []);
  return [open, set];
}
