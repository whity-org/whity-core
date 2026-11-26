'use client';

/**
 * Calendar › Front desk (WHIT-1441, design section 39 §10, AP.27-AP.30).
 *
 * The day at the desk, for people with `calendar:front_desk`:
 * - **Columns** (one per staff member, or per room or piece of equipment) on
 *   a grid of 48px an hour with now as a red line, or a **List**, one row per
 *   booking in time order. The List is the default under 1024px.
 * - Each booking shows its time, customer, service, status and flag. Each
 *   step is one labelled button: Check in, Start, Finish, Didn't come. With a
 *   booking selected, the keys I, S, F and N do the same, and the arrow keys
 *   move the selection.
 * - The side panel: Waiting now (arrived, and walk-ins, with minutes waited),
 *   Did they come?, and the selected booking with all its actions.
 * - **Running late**, for a customer (shown, nothing moves) and for a staff
 *   member (their day pushed back, then Move and tell them).
 * - The main button is Add walk-in when walk-ins are on, otherwise New booking.
 *
 * One read loads the day; a light check every 20 seconds returns only what
 * changed, and the desk's own changes show at once (AP.28). On a phone the
 * List has 48px rows with Check in on the row and the rest in a sheet (AP.30).
 */

import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { FieldSelect } from '@/components/field-select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useToast } from '@/lib/toast-context';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Skeleton } from '@whity/ui/skeleton';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import {
  calendarApi,
  type CalendarBooking,
  type CalendarLatePlanStep,
  type CalendarStaff,
  type CalendarWalkin,
  type DeskStatus,
} from '@/lib/calendar/api';
import { CalendarNav } from './calendar-nav';
import { useCalendarAccess, useCalendarBasics } from './use-calendar-basics';
import { useCalendarWords } from './use-calendar-words';
import { DidTheyComeCard } from './appointments-side';
import { BookingDialog } from './booking-dialog';
import { BookingSheet, CustomerFlag } from './booking-sheet';

/** 48px an hour (§10.1). */
const HOUR_PX = 48;
/** The light check for changes (AP.28). */
const CHECK_MS = 20_000;
const LATE_CHOICES = [5, 10, 15];
const STAFF_LATE_CHOICES = [10, 15, 20, 30];

/** Where each step leads, and its key. */
const NEXT: Record<string, { to: DeskStatus; key: string } | null> = {
  expected: { to: 'arrived', key: 'i' },
  arrived: { to: 'with_staff', key: 's' },
  with_staff: { to: 'done', key: 'f' },
  done: null,
};
const BACK: Record<string, DeskStatus | null> = { expected: null, arrived: 'expected', with_staff: 'arrived', done: 'with_staff', no_show: null };

function subscribeWidth(query: string) {
  return (onChange: () => void) => {
    if (typeof window.matchMedia !== 'function') return () => undefined;
    const list = window.matchMedia(query);
    list.addEventListener('change', onChange);
    return () => list.removeEventListener('change', onChange);
  };
}
function useMedia(query: string): boolean {
  return useSyncExternalStore(
    useMemo(() => subscribeWidth(query), [query]),
    () => typeof window.matchMedia === 'function' && window.matchMedia(query).matches,
    () => false
  );
}

/** The time now, to the minute. */
function useMinuteClock(): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(id);
  }, []);
  return now;
}

/** Minutes from the start of the local day, for the grid. */
function useMinuteOfDay(timeZone: string): (iso: string | null | undefined) => number | null {
  const dates = useDateDisplay({ timeZone });
  return useCallback(
    (iso) => {
      const hm = dates.time(iso ?? null);
      if (hm === null) return null;
      const m = /(\d{1,2}):(\d{2})/.exec(hm);
      return m ? Number(m[1]) * 60 + Number(m[2]) : null;
    },
    [dates]
  );
}

function useDeskWords() {
  const t = useTranslation('calendar');
  return {
    status: (s: string) =>
      ({
        expected: t('desk.status.expected', 'Expected'),
        arrived: t('desk.status.arrived', 'Arrived'),
        with_staff: t('desk.status.withStaff', 'With staff'),
        done: t('desk.status.done', 'Done'),
        no_show: t('desk.status.noShow', 'Didn’t come'),
        requested: t('desk.status.requested', 'Request'),
        pending_payment: t('desk.status.pending', 'Waiting for payment'),
      })[s] ?? s,
    variant: (s: string): 'outline' | 'info' | 'warning' | 'success' | 'destructive' =>
      s === 'arrived' ? 'info' : s === 'with_staff' ? 'warning' : s === 'done' ? 'success' : s === 'no_show' ? 'destructive' : 'outline',
    step: (to: DeskStatus) =>
      ({
        arrived: t('desk.step.checkIn', 'Check in'),
        with_staff: t('desk.step.start', 'Start'),
        done: t('desk.step.finish', 'Finish'),
        expected: t('desk.step.expected', 'Not arrived'),
      })[to],
    back: (to: DeskStatus) =>
      ({
        expected: t('desk.back.expected', 'Undo check-in'),
        arrived: t('desk.back.arrived', 'Undo start'),
        with_staff: t('desk.back.withStaff', 'Undo finish'),
        done: '',
      })[to],
  };
}

