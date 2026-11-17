'use client';

/**
 * The calendar on a phone (design 3.5, CV.11, `TplCalendarPhone`).
 *
 * - Day, 3 days (the phone's Week: seven columns don't fit a thumb) and Month
 *   with the chosen day's list under it. The month name opens the mini month;
 *   Search and Today sit on the right; the tab bar stays.
 * - ☰ opens a drawer with the views, Colour by, People and Rooms and
 *   branches. Services, hours and settings open on a computer.
 * - Tapping a booking opens a sheet: when, the customer with Call, with whom,
 *   where, what is paid; Move, Call, Message, Cancel booking.
 * - "+ Booking" floats above the tab bar. Tapping an empty time puts a dashed
 *   slot there and opens New booking with the time filled in. Press and hold
 *   half a second to pick a booking up, drag, let go: it asks first, with
 *   "Email the new time" ticked, then Undo.
 * - 14px on the grid, 17px elsewhere, 56px an hour, 48px targets. Arabic
 *   mirrors through `dir` and logical properties.
 *
 * The choices (view, date, Colour by, ticks) are the same per-person ones the
 * computer keeps; the computer's views map to the nearest phone view.
 */

import { useCallback, useMemo, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Icon, type IconName } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { EmptyFirstTime } from '@whity/ui/page-states';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay, zonedDate } from '@whity/features/datetime';
import { LoadProblem } from '@/components/load-problem';
import { useFetch } from '@/hooks/useFetch';
import { useLocalizedUnits } from '@/hooks/useLocalizedUnits';
import { useToast } from '@/lib/toast-context';
import { cn } from '@/lib/utils';
import { calendarApi, type CalendarBooking, type CalendarEvent } from '@/lib/calendar/api';
import { DEFAULT_ALSO_SHOW, useViewChoices, type AlsoShow } from '@/lib/calendar/choices';
import { kindsFor, toCalItems } from '@/lib/calendar/items';
import { colourOf, palette, type ColourBy } from '@/lib/calendar/colours';
import {
  dateKey,
  isoWeekday,
  labelInstant,
  monthWeeks,
  overlapsDay,
  parseDateKey,
  placeInDay,
  rangeQuery,
  sameDate,
  stepAnchor,
  viewRange,
  type CalendarDate,
  type CalendarView,
} from '@/lib/calendar/layout';
import { BookingDetails } from './booking-details';
import { BookingLinkCard, BookingLinkShare } from './booking-link';
import { BookingDialog } from './booking-dialog';
import { BookingSheet } from './booking-sheet';
import { EventDialog } from './event-dialog';
import { useColourByLabels, useViewLabels } from './calendar-bar';
import { Check, Group, MiniMonth, useDayLetters, type PanelEntry } from './calendar-panel';
import { QuickBooking, useGridEditing } from './calendar-editing';
import { TimeGrid, hoursToDraw, type CalItem } from './time-grid';
import { useCalendarAccess, type CalendarBasics } from './use-calendar-basics';
import { useCalendarWords } from './use-calendar-words';

export const PHONE_VIEWS: CalendarView[] = ['day', 'three_days', 'month'];
const VIEW_ICON: Record<string, IconName> = { day: 'calendar', three_days: 'columns', month: 'grid' };


/** The computer's views on a phone: 4 days and Week become 3 days, Year becomes Month. */
export function phoneView(stored: string | undefined): CalendarView {
  if (stored === 'day' || stored === 'three_days' || stored === 'month') return stored;
  if (stored === 'year') return 'month';
  return 'three_days';
}

