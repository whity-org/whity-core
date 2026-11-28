'use client';

/**
 * One booking (WHIT-944): who, what, when, with whom, and its state, with
 * Move and Cancel for a person who may book.
 *
 * MOVE picks a new free time for the same service and person (or anyone), the
 * same picker as New booking, and handles a 409 the same way: say so, clear the
 * choice, refresh the times.
 *
 * CANCEL cannot be undone through the API, so it is a dialog that names the
 * consequence (section 21: confirm by how undoable an action is), with an
 * optional reason that the customer will see.
 *
 * DESIGN 3.3. A customer's request has Accept (its one filled button) and
 * Suggest another time, which uses the Move picker and leaves the request
 * waiting for the customer. A confirmed booking has Check in, and Undo
 * check-in once the customer has arrived; both undo each other, so neither
 * asks first.
 *
 * WHIT-1427 (design section 39 §4, AP.3–AP.5): once a confirmed booking has
 * started, Didn't come marks it, and Mark as came undoes that; both change
 * back and forth, so neither asks. The facts say who marked it and when, and
 * the customer's flag ("Missed 2 of the last 5"), always in words. Cancel asks
 * whose cancel it is: the customer asked (early or late by the window) or we
 * cancelled (never counted).
 */

import { useEffect, useState } from 'react';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { FieldSelect } from '@/components/field-select';
import { ActorChip } from '@whity/ui/actor-chip';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Textarea } from '@whity/ui/textarea';
import { Icon } from '@whity/ui/icon';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import { formatMoney } from '@whity/ui/money/currency';
import { useTranslation } from '@whity/features/i18n';
import { toZonedIso, useDateDisplay } from '@whity/features/datetime';
import {
  calendarApi,
  isTaken,
  type CalendarBooking,
  type CalendarRoom,
  type CalendarService,
  type CalendarSlot,
  type CalendarStaff,
} from '@/lib/calendar/api';
import { dateInZone, type CalendarDate } from '@/lib/calendar/layout';
import { SlotPicker } from './slot-picker';
import { ServiceList, isSeveral, useServicesName } from './booking-services';
import { useCalendarWords } from './use-calendar-words';
import { IntakeSection, VisitRecordSection } from './visit-record';
import { BookingPlanSection } from './treatment-plans';
import { DepositSection } from './booking-deposit';

const ANYONE = 'anyone';
/** Moving a booking of several services: each keeps its own person. */
const KEEP = 'keep';

/** Money in normal units with the currency's decimals: JOD 12.500. */
export function bookingMoney(minor: number, currency: string): string {
  try {
    return formatMoney(minor, currency, 'en-GB');
  } catch {
    return `${currency} ${minor}`;
  }
}

export function statusVariant(status: CalendarBooking['status']): 'success' | 'warning' | 'outline' {
  if (status === 'confirmed') return 'success';
  if (status === 'pending_payment' || status === 'requested') return 'warning';
  return 'outline';
}

/** What just happened to a booking, so the screen can say it. */
export type BookingChange = 'moved' | 'cancelled' | 'accepted' | 'suggested' | 'checkedIn' | 'notCheckedIn' | 'cameMarked' | 'noShowMarked' | 'resourcesChanged' | 'depositRefunded';

export interface BookingSheetProps {
  booking: CalendarBooking | null;
  /** Open straight on Suggest another time (from the Requests card). */
  suggest?: boolean;
  /** Open straight on Move or Cancel (the details beside a booking, design 3.5 CV.6). */
  startWith?: 'move' | 'cancel' | null;
  onClose: () => void;
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  canChange: boolean;
  onChanged: (booking: CalendarBooking, what: BookingChange) => void;
}