export function FrontDesk() {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const basics = useCalendarBasics();
  const { addToast } = useToast();
  const narrow = useMedia('(max-width: 1023px)');
  const phone = useMedia('(max-width: 767px)');
  const nowMs = useMinuteClock();

  const [bookings, setBookings] = useState<Map<number, CalendarBooking>>(new Map());
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState<{ forbidden: boolean } | null>(null);
  const [walkins, setWalkins] = useState<CalendarWalkin[]>([]);
  // WHIT-1442 (AP.23): how many are on the waitlist.
  const [waitlistCount, setWaitlistCount] = useState(0);
  const [view, setView] = useState<'columns' | 'list' | null>(null);
  const [by, setBy] = useState<'people' | 'rooms'>('people');
  const [selected, setSelected] = useState<number | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [lateFor, setLateFor] = useState<CalendarBooking | null>(null);
  const [staffLate, setStaffLate] = useState<CalendarStaff | null>(null);
  const [sheet, setSheet] = useState<CalendarBooking | null>(null);
  const [actions, setActions] = useState<CalendarBooking | null>(null);
  const [addWalkin, setAddWalkin] = useState(false);
  const [newBooking, setNewBooking] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const since = useRef<string | null>(null);

  const data = basics.data;
  const timeZone = data?.timeZone ?? 'UTC';
  const walkinsOn = data?.settings.walkins_enabled === true;
  const shown = view ?? (narrow ? 'list' : 'columns');

  const merge = useCallback((changed: CalendarBooking[], full: boolean) => {
    setBookings((current) => {
      const next = full ? new Map<number, CalendarBooking>() : new Map(current);
      for (const b of changed) {
        if (b.status === 'cancelled' || b.status === 'expired') next.delete(b.id);
        else next.set(b.id, b);
      }
      return next;
    });
  }, []);

  const load = useCallback(
    async (full: boolean) => {
      try {
        const day = await calendarApi.desk({ since: full ? null : since.current });
        since.current = day.now;
        merge(day.bookings, day.full);
        setWaitlistCount(day.waitlist_count);
        setLoaded(true);
        setFailed(null);
        if (walkinsOn) setWalkins(await calendarApi.walkins().catch(() => []));
      } catch (error) {
        if (full) setFailed({ forbidden: (error as { name?: string })?.name === 'ForbiddenError' });
      }
    },
    [merge, walkinsOn]
  );

  useEffect(() => {
    if (!access.canFrontDesk || data === null) return;
    void load(true);
    const id = window.setInterval(() => void load(false), CHECK_MS);
    return () => window.clearInterval(id);
  }, [access.canFrontDesk, data, load]);

  const list = useMemo(() => [...bookings.values()].sort((a, b) => a.starts_at.localeCompare(b.starts_at) || a.id - b.id), [bookings]);
  const chosen = selected !== null ? (bookings.get(selected) ?? null) : null;

  const changed = (booking: CalendarBooking) => {
    merge([booking], false);
    setReloadKey((k) => k + 1);
  };

  const step = useCallback(
    async (booking: CalendarBooking, to: DeskStatus | 'no_show') => {
      setBusy(booking.id);
      try {
        const next = to === 'no_show' ? await calendarApi.markAttendance(booking.id, 'no_show') : await calendarApi.deskStatus(booking.id, to);
        merge([next], false);
        setReloadKey((k) => k + 1);
      } catch (error) {
        addToast(words.errorText(error, t('desk.error.step', 'That didn’t work. Try again.')), 'error');
      } finally {
        setBusy(null);
      }
    },
    [addToast, merge, t, words]
  );

  // I, S, F and N on the selected booking; arrows move the selection (AP.27, CV.10).
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target && (target.closest('input, textarea, select, [contenteditable="true"], [role="dialog"]') || event.metaKey || event.ctrlKey || event.altKey)) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (list.length === 0) return;
        event.preventDefault();
        const at = list.findIndex((b) => b.id === selected);
        const next = event.key === 'ArrowDown' ? Math.min(list.length - 1, at + 1) : Math.max(0, at === -1 ? 0 : at - 1);
        setSelected(list[next].id);
        return;
      }
      if (chosen === null || busy !== null) return;
      const key = event.key.toLowerCase();
      const status = chosen.desk_status ?? 'expected';
      if (key === 'n' && status === 'expected' && new Date(chosen.starts_at).getTime() <= nowMs) {
        event.preventDefault();
        void step(chosen, 'no_show');
        return;
      }
      const next = NEXT[status];
      if (next && (key === 'i' || key === 's' || key === 'f') && (key === next.key || (key !== 'i' && status === 'expected'))) {
        event.preventDefault();
        void step(chosen, key === 'i' ? 'arrived' : key === 's' ? 'with_staff' : 'done');
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [list, selected, chosen, busy, step, nowMs]);

  if (!access.loading && !access.canFrontDesk) {
    return <NoAccess area={t('desk.area', 'the front desk')} permission="calendar:front_desk" />;
  }

  // On a phone the main button floats above the tab bar, under the thumb (AP.30).
  const floating = phone ? 'fixed end-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 h-14 rounded-full px-6 text-base' : undefined;
  const mainButton = walkinsOn ? (
    <Button size={phone ? 'lg' : undefined} className={floating} onClick={() => setAddWalkin(true)} data-testid="desk-add-walkin">
      {t('desk.addWalkin', 'Add walk-in')}
    </Button>
  ) : (
    <Button size={phone ? 'lg' : undefined} className={floating} onClick={() => setNewBooking(true)} disabled={!data || data.services.length === 0} data-testid="desk-new-booking">
      {t('desk.newBooking', 'New booking')}
    </Button>
  );

  return (
    <div className="space-y-4 pb-24 md:pb-4" data-testid="front-desk">
      <AdminHeader
        title={t('desk.title', 'Front desk')}
        description={t('desk.description', 'Today’s bookings as they arrive, are seen and leave.')}
        action={phone ? undefined : mainButton}
      />
      <CalendarNav active="desk" />

      {basics.error || failed ? (
        <LoadProblem
          forbidden={basics.forbidden || (failed?.forbidden ?? false)}
          message={t('desk.error.load', 'The front desk could not be loaded.')}
          onRetry={() => {
            basics.refetch();
            void load(true);
          }}
          area={t('desk.area', 'the front desk')}
        />
      ) : data === null || !loaded ? (
        <div role="status" aria-busy="true" aria-label={t('desk.loading', 'Loading the front desk')} className="space-y-3">
          <Skeleton className="h-10 w-full max-w-xl rounded-control" />
          <Skeleton className="h-[50vh] w-full rounded-card" />
        </div>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <ToggleGroup
              label={t('desk.view', 'Show')}
              required
              value={shown}
              onChange={(value) => (value === 'columns' || value === 'list') && setView(value)}
              options={[
                { value: 'columns', label: t('desk.view.columns', 'Columns') },
                { value: 'list', label: t('desk.view.list', 'List') },
              ]}
            />
            {shown === 'columns' && (
              <ToggleGroup
                label={t('desk.by', 'A column for each')}
                required
                value={by}
                onChange={(value) => (value === 'people' || value === 'rooms') && setBy(value)}
                options={[
                  { value: 'people', label: t('desk.by.people', 'Person') },
                  { value: 'rooms', label: t('desk.by.rooms', 'Room or equipment') },
                ]}
              />
            )}
          </div>

          <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div className="min-w-0">
              {list.length === 0 ? (
                <Card>
                  <CardContent className="py-8 text-center text-sm text-muted-foreground" data-testid="desk-empty">
                    {t('desk.empty', 'No bookings today yet.')}
                  </CardContent>
                </Card>
              ) : shown === 'list' ? (
                <DeskList
                  bookings={list}
                  timeZone={timeZone}
                  selected={selected}
                  busy={busy}
                  phone={phone}
                  nowMs={nowMs}
                  onSelect={setSelected}
                  onStep={step}
                  onLate={setLateFor}
                  onOpen={setSheet}
                  onMore={setActions}
                />
              ) : (
                <DeskColumns
                  bookings={list}
                  staff={data.staff}
                  rooms={data.rooms}
                  by={by}
                  timeZone={timeZone}
                  selected={selected}
                  onSelect={setSelected}
                  onStaffLate={setStaffLate}
                />
              )}
            </div>

            <aside className="space-y-4" aria-label={t('desk.side', 'At the desk')}>
              {chosen && shown === 'columns' && (
                <Card data-testid="desk-selected">
                  <CardHeader>
                    <CardTitle>{chosen.customer.name || t('booking.noName', 'No name given')}</CardTitle>
                    <CardDescription>
                      <BookingLine booking={chosen} timeZone={timeZone} />
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <BookingActions
                      booking={chosen}
                      busy={busy === chosen.id}
                      nowMs={nowMs}
                      onStep={step}
                      onLate={setLateFor}
                      onOpen={setSheet}
                    />
                  </CardContent>
                </Card>
              )}
              <WaitingNow bookings={list} walkins={walkins} timeZone={timeZone} nowMs={nowMs} onSelect={setSelected} />
              <DidTheyComeCard unitId={null} reloadKey={reloadKey} timeZone={timeZone} canChange={access.canWrite} onChanged={() => void load(false)} />
              <Card data-testid="desk-waitlist">
                <CardHeader>
                  <CardTitle>{t('nav.waitlist', 'Waitlist')}</CardTitle>
                  <CardDescription>
                    {waitlistCount === 0
                      ? t('desk.waitlistNone', 'Nobody is waiting for a time.')
                      : waitlistCount === 1
                        ? t('desk.waitlistOne', 'One person is waiting for a time.')
                        : t('desk.waitlistCount', '{count} people are waiting for a time.', { count: waitlistCount })}
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <Link href="/calendar/waitlist" className="text-sm font-medium text-primary hover:underline">
                    {t('desk.openWaitlist', 'Open the waitlist')}
                  </Link>
                </CardContent>
              </Card>
            </aside>
          </div>

          {phone && mainButton}

          <CustomerLateDialog
            booking={lateFor}
            onClose={() => setLateFor(null)}
            onSaved={(booking) => {
              changed(booking);
              setLateFor(null);
            }}
          />
          <StaffLateDialog
            person={staffLate}
            timeZone={timeZone}
            onClose={() => setStaffLate(null)}
            onDone={() => {
              setStaffLate(null);
              void load(false);
            }}
          />
          <DeskActionsSheet
            booking={actions}
            timeZone={timeZone}
            busy={actions !== null && busy === actions.id}
            nowMs={nowMs}
            onClose={() => setActions(null)}
            onStep={async (b, to) => {
              await step(b, to);
              setActions(null);
            }}
            onLate={(b) => {
              setActions(null);
              setLateFor(b);
            }}
            onOpen={(b) => {
              setActions(null);
              setSheet(b);
            }}
          />
          <BookingSheet
            booking={sheet}
            onClose={() => setSheet(null)}
            services={data.services}
            staff={data.staff}
            timeZone={timeZone}
            canChange={access.canWrite}
            onChanged={(booking) => {
              setSheet(booking);
              changed(booking);
            }}
          />
          {walkinsOn ? (
            <WalkinDialog
              open={addWalkin}
              services={data.services.filter((s) => s.active)}
              onClose={() => setAddWalkin(false)}
              onAdded={(walkin) => {
                setAddWalkin(false);
                setWalkins((current) => [...current, walkin]);
                addToast(t('desk.walkinAdded', '{name} is waiting', { name: walkin.name }), 'success');
              }}
            />
          ) : (
            <BookingDialog
              open={newBooking}
              onOpenChange={setNewBooking}
              services={data.services}
              staff={data.staff}
              timeZone={timeZone}
              multiService={data.settings.multi_service_booking === true}
              onBooked={(booking) => {
                setNewBooking(false);
                changed(booking);
                addToast(t('booking.made', 'Booked for {name}', { name: booking.customer.name ?? '' }), 'success');
              }}
            />
          )}
        </>
      )}
    </div>
  );
}