export function CalendarPhone({ basics }: { basics: CalendarBasics }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const { addToast } = useToast();
  const { settings, staff, services, rooms, timeZone } = basics;
  const units = useLocalizedUnits(basics.units);
  const dates = useDateDisplay({ timeZone });
  const viewLabels = useViewLabels();
  const colourLabels = useColourByLabels();
  const today = useMemo(() => zonedDate(timeZone), [timeZone]);
  const { choices, update } = useViewChoices();

  const view = phoneView(choices.view as string | undefined);
  const anchor = parseDateKey(choices.date) ?? today;
  const colourBy: ColourBy = choices.colour_by ?? 'person';
  const alsoShow: AlsoShow[] = (choices.also_show as AlsoShow[] | undefined) ?? DEFAULT_ALSO_SHOW;
  const hiddenPeople = useMemo(() => new Set(choices.hidden_people ?? []), [choices.hidden_people]);
  const hiddenRooms = useMemo(() => new Set(choices.hidden_rooms ?? []), [choices.hidden_rooms]);

  const setAnchor = useCallback((date: CalendarDate) => update({ date: dateKey(date) }), [update]);
  // The booking link (WHIT-1117): shown while the booking page is on, and offered
  // to managers when it is off. `?share=booking-link` (Ctrl+K) opens Share.
  const [bookingOn, setBookingOn] = useState(settings.booking_page_enabled);
  const shareOnOpen = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('share') === 'booking-link';
  const showLink = (settings.booking_page_url ?? null) !== null && (bookingOn || access.canManage);
  const [sharingLink, setSharingLink] = useState(shareOnOpen);
  const [drawer, setDrawer] = useState(false);
  const [picker, setPicker] = useState(false);
  const [searching, setSearching] = useState(false);
  const [search, setSearch] = useState('');
  const [chosenDay, setChosenDay] = useState<CalendarDate | null>(null);
  const [details, setDetails] = useState<CalItem | null>(null);
  const [sheet, setSheet] = useState<{ booking: CalendarBooking; startWith: 'move' | 'cancel' | null } | null>(null);
  const [eventOpen, setEventOpen] = useState(false);
  const [editingEvent, setEditingEvent] = useState<CalendarEvent | null>(null);
  const [fullForm, setFullForm] = useState<{ date: CalendarDate; profileId: number | null } | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const refetch = () => setReloadKey((k) => k + 1);

  const range = viewRange(view, anchor, settings.week_start);
  const query = rangeQuery(range, timeZone);
  const kinds = kindsFor(alsoShow);
  const { data, loading, error, forbidden } = useFetch(
    () => calendarApi.items({ ...query, kinds, cancelled: alsoShow.includes('cancelled') }),
    [query.from, query.to, kinds.join(','), alsoShow.includes('cancelled'), reloadKey]
  );
  const hiddenServices = useMemo(() => new Set(choices.hidden_services ?? []), [choices.hidden_services]);

  const openings = useFetch(async () => (await Promise.all(staff.map((s) => calendarApi.hours(s.profile_id).catch(() => [])))).flat(), [
    staff.map((s) => s.profile_id).join(','),
  ]);

  const colours = useMemo(() => palette(staff.map((s) => s.profile_id), services.map((s) => s.id), rooms.map((r) => r.id)), [staff, services, rooms]);
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

  const hours = hoursToDraw(openings.data ?? [], items, range.days, timeZone);
  const monthTitle = dates.calendarLabel(labelInstant(anchor, timeZone), view === 'month' ? 'monthYear' : 'month') ?? '';
  const title = view === 'day' ? `${words.weekdayShort(isoWeekday(anchor))} ${anchor.day} ${dates.calendarLabel(labelInstant(anchor, timeZone), 'month') ?? ''}` : monthTitle;

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
    setEditingEvent(item.event);
    setEventOpen(true);
  };

  const openItem = (item: CalItem | null) => {
    edits.setDraft(null);
    setDetails(item);
  };

  const people: PanelEntry[] = staff.map((s) => ({
    id: s.profile_id,
    label: s.display_name,
    colour: colourBy === 'person' ? colourOf('person', colours, { profileId: s.profile_id }) : null,
    shown: !hiddenPeople.has(s.profile_id),
  }));
  const roomEntries: PanelEntry[] = rooms
    .filter((r) => r.active)
    .map((r) => ({
      id: r.id,
      label: [r.unit_id !== null ? units.find((u) => u.id === r.unit_id)?.name : undefined, words.label(r.name)].filter(Boolean).join(' · '),
      colour: colourBy === 'room' ? colourOf('room', colours, { roomId: r.id }) : null,
      shown: !hiddenRooms.has(r.id),
    }));
  const toggle = (key: 'hidden_people' | 'hidden_rooms', current: Set<number>, id: number, shown: boolean) => {
    const next = new Set(current);
    if (shown) next.delete(id);
    else next.add(id);
    update({ [key]: [...next] });
  };

  // The day listed under Month: the one tapped, else today in this month, else the 1st.
  const listedDay =
    chosenDay !== null && chosenDay.month === anchor.month && chosenDay.year === anchor.year
      ? chosenDay
      : today.month === anchor.month && today.year === anchor.year
        ? today
        : { year: anchor.year, month: anchor.month, day: 1 };

  const notSetUp = services.length === 0 && staff.length === 0;
  const startNew = () => {
    // The next quarter hour today, or 10:00 on another day.
    const base = sameDate(anchor, today) ? Math.ceil(((new Date().getHours() * 60 + new Date().getMinutes()) + 1) / 15) * 15 : 10 * 60;
    edits.onCreate({ date: view === 'month' ? listedDay : anchor, startMinute: Math.min(base, 22 * 60), endMinute: null });
  };

  return (
    <div className="-mx-4 -mt-4 grid gap-2 text-base" data-testid="calendar-phone" data-full-width="">
      <h1 className="sr-only">{t('title', 'Calendar')}</h1>
      <div className="sticky top-0 z-20 flex items-center gap-1 border-b border-border bg-background px-2 py-2">
        <Button variant="ghost" size="icon-lg" onClick={() => setDrawer(true)} aria-label={t('phone.menu', 'Calendar views and filters')} data-testid="calendar-phone-menu">
          <Icon name="menu" size={20} />
        </Button>
        <button
          type="button"
          onClick={() => setPicker(true)}
          className="flex min-h-12 min-w-0 items-center gap-1 rounded-control px-2 text-xl font-semibold text-foreground outline-none focus-visible:outline-2 focus-visible:outline-ring"
          aria-haspopup="dialog"
          data-testid="calendar-title"
        >
          <span className="truncate">{title}</span>
          <Icon name="chevron-down" />
        </button>
        <span className="flex-1" />
        <Button variant="ghost" size="icon-lg" onClick={() => setSearching((open) => !open)} aria-label={t('panel.search', 'Search bookings and people')} aria-expanded={searching}>
          <Icon name="search" size={20} />
        </Button>
        <Button variant="outline" onClick={() => setAnchor(today)} data-testid="calendar-today">
          {t('toolbar.today', 'Today')}
        </Button>
      </div>
      {searching && (
        <div className="px-4">
          <input
            type="search"
            autoFocus
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t('panel.search', 'Search bookings and people')}
            className="h-12 w-full rounded-control border border-input bg-background px-3 text-base outline-none focus-visible:outline-2 focus-visible:outline-ring"
            data-testid="calendar-search"
          />
        </div>
      )}

      <div className="px-2">
        {error ? (
          <LoadProblem forbidden={forbidden} message={t('error.loadRange', 'Bookings and events could not be loaded.')} onRetry={refetch} area={t('area', 'the calendar')} />
        ) : loading && data === null ? (
          <Skeleton className="h-[60vh] w-full rounded-card" role="status" aria-busy="true" aria-label={t('loading', 'Loading the calendar')} />
        ) : notSetUp && items.length === 0 ? (
          <EmptyFirstTime
            icon="calendar"
            title={t('empty.title', 'Set up the calendar')}
            description={t('phone.setUpOnComputer', 'Services, hours and settings are set up on a computer.')}
          />
        ) : view === 'month' ? (
          <PhoneMonth
            anchor={anchor}
            weekStart={settings.week_start}
            weekendDays={settings.weekend_days}
            today={today}
            timeZone={timeZone}
            items={items}
            chosen={listedDay}
            onChoose={setChosenDay}
            onOpen={openItem}
          />
        ) : (
          <TimeGrid
            phone
            days={range.days}
            items={items}
            startHour={hours.startHour}
            endHour={hours.endHour}
            timeZone={timeZone}
            weekendDays={settings.weekend_days}
            today={today}
            zone=""
            selectedKey={details?.key ?? null}
            onSelect={openItem}
            onPickDay={(date) => update({ date: dateKey(date), view: 'day' })}
            canDrag={edits.canDrag}
            canResize={edits.canResize}
            onCreate={access.canWrite ? edits.onCreate : undefined}
            onChange={edits.onChange}
            preview={edits.preview}
          />
        )}
      </div>

      {access.canWrite && services.length > 0 && (
        <Button
          size="lg"
          onClick={startNew}
          className="fixed end-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 h-14 rounded-full px-6 text-base"
          data-testid="calendar-new-booking"
        >
          <Icon name="plus" size={20} />
          {t('phone.booking', 'Booking')}
        </Button>
      )}

      {/* ☰: the views, Colour by, People and Rooms and branches (CV.11). */}
      <Sheet open={drawer} onOpenChange={setDrawer}>
        <SheetContent side="left" closeLabel={t('action.close', 'Close')} className="w-[85vw] max-w-sm gap-5 overflow-y-auto p-5 text-base" data-testid="calendar-phone-drawer">
          <SheetHeader className="p-0">
            <SheetTitle className="text-xl font-semibold">{t('title', 'Calendar')}</SheetTitle>
            <SheetDescription className="sr-only">{t('phone.menu', 'Calendar views and filters')}</SheetDescription>
          </SheetHeader>
          <ul className="m-0 grid list-none gap-1 p-0">
            {PHONE_VIEWS.map((v) => (
              <li key={v}>
                <button
                  type="button"
                  onClick={() => {
                    update({ view: v });
                    setDrawer(false);
                  }}
                  aria-current={v === view ? 'true' : undefined}
                  className={cn('flex min-h-12 w-full items-center gap-3 rounded-control px-3 text-start', v === view && 'bg-muted font-medium')}
                >
                  <Icon name={VIEW_ICON[v]} size={20} />
                  <span className="flex-1">{viewLabels[v]}</span>
                  {v === view && <Icon name="check" size={20} />}
                </button>
              </li>
            ))}
          </ul>
          <div className="grid gap-2">
            <h4 className="m-0 text-sm font-semibold text-muted-foreground">{t('colourBy.label', 'Colour by')}</h4>
            <ToggleGroup
              label={t('colourBy.label', 'Colour by')}
              required
              value={colourBy}
              onChange={(value) => {
                if (value === 'person' || value === 'service' || value === 'room') update({ colour_by: value });
              }}
              options={(['person', 'service', 'room'] as const).map((by) => ({ value: by, label: by === 'room' ? t('colourBy.roomShort', 'Room') : colourLabels[by] }))}
            />
          </div>
          {people.length > 0 && (
            <Group title={t('panel.people', 'People')}>
              {people.map((entry) => (
                <Check key={entry.id} testId="calendar-person-check" entry={entry} onChange={(shown) => toggle('hidden_people', hiddenPeople, entry.id, shown)} />
              ))}
            </Group>
          )}
          {roomEntries.length > 0 && (
            <Group title={t('panel.rooms', 'Rooms and branches')}>
              {roomEntries.map((entry) => (
                <Check key={entry.id} testId="calendar-room-check" entry={entry} onChange={(shown) => toggle('hidden_rooms', hiddenRooms, entry.id, shown)} />
              ))}
            </Group>
          )}
          {showLink && (
            <BookingLinkCard
              compact
              link={settings.booking_page_url ?? null}
              enabled={bookingOn}
              canManage={access.canManage}
              onEnabled={() => setBookingOn(true)}
            />
          )}
          <p className="m-0 text-sm text-muted-foreground">{t('phone.setUpOnComputer', 'Services, hours and settings are set up on a computer.')}</p>
        </SheetContent>
      </Sheet>

      {/* The month name opens the mini month. */}
      <Sheet open={picker} onOpenChange={setPicker}>
        <SheetContent side="bottom" closeLabel={t('action.close', 'Close')} className="gap-3 rounded-t-2xl p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
          <SheetHeader className="p-0">
            <SheetTitle>{t('phone.pickDay', 'Go to a day')}</SheetTitle>
            <SheetDescription className="sr-only">{t('phone.pickDay', 'Go to a day')}</SheetDescription>
          </SheetHeader>
          <MiniMonth
            timeZone={timeZone}
            weekStart={settings.week_start}
            today={today}
            shownFrom={range.days[0] ?? anchor}
            shownTo={range.days[range.days.length - 1] ?? anchor}
            // @date-display-ignore: a callback that jumps to a date, not a timestamp rendered here
            onPickDate={(date) => {
              setAnchor(date);
              setChosenDay(date);
              setPicker(false);
            }}
          />
          <div className="flex justify-between">
            <Button variant="ghost" onClick={() => setAnchor(stepAnchor(view, anchor, -1))}>
              <Icon name="chevron-left" className="rtl:-scale-x-100" />
              {t('toolbar.previous', 'Previous')}
            </Button>
            <Button variant="ghost" onClick={() => setAnchor(stepAnchor(view, anchor, 1))}>
              {t('toolbar.next', 'Next')}
              <Icon name="chevron-right" className="rtl:-scale-x-100" />
            </Button>
          </div>
        </SheetContent>
      </Sheet>

      {/* A booking's sheet: when, customer with Call, with, where, paid (CV.11). */}
      <Sheet open={details !== null} onOpenChange={(open) => !open && setDetails(null)}>
        <SheetContent side="bottom" closeLabel={t('action.close', 'Close')} className="rounded-t-2xl p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
          <SheetHeader className="sr-only">
            <SheetTitle>{details?.title ?? ''}</SheetTitle>
            <SheetDescription>{details?.who ?? ''}</SheetDescription>
          </SheetHeader>
          {details !== null && (
            <BookingDetails
              inSheet
              item={details}
              timeZone={timeZone}
              withName={details.booking ? staffName.get(details.booking.profile_id) ?? '' : ''}
              canChange={access.canWrite}
              onClose={() => setDetails(null)}
              onMove={() => {
                if (details.booking) setSheet({ booking: details.booking, startWith: 'move' });
                setDetails(null);
              }}
              onCancel={() => {
                if (details.booking) setSheet({ booking: details.booking, startWith: 'cancel' });
                setDetails(null);
              }}
              onOpen={() => {
                if (details.booking) setSheet({ booking: details.booking, startWith: null });
                setDetails(null);
              }}
              nameOf={nameOf}
              me={access.me}
              onAnswer={(response) => void answer(details, response)}
              onEdit={() => {
                editEvent(details);
                setDetails(null);
              }}
            />
          )}
        </SheetContent>
      </Sheet>

      {/* New booking: the slot is dashed on the grid and the form opens below (CV.11). */}
      <Sheet open={edits.draft !== null} onOpenChange={(open) => !open && edits.setDraft(null)}>
        <SheetContent side="bottom" closeLabel={t('action.close', 'Close')} className="max-h-[85vh] overflow-y-auto rounded-t-2xl p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))]" data-testid="calendar-phone-new">
          <SheetHeader className="p-0">
            <SheetTitle className="text-xl font-semibold">{t('toolbar.newBooking', 'New booking')}</SheetTitle>
            <SheetDescription className="sr-only">{t('toolbar.newBooking', 'New booking')}</SheetDescription>
          </SheetHeader>
          {edits.draft !== null && (
            <QuickBooking
              inSheet
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
                setFullForm({ date, profileId });
              }}
            />
          )}
        </SheetContent>
      </Sheet>

      {edits.dialogs}
      {settings.booking_page_url && bookingOn && (
        <BookingLinkShare open={sharingLink} onOpenChange={setSharingLink} link={settings.booking_page_url} />
      )}

      <BookingDialog
        open={fullForm !== null}
        onOpenChange={(open) => !open && setFullForm(null)}
        services={services}
        staff={staff}
        timeZone={timeZone}
        initialDate={fullForm?.date ?? null}
        initialProfileId={fullForm?.profileId ?? null}
        multiService={settings.multi_service_booking ?? false}
        onBooked={(made) => {
          setFullForm(null);
          addToast(t('booking.made', 'Booked for {name}', { name: made.customer.name ?? '' }), 'success');
          refetch();
        }}
      />
      <BookingSheet
        booking={sheet?.booking ?? null}
        startWith={sheet?.startWith ?? null}
        onClose={() => setSheet(null)}
        services={services}
        staff={staff}
        timeZone={timeZone}
        canChange={access.canWrite}
        onChanged={(changed, what) => {
          setSheet((current) => (current === null ? null : { booking: changed, startWith: null }));
          addToast(what === 'cancelled' ? t('booking.cancelled', 'Booking cancelled') : t('booking.moved', 'Booking moved'), 'success');
          refetch();
        }}
      />
      <EventDialog
        open={eventOpen}
        onOpenChange={(open) => {
          setEventOpen(open);
          if (!open) setEditingEvent(null);
        }}
        event={editingEvent}
        staff={staff}
        timeZone={timeZone}
        me={access.me}
        canManage={access.canManage}
        canChange={editingEvent === null ? access.canWrite : access.canChange(editingEvent.profile_id)}
        initialDate={anchor}
        onSaved={refetch}
        rooms={rooms}
      />
    </div>
  );
}