export function BookingSheet({ booking, suggest = false, startWith = null, onClose, services, staff, timeZone, canChange, onChanged }: BookingSheetProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const [mode, setMode] = useState<'view' | 'move' | 'suggest'>('view');
  const [cancelling, setCancelling] = useState(false);
  const [acting, setActing] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const bookingId = booking?.id ?? null;

  // A different booking, or the Requests card's Suggest, starts the sheet
  // afresh (adjusting state while rendering, not in an effect).
  const opening = `${bookingId ?? ''}:${suggest ? 'suggest' : startWith ?? 'view'}`;
  const [opened, setOpened] = useState(opening);
  if (opened !== opening) {
    setOpened(opening);
    setMode(bookingId === null ? 'view' : suggest ? 'suggest' : startWith === 'move' ? 'move' : 'view');
    setCancelling(bookingId !== null && !suggest && startWith === 'cancel');
    setProblem(null);
  }

  const act = async (what: 'accepted' | 'checkedIn' | 'notCheckedIn' | 'cameMarked' | 'noShowMarked') => {
    if (booking === null) return;
    setActing(true);
    setProblem(null);
    try {
      const changed =
        what === 'accepted'
          ? await calendarApi.acceptBooking(booking.id)
          : what === 'cameMarked' || what === 'noShowMarked'
            ? await calendarApi.markAttendance(booking.id, what === 'cameMarked' ? 'attended' : 'no_show')
            : await calendarApi.checkIn(booking.id, what === 'checkedIn');
      onChanged(changed, what);
    } catch (error) {
      setProblem(words.errorText(error, t('booking.actError', 'That didn’t work. Try again.')));
    } finally {
      setActing(false);
    }
  };

  const close = () => {
    setMode('view');
    onClose();
  };

  const service = booking ? services.find((s) => s.id === booking.service_id) ?? null : null;
  const person = booking ? staff.find((s) => s.profile_id === booking.profile_id) ?? null : null;
  const open = booking !== null && booking.status !== 'cancelled' && booking.status !== 'expired';
  // WHIT-1427: from its start a confirmed booking can be marked; never in the future.
  const now = useMinuteClock();
  const started = booking !== null && booking.status === 'confirmed' && new Date(booking.starts_at).getTime() <= now;
  // WHIT-1173: a booking of several services is named by all of them.
  const servicesName = useServicesName();
  const what = booking && isSeveral(booking) ? servicesName(booking.items ?? []) : words.serviceName(service);

  return (
    <Sheet open={booking !== null} onOpenChange={(next) => (next ? undefined : close())}>
      <SheetContent className="w-full gap-5 text-sm sm:max-w-lg" data-testid="booking-sheet">
        {booking && (
          <>
            <SheetHeader className="pe-10">
              <SheetTitle className="text-lg font-semibold">{booking.customer.name ?? t('booking.noName', 'No name given')}</SheetTitle>
              <SheetDescription className="text-sm">
                {what}
                {' · '}
                {dates.dateTime(booking.starts_at) ?? '—'}
              </SheetDescription>
              <div>
                <Badge variant={statusVariant(booking.status)} data-testid="booking-status">
                  {words.status(booking.status)}
                </Badge>
              </div>
            </SheetHeader>

            {mode === 'view' ? (
              <>
                <BookingFacts
                  booking={booking}
                  service={service}
                  person={person}
                  timeZone={timeZone}
                  canChange={canChange && open}
                  onResourcesChanged={(changed) => onChanged(changed, 'resourcesChanged')}
                />
                {/* WHIT-1443 (AP.20, AP.21): the forms to fill, and the visit record. */}
                {/* WHIT-1445 (AP.26): the deposit and Refund. */}
                {booking.deposit && <DepositSection booking={booking} timeZone={timeZone} onChanged={(changed) => onChanged(changed, 'depositRefunded')} />}
                {/* WHIT-1444 (AP.22): the treatment plan this visit is part of. */}
                <BookingPlanSection key={`plan-${booking.id}`} booking={booking} canChange={canChange && open} />
                <IntakeSection booking={booking} canChange={canChange && open} />
                <VisitRecordSection key={booking.id} booking={booking} timeZone={timeZone} />
              </>
            ) : (
              <MovePanel
                booking={booking}
                service={service}
                services={services}
                staff={staff}
                timeZone={timeZone}
                suggest={mode === 'suggest'}
                onCancel={() => setMode('view')}
                onMoved={(moved) => {
                  const what = mode === 'suggest' ? 'suggested' : 'moved';
                  setMode('view');
                  onChanged(moved, what);
                }}
              />
            )}

            {problem && mode === 'view' && (
              <Alert variant="destructive" data-testid="booking-problem">
                <AlertDescription>{problem}</AlertDescription>
              </Alert>
            )}

            {mode === 'view' && canChange && open && (
              <SheetFooter className="flex-wrap gap-2">
                <Button variant="outline" onClick={() => setCancelling(true)} data-testid="booking-cancel">
                  {booking.status === 'requested' ? t('booking.decline', 'Decline') : t('booking.cancel.action', 'Cancel booking')}
                </Button>
                {booking.status === 'requested' ? (
                  <>
                    <Button variant="outline" onClick={() => setMode('suggest')} data-testid="booking-suggest">
                      {t('request.suggest', 'Suggest another time')}
                    </Button>
                    <Button onClick={() => void act('accepted')} loading={acting} data-testid="booking-accept">
                      {t('request.accept', 'Accept')}
                    </Button>
                  </>
                ) : (
                  <>
                    <Button variant="outline" onClick={() => setMode('move')} data-testid="booking-move">
                      {t('booking.move.action', 'Move')}
                    </Button>
                    {started && booking.attendance !== 'no_show' && !booking.checked_in_at && (
                      <Button variant="outline" onClick={() => void act('noShowMarked')} loading={acting} data-testid="booking-no-show">
                        {t('booking.noShow', 'Didn’t come')}
                      </Button>
                    )}
                    {booking.status === 'confirmed' &&
                      (booking.attendance === 'no_show' ? (
                        <Button variant="outline" onClick={() => void act('cameMarked')} loading={acting} data-testid="booking-came">
                          {t('booking.came', 'Mark as came')}
                        </Button>
                      ) : booking.checked_in_at ? (
                        <Button variant="outline" onClick={() => void act('notCheckedIn')} loading={acting} data-testid="booking-undo-check-in">
                          {t('booking.undoCheckIn', 'Undo check-in')}
                        </Button>
                      ) : (
                        <Button onClick={() => void act('checkedIn')} loading={acting} data-testid="booking-check-in">
                          {t('booking.checkIn', 'Check in')}
                        </Button>
                      ))}
                  </>
                )}
              </SheetFooter>
            )}

            <CancelDialog
              open={cancelling}
              booking={booking}
              serviceName={what}
              when={dates.dateTime(booking.starts_at) ?? '—'}
              onOpenChange={setCancelling}
              onCancelled={(cancelled) => {
                setCancelling(false);
                onChanged(cancelled, 'cancelled');
              }}
            />
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}

/** "Missed 2 of the last 5", in words, never by colour alone (WHIT-1427, AP.8). */
export function CustomerFlag({ flag }: { flag: NonNullable<CalendarBooking['customer_flag']> }) {
  const t = useTranslation('calendar');
  return (
    <span className="inline-flex items-center gap-1 text-warning-text" data-testid="customer-flag">
      <Icon name="alert" size={14} aria-hidden />
      {t('flag.missed', 'Missed {misses} of the last {of}', { misses: flag.misses, of: flag.of })}
    </span>
  );
}

/** The time now, to the minute, kept current while the sheet is open. */
function useMinuteClock(): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(id);
  }, []);
  return now;
}

