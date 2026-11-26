'use client';

/**
 * Creating and changing on the grid (design 3.5, CV.8–CV.10, `TplCalendarEditing`).
 *
 * - Click an empty time for a slot as long as the default service, or drag
 *   across the time you want; it stays dashed until Save. The quick booking
 *   beside it chooses Booking, Event or Time off; More options opens the full
 *   booking dialog.
 * - Drag a booking to move it, or use the arrow keys. A customer's booking
 *   asks first, with "Email the new time" ticked; unticking it says the
 *   customer's calendar won't update (owner, Q35.8). Team events move without
 *   asking, and only they can be pulled to a new length: a booking lasts as
 *   long as its service (owner, Q35.7). After any change, Undo for 10 seconds.
 * - A busy person says so and offers Book anyway. A taken room, closed hours
 *   or a time just taken (409) send it back and say why: nothing is saved.
 *
 * `useGridEditing` holds that flow for the computer's grid; the phone reuses
 * it with its own press-and-hold (CV.11).
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Checkbox } from '@whity/ui/checkbox';
import { Icon } from '@whity/ui/icon';
import { Input } from '@whity/ui/input';
import { ToggleGroup } from '@whity/ui/toggle-group';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@whity/ui/alert-dialog';
import { useTranslation } from '@whity/features/i18n';
import { toZonedIso, zonedInstant } from '@whity/features/datetime';
import { FieldSelect } from '@/components/field-select';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import {
  calendarApi,
  isTaken,
  takenReason,
  type CalendarBooking,
  type CalendarService,
  type CalendarStaff,
} from '@/lib/calendar/api';
import { isoWeekday, type CalendarDate } from '@/lib/calendar/layout';
import { minuteToTime } from '@/lib/calendar/times';
import type { CalItem, GridPreview, GridSlot } from './time-grid';
import { useCalendarWords } from './use-calendar-words';

/** How long Undo stays offered (CV.9). */
export const UNDO_MS = 10_000;
/** The slot a plain click makes when there is no service to size it (CV.8). */
const DEFAULT_MINUTES = 60;

export function isoAt(date: CalendarDate, minute: number, timeZone: string): string {
  return toZonedIso(zonedInstant(date, minute, timeZone), timeZone);
}

function firstName(name: string | null | undefined): string {
  return (name ?? '').trim().split(/\s+/)[0] ?? '';
}

/** "Wednesday 18 November, 12:30", the words the move messages use. */
function useWhen() {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const months = useMonthNames();
  // Without a minute: the day alone, "Thursday 19 November".
  return (date: CalendarDate, minute?: number) => {
    const parts = { weekday: words.weekday(isoWeekday(date)), day: date.day, month: months[date.month - 1] };
    return minute === undefined
      ? t('edit.day', '{weekday} {day} {month}', parts)
      : t('edit.when', '{weekday} {day} {month}, {time}', { ...parts, time: minuteToTime(minute) });
  };
}

function useMonthNames(): string[] {
  const t = useTranslation('calendar');
  return [
    t('month.jan', 'January'), t('month.feb', 'February'), t('month.mar', 'March'), t('month.apr', 'April'),
    t('month.may', 'May'), t('month.jun', 'June'), t('month.jul', 'July'), t('month.aug', 'August'),
    t('month.sep', 'September'), t('month.oct', 'October'), t('month.nov', 'November'), t('month.dec', 'December'),
  ];
}

// ---------------------------------------------------------------------------
// The quick booking beside a new slot (CV.8).
// ---------------------------------------------------------------------------

interface ContactHit {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
}

async function findContacts(text: string): Promise<ContactHit[]> {
  try {
    const { data, response } = await api.GET('/api/v1/contacts', { params: { query: { q: text, per_page: 5 } as never } });
    if (!response.ok) return [];
    const rows = ((data ?? {}) as { data?: { id: number; name: string; email?: string | null; mobile?: string | null; phone?: string | null }[] }).data ?? [];
    return rows.map((r) => ({ id: r.id, name: r.name, email: r.email ?? null, phone: r.mobile || r.phone || null }));
  } catch {
    // Contacts are a help, not a need: without them, type the details.
    return [];
  }
}

