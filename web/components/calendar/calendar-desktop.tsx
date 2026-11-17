'use client';

/**
 * The calendar on a computer, like Google Calendar (design 3.5, CV.1–CV.7;
 * cards `TplCalendarWeek`, `TplCalendarViews`).
 *
 * The left panel (New booking, mini month, search, People and Rooms and
 * branches as coloured checkboxes, Also show, the time zone), then the bar
 * (Today, ‹ ›, the range, Colour by, the view, the gear) over Day, 4 days,
 * Week or Month. A booking's details open beside it.
 *
 * Every choice here (view, date, Colour by, ticks) is the person's own and is
 * remembered on their account (`useViewChoices`); nothing changes what anyone
 * else sees. The booking sheet, the full booking dialog and the event dialog
 * are the same ones the phone uses.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocalizedUnits } from '@/hooks/useLocalizedUnits';
import { LoadProblem } from '@/components/load-problem';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Skeleton } from '@whity/ui/skeleton';
import { EmptyFirstTime, ReadOnlyNotice } from '@whity/ui/page-states';
import { Button } from '@whity/ui/button';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay, zonedDate } from '@whity/features/datetime';
import { calendarApi, type CalendarBooking, type CalendarEvent } from '@/lib/calendar/api';
import { DEFAULT_ALSO_SHOW, useViewChoices, type AlsoShow } from '@/lib/calendar/choices';
import { kindsFor, toCalItems } from '@/lib/calendar/items';
import { colourOf, palette, type ColourBy } from '@/lib/calendar/colours';
import {
  addDays,
  dateKey,
  isTimeView,
  labelInstant,
  placeInDay,
  addMonths,
  parseDateKey,
  rangeQuery,
  stepAnchor,
  viewRange,
  type CalendarDate,
  type CalendarView,
} from '@/lib/calendar/layout';
import { useNewIntent } from '@/lib/commands/new-intent';
import { MonthView, YearLegend, YearView } from './month-year';
import { CalendarBar, VIEW_KEYS } from './calendar-bar';
import { CalendarPanel, zoneLabel, type PanelEntry } from './calendar-panel';
import { BookingDetails } from './booking-details';
import { BookingDialog } from './booking-dialog';
import { BookingSheet } from './booking-sheet';
import { EventDialog } from './event-dialog';
import { RequestsCard } from './appointments-side';
import { BookingLinkCard } from './booking-link';
import { TimeGrid, hoursToDraw, type CalItem } from './time-grid';
import { QuickBooking, useGridEditing } from './calendar-editing';
import { useCalendarAccess, type CalendarBasics } from './use-calendar-basics';
import { useCalendarWords } from './use-calendar-words';

/** The views a computer offers (CV.7). */
export const DESKTOP_VIEWS: CalendarView[] = ['day', 'four_days', 'week', 'month', 'year'];
const REQUESTS_AHEAD_DAYS = 60;