/** Month on a phone: dots by colour under each day, and the chosen day's list (CV.11). */
function PhoneMonth({
  anchor,
  weekStart,
  weekendDays,
  today,
  timeZone,
  items,
  chosen,
  onChoose,
  onOpen,
}: {
  anchor: CalendarDate;
  weekStart: string;
  weekendDays: string[];
  today: CalendarDate;
  timeZone: string;
  items: CalItem[];
  chosen: CalendarDate;
  onChoose: (date: CalendarDate) => void;
  onOpen: (item: CalItem) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const weeks = monthWeeks(anchor, weekStart);
  const letters = useDayLetters();
  const weekend = new Set(weekendDays);
  const onDay = (date: CalendarDate) => items.filter((item) => overlapsDay(item, date, timeZone)).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  const listed = onDay(chosen).filter((item) => !item.allDay || item.kind !== 'timeoff');

  return (
    <div className="grid gap-3" data-testid="calendar-phone-month">
      <div className="grid grid-cols-7 text-center">
        {weeks[0].map((date) => (
          <span key={dateKey(date)} className="py-2 text-sm text-muted-foreground">
            {letters[isoWeekday(date) - 1]}
          </span>
        ))}
        {weeks.flat().map((date) => {
          const dots = onDay(date).slice(0, 3);
          const isToday = sameDate(date, today);
          const isChosen = sameDate(date, chosen);
          const off = weekend.has(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'][isoWeekday(date) - 1]);
          return (
            <button
              key={dateKey(date)}
              type="button"
              onClick={() => onChoose(date)}
              aria-pressed={isChosen}
              aria-label={dates.calendarLabel(labelInstant(date, timeZone), 'fullDate') ?? dateKey(date)}
              className={cn('grid min-h-14 justify-items-center gap-1 rounded-control py-1 outline-none focus-visible:outline-2 focus-visible:outline-ring', off && 'bg-muted')}
              data-testid="calendar-phone-month-day"
              data-date={dateKey(date)}
            >
              <span
                className={cn(
                  'grid size-9 place-items-center rounded-full tabular-nums',
                  date.month !== anchor.month && 'text-muted-foreground',
                  isToday && 'bg-primary font-semibold text-primary-foreground',
                  isChosen && !isToday && 'ring-2 ring-primary'
                )}
                dir="ltr"
              >
                {date.day}
              </span>
              <span className="flex h-2 gap-1" aria-hidden="true">
                {dots.map((item) => (
                  <i key={item.key} className="size-1.5 rounded-full" style={{ background: item.colour }} />
                ))}
              </span>
            </button>
          );
        })}
      </div>
      <section className="grid gap-1 border-t border-border pt-3" aria-label={dates.calendarLabel(labelInstant(chosen, timeZone), 'fullDate') ?? ''}>
        <h3 className="m-0 px-2 text-lg font-semibold">
          {words.weekday(isoWeekday(chosen))} {chosen.day} {dates.calendarLabel(labelInstant(chosen, timeZone), 'month') ?? ''}
        </h3>
        {listed.length === 0 ? (
          <p className="m-0 px-2 text-muted-foreground">{t('grid.nothingBooked', 'Nothing booked')}</p>
        ) : (
          listed.map((item) => (
            <button
              key={item.key}
              type="button"
              onClick={() => onOpen(item)}
              className="grid min-h-16 grid-cols-[56px_4px_1fr] items-center gap-3 border-b border-border px-2 py-2 text-start outline-none last:border-b-0 focus-visible:outline-2 focus-visible:outline-ring"
              data-testid="calendar-phone-month-item"
            >
              <span className="text-sm text-muted-foreground tabular-nums" dir="ltr">
                {item.allDay ? '' : dates.time(item.starts_at)}
              </span>
              <span className="h-full min-h-10 rounded-full" style={{ background: item.colour }} aria-hidden="true" />
              <span className="min-w-0">
                <b className={cn('block truncate font-medium', item.kind === 'cancelled' && 'line-through')}>
                  {item.title}
                  {item.kind === 'wait' ? ` · ${t('grid.waitingPayment', 'waiting for payment')}` : ''}
                </b>
                <span className="block truncate text-sm text-muted-foreground">{item.who}</span>
              </span>
            </button>
          ))
        )}
      </section>
      <div className="h-20" aria-hidden="true" />
    </div>
  );
}