type ReminderLog = NonNullable<CalendarBooking['reminders']>;

/** The booking's reminders, read once for the booking shown; empty until they arrive or when none were sent. */
function useReminderLog(booking: CalendarBooking): ReminderLog {
  const [loaded, setLoaded] = useState<{ id: number; sent: string | null; log: ReminderLog } | null>(null);
  const sent = booking.reminder_sent_at ?? null;
  useEffect(() => {
    if (booking.reminders !== undefined || sent === null) return;
    let cancelled = false;
    calendarApi
      .booking(booking.id)
      .then((full) => {
        if (!cancelled) setLoaded({ id: booking.id, sent, log: full.reminders ?? [] });
      })
      .catch(() => {
        // The line is extra: without it the booking still shows.
      });
    return () => {
      cancelled = true;
    };
  }, [booking.id, booking.reminders, sent]);
  if (booking.reminders !== undefined) return booking.reminders;
  return loaded !== null && loaded.id === booking.id && loaded.sent === sent ? loaded.log : [];
}

/**
 * What a booking holds (WHIT-1439, AP.18): the one booking with its
 * resources and the workspace's rooms and equipment, read once for the
 * booking shown, since the calendar's list carries neither.
 */
function useHeld(booking: CalendarBooking): { full: CalendarBooking | null; rooms: CalendarRoom[] } {
  const [loaded, setLoaded] = useState<{ id: number; full: CalendarBooking; rooms: CalendarRoom[] } | null>(null);
  const roomId = booking.room_id ?? null;
  useEffect(() => {
    if (roomId === null) return;
    let cancelled = false;
    Promise.all([calendarApi.booking(booking.id), calendarApi.rooms()])
      .then(([full, rooms]) => {
        if (!cancelled) setLoaded({ id: booking.id, full, rooms });
      })
      .catch(() => {
        // The facts still show the room by name.
      });
    return () => {
      cancelled = true;
    };
  }, [booking.id, roomId]);
  return loaded !== null && loaded.id === booking.id ? { full: loaded.full, rooms: loaded.rooms } : { full: null, rooms: [] };
}

