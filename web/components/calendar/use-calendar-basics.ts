'use client';

/**
 * What every calendar screen needs before it can draw anything (WHIT-944):
 * the settings (above all the workspace's time zone and week layout), the team
 * with calendars, and the services. One request each, together, so a screen
 * shows one loading state and one error rather than three.
 */

import { useMemo } from 'react';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { safeTimeZone } from '@whity/features/datetime';
import {
  CALENDAR_MANAGE,
  CALENDAR_READ,
  CALENDAR_WRITE,
  calendarApi,
  type CalendarService,
  type CalendarSettings,
  type CalendarStaff,
} from '@/lib/calendar/api';

export interface CalendarBasics {
  settings: CalendarSettings;
  staff: CalendarStaff[];
  services: CalendarService[];
  /** The workspace zone, or UTC when the runtime does not know its name. */
  timeZone: string;
}

export interface CalendarAccess {
  /** Still finding out what the person may do. */
  loading: boolean;
  canRead: boolean;
  canWrite: boolean;
  canManage: boolean;
  /** The signed-in person's profile id. */
  me: number | null;
  /** Whether the person may change `profileId`'s calendar (their own on write, anyone's on manage). */
  canChange: (profileId: number) => boolean;
}

export function useCalendarAccess(): CalendarAccess {
  const { has, loading } = useCapabilities();
  const { user } = useAuth();
  const me = user?.id ?? null;
  const canRead = has(CALENDAR_READ);
  const canWrite = has(CALENDAR_WRITE);
  const canManage = has(CALENDAR_MANAGE);
  return useMemo(
    () => ({
      loading,
      canRead,
      canWrite,
      canManage,
      me,
      canChange: (profileId: number) => canManage || (canWrite && profileId === me),
    }),
    [loading, canRead, canWrite, canManage, me]
  );
}

export function useCalendarBasics() {
  const result = useFetch<CalendarBasics>(async () => {
    const [settings, staff, services] = await Promise.all([
      calendarApi.settings(),
      calendarApi.staff(),
      calendarApi.services(),
    ]);
    return { settings, staff, services, timeZone: safeTimeZone(settings.time_zone) };
  }, []);
  return result;
}
