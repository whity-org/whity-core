'use client';

/**
 * /calendar (WHIT-944, ADR 0017): the team's bookings and events in the
 * workspace's time zone.
 *
 * Design 3.5, like Google Calendar (CV.1–CV.11): from 768px `CalendarDesktop`
 * (the left panel, Colour by, Day, 4 days, Week, Month and Year, editing on
 * the grid); narrower, `CalendarPhone` (Day, 3 days and Month, a drawer and
 * sheets). This file loads what both need and shows the loading and error
 * states.
 */

import { useSyncExternalStore } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { CalendarNav } from './calendar-nav';
import { useCalendarAccess, useCalendarBasics } from './use-calendar-basics';
import { CalendarDesktop } from './calendar-desktop';
import { CalendarPhone } from './calendar-phone';

function isNarrow(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 767px)').matches;
}

export function CalendarScreen() {
  const t = useTranslation('calendar');
  const access = useCalendarAccess();
  const basics = useCalendarBasics();

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('area', 'the calendar')} permission="calendar:read" />;
  }

  if (!basics.error && basics.data !== null) return <CalendarByWidth basics={basics.data} />;

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('title', 'Calendar')}
        description={t('description', 'Bookings and the team’s time, in the workspace’s time zone.')}
      />
      <CalendarNav active="calendar" />
      {basics.error ? (
        <LoadProblem
          forbidden={basics.forbidden}
          message={t('error.load', 'The calendar could not be loaded.')}
          onRetry={basics.refetch}
          area={t('area', 'the calendar')}
        />
      ) : (
        <div role="status" aria-busy="true" aria-label={t('loading', 'Loading the calendar')} className="space-y-3">
          <Skeleton className="h-10 w-full max-w-xl rounded-control" />
          <Skeleton className="h-[60vh] w-full rounded-card" />
        </div>
      )}
    </div>
  );
}

const NARROW = '(max-width: 767px)';

function subscribeNarrow(onChange: () => void): () => void {
  if (typeof window.matchMedia !== 'function') return () => undefined;
  const query = window.matchMedia(NARROW);
  query.addEventListener('change', onChange);
  return () => query.removeEventListener('change', onChange);
}

/**
 * From 768px the calendar is design 3.5's computer (CV.1–CV.10,
 * `CalendarDesktop`); narrower, its phone (CV.11, `CalendarPhone`).
 */
function CalendarByWidth({ basics }: { basics: NonNullable<ReturnType<typeof useCalendarBasics>['data']> }) {
  const narrow = useSyncExternalStore(subscribeNarrow, isNarrow, () => false);
  return narrow ? <CalendarPhone basics={basics} /> : <CalendarDesktop basics={basics} />;
}