function BookingFacts({
  booking,
  service,
  person,
  timeZone,
  canChange = false,
  onResourcesChanged,
}: {
  booking: CalendarBooking;
  service: CalendarService | null;
  person: CalendarStaff | null;
  timeZone: string;
  canChange?: boolean;
  onResourcesChanged?: (booking: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  // WHIT-1417: the reminders sent come only with the one booking, not the calendar's list.
  const reminders = useReminderLog(booking);
  const heldInfo = useHeld(booking);
  const [changingResource, setChangingResource] = useState(false);
  const [resourceProblem, setResourceProblem] = useState<string | null>(null);
  const changeResource = async (index: number, resourceId: number) => {
    const current = (heldInfo.full?.resources ?? []).filter((r) => r.part === 0).map((r) => r.resource_id);
    const next = current.map((id, i) => (i === index ? resourceId : id));
    setChangingResource(true);
    setResourceProblem(null);
    try {
      onResourcesChanged?.(await calendarApi.changeResources(booking.id, next));
    } catch (error) {
      setResourceProblem(words.errorText(error, t('booking.resources.error', 'That one is taken then. Choose another.')));
    } finally {
      setChangingResource(false);
    }
  };

  const rows: { label: string; value: React.ReactNode; testId?: string }[] = [];
  if (!dates.hidden) {
    rows.push({
      label: t('booking.fact.when', 'When'),
      value: (
        <span>
          {dates.calendarLabel(booking.starts_at, 'fullDate') ?? '—'}
          <br />
          <span dir="ltr" className="tabular-nums">
            {dates.time(booking.starts_at) ?? '—'}–{dates.time(booking.ends_at) ?? '—'}
          </span>
        </span>
      ),
      testId: 'booking-when',
    });
  }
  if (isSeveral(booking)) {
    // WHIT-1173: each service with its time and who does it.
    rows.push({
      label: t('booking.fact.services', 'Services'),
      value: <ServiceList items={booking.items ?? []} timeZone={timeZone} />,
      testId: 'booking-services-fact',
    });
  } else {
    rows.push({
      label: t('booking.fact.with', 'With'),
      value: person ? <ActorChip name={person.display_name} /> : t('booking.fact.removedPerson', 'Someone no longer on the calendar'),
    });
    rows.push({ label: t('booking.fact.service', 'Service'), value: words.serviceName(service) });
  }
  const held = (heldInfo.full?.resources ?? []).filter((r) => r.part === 0);
  if (held.length > 0 && heldInfo.rooms.length > 0 && !isSeveral(booking)) {
    // WHIT-1439 (AP.18): each thing it holds; for a group, the desk can pick another.
    rows.push({
      label: t('booking.fact.resources', 'Room and equipment'),
      value: (
        <ul className="space-y-2" data-testid="booking-resources">
          {held.map((r, index) => {
            const room = heldInfo.rooms.find((x) => x.id === r.resource_id);
            const need = service?.resources?.[index];
            const group = need?.group_key ?? null;
            const choices = group ? heldInfo.rooms.filter((x) => x.group_key === group && (x.active || x.id === r.resource_id)) : [];
            return (
              <li key={`${r.resource_id}-${index}`}>
                {canChange && choices.length > 1 ? (
                  <>
                    <label htmlFor={`booking-resource-${index}`} className="sr-only">
                      {t('booking.resources.change', 'Change {name}', { name: room ? words.label(room.name) : '' })}
                    </label>
                    <FieldSelect
                      id={`booking-resource-${index}`}
                      value={String(r.resource_id)}
                      disabled={changingResource}
                      onChange={(value) => void changeResource(index, Number(value))}
                      options={choices.map((x) => ({ value: String(x.id), label: words.label(x.name) }))}
                    />
                  </>
                ) : (
                  <span>{room ? words.label(room.name) : t('room.unnamed', 'Unnamed room')}</span>
                )}
              </li>
            );
          })}
          {resourceProblem && <li className="text-destructive">{resourceProblem}</li>}
        </ul>
      ),
      testId: 'booking-resources-fact',
    });
  } else if (booking.room_name) {
    rows.push({ label: t('booking.fact.room', 'Room'), value: words.label(booking.room_name), testId: 'booking-room' });
  }
  if (booking.checked_in_at) {
    rows.push({
      label: t('booking.fact.checkedIn', 'Checked in'),
      value: <span dir="ltr" className="tabular-nums">{dates.time(booking.checked_in_at) ?? '—'}</span>,
      testId: 'booking-checked-in',
    });
  }
  if (booking.customer_flag) {
    rows.push({
      label: t('booking.fact.history', 'Before'),
      value: <CustomerFlag flag={booking.customer_flag} />,
      testId: 'booking-customer-flag',
    });
  }
  if (booking.status === 'confirmed' && booking.attendance) {
    const at = dates.time(booking.attendance_at ?? '') ?? '—';
    rows.push({
      label: t('booking.fact.attendance', 'Did they come'),
      value:
        booking.attendance === 'attended'
          ? t('booking.attendance.came', 'Came')
          : booking.attendance_auto
            ? t('booking.attendance.noShowAuto', 'Didn’t come (marked by Whity at the end of the day)')
            : t('booking.attendance.noShowAt', 'Didn’t come (marked at {time})', { time: at }),
      testId: 'booking-attendance',
    });
  }
  if (booking.status === 'cancelled' && booking.cancel_kind) {
    rows.push({
      label: t('booking.fact.cancelKind', 'Cancelled'),
      value:
        booking.cancel_kind === 'late'
          ? t('booking.cancelKind.late', 'Late, by or for the customer')
          : booking.cancel_kind === 'early'
            ? t('booking.cancelKind.early', 'In good time, by or for the customer')
            : t('booking.cancelKind.business', 'By us'),
      testId: 'booking-cancel-kind',
    });
  }
  if (booking.confirmed_by_customer_at) {
    rows.push({
      label: t('booking.fact.coming', 'Coming'),
      value: t('booking.fact.comingValue', 'The customer said they’re coming ({time})', { time: dates.time(booking.confirmed_by_customer_at) ?? '—' }),
      testId: 'booking-coming',
    });
  }
  if (reminders.length > 0) {
    // WHIT-1417 (AP.14): every reminder sent, newest first, one line per channel.
    const channel = (full: string) => {
      // A plugin's channel is "plugin:whatsapp"; the desk shows the channel.
      const key = full.includes(':') ? full.slice(full.indexOf(':') + 1) : full;
      return key === 'email' ? t('reminder.channel.email', 'email') : key === 'whatsapp' ? 'WhatsApp' : key === 'sms' ? 'SMS' : key;
    };
    rows.push({
      label: t('booking.fact.reminders', 'Reminders'),
      value: (
        <ul className="space-y-1">
          {[...reminders].reverse().map((r, i) => (
            <li key={i} className={r.outcome === 'sent' ? undefined : 'text-destructive'}>
              {r.outcome === 'sent'
                ? t('reminder.sent', 'Reminder sent {time} by {channel}', { time: dates.time(r.sent_at ?? '') ?? '—', channel: channel(r.channel) })
                : r.outcome === 'no_address'
                ? r.channel === 'email'
                  ? t('reminder.noEmail', 'Reminder failed: no email')
                  : t('reminder.noPhone', 'Reminder failed: no phone ({channel})', { channel: channel(r.channel) })
                : t('reminder.failed', 'Reminder failed on {channel} at {time}', { channel: channel(r.channel), time: dates.time(r.sent_at ?? '') ?? '—' })}
            </li>
          ))}
        </ul>
      ),
      testId: 'booking-reminders',
    });
  }
  if (booking.status === 'requested' && booking.suggested_at) {
    rows.push({ label: t('booking.fact.suggested', 'Suggested'), value: t('request.suggested', 'You suggested this time. Waiting for the customer.') });
  }
  if (booking.price_minor !== null && booking.price_minor !== undefined && booking.currency) {
    rows.push({
      label: t('booking.fact.price', 'Price'),
      value: (
        <span dir="ltr" className="tabular-nums">
          {bookingMoney(booking.price_minor, booking.currency)}
        </span>
      ),
    });
  }
  if (booking.customer.email) {
    rows.push({ label: t('booking.fact.email', 'Email'), value: <span dir="ltr">{booking.customer.email}</span> });
  }
  if (booking.customer.phone) {
    rows.push({ label: t('booking.fact.phone', 'Phone'), value: <span dir="ltr">{booking.customer.phone}</span> });
  }
  if (booking.customer.note) {
    rows.push({ label: t('booking.fact.note', 'Note'), value: booking.customer.note });
  }
  rows.push({ label: t('booking.fact.source', 'How it was made'), value: words.source(booking.source) });
  if (booking.status === 'cancelled' && booking.cancel_reason) {
    rows.push({ label: t('booking.fact.reason', 'Why it was cancelled'), value: booking.cancel_reason });
  }

  return (
    <dl className="divide-y divide-divider rounded-card border border-border">
      {rows.map((row) => (
        <div key={row.label} className="grid gap-1 px-4 py-3 sm:grid-cols-[9rem_1fr]" data-testid={row.testId}>
          <dt className="text-muted-foreground">{row.label}</dt>
          <dd className="text-foreground">{row.value}</dd>
        </div>
      ))}
    </dl>
  );
}

function MovePanel({
  booking,
  service,
  services,
  staff,
  timeZone,
  suggest,
  onCancel,
  onMoved,
}: {
  booking: CalendarBooking;
  service: CalendarService | null;
  /** Every service, to know who does all of a booking of several. */
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  /** Suggest the time to the customer instead of moving the booking. */
  suggest: boolean;
  onCancel: () => void;
  onMoved: (booking: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  // WHIT-1173: a booking of several services moves as one; each keeps its
  // person unless one person who does them all is chosen.
  const items = isSeveral(booking) ? booking.items ?? [] : [];
  const several = items.length > 1;
  const [person, setPerson] = useState<string>(several ? KEEP : String(booking.profile_id));
  const [date, setDate] = useState<CalendarDate>(dateInZone(new Date(booking.starts_at), timeZone));
  const [slot, setSlot] = useState<CalendarSlot | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const offers = (s: CalendarService | null | undefined, profile: number) =>
    s === null || s === undefined || s.staff_profile_ids.length === 0 || s.staff_profile_ids.includes(profile);
  const providers = several
    ? staff.filter((p) => items.every((item) => offers(services.find((s) => s.id === item.service_id), p.profile_id)))
    : service && service.staff_profile_ids.length > 0
      ? staff.filter((s) => service.staff_profile_ids.includes(s.profile_id))
      : staff;
  const profileId = person === ANYONE || person === KEEP ? null : Number(person);
  const choices = several
    ? items.map((item) => ({ service_id: item.service_id, profile_id: profileId ?? item.profile_id }))
    : null;
  // The booking's own time is left out of the choices: it is not a move.
  const currentStart = booking.starts_at;

  const save = async () => {
    if (slot === null) {
      setProblem(t('booking.field.timeMissing', 'Choose a free time.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      // With Anyone, take the first person free then; the server re-checks.
      // Several services keep their people unless one person was chosen.
      const target = several ? profileId ?? undefined : profileId ?? slot.profile_ids[0];
      const body = {
        starts_at: toZonedIso(new Date(slot.starts_at), timeZone),
        ...(target !== undefined && target !== booking.profile_id ? { profile_id: target } : {}),
      };
      const moved = suggest ? await calendarApi.suggestBooking(booking.id, body) : await calendarApi.moveBooking(booking.id, body);
      onMoved(moved);
    } catch (error) {
      if (isTaken(error)) {
        setSlot(null);
        setReloadKey((k) => k + 1);
      }
      setProblem(words.errorText(error, t('booking.move.error', 'The booking could not be moved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4" data-testid="booking-move-panel">
      <h3 className="text-base font-semibold text-foreground">
        {suggest ? t('request.suggestTitle', 'Suggest another time') : t('booking.move.title', 'Move to another time')}
      </h3>
      {suggest && (
        <p className="text-sm text-muted-foreground">
          {t('request.suggestHint', 'The customer gets an email with the new time and takes it, or picks another, from their link.')}
        </p>
      )}
      <div className="space-y-1">
        <label htmlFor="move-person" className="text-sm font-medium text-foreground">
          {t('booking.field.person', 'With')}
        </label>
        <FieldSelect
          id="move-person"
          value={person}
          onChange={(value) => {
            setPerson(value);
            setSlot(null);
          }}
          options={[
            several
              ? { value: KEEP, label: t('booking.field.keepPeople', 'Each with the same person') }
              : { value: ANYONE, label: t('booking.field.anyone', 'Anyone who is free') },
            ...providers.map((s) => ({ value: String(s.profile_id), label: s.display_name })),
          ]}
        />
      </div>
      <SlotPicker
        id="move"
        several={choices}
        exceptBookingId={several ? booking.id : null}
        serviceId={booking.service_id}
        profileId={profileId}
        date={date}
        onDateChange={setDate}
        timeZone={timeZone}
        selected={slot}
        onSelect={setSlot}
        reloadKey={reloadKey}
        exclude={currentStart}
      />
      {problem && (
        <Alert variant="destructive" data-testid="move-problem">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="outline" onClick={onCancel} disabled={saving}>
          {t('action.back', 'Back')}
        </Button>
        <Button onClick={() => void save()} loading={saving} data-testid="move-submit">
          {suggest ? t('request.suggestSubmit', 'Suggest this time') : t('booking.move.submit', 'Move booking')}
        </Button>
      </div>
    </div>
  );
}

function CancelDialog({
  open,
  booking,
  serviceName,
  when,
  onOpenChange,
  onCancelled,
}: {
  open: boolean;
  booking: CalendarBooking;
  serviceName: string;
  when: string;
  onOpenChange: (open: boolean) => void;
  onCancelled: (booking: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const [reason, setReason] = useState('');
  const [whose, setWhose] = useState<'customer' | 'business'>('customer');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  // WHIT-1427 (AP.5): only a confirmed booking's cancel is counted.
  const asks = booking.status === 'confirmed';

  const confirm = async () => {
    setSaving(true);
    setProblem(null);
    try {
      onCancelled(await calendarApi.cancelBooking(booking.id, reason.trim(), asks ? whose : undefined));
      setReason('');
    } catch (error) {
      setProblem(words.errorText(error, t('booking.cancel.error', 'The booking could not be cancelled. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-testid="booking-cancel-dialog">
        <DialogHeader>
          <DialogTitle>{t('booking.cancel.title', 'Cancel this booking?')}</DialogTitle>
          <DialogDescription>
            {t(
              'booking.cancel.description',
              '{customer}’s {service} on {when} will be cancelled and the time becomes free for others. This can’t be undone; to keep the customer, book them again.',
              { customer: booking.customer.name ?? '', service: serviceName, when }
            )}
          </DialogDescription>
        </DialogHeader>
        {asks && (
          <fieldset className="space-y-2" data-testid="booking-cancel-whose">
            <legend className="text-sm font-medium text-foreground">{t('booking.cancel.whose', 'Who is cancelling?')}</legend>
            <RadioGroup value={whose} onValueChange={(next) => setWhose(next === 'business' ? 'business' : 'customer')}>
              <label className="flex items-center gap-3">
                <RadioGroupItem value="customer" data-testid="booking-cancel-customer" />
                <span>{t('booking.cancel.customerAsked', 'The customer asked')}</span>
              </label>
              <label className="flex items-center gap-3">
                <RadioGroupItem value="business" data-testid="booking-cancel-business" />
                <span>{t('booking.cancel.weCancelled', 'We cancelled')}</span>
              </label>
            </RadioGroup>
            <p className="text-sm text-muted-foreground">
              {whose === 'customer'
                ? t('booking.cancel.customerHint', 'Counts as a cancel by the customer: late when it is close to the start.')
                : t('booking.cancel.businessHint', 'Never counted against the customer.')}
            </p>
          </fieldset>
        )}
        <div className="space-y-1">
          <label htmlFor="cancel-reason" className="text-sm font-medium text-foreground">
            {t('booking.cancel.reason', 'Reason (optional)')}
          </label>
          <Textarea id="cancel-reason" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          <p className="text-sm text-muted-foreground">{t('booking.cancel.reasonHint', 'The customer will see this.')}</p>
        </div>
        {problem && (
          <Alert variant="destructive">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t('booking.cancel.keep', 'Keep booking')}
          </Button>
          <Button variant="destructive" onClick={() => void confirm()} loading={saving} data-testid="booking-cancel-confirm">
            {t('booking.cancel.confirm', 'Cancel booking')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