export interface Draft {
  date: CalendarDate;
  startMinute: number;
  endMinute: number;
  /** Dragged across: an event or time off keeps that length; a booking always takes its service's (Q35.7). */
  fixed: boolean;
  /** Where the slot ended before a service sized it. */
  dragEnd: number;
}

export interface QuickBookingProps {
  draft: Draft;
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  canEvent: boolean;
  me: number | null;
  /** A booking's service sets the slot's length; null gives an event or time off back the length dragged. */
  onServiceLength: (minutes: number | null) => void;
  onClose: () => void;
  /** WHIT-1440 (AP.17): time off also gives the bookings it covers. */
  onSaved: (message: string, covered?: CalendarBooking[]) => void;
  onMore: (date: CalendarDate, profileId: number | null) => void;
  /** Inside the phone's sheet: full width, no card of its own. */
  inSheet?: boolean;
}

export function QuickBooking({ draft, services, staff, timeZone, canEvent, me, onServiceLength, onClose, onSaved, onMore, inSheet = false }: QuickBookingProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const when = useWhen();
  const bookable = useMemo(() => services.filter((s) => s.active), [services]);
  const [kind, setKind] = useState<'booking' | 'event' | 'timeoff'>(bookable.length > 0 ? 'booking' : 'event');
  const [serviceId, setServiceId] = useState(bookable[0] ? String(bookable[0].id) : '');
  const service = bookable.find((s) => String(s.id) === serviceId) ?? null;
  const providers = service && service.staff_profile_ids.length > 0 ? staff.filter((s) => service.staff_profile_ids.includes(s.profile_id)) : staff;
  const [person, setPerson] = useState<string>(() => {
    const own = staff.find((s) => s.profile_id === me);
    return String((own ?? providers[0] ?? staff[0])?.profile_id ?? '');
  });
  const chosen = providers.some((p) => String(p.profile_id) === person) ? person : String(providers[0]?.profile_id ?? person);
  const [customer, setCustomer] = useState('');
  const [contact, setContact] = useState('');
  const [picked, setPicked] = useState<ContactHit | null>(null);
  const [hits, setHits] = useState<ContactHit[]>([]);
  const [notify, setNotify] = useState(true);
  const [title, setTitle] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    onServiceLength(service !== null && kind === 'booking' ? service.duration_minutes : null);
  }, [service, kind, onServiceLength]);

  const searching = picked === null && customer.trim().length >= 2;
  useEffect(() => {
    if (!searching) return;
    const id = window.setTimeout(() => {
      void findContacts(customer.trim()).then(setHits);
    }, 250);
    return () => window.clearTimeout(id);
  }, [customer, searching]);
  const shownHits = searching ? hits : [];

  const email = picked?.email ?? (contact.includes('@') ? contact.trim() : '');
  const phone = picked?.phone ?? (!contact.includes('@') ? contact.trim() : '');
  const starts = isoAt(draft.date, draft.startMinute, timeZone);
  const ends = isoAt(draft.date, draft.endMinute, timeZone);

  const save = async (anyway = false) => {
    setProblem(null);
    if (kind === 'booking') {
      if (service === null) return setProblem(t('quick.needService', 'Choose a service.'));
      if (customer.trim() === '') return setProblem(t('booking.field.nameMissing', 'Enter the customer’s name.'));
      if (email === '' && phone === '') return setProblem(t('booking.field.contactMissing', 'Enter an email or a phone number.'));
    }
    if (kind === 'event' && title.trim() === '') return setProblem(t('quick.needTitle', 'Give the event a title.'));
    setSaving(true);
    try {
      if (kind === 'booking' && service !== null) {
        const made = await calendarApi.createBooking({
          service_id: service.id,
          profile_id: Number(chosen),
          starts_at: starts,
          customer: { name: customer.trim(), ...(email !== '' ? { email } : {}), ...(phone !== '' ? { phone } : {}) },
          notify: notify && email !== '',
          within_hours: true,
          ...(anyway ? { book_anyway: true } : {}),
        });
        onSaved(t('booking.made', 'Booked for {name}', { name: made.customer.name ?? '' }));
      } else if (kind === 'event') {
        await calendarApi.createEvent({ profile_id: Number(chosen), title: title.trim(), starts_at: starts, ends_at: ends, busy: true, all_day: false });
        onSaved(t('quick.eventAdded', 'Event added'));
      } else {
        const added = await calendarApi.addTimeOff({ profile_id: Number(chosen), kind: 'off', starts_at: starts, ends_at: ends, note: '' });
        onSaved(t('quick.timeOffAdded', 'Time off added'), added.covered_bookings);
      }
    } catch (error) {
      const reason = takenReason(error);
      if (reason === 'person_busy') setBusy(true);
      else if (reason === 'room_taken') setProblem(t('edit.roomTaken', 'The room is taken then. Pick another time; nothing was saved.'));
      else if (reason === 'closed') setProblem(t('edit.closedCreate', 'That is outside their working hours. Pick another time, or use More options.'));
      else if (isTaken(error)) setProblem(t('error.taken', 'That time was just taken. Pick another time.'));
      else setProblem(words.errorText(error, t('quick.error', 'That could not be saved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  const personName = staff.find((s) => String(s.profile_id) === chosen)?.display_name ?? '';
  const personOptions = (kind === 'booking' ? providers : staff).map((s) => ({ value: String(s.profile_id), label: s.display_name }));

  return (
    <div
      role="dialog"
      aria-label={t('toolbar.newBooking', 'New booking')}
      className={inSheet ? 'grid gap-3 text-base text-foreground' : 'grid w-[360px] gap-3 rounded-[14px] border border-border bg-card p-4 text-sm text-foreground shadow-float'}
      data-testid="calendar-quick"
      onPointerDown={(event) => event.stopPropagation()}
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          event.stopPropagation();
          onClose();
        }
      }}
    >
      <ToggleGroup
        label={t('quick.what', 'What')}
        required
        value={kind}
        onChange={(value) => {
          if (value === 'booking' || value === 'event' || value === 'timeoff') setKind(value);
        }}
        options={[
          ...(bookable.length > 0 ? [{ value: 'booking', label: t('quick.booking', 'Booking') }] : []),
          ...(canEvent ? [{ value: 'event', label: t('quick.event', 'Event') }, { value: 'timeoff', label: t('panel.timeOff', 'Time off') }] : []),
        ]}
      />
      {kind === 'booking' && (
        <div className="grid gap-1">
          <label htmlFor="quick-service" className="text-sm font-medium">{t('booking.field.service', 'Service')}</label>
          <FieldSelect
            id="quick-service"
            value={serviceId}
            onChange={setServiceId}
            options={bookable.map((s) => ({ value: String(s.id), label: t('quick.serviceLength', '{service} · {length}', { service: words.serviceName(s), length: words.minutes(s.duration_minutes) }) }))}
          />
        </div>
      )}
      {kind === 'event' && (
        <Input id="quick-title" label={t('quick.title', 'Title')} value={title} onChange={(e) => setTitle(e.target.value)} autoFocus />
      )}
      <p className="m-0 flex items-center gap-2" data-testid="calendar-quick-when">
        <Icon name="clock" />
        <span>
          {when(draft.date)} · <bdi dir="ltr">{minuteToTime(draft.startMinute)} – {minuteToTime(draft.endMinute)}</bdi>
        </span>
      </p>
      {kind === 'booking' && (
        <div className="relative grid gap-2">
          <Input
            id="quick-customer"
            label={t('booking.field.customer', 'Customer')}
            placeholder={t('quick.customerPlaceholder', 'Search or add a customer')}
            value={customer}
            onChange={(e) => {
              setCustomer(e.target.value);
              setPicked(null);
            }}
            autoComplete="off"
            autoFocus
          />
          {shownHits.length > 0 && (
            <ul className="absolute top-full z-10 m-0 mt-1 w-full list-none rounded-control border border-border bg-card p-1 shadow-float" role="listbox">
              {shownHits.map((hit) => (
                <li key={hit.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={false}
                    className="w-full rounded px-2 py-1.5 text-start hover:bg-muted"
                    onClick={() => {
                      setPicked(hit);
                      setCustomer(hit.name);
                      setHits([]);
                    }}
                  >
                    {hit.name}
                    {(hit.email || hit.phone) && <small className="block text-xs text-muted-foreground" dir="ltr">{hit.email ?? hit.phone}</small>}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {picked === null && (
            <Input
              id="quick-contact"
              dir="ltr"
              label={t('quick.contact', 'Email or phone')}
              value={contact}
              onChange={(e) => setContact(e.target.value)}
              autoComplete="off"
            />
          )}
        </div>
      )}
      <div className="grid gap-1">
        <label htmlFor="quick-with" className="text-sm font-medium">{t('quick.with', 'With')}</label>
        <FieldSelect id="quick-with" value={chosen} onChange={setPerson} options={personOptions} />
      </div>
      {kind === 'booking' && (
        <label className="flex items-center gap-2">
          <Checkbox checked={notify && email !== ''} disabled={email === ''} onCheckedChange={(value) => setNotify(value === true)} />
          {t('quick.notify', 'Email the customer a confirmation')}
        </label>
      )}
      {busy && (
        <div className="rounded-control border border-warning bg-warning-tint p-3 text-warning-text" role="alert" data-testid="calendar-quick-busy">
          <b className="block font-semibold">{t('edit.busyTitle', '{name} is busy then', { name: personName })}</b>
          <span>{t('edit.busyBody', 'Pick another time, or book anyway.')}</span>
          <div className="mt-2 flex gap-2">
            <Button size="sm" variant="outline" onClick={() => setBusy(false)}>
              {t('edit.pickAnother', 'Pick another time')}
            </Button>
            <Button size="sm" variant="outline" onClick={() => void save(true)} data-testid="calendar-quick-anyway">
              {t('edit.bookAnyway', 'Book anyway')}
            </Button>
          </div>
        </div>
      )}
      {problem !== null && (
        <p className="m-0 text-error-text" role="alert">
          {problem}
        </p>
      )}
      <div className="flex items-center gap-2">
        {kind === 'booking' && (
          <Button variant="ghost" onClick={() => onMore(draft.date, Number(chosen) || null)}>
            {t('quick.more', 'More options')}
          </Button>
        )}
        <span className="flex-1" />
        <Button variant="ghost" onClick={onClose}>
          {t('action.cancel', 'Cancel')}
        </Button>
        <Button onClick={() => void save()} loading={saving} data-testid="calendar-quick-save">
          {t('quick.save', 'Save')}
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Changing what is already on the grid (CV.9, CV.10).
// ---------------------------------------------------------------------------

interface Change {
  item: CalItem;
  to: GridSlot & { endMinute: number };
  how: 'move' | 'resize';
  from: { date: CalendarDate; startMinute: number; endMinute: number };
}

export interface GridEditingOptions {
  items: CalItem[];
  days: CalendarDate[];
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  canWrite: boolean;
  canChange: (profileId: number) => boolean;
  /** The day an item starts on and its minutes, as the grid placed it. */
  placeOf: (item: CalItem) => { date: CalendarDate; startMinute: number; endMinute: number } | null;
  onChanged: () => void;
}

export function useGridEditing({ services, staff, timeZone, canWrite, canChange, placeOf, onChanged }: GridEditingOptions) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const when = useWhen();
  const { addToast } = useToast();
  const [draft, setDraft] = useState<Draft | null>(null);
  const [asking, setAsking] = useState<Change | null>(null);
  const [notify, setNotify] = useState(true);
  const [busy, setBusy] = useState<Change | null>(null);
  const [saving, setSaving] = useState<Change | null>(null);

  const defaultLength = services.find((s) => s.active)?.duration_minutes ?? DEFAULT_MINUTES;

  const canDrag = useCallback(
    (item: CalItem) => {
      if (item.allDay) return false;
      // The server says what this person may move (the one-calendar `can`, WHIT-1116).
      if (item.can !== undefined && !item.can.move) return false;
      if (item.booking) return canWrite && item.kind !== 'cancelled';
      if (item.event) return item.kind !== 'meeting' && canChange(item.event.profile_id);
      return false;
    },
    [canWrite, canChange]
  );

  const onCreate = useCallback(
    (slot: GridSlot) => {
      if (!canWrite) return;
      const fixed = slot.endMinute !== null;
      // @date-display-ignore: a calendar day for the grid, not a timestamp rendered here
      const end = slot.endMinute ?? Math.min(24 * 60, slot.startMinute + defaultLength);
      // @date-display-ignore: a calendar day for the grid, not a timestamp rendered here
      setDraft({ date: slot.date, startMinute: slot.startMinute, endMinute: end, fixed, dragEnd: end });
    },
    [canWrite, defaultLength]
  );

  const onServiceLength = useCallback((minutes: number | null) => {
    setDraft((current) => {
      if (current === null) return current;
      const endMinute = minutes === null ? current.dragEnd : Math.min(24 * 60, current.startMinute + minutes);
      return endMinute === current.endMinute ? current : { ...current, endMinute };
    });
  }, []);

  // Only team events and time off can be pulled to a new length; a booking lasts as long as its service (Q35.7).
  const canResize = useCallback(
    (item: CalItem) =>
      item.event !== undefined &&
      (item.kind === 'event' || item.kind === 'timeoff') &&
      !item.allDay &&
      (item.can === undefined || item.can.resize) &&
      canChange(item.event.profile_id),
    [canChange]
  );

  const name = (item: CalItem) => item.booking?.customer.name ?? '';

  const save = async (change: Change, options: { notify: boolean; anyway: boolean }) => {
    const { item, to, from } = change;
    setSaving(change);
    try {
      if (item.booking) {
        const booking = item.booking;
        await calendarApi.moveBooking(booking.id, {
          starts_at: isoAt(to.date, to.startMinute, timeZone),
          notify: options.notify,
          within_hours: true,
          ...(options.anyway ? { book_anyway: true } : {}),
        });
        const told = options.notify && booking.customer.email ? t('edit.wasEmailed', ' {name} was emailed.', { name: firstName(booking.customer.name) }) : '';
        const message = t('edit.moved', 'Moved to {when}.', { when: when(to.date, to.startMinute) }) + told;
        onChanged();
        addToast(message, 'success', UNDO_MS, {
          label: t('edit.undo', 'Undo'),
          onClick: () => {
            void calendarApi
              .moveBooking(booking.id, {
                starts_at: isoAt(from.date, from.startMinute, timeZone),
                notify: options.notify,
                book_anyway: true,
              })
              .then(onChanged)
              .catch(() => addToast(t('edit.undoFailed', 'It could not be put back. Move it from its details.'), 'error'));
          },
        });
      } else if (item.event) {
        const event = item.event;
        const body = (start: CalendarDate, startMinute: number, endMinute: number) => ({
          profile_id: event.profile_id,
          title: event.title,
          notes: event.notes,
          all_day: false,
          busy: event.busy,
          starts_at: isoAt(start, startMinute, timeZone),
          ends_at: isoAt(start, endMinute, timeZone),
        });
        await calendarApi.updateEvent(event.id, body(to.date, to.startMinute, to.endMinute));
        onChanged();
        addToast(t('edit.moved', 'Moved to {when}.', { when: when(to.date, to.startMinute) }), 'success', UNDO_MS, {
          label: t('edit.undo', 'Undo'),
          onClick: () => {
            void calendarApi.updateEvent(event.id, body(from.date, from.startMinute, from.endMinute)).then(onChanged);
          },
        });
      }
    } catch (error) {
      const reason = takenReason(error);
      if (reason === 'person_busy') {
        setBusy(change);
      } else if (reason === 'room_taken') {
        addToast(t('edit.roomTakenBack', 'The room is taken at {time}. The booking went back; nothing changed.', { time: minuteToTime(to.startMinute) }), 'error', 6000);
      } else if (reason === 'closed') {
        const person = staff.find((s) => s.profile_id === item.booking?.profile_id)?.display_name ?? '';
        addToast(t('edit.closedBack', '{name} isn’t working then. The booking went back; nothing changed.', { name: person }), 'error', 6000);
      } else if (isTaken(error)) {
        addToast(t('edit.takenBack', 'That time was just taken. The booking went back; nothing changed.'), 'error', 6000);
      } else {
        addToast(words.errorText(error, t('edit.failed', 'That change could not be saved. Nothing changed.')), 'error', 6000);
      }
    } finally {
      setSaving(null);
    }
  };

  const onChange = useCallback(
    (item: CalItem, to: GridSlot & { endMinute: number }, how: 'move' | 'resize') => {
      const from = placeOf(item);
      if (from === null) return;
      // A booking lasts as long as its service (Q35.7): never resized here.
      if (item.booking && how === 'resize') return;
      if (from.startMinute === to.startMinute && from.endMinute === to.endMinute && from.date.day === to.date.day && from.date.month === to.date.month) return;
      const change = { item, to, how, from };
      // A customer's booking asks first; team events move without asking (CV.9).
      if (item.booking) {
        setNotify(item.booking.customer.email !== '' && item.booking.customer.email !== null && item.booking.customer.email !== undefined);
        setAsking(change);
      } else {
        void save(change, { notify: false, anyway: false });
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [placeOf, timeZone]
  );

  const pending = asking ?? busy ?? saving;
  // @date-display-ignore: a calendar day for the grid, not a timestamp rendered here
  const preview: GridPreview | null = pending
    ? { key: pending.item.key, date: pending.to.date, startMinute: pending.to.startMinute, endMinute: pending.to.endMinute, how: pending.how }
    : draft
      ? // @date-display-ignore: a calendar day for the grid, not a timestamp rendered here
        { key: null, date: draft.date, startMinute: draft.startMinute, endMinute: draft.endMinute, how: 'create' }
      : null;

  const askTitle = (change: Change) =>
    t('edit.askMove', 'Move {name}’s {service}?', { name: name(change.item), service: change.item.title.toLowerCase() });
  const person = (change: Change) => staff.find((s) => s.profile_id === change.item.booking?.profile_id)?.display_name ?? '';

  const dialogs = (
    <>
      <AlertDialog open={asking !== null} onOpenChange={(open) => !open && setAsking(null)}>
        {asking !== null && (
          <AlertDialogContent data-testid="calendar-ask">
            <AlertDialogHeader>
              <AlertDialogTitle>{askTitle(asking)}</AlertDialogTitle>
              <AlertDialogDescription>
                {t('edit.askMoveBody', 'From {from} to {to}. Same place, with {person}.', {
                  from: when(asking.from.date, asking.from.startMinute),
                  to: when(asking.to.date, asking.to.startMinute),
                  person: firstName(person(asking)),
                })}
              </AlertDialogDescription>
            </AlertDialogHeader>
            {asking.item.booking?.customer.email ? (
              <label className="flex items-center gap-2 text-sm">
                <Checkbox checked={notify} onCheckedChange={(value) => setNotify(value === true)} data-testid="calendar-ask-notify" />
                {t('edit.emailNewTime', 'Email {name} the new time with a calendar file', { name: firstName(asking.item.booking.customer.name) })}
              </label>
            ) : null}
            {asking.item.booking?.customer.email && !notify ? (
              <p className="m-0 text-sm text-warning-text" role="status" data-testid="calendar-ask-no-email">
                {t('edit.noEmailWarning', '{name}’s calendar won’t update: they keep the old time until someone tells them.', {
                  name: firstName(asking.item.booking.customer.name),
                })}
              </p>
            ) : null}
            <AlertDialogFooter>
              <AlertDialogCancel>{t('edit.keep', 'Keep the old time')}</AlertDialogCancel>
              <Button
                data-testid="calendar-ask-confirm"
                onClick={() => {
                  const change = asking;
                  setAsking(null);
                  void save(change, { notify, anyway: false });
                }}
              >
                {t('edit.moveIt', 'Move it')}
              </Button>
            </AlertDialogFooter>
          </AlertDialogContent>
        )}
      </AlertDialog>
      <AlertDialog open={busy !== null} onOpenChange={(open) => !open && setBusy(null)}>
        {busy !== null && (
          <AlertDialogContent data-testid="calendar-busy">
            <AlertDialogHeader>
              <AlertDialogTitle>{t('edit.busyTitle', '{name} is busy then', { name: person(busy) })}</AlertDialogTitle>
              <AlertDialogDescription>
                {t('edit.busyMoveBody', '{name} already has something at {time}. Pick another time, or book anyway.', {
                  name: firstName(person(busy)),
                  time: minuteToTime(busy.to.startMinute),
                })}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>{t('edit.pickAnother', 'Pick another time')}</AlertDialogCancel>
              <Button
                variant="outline"
                data-testid="calendar-busy-anyway"
                onClick={() => {
                  const change = busy;
                  setBusy(null);
                  void save(change, { notify, anyway: true });
                }}
              >
                {t('edit.bookAnyway', 'Book anyway')}
              </Button>
            </AlertDialogFooter>
          </AlertDialogContent>
        )}
      </AlertDialog>
    </>
  );

  return { draft, setDraft, preview, canDrag, canResize, onCreate, onChange, onServiceLength, dialogs };
}