function typingIn(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

export function CalendarDesktop({ basics }: { basics: CalendarBasics }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const { addToast } = useToast();
  const router = useRouter();
  const { settings, staff, services, rooms, timeZone } = basics;
  const units = useLocalizedUnits(basics.units);
  const dates = useDateDisplay({ timeZone });
  const today = useMemo(() => zonedDate(timeZone), [timeZone]);
  const { choices, update } = useViewChoices();

  const stored = choices.view as CalendarView | undefined;
  const view: CalendarView = stored !== undefined && DESKTOP_VIEWS.includes(stored) ? stored : stored === 'three_days' ? 'four_days' : 'week';
  const anchor = parseDateKey(choices.date) ?? today;
  const colourBy: ColourBy = choices.colour_by ?? 'person';
  const alsoShow: AlsoShow[] = (choices.also_show as AlsoShow[] | undefined) ?? DEFAULT_ALSO_SHOW;
  const hiddenPeople = useMemo(() => new Set(choices.hidden_people ?? []), [choices.hidden_people]);
  const hiddenRooms = useMemo(() => new Set(choices.hidden_rooms ?? []), [choices.hidden_rooms]);
  const hiddenServices = useMemo(() => new Set(choices.hidden_services ?? []), [choices.hidden_services]);

  const setView = useCallback((next: CalendarView) => update({ view: next }), [update]);
  const setAnchor = useCallback((next: CalendarDate) => update({ date: dateKey(next) }), [update]);

  // The booking link (WHIT-1117): shown while the booking page is on, and offered
  // to managers when it is off. `?share=booking-link` (Ctrl+K) opens Share.
  const [bookingOn, setBookingOn] = useState(settings.booking_page_enabled);
  const shareOnOpen = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('share') === 'booking-link';
  const showLink = (settings.booking_page_url ?? null) !== null && (bookingOn || access.canManage);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<CalItem | null>(null);
  const [sheet, setSheet] = useState<{ booking: CalendarBooking; startWith: 'move' | 'cancel' | null; suggest: boolean } | null>(null);
  const [newBooking, setNewBooking] = useState(false);
  const [eventOpen, setEventOpen] = useState(false);
  const [editing, setEditing] = useState<CalendarEvent | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const refetch = () => setReloadKey((k) => k + 1);

  useNewIntent('booking', () => {
    if (access.canWrite && services.length > 0) setNewBooking(true);
  });

  const range = viewRange(view, anchor, settings.week_start);
  const query = rangeQuery(range, timeZone);
  const showCancelled = alsoShow.includes('cancelled');

  // One list for everything on the calendar (WHIT-1116): Also show picks the kinds.
  const kinds = kindsFor(alsoShow);
  const { data, loading, error, forbidden } = useFetch(async () => {
    // Year reads counts only (below); the list is capped at 62 days.
    if (view === 'year') return { items: [], unavailable: [] as string[] };
    return calendarApi.items({ ...query, kinds, cancelled: showCancelled });
  }, [view === 'year', query.from, query.to, showCancelled, kinds.join(','), reloadKey]);

  // The working hours of everyone, once, for the hours the grid draws (CV.6).
  const openings = useFetch(async () => {
    const all = await Promise.all(staff.map((s) => calendarApi.hours(s.profile_id).catch(() => [])));
    return all.flat();
  }, [staff.map((s) => s.profile_id).join(',')]);

  // Year: bookings a day for the whole year, left out as the panel says (CV.7).
  const yearCounts = useFetch(
    async () => (view === 'year' ? calendarApi.dayCounts(query) : []),
    [view, query.from, query.to, reloadKey]
  );
  const counts = useMemo(() => {
    const byDay = new Map<string, number>();
    for (const row of yearCounts.data ?? []) {
      if (hiddenPeople.has(row.profile_id)) continue;
      if (row.room_id !== null && hiddenRooms.has(row.room_id)) continue;
      if (colourBy === 'service' && hiddenServices.has(row.service_id)) continue;
      byDay.set(row.date, (byDay.get(row.date) ?? 0) + row.count);
    }
    return byDay;
  }, [yearCounts.data, hiddenPeople, hiddenRooms, hiddenServices, colourBy]);

  const requests = useFetch(async () => {
    const from = new Date();
    const to = new Date(from.getTime() + REQUESTS_AHEAD_DAYS * 86_400_000);
    return calendarApi.bookings({ from: from.toISOString(), to: to.toISOString(), status: 'requested' });
  }, [reloadKey]);

  const colours = useMemo(
    () => palette(staff.map((s) => s.profile_id), services.map((s) => s.id), rooms.map((r) => r.id)),
    [staff, services, rooms]
  );
  const staffName = useMemo(() => new Map(staff.map((s) => [s.profile_id, s.display_name])), [staff]);
  const serviceById = useMemo(() => new Map(services.map((s) => [s.id, s])), [services]);
  const roomById = useMemo(() => new Map(rooms.map((r) => [r.id, r])), [rooms]);

  const items: CalItem[] = useMemo(
    () =>
      data === null
        ? []
        : toCalItems(data.items, {
            colourBy,
            colours,
            staffName,
            services: serviceById,
            rooms: roomById,
            hiddenPeople,
            hiddenRooms,
            hiddenServices,
            search,
            serviceName: words.serviceName,
            label: words.label,
            words: {
              noName: t('booking.noName', 'No name given'),
              waitingPayment: t('grid.waitingPayment', 'waiting for payment'),
              request: t('grid.requestShort', 'request'),
              cancelled: t('grid.cancelledShort', 'cancelled'),
              checkedIn: t('grid.checkedInShort', 'checked in'),
              timeOff: (name) => t('grid.leave', '{name} · time off', { name }),
              guests: (count) => (count === 1 ? t('grid.guestsOne', '1 guest') : t('grid.guestsMany', '{count} guests', { count })),
            },
          }),
    [data, colourBy, colours, staffName, serviceById, roomById, hiddenPeople, hiddenRooms, hiddenServices, search, t, words]
  );

  const hours = hoursToDraw(openings.data ?? [], items, range.days, timeZone);

  // The range as the title: "15 – 21 November 2026" (CV.3).
  const title = (() => {
    if (view === 'day') return dates.calendarLabel(labelInstant(anchor, timeZone), 'fullDate') ?? '';
    if (view === 'month') return dates.calendarLabel(labelInstant(anchor, timeZone), 'monthYear') ?? '';
    if (view === 'year') return String(anchor.year);
    const first = range.days[0];
    const last = range.days[range.days.length - 1];
    const start =
      first.month === last.month
        ? dates.calendarLabel(labelInstant(first, timeZone), 'dayNumber')
        : dates.calendarLabel(labelInstant(first, timeZone), 'dayMonthLong');
    const end = dates.calendarLabel(labelInstant(last, timeZone), 'dayMonthLong');
    return t('toolbar.weekRange', '{start} – {end} {year}', { start: start ?? '', end: end ?? '', year: String(last.year) });
  })();

  // Keys (CV.7, `TplCalendarEditing`): D X W M, T today, J K next and previous, / search, C new booking.
  const searchBox = useRef<HTMLElement | null>(null);
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.altKey || event.ctrlKey || event.metaKey || typingIn(event.target)) return;
      const key = event.key.toLowerCase();
      const next = VIEW_KEYS[key];
      if (next !== undefined && DESKTOP_VIEWS.includes(next)) {
        setView(next);
      } else if (key === 't') {
        setAnchor(today);
      } else if (key === 'j') {
        setAnchor(stepAnchor(view, anchor, 1));
      } else if (key === 'k') {
        setAnchor(stepAnchor(view, anchor, -1));
      } else if (key === '/') {
        searchBox.current = document.querySelector('[data-testid="calendar-search"]');
        searchBox.current?.focus();
      } else if (key === 'c' && access.canWrite && services.length > 0) {
        setNewBooking(true);
      } else {
        return;
      }
      event.preventDefault();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [view, anchor, today, setView, setAnchor, access.canWrite, services.length]);

  const toggle = (group: 'people' | 'rooms' | 'services', id: number, shown: boolean) => {
    const key = group === 'people' ? 'hidden_people' : group === 'rooms' ? 'hidden_rooms' : 'hidden_services';
    const set = new Set(group === 'people' ? hiddenPeople : group === 'rooms' ? hiddenRooms : hiddenServices);
    if (shown) set.delete(id);
    else set.add(id);
    update({ [key]: [...set] });
  };

  const people: PanelEntry[] = staff.map((s) => ({
    id: s.profile_id,
    label: s.display_name,
    colour: colourBy === 'person' ? colourOf('person', colours, { profileId: s.profile_id }) : null,
    shown: !hiddenPeople.has(s.profile_id),
  }));
  const roomEntries: PanelEntry[] = rooms
    .filter((r) => r.active)
    .map((r) => {
      const unit = r.unit_id !== null ? units.find((u) => u.id === r.unit_id)?.name : undefined;
      return {
        id: r.id,
        label: [unit, words.label(r.name)].filter(Boolean).join(' · '),
        colour: colourBy === 'room' ? colourOf('room', colours, { roomId: r.id }) : null,
        shown: !hiddenRooms.has(r.id),
      };
    });
  const serviceEntries: PanelEntry[] | null =
    colourBy === 'service'
      ? services
          .filter((s) => s.active)
          .map((s) => ({ id: s.id, label: words.serviceName(s), colour: colourOf('service', colours, { serviceId: s.id }), shown: !hiddenServices.has(s.id) }))
      : null;

  const notSetUp = services.length === 0 && staff.length === 0;
  const shownFrom = isTimeView(view) ? range.days[0] : { year: anchor.year, month: anchor.month, day: 1 };
  const shownTo = isTimeView(view) ? range.days[range.days.length - 1] : addDays(addMonths(shownFrom, 1), -1);

  // CV.8–CV.10: create, move and resize on the grid, asked first, Undo.
  const [moreFor, setMoreFor] = useState<{ date: CalendarDate; profileId: number | null } | null>(null);
  const edits = useGridEditing({
    items,
    days: range.days,
    services,
    staff,
    timeZone,
    canWrite: access.canWrite,
    canChange: access.canChange,
    placeOf: (item) => {
      for (const date of range.days) {
        const place = placeInDay(item, date, timeZone);
        if (place !== null && !place.continuesBefore) return { date, startMinute: place.startMinute, endMinute: place.endMinute };
      }
      return null;
    },
    onChanged: refetch,
  });

  // An invitation answered from the details (WHIT-1116).
  const answer = async (item: CalItem, response: 'accepted' | 'declined' | 'tentative') => {
    if (!item.event) return;
    try {
      await calendarApi.answerEvent(item.event.id, response);
      addToast(t('details.answered', 'Answer saved'), 'success');
      refetch();
    } catch (error) {
      addToast(words.errorText(error, t('details.answerFailed', 'Your answer could not be saved. Try again.')), 'error');
    }
  };
  const nameOf = (profileId: number) => staffName.get(profileId) ?? '';
  const editEvent = (item: CalItem) => {
    if (!item.event) return;
    setEditing(item.event);
    setEventOpen(true);
  };

  // Every item opens its details beside it: bookings, events, meetings, time off.
  const openItem = (item: CalItem | null) => {
    edits.setDraft(null);
    setSelected(item);
  };

  return (
    <div className="grid grid-cols-[256px_minmax(0,1fr)] items-start gap-5" data-testid="calendar-desktop" data-full-width="">
      <h1 className="sr-only">{t('title', 'Calendar')}</h1>
      <CalendarPanel
        timeZone={timeZone}
        weekStart={settings.week_start}
        today={today}
        shownFrom={shownFrom}
        shownTo={shownTo}
        onPickDate={setAnchor}
        search={search}
        onSearch={setSearch}
        people={people}
        rooms={roomEntries}
        services={serviceEntries}
        onToggle={toggle}
        alsoShow={alsoShow}
        onAlsoShow={(what, shown) =>
          update({ also_show: shown ? [...new Set([...alsoShow, what])] : alsoShow.filter((w) => w !== what) })
        }
        canBook={access.canWrite && services.length > 0}
        onNewBooking={() => setNewBooking(true)}
        footer={
          <>
            {(requests.data ?? []).length > 0 && (
              <RequestsCard
                requests={requests.data ?? []}
                services={services}
                staff={staff}
                timeZone={timeZone}
                canChange={access.canWrite}
                onSuggest={(request) => setSheet({ booking: request, startWith: null, suggest: true })}
                onChanged={refetch}
              />
            )}
            {showLink && (
              <BookingLinkCard
                compact
                link={settings.booking_page_url ?? null}
                enabled={bookingOn}
                canManage={access.canManage}
                onEnabled={() => setBookingOn(true)}
                shareOnOpen={shareOnOpen}
              />
            )}
          </>
        }
      />
      <div className="grid min-w-0 gap-3">
        <CalendarBar
          title={title}
          view={view}
          views={DESKTOP_VIEWS}
          onView={setView}
          colourBy={colourBy}
          onColourBy={(by) => update({ colour_by: by })}
          onToday={() => setAnchor(today)}
          onStep={(direction) => setAnchor(stepAnchor(view, anchor, direction))}
        />
        {data !== null && data.unavailable.includes('task_due') && (
          <p className="m-0 text-sm text-muted-foreground" role="status" data-testid="calendar-tasks-unavailable">
            {t('grid.tasksUnavailable', 'Tasks due couldn’t be read from Taskaat just now. Everything else is up to date.')}
          </p>
        )}
        {!access.loading && !access.canWrite && (
          <ReadOnlyNotice
            title={t('readOnly.title', 'You can see the calendar but not book')}
            description={t('readOnly.description', 'Ask someone who manages the calendar if you need to make or change bookings.')}
          />
        )}
        {error ? (
          <LoadProblem
            forbidden={forbidden}
            message={t('error.loadRange', 'Bookings and events could not be loaded.')}
            onRetry={refetch}
            area={t('area', 'the calendar')}
          />
        ) : loading && data === null ? (
          <Skeleton className="h-[60vh] w-full rounded-card" role="status" aria-busy="true" aria-label={t('loading', 'Loading the calendar')} />
        ) : notSetUp && items.length === 0 ? (
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
        ) : view === 'month' ? (
          <MonthView
            anchor={anchor}
            weekStart={settings.week_start}
            weekendDays={settings.weekend_days}
            today={today}
            timeZone={timeZone}
            items={items}
            onOpenItem={(item) => {
              // Month has no room beside an item: a booking opens its sheet, an event its dialog, a meeting its page.
              if (item.booking) setSheet({ booking: item.booking, startWith: null, suggest: false });
              else if (item.kind === 'meeting' && item.meetingId) router.push(`/calendar/meetings/${item.meetingId}`);
              else editEvent(item);
            }}
            onOpenDay={(date) => update({ date: dateKey(date), view: 'day' })}
          />
        ) : view === 'year' ? (
          <div className="grid gap-3">
            <YearLegend />
            <YearView
              year={anchor.year}
              weekStart={settings.week_start}
              today={today}
              timeZone={timeZone}
              counts={counts}
              onOpenDay={(date) => update({ date: dateKey(date), view: 'day' })}
            />
          </div>
        ) : (
          <TimeGrid
            days={range.days}
            items={items}
            startHour={hours.startHour}
            endHour={hours.endHour}
            timeZone={timeZone}
            weekendDays={settings.weekend_days}
            today={today}
            zone={zoneLabel(timeZone).replace(/^.*\((.*)\)$/, '$1')}
            selectedKey={selected?.key ?? null}
            onSelect={openItem}
            canDrag={edits.canDrag}
            canResize={edits.canResize}
            onCreate={access.canWrite ? (slot) => {
              setSelected(null);
              edits.onCreate(slot);
            } : undefined}
            onChange={edits.onChange}
            preview={edits.preview}
            renderDraft={() =>
              edits.draft === null ? null : (
                <QuickBooking
                  draft={edits.draft}
                  services={services}
                  staff={staff}
                  timeZone={timeZone}
                  canEvent={access.canWrite}
                  me={access.me}
                  onServiceLength={edits.onServiceLength}
                  onClose={() => edits.setDraft(null)}
                  onSaved={(message) => {
                    edits.setDraft(null);
                    addToast(message, 'success');
                    refetch();
                  }}
                  onMore={(date, profileId) => {
                    edits.setDraft(null);
                    setMoreFor({ date, profileId });
                    setNewBooking(true);
                  }}
                />
              )
            }
            onPickDay={(date) => update({ date: dateKey(date), view: 'day' })}
            renderDetails={(item) => (
              <BookingDetails
                item={item}
                timeZone={timeZone}
                withName={item.booking ? staffName.get(item.booking.profile_id) ?? '' : ''}
                canChange={access.canWrite}
                onClose={() => setSelected(null)}
                onMove={() => item.booking && setSheet({ booking: item.booking, startWith: 'move', suggest: false })}
                onCancel={() => item.booking && setSheet({ booking: item.booking, startWith: 'cancel', suggest: false })}
                onOpen={() => item.booking && setSheet({ booking: item.booking, startWith: null, suggest: false })}
                nameOf={nameOf}
                me={access.me}
                onAnswer={(response) => void answer(item, response)}
                onEdit={() => {
                  setSelected(null);
                  editEvent(item);
                }}
              />
            )}
          />
        )}
      </div>

      <BookingDialog
        open={newBooking}
        onOpenChange={(open) => {
          setNewBooking(open);
          if (!open) setMoreFor(null);
        }}
        services={services}
        staff={staff}
        timeZone={timeZone}
        initialDate={moreFor?.date ?? (view === 'day' ? anchor : null)}
        initialProfileId={moreFor?.profileId ?? null}
        multiService={settings.multi_service_booking ?? false}
        onBooked={(made) => {
          setNewBooking(false);
          addToast(t('booking.made', 'Booked for {name}', { name: made.customer.name ?? '' }), 'success');
          refetch();
        }}
      />

      <BookingSheet
        booking={sheet?.booking ?? null}
        suggest={sheet?.suggest ?? false}
        startWith={sheet?.startWith ?? null}
        onClose={() => setSheet(null)}
        services={services}
        staff={staff}
        timeZone={timeZone}
        canChange={access.canWrite}
        onChanged={(changed, what) => {
          setSheet((current) => (current === null ? null : { ...current, booking: changed, startWith: null, suggest: false }));
          setSelected(null);
          addToast(
            what === 'cancelled'
              ? t('booking.cancelled', 'Booking cancelled')
              : what === 'suggested'
                ? t('booking.suggested', 'Another time suggested to {name}', { name: changed.customer.name ?? '' })
                : what === 'accepted'
                  ? t('request.accepted', 'Booked for {name}', { name: changed.customer.name ?? '' })
                  : what === 'checkedIn'
                    ? t('booking.checkedInToast', '{name} checked in', { name: changed.customer.name ?? '' })
                    : what === 'notCheckedIn'
                      ? t('booking.checkInUndone', 'Check-in undone')
                      : t('booking.moved', 'Booking moved'),
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
        rooms={rooms}
      />
      {edits.dialogs}
    </div>
  );
}