/** Time, service and who, on one line; the flag and lateness after. */
function BookingLine({ booking, timeZone }: { booking: CalendarBooking; timeZone: string }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  return (
    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span dir="ltr" className="tabular-nums">
        {dates.time(booking.starts_at) ?? '—'}–{dates.time(booking.ends_at) ?? '—'}
      </span>
      {booking.service_name ? <span>{words.label(booking.service_name)}</span> : null}
      {booking.staff_name ? <span>· {booking.staff_name}</span> : null}
      {booking.late_minutes ? (
        <Badge variant="warning" data-testid={`desk-late-${booking.id}`}>
          {t('desk.lateBy', 'Running {minutes} min late', { minutes: booking.late_minutes })}
        </Badge>
      ) : null}
      {booking.customer_flag ? <CustomerFlag flag={booking.customer_flag} /> : null}
    </span>
  );
}

/** Every action on a booking, each a labelled button. */
function BookingActions({
  booking,
  busy,
  nowMs,
  onStep,
  onLate,
  onOpen,
}: {
  booking: CalendarBooking;
  busy: boolean;
  nowMs: number;
  onStep: (b: CalendarBooking, to: DeskStatus | 'no_show') => void | Promise<void>;
  onLate: (b: CalendarBooking) => void;
  onOpen: (b: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  const desk = useDeskWords();
  const status = booking.desk_status ?? 'expected';
  const next = NEXT[status] ?? null;
  const back = BACK[status] ?? null;
  const started = new Date(booking.starts_at).getTime() <= nowMs;
  return (
    <div className="flex flex-wrap gap-2">
      {next && (
        <Button variant="outline" size="sm" loading={busy} onClick={() => void onStep(booking, next.to)} data-testid={`desk-step-${booking.id}`}>
          {desk.step(next.to)}
        </Button>
      )}
      {status === 'expected' && started && (
        <Button variant="outline" size="sm" disabled={busy} onClick={() => void onStep(booking, 'no_show')} data-testid={`desk-noshow-${booking.id}`}>
          {t('desk.step.noShow', 'Didn’t come')}
        </Button>
      )}
      {status === 'expected' && (
        <Button variant="outline" size="sm" disabled={busy} onClick={() => onLate(booking)} data-testid={`desk-late-open-${booking.id}`}>
          {t('desk.late', 'Running late')}
        </Button>
      )}
      {back && (
        <Button variant="ghost" size="sm" disabled={busy} onClick={() => void onStep(booking, back)}>
          {desk.back(back)}
        </Button>
      )}
      <Button variant="ghost" size="sm" onClick={() => onOpen(booking)} data-testid={`desk-open-${booking.id}`}>
        {t('desk.open', 'Open booking')}
      </Button>
    </div>
  );
}

function DeskList({
  bookings,
  timeZone,
  selected,
  busy,
  phone,
  nowMs,
  onSelect,
  onStep,
  onLate,
  onOpen,
  onMore,
}: {
  bookings: CalendarBooking[];
  timeZone: string;
  selected: number | null;
  busy: number | null;
  phone: boolean;
  nowMs: number;
  onSelect: (id: number) => void;
  onStep: (b: CalendarBooking, to: DeskStatus | 'no_show') => void | Promise<void>;
  onLate: (b: CalendarBooking) => void;
  onOpen: (b: CalendarBooking) => void;
  onMore: (b: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  const desk = useDeskWords();
  const dates = useDateDisplay({ timeZone });
  return (
    <Card>
      <ul className="divide-y divide-divider" data-testid="desk-list" aria-label={t('desk.view.list', 'List')}>
        {bookings.map((b) => {
          const status = b.desk_status ?? 'expected';
          const next = NEXT[status] ?? null;
          return (
            <li
              key={b.id}
              className={`flex min-h-12 items-center gap-3 px-4 py-2 ${selected === b.id ? 'bg-primary-tint' : ''}`}
              data-testid={`desk-row-${b.id}`}
              onClick={() => onSelect(b.id)}
              aria-current={selected === b.id ? 'true' : undefined}
            >
              <span dir="ltr" className={`w-14 shrink-0 tabular-nums ${phone ? 'text-base' : 'text-sm'} font-medium text-foreground`}>
                {dates.time(b.starts_at) ?? '—'}
              </span>
              {phone ? (
                // On a phone the name opens everything else, in a sheet (AP.30).
                <button
                  type="button"
                  className="min-w-0 flex-1 rounded-control text-start outline-none focus-visible:outline-2 focus-visible:outline-ring"
                  onClick={(e) => {
                    e.stopPropagation();
                    onMore(b);
                  }}
                  aria-label={t('desk.moreFor', 'More for {name}', { name: b.customer.name ?? '' })}
                  data-testid={`desk-more-${b.id}`}
                >
                  <span className="block truncate text-base font-medium text-foreground">{b.customer.name || t('booking.noName', 'No name given')}</span>
                  <Badge variant={desk.variant(status)}>{desk.status(status)}</Badge>
                </button>
              ) : (
                <div className="min-w-0 flex-1">
                  <p className="m-0 truncate text-sm font-medium text-foreground">{b.customer.name || t('booking.noName', 'No name given')}</p>
                  <p className="m-0 text-sm text-muted-foreground">
                    <BookingLine booking={b} timeZone={timeZone} />
                  </p>
                </div>
              )}
              {!phone && <Badge variant={desk.variant(status)}>{desk.status(status)}</Badge>}
              {phone ? (
                next?.to === 'arrived' && (
                  <Button variant="outline" loading={busy === b.id} onClick={(e) => { e.stopPropagation(); void onStep(b, 'arrived'); }} data-testid={`desk-step-${b.id}`}>
                    {desk.step('arrived')}
                  </Button>
                )
              ) : (
                <div onClick={(e) => e.stopPropagation()}>
                  <BookingActions booking={b} busy={busy === b.id} nowMs={nowMs} onStep={onStep} onLate={onLate} onOpen={onOpen} />
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </Card>
  );
}

function DeskColumns({
  bookings,
  staff,
  rooms,
  by,
  timeZone,
  selected,
  onSelect,
  onStaffLate,
}: {
  bookings: CalendarBooking[];
  staff: CalendarStaff[];
  rooms: { id: number; name: Record<string, string> }[];
  by: 'people' | 'rooms';
  timeZone: string;
  selected: number | null;
  onSelect: (id: number) => void;
  onStaffLate: (person: CalendarStaff) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const desk = useDeskWords();
  const dates = useDateDisplay({ timeZone });
  const minuteOf = useMinuteOfDay(timeZone);
  const nowMs = useMinuteClock();
  const nowMinute = minuteOf(new Date(nowMs).toISOString());

  const columns =
    by === 'people'
      ? staff
          .filter((p) => bookings.some((b) => b.profile_id === p.profile_id))
          .map((p) => ({ key: p.profile_id, label: p.display_name, person: p as CalendarStaff | null }))
      : rooms
          .filter((r) => bookings.some((b) => b.room_id === r.id))
          .map((r) => ({ key: r.id, label: words.label(r.name), person: null as CalendarStaff | null }));
  const inColumn = (b: CalendarBooking, key: number) => (by === 'people' ? b.profile_id === key : b.room_id === key);

  const starts = bookings.map((b) => minuteOf(b.starts_at) ?? 0);
  const ends = bookings.map((b) => minuteOf(b.ends_at) ?? 0);
  const first = Math.min(8 * 60, Math.floor(Math.min(...starts) / 60) * 60);
  const last = Math.max(18 * 60, Math.ceil(Math.max(...ends) / 60) * 60);
  const px = (minute: number) => ((minute - first) / 60) * HOUR_PX;
  const hours = Array.from({ length: (last - first) / 60 }, (_, i) => first / 60 + i);

  if (columns.length === 0) {
    return (
      <Card>
        <CardContent className="py-8 text-center text-sm text-muted-foreground">
          {t('desk.noRooms', 'No booking today holds a room or equipment.')}
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="overflow-x-auto" data-testid="desk-columns">
      <div className="min-w-fit">
        <div className="sticky top-0 z-10 grid border-b border-border bg-card" style={{ gridTemplateColumns: `3.5rem repeat(${columns.length}, minmax(11rem, 1fr))` }}>
          <span />
          {columns.map((c) => (
            <div key={c.key} className="flex min-h-12 items-center justify-between gap-2 border-s border-border px-3 py-2">
              <span className="truncate text-sm font-medium text-foreground">{c.label}</span>
              {c.person && (
                <Button variant="outline" size="sm" onClick={() => c.person && onStaffLate(c.person)} data-testid={`desk-staff-late-${c.key}`}>
                  {t('desk.late', 'Running late')}
                </Button>
              )}
            </div>
          ))}
        </div>
        <div className="relative grid" style={{ gridTemplateColumns: `3.5rem repeat(${columns.length}, minmax(11rem, 1fr))`, height: px(last) }}>
          <div className="relative">
            {hours.map((h) => (
              <span key={h} dir="ltr" className="absolute end-2 text-xs tabular-nums text-muted-foreground" style={{ top: px(h * 60) }}>
                {String(h).padStart(2, '0')}:00
              </span>
            ))}
          </div>
          {columns.map((c) => (
            <div key={c.key} className="relative border-s border-border">
              {hours.map((h) => (
                <span key={h} className="absolute inset-x-0 border-t border-divider" style={{ top: px(h * 60) }} aria-hidden />
              ))}
              {bookings
                .filter((b) => inColumn(b, c.key))
                .map((b) => {
                  const top = px(minuteOf(b.starts_at) ?? first);
                  const height = Math.max(px(minuteOf(b.ends_at) ?? first) - top, 24);
                  const status = b.desk_status ?? 'expected';
                  return (
                    <button
                      key={b.id}
                      type="button"
                      onClick={() => onSelect(b.id)}
                      aria-pressed={selected === b.id}
                      data-testid={`desk-card-${b.id}`}
                      className={`absolute inset-x-1 overflow-hidden rounded-control border px-2 py-0.5 text-start text-xs outline-none focus-visible:outline-2 focus-visible:outline-ring ${
                        selected === b.id ? 'border-primary bg-primary-tint' : 'border-border bg-card'
                      }`}
                      style={{ top, height }}
                    >
                      <span className="flex min-w-0 items-center gap-1">
                        <span dir="ltr" className="shrink-0 tabular-nums font-medium text-foreground">{dates.time(b.starts_at) ?? ''}</span>
                        <span className="min-w-0 flex-1 truncate font-medium text-foreground">{b.customer.name}</span>
                        <Badge variant={desk.variant(status)} className="shrink-0">{desk.status(status)}</Badge>
                        {b.late_minutes ? <Badge variant="warning" className="shrink-0">{t('desk.lateShort', '+{minutes} min', { minutes: b.late_minutes })}</Badge> : null}
                      </span>
                    </button>
                  );
                })}
            </div>
          ))}
          {nowMinute !== null && nowMinute >= first && nowMinute <= last && (
            <span className="pointer-events-none absolute inset-x-0 border-t-2 border-destructive" style={{ top: px(nowMinute) }} data-testid="desk-now" aria-hidden />
          )}
        </div>
      </div>
    </Card>
  );
}

/** Arrived and walk-ins, in arrival order, with minutes waited. */
function WaitingNow({
  bookings,
  walkins,
  timeZone,
  nowMs,
  onSelect,
}: {
  bookings: CalendarBooking[];
  walkins: CalendarWalkin[];
  timeZone: string;
  nowMs: number;
  onSelect: (id: number) => void;
}) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const waiting = [
    ...bookings
      .filter((b) => b.desk_status === 'arrived' && b.checked_in_at)
      .map((b) => ({ key: `b${b.id}`, id: b.id, name: b.customer.name ?? '', since: b.checked_in_at as string, walkIn: false, at: b.starts_at })),
    ...walkins
      .filter((w) => (w.status ?? 'waiting') === 'waiting' && w.added_at)
      .map((w) => ({ key: `w${w.id}`, id: null, name: w.name, since: w.added_at as string, walkIn: true, at: null })),
  ].sort((a, b) => a.since.localeCompare(b.since));

  return (
    <Card data-testid="desk-waiting">
      <CardHeader>
        <CardTitle>{t('desk.waiting', 'Waiting now')}</CardTitle>
      </CardHeader>
      <CardContent>
        {waiting.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('desk.waitingNone', 'Nobody is waiting.')}</p>
        ) : (
          <ul className="grid gap-3">
            {waiting.map((w) => (
              <li key={w.key} className="flex items-center gap-3">
                <Avatar name={w.name} size={32} />
                <button type="button" className="min-w-0 flex-1 text-start" onClick={() => w.id !== null && onSelect(w.id)} disabled={w.id === null}>
                  <span className="block truncate text-sm font-medium text-foreground">{w.name}</span>
                  <span className="block text-sm text-muted-foreground">
                    {w.walkIn
                      ? t('desk.walkIn', 'Walk-in')
                      : t('desk.bookedFor', 'Booked for {time}', { time: dates.time(w.at) ?? '—' })}
                    {' · '}
                    {t('desk.waited', 'Waiting {minutes} min', { minutes: Math.max(0, Math.round((nowMs - new Date(w.since).getTime()) / 60000)) })}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

/** The customer is running late: 5, 10, 15 or another number of minutes. Nothing moves. */
function CustomerLateDialog({ booking, onClose, onSaved }: { booking: CalendarBooking | null; onClose: () => void; onSaved: (b: CalendarBooking) => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const [other, setOther] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const save = async (minutes: number | null) => {
    if (booking === null) return;
    setSaving(true);
    setProblem(null);
    try {
      onSaved(await calendarApi.markLate(booking.id, minutes));
      setOther('');
    } catch (error) {
      setProblem(words.errorText(error, t('desk.error.step', 'That didn’t work. Try again.')));
    } finally {
      setSaving(false);
    }
  };
  return (
    <Dialog open={booking !== null} onOpenChange={(open) => !open && onClose()}>
      {booking && (
        <DialogContent data-testid="desk-late-dialog">
          <DialogHeader>
            <DialogTitle>{t('desk.lateTitle', 'How late will {name} be?', { name: booking.customer.name ?? '' })}</DialogTitle>
            <DialogDescription>{t('desk.lateBody', 'It shows on the booking. Nothing moves, and they can still be checked in when they arrive.')}</DialogDescription>
          </DialogHeader>
          <div className="flex flex-wrap gap-2">
            {LATE_CHOICES.map((m) => (
              <Button key={m} variant="outline" disabled={saving} onClick={() => void save(m)} data-testid={`desk-late-${m}`}>
                {t('desk.minutes', '{minutes} min', { minutes: m })}
              </Button>
            ))}
          </div>
          <div className="flex items-end gap-2">
            <Input
              id="desk-late-other"
              type="number"
              min={1}
              max={240}
              label={t('desk.lateOther', 'Another number of minutes')}
              value={other}
              onChange={(e) => setOther(e.target.value)}
            />
            <Button variant="outline" disabled={saving || !(Number(other) >= 1 && Number(other) <= 240)} onClick={() => void save(Math.round(Number(other)))}>
              {t('desk.lateSave', 'Save')}
            </Button>
          </div>
          {problem && <p className="text-sm text-error-text" role="alert">{problem}</p>}
          <DialogFooter>
            {booking.late_minutes ? (
              <Button variant="ghost" disabled={saving} onClick={() => void save(null)}>
                {t('desk.lateClear', 'They’re on time after all')}
              </Button>
            ) : null}
            <Button variant="outline" onClick={onClose}>
              {t('action.cancel', 'Cancel')}
            </Button>
          </DialogFooter>
        </DialogContent>
      )}
    </Dialog>
  );
}

/**
 * A staff member is behind (AP.29): the rest of their day pushed back, what
 * can't move marked "needs a decision", and Move and tell them, which moves
 * only on that click and tells each customer the new time.
 */
function StaffLateDialog({ person, timeZone, onClose, onDone }: { person: CalendarStaff | null; timeZone: string; onClose: () => void; onDone: () => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const { addToast } = useToast();
  const [minutes, setMinutes] = useState(15);
  const [plan, setPlan] = useState<CalendarLatePlanStep[] | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const profileId = person?.profile_id ?? null;

  useEffect(() => {
    if (profileId === null) return;
    let cancelled = false;
    calendarApi
      .staffLatePlan(profileId, minutes)
      .then((steps) => {
        if (!cancelled) {
          setPlan(steps);
          setProblem(null);
        }
      })
      .catch((error) => !cancelled && setProblem(words.errorText(error, t('desk.error.plan', 'The new times could not be worked out.'))));
    return () => {
      cancelled = true;
    };
  }, [profileId, minutes, t, words]);

  const why = (reason: string | null | undefined) =>
    reason === 'closed'
      ? t('desk.why.closed', 'Needs a decision: that is outside their hours')
      : reason === 'room_taken'
        ? t('desk.why.room', 'Needs a decision: the room or equipment is taken then')
        : t('desk.why.busy', 'Needs a decision: it would run into something else');
  const movable = (plan ?? []).filter((s) => s.to !== null);

  const apply = async () => {
    if (profileId === null) return;
    setSaving(true);
    try {
      const done = await calendarApi.staffLateApply(profileId, minutes, movable.map((s) => s.booking.id));
      const moved = done.filter((r) => r.moved).length;
      const left = done.length - moved;
      addToast(
        left > 0
          ? t('desk.movedSome', 'Moved {moved} and told the customers. {left} still need a decision.', { moved, left })
          : t('desk.movedAll', 'Moved {moved} and told the customers.', { moved }),
        left > 0 ? 'warning' : 'success'
      );
      setPlan(null);
      onDone();
    } catch (error) {
      setProblem(words.errorText(error, t('desk.error.move', 'The bookings could not be moved. Nothing changed.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog
      open={person !== null}
      onOpenChange={(open) => {
        if (!open) {
          setPlan(null);
          onClose();
        }
      }}
    >
      {person && (
        <DialogContent className="max-w-lg" data-testid="desk-staff-late-dialog">
          <DialogHeader>
            <DialogTitle>{t('desk.staffLateTitle', '{name} is running late', { name: person.display_name })}</DialogTitle>
            <DialogDescription>
              {t('desk.staffLateBody', 'Their bookings for the rest of today, pushed back. Nothing moves until you press Move and tell them.')}
            </DialogDescription>
          </DialogHeader>
          <ToggleGroup
            label={t('desk.staffLateBy', 'Behind by')}
            required
            value={String(minutes)}
            onChange={(value) => typeof value === 'string' && setMinutes(Number(value))}
            options={STAFF_LATE_CHOICES.map((m) => ({ value: String(m), label: t('desk.minutes', '{minutes} min', { minutes: m }) }))}
          />
          {plan === null ? (
            <Skeleton className="h-24 w-full" />
          ) : plan.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t('desk.staffLateNone', 'They have no more bookings today.')}</p>
          ) : (
            <ul className="divide-y divide-divider" data-testid="desk-staff-late-plan">
              {plan.map((s) => (
                <li key={s.booking.id} className="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                  <span className="min-w-0 flex-1 truncate font-medium text-foreground">{s.booking.customer.name}</span>
                  {s.to ? (
                    <span dir="ltr" className="tabular-nums text-foreground">
                      {dates.time(s.booking.starts_at) ?? '—'} → {dates.time(s.to) ?? '—'}
                    </span>
                  ) : (
                    <span className="text-warning-text">{why(s.reason)}</span>
                  )}
                </li>
              ))}
            </ul>
          )}
          {problem && <p className="text-sm text-error-text" role="alert">{problem}</p>}
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button onClick={() => void apply()} loading={saving} disabled={movable.length === 0} data-testid="desk-staff-late-apply">
              {t('desk.moveAndTell', 'Move and tell them')}
            </Button>
          </DialogFooter>
        </DialogContent>
      )}
    </Dialog>
  );
}

/** On a phone: everything but Check in, in a sheet (AP.30). */
function DeskActionsSheet({
  booking,
  timeZone,
  busy,
  nowMs,
  onClose,
  onStep,
  onLate,
  onOpen,
}: {
  booking: CalendarBooking | null;
  timeZone: string;
  busy: boolean;
  nowMs: number;
  onClose: () => void;
  onStep: (b: CalendarBooking, to: DeskStatus | 'no_show') => Promise<void>;
  onLate: (b: CalendarBooking) => void;
  onOpen: (b: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  return (
    <Sheet open={booking !== null} onOpenChange={(open) => !open && onClose()}>
      {booking && (
        <SheetContent side="bottom" className="gap-4 text-base" data-testid="desk-actions-sheet">
          <SheetHeader>
            <SheetTitle>{booking.customer.name || t('booking.noName', 'No name given')}</SheetTitle>
            <SheetDescription>
              <BookingLine booking={booking} timeZone={timeZone} />
            </SheetDescription>
          </SheetHeader>
          <div className="px-4 pb-4">
            <BookingActions booking={booking} busy={busy} nowMs={nowMs} onStep={onStep} onLate={onLate} onOpen={onOpen} />
          </div>
        </SheetContent>
      )}
    </Sheet>
  );
}

/** Add walk-in: their name, the service, and optionally a phone and a note. */
function WalkinDialog({
  open,
  services,
  onClose,
  onAdded,
}: {
  open: boolean;
  services: { id: number; name: Record<string, string> }[];
  onClose: () => void;
  onAdded: (w: CalendarWalkin) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [service, setService] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const save = async () => {
    if (name.trim() === '' || service === '') {
      setProblem(t('desk.walkinMissing', 'Add their name and the service they want.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      onAdded(await calendarApi.addWalkin({ name: name.trim(), service_id: Number(service), ...(phone.trim() !== '' ? { phone: phone.trim() } : {}) }));
      setName('');
      setPhone('');
      setService('');
    } catch (error) {
      setProblem(words.errorText(error, t('desk.error.walkin', 'The walk-in could not be added. Try again.')));
    } finally {
      setSaving(false);
    }
  };
  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      {open && (
        <DialogContent data-testid="desk-walkin-dialog">
          <DialogHeader>
            <DialogTitle>{t('desk.addWalkin', 'Add walk-in')}</DialogTitle>
            <DialogDescription>{t('desk.walkinBody', 'They join Waiting now. Someone serves them when free.')}</DialogDescription>
          </DialogHeader>
          <Input id="desk-walkin-name" label={t('desk.walkinName', 'Their name')} value={name} onChange={(e) => setName(e.target.value)} />
          <Input id="desk-walkin-phone" dir="ltr" label={t('desk.walkinPhone', 'Phone (optional)')} value={phone} onChange={(e) => setPhone(e.target.value)} />
          <div className="space-y-1">
            <label htmlFor="desk-walkin-service" className="text-sm font-medium text-foreground">
              {t('desk.walkinService', 'Service')}
            </label>
            <FieldSelect
              id="desk-walkin-service"
              value={service}
              onChange={setService}
              placeholder={t('desk.walkinChoose', 'Choose a service')}
              options={services.map((s) => ({ value: String(s.id), label: words.label(s.name) }))}
            />
          </div>
          {problem && <p className="text-sm text-error-text" role="alert">{problem}</p>}
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>
              {t('action.cancel', 'Cancel')}
            </Button>
            <Button onClick={() => void save()} loading={saving} data-testid="desk-walkin-save">
              {t('desk.walkinSave', 'Add to waiting')}
            </Button>
          </DialogFooter>
        </DialogContent>
      )}
    </Dialog>
  );
}
