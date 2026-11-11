'use client';

/**
 * /calendar (WHIT-944, ADR 0017): the team's bookings and events by day, week
 * or month, for everyone or one person, in the workspace's time zone.
 *
 * No design card exists yet (brand ask WHIT-946). Built from section 21 and
 * existing pieces: the toggle group for the view, the select for the person,
 * the dialog and sheet patterns, and the six states.
 *
 * PHONE. Day is the default on a narrow screen. Week becomes a list of days
 * (seven columns of 40px are unreadable for anyone, and the people this is
 * sized for are over 50); Month shows a count per day that opens the day.
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { FieldSelect } from '@/components/field-select';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { EmptyFiltered, EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay, zonedDate } from '@whity/features/datetime';
import { calendarApi, type CalendarBooking, type CalendarEvent } from '@/lib/calendar/api';
import {
  labelInstant,
  monthWeeks,
  rangeQuery,
  stepAnchor,
  viewRange,
  type CalendarDate,
  type CalendarView,
} from '@/lib/calendar/layout';
import { AgendaList, MonthGrid, TimeGrid, type GridItem } from './calendar-grid';
import { BookingDialog } from './booking-dialog';
import { BookingSheet } from './booking-sheet';
import { CalendarNav } from './calendar-nav';
import { EventDialog } from './event-dialog';
import { useCalendarAccess, useCalendarBasics } from './use-calendar-basics';
import { useCalendarWords } from './use-calendar-words';

const EVERYONE = 'everyone';

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
      ) : basics.data === null ? (
        <div role="status" aria-busy="true" aria-label={t('loading', 'Loading the calendar')} className="space-y-3">
          <Skeleton className="h-10 w-full max-w-xl rounded-control" />
          <Skeleton className="h-[60vh] w-full rounded-card" />
        </div>
      ) : (
        <CalendarBody basics={basics.data} />
      )}
    </div>
  );
}

function CalendarBody({
  basics,
}: {
  basics: NonNullable<ReturnType<typeof useCalendarBasics>['data']>;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const { addToast } = useToast();
  const { settings, staff, services, timeZone } = basics;
  const dates = useDateDisplay({ timeZone });

  const today = useMemo(() => zonedDate(timeZone), [timeZone]);
  // A phone opens on one day; the choice is the person's after that. This
  // body only renders once the settings have loaded, in the browser, so
  // reading the screen width here cannot disagree with a server render.
  const [view, setView] = useState<CalendarView>(() => (isNarrow() ? 'day' : 'week'));
  const [anchor, setAnchor] = useState<CalendarDate>(today);
  const [person, setPerson] = useState<string>(EVERYONE);
  const [booking, setBooking] = useState<CalendarBooking | null>(null);
  const [newBooking, setNewBooking] = useState(false);
  const [eventOpen, setEventOpen] = useState(false);
  const [editing, setEditing] = useState<CalendarEvent | null>(null);

  const range = viewRange(view, anchor, settings.week_start);
  const query = rangeQuery(range, timeZone);
  const profileId = person === EVERYONE ? null : Number(person);

  const { data, loading, error, forbidden, refetch } = useFetch(async () => {
    const [bookings, events] = await Promise.all([
      calendarApi.bookings({ ...query, profileId }),
      calendarApi.events({ ...query, profileId }),
    ]);
    return { bookings, events };
  }, [query.from, query.to, profileId]);

  const staffName = useMemo(() => new Map(staff.map((s) => [s.profile_id, s.display_name])), [staff]);
  const serviceById = useMemo(() => new Map(services.map((s) => [s.id, s])), [services]);

  const items: GridItem[] = useMemo(() => {
    if (data === null) return [];
    const fromBookings = data.bookings
      .filter((b) => b.status === 'confirmed' || b.status === 'pending_payment')
      .map<GridItem>((b) => ({
        key: `b${b.id}`,
        starts_at: b.starts_at,
        ends_at: b.ends_at,
        allDay: false,
        title: b.customer.name ?? t('booking.noName', 'No name given'),
        subtitle:
          b.status === 'pending_payment'
            ? `${words.serviceName(serviceById.get(b.service_id))} · ${words.status(b.status)}`
            : words.serviceName(serviceById.get(b.service_id)),
        person: staffName.get(b.profile_id) ?? '',
        tone: b.status === 'pending_payment' ? 'pending' : 'booking',
        booking: b,
      }));
    const fromEvents = data.events
      .filter((e) => e.status !== 'cancelled')
      .map<GridItem>((e) => ({
        key: `e${e.id}`,
        starts_at: e.starts_at,
        ends_at: e.ends_at,
        allDay: e.all_day,
        title: e.title,
        subtitle: e.busy ? t('event.kind.busy', 'Busy') : t('event.kind.free', 'Doesn’t block bookings'),
        person: staffName.get(e.profile_id) ?? '',
        tone: e.busy ? 'busy' : 'free',
        event: e,
      }));
    return [...fromBookings, ...fromEvents];
  }, [data, serviceById, staffName, t, words]);

  const title =
    view === 'day'
      ? dates.calendarLabel(labelInstant(anchor, timeZone), 'fullDate')
      : dates.calendarLabel(labelInstant(view === 'week' ? range.days[3] : anchor, timeZone), 'monthYear');

  const notSetUp = services.length === 0 && staff.length === 0;
  const filtered = person !== EVERYONE;
  const nothing = data !== null && items.length === 0;

  const openDay = (date: CalendarDate) => {
    setAnchor(date);
    setView('day');
  };

  const gridProps = {
    items,
    timeZone,
    weekendDays: settings.weekend_days,
    today,
    showPerson: person === EVERYONE && staff.length > 1,
    onOpen: (item: GridItem) => {
      if (item.booking) setBooking(item.booking);
      if (item.event) {
        setEditing(item.event);
        setEventOpen(true);
      }
    },
    onPickDay: openDay,
  };

  return (
    <div className="space-y-4">
      {!access.loading && !access.canWrite && (
        <ReadOnlyNotice
          title={t('readOnly.title', 'You can see the calendar but not book')}
          description={t('readOnly.description', 'Ask someone who manages the calendar if you need to make or change bookings.')}
        />
      )}

      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1">
          <Button variant="outline" onClick={() => setAnchor(today)} data-testid="calendar-today">
            {t('toolbar.today', 'Today')}
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setAnchor(stepAnchor(view, anchor, -1))}
            aria-label={t('toolbar.previous', 'Previous')}
            title={t('toolbar.previous', 'Previous')}
            data-testid="calendar-previous"
          >
            <Icon name="chevron-left" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setAnchor(stepAnchor(view, anchor, 1))}
            aria-label={t('toolbar.next', 'Next')}
            title={t('toolbar.next', 'Next')}
            data-testid="calendar-next"
          >
            <Icon name="chevron-right" />
          </Button>
        </div>
        <h2 className="min-w-0 flex-1 truncate text-lg font-semibold text-foreground" data-testid="calendar-title" aria-live="polite">
          {title ?? ''}
        </h2>
        {access.canWrite && (
          <Button variant="outline" onClick={() => { setEditing(null); setEventOpen(true); }} data-testid="calendar-add-event">
            {t('toolbar.addEvent', 'Add event')}
          </Button>
        )}
        {access.canWrite && services.length > 0 && (
          <Button onClick={() => setNewBooking(true)} data-testid="calendar-new-booking">
            <Icon name="plus" />
            {t('toolbar.newBooking', 'New booking')}
          </Button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <ToggleGroup
          label={t('toolbar.view', 'View')}
          required
          value={view}
          onChange={(value) => {
            if (value === 'day' || value === 'week' || value === 'month') setView(value);
          }}
          options={[
            { value: 'day', label: t('view.day', 'Day') },
            { value: 'week', label: t('view.week', 'Week') },
            { value: 'month', label: t('view.month', 'Month') },
          ]}
        />
        {staff.length > 0 && (
          <div className="w-full sm:w-64">
            <label htmlFor="calendar-person" className="sr-only">
              {t('toolbar.person', 'Whose calendar')}
            </label>
            <FieldSelect
              id="calendar-person"
              value={person}
              onChange={setPerson}
              options={[
                { value: EVERYONE, label: t('toolbar.everyone', 'Everyone') },
                ...staff.map((s) => ({ value: String(s.profile_id), label: s.display_name })),
              ]}
            />
          </div>
        )}
        <p className="text-sm text-muted-foreground" data-testid="calendar-zone">
          {t('toolbar.zone', 'Times in {zone}', { zone: timeZone })}
        </p>
      </div>

      {error ? (
        <LoadProblem
          forbidden={forbidden}
          message={t('error.loadRange', 'Bookings and events could not be loaded.')}
          onRetry={refetch}
          area={t('area', 'the calendar')}
        />
      ) : loading && data === null ? (
        <Skeleton className="h-[60vh] w-full rounded-card" role="status" aria-busy="true" aria-label={t('loading', 'Loading the calendar')} />
      ) : notSetUp && nothing ? (
        <EmptyFirstTime
          icon="calendar"
          title={t('empty.title', 'Set up the calendar')}
          description={
            access.canManage
              ? t('empty.manage', 'Add the services you offer and who provides them. Then bookings can be made here, and events added.')
              : t('empty.ask', 'Nothing is set up yet. Someone who manages the calendar adds the services and the team’s hours.')
          }
          action={
            access.canManage ? (
              <Button asChild>
                <Link href="/calendar/services">{t('empty.action', 'Add a service')}</Link>
              </Button>
            ) : undefined
          }
        />
      ) : filtered && nothing ? (
        <EmptyFiltered
          title={t('empty.filtered', 'Nothing for {name} here', { name: staffName.get(profileId ?? 0) ?? '' })}
          description={t('empty.filteredHint', 'Try another day, or show everyone.')}
          clearLabel={t('empty.clear', 'Show everyone')}
          onClear={() => setPerson(EVERYONE)}
        />
      ) : view === 'month' ? (
        <MonthGrid {...gridProps} weeks={monthWeeks(anchor, settings.week_start)} month={anchor.month} />
      ) : view === 'week' ? (
        <>
          <div className="hidden md:block">
            <TimeGrid {...gridProps} days={range.days} />
          </div>
          <div className="md:hidden">
            <AgendaList {...gridProps} days={range.days} />
          </div>
        </>
      ) : (
        <TimeGrid {...gridProps} days={range.days} />
      )}

      {data !== null && !notSetUp && nothing && !filtered && (
        <p className="text-sm text-muted-foreground" data-testid="calendar-empty-range">
          {view === 'day'
            ? t('empty.day', 'Nothing booked on this day.')
            : view === 'week'
              ? t('empty.week', 'Nothing booked this week.')
              : t('empty.month', 'Nothing booked this month.')}
        </p>
      )}

      <BookingDialog
        open={newBooking}
        onOpenChange={setNewBooking}
        services={services}
        staff={staff}
        timeZone={timeZone}
        initialDate={view === 'day' ? anchor : null}
        initialProfileId={profileId}
        onBooked={(made) => {
          setNewBooking(false);
          addToast(t('booking.made', 'Booked for {name}', { name: made.customer.name ?? '' }), 'success');
          refetch();
        }}
      />

      <BookingSheet
        booking={booking}
        onClose={() => setBooking(null)}
        services={services}
        staff={staff}
        timeZone={timeZone}
        canChange={access.canWrite}
        onChanged={(changed) => {
          setBooking(changed);
          addToast(
            changed.status === 'cancelled' ? t('booking.cancelled', 'Booking cancelled') : t('booking.moved', 'Booking moved'),
            'success'
          );
          refetch();
        }}
      />

      <EventDialog
        open={eventOpen}
        onOpenChange={(open) => {
          setEventOpen(open);
          if (!open) setEditing(null);
        }}
        event={editing}
        staff={staff}
        timeZone={timeZone}
        me={access.me}
        canManage={access.canManage}
        canChange={editing === null ? access.canWrite : access.canChange(editing.profile_id)}
        initialDate={view === 'day' ? anchor : null}
        onSaved={refetch}
      />
    </div>
  );
}
