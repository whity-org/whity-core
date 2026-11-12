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
 */

import { useState } from 'react';
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
import { formatMoney } from '@whity/ui/money/currency';
import { useTranslation } from '@whity/features/i18n';
import { toZonedIso, useDateDisplay } from '@whity/features/datetime';
import {
  calendarApi,
  isTaken,
  type CalendarBooking,
  type CalendarService,
  type CalendarSlot,
  type CalendarStaff,
} from '@/lib/calendar/api';
import { dateInZone, type CalendarDate } from '@/lib/calendar/layout';
import { SlotPicker } from './slot-picker';
import { useCalendarWords } from './use-calendar-words';

const ANYONE = 'anyone';

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
export type BookingChange = 'moved' | 'cancelled' | 'accepted' | 'suggested' | 'checkedIn' | 'notCheckedIn';

export interface BookingSheetProps {
  booking: CalendarBooking | null;
  /** Open straight on Suggest another time (from the Requests card). */
  suggest?: boolean;
  onClose: () => void;
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  canChange: boolean;
  onChanged: (booking: CalendarBooking, what: BookingChange) => void;
}

export function BookingSheet({ booking, suggest = false, onClose, services, staff, timeZone, canChange, onChanged }: BookingSheetProps) {
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
  const opening = `${bookingId ?? ''}:${suggest ? 'suggest' : 'view'}`;
  const [opened, setOpened] = useState(opening);
  if (opened !== opening) {
    setOpened(opening);
    setMode(suggest && bookingId !== null ? 'suggest' : 'view');
    setProblem(null);
  }

  const act = async (what: 'accepted' | 'checkedIn' | 'notCheckedIn') => {
    if (booking === null) return;
    setActing(true);
    setProblem(null);
    try {
      const changed =
        what === 'accepted'
          ? await calendarApi.acceptBooking(booking.id)
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

  return (
    <Sheet open={booking !== null} onOpenChange={(next) => (next ? undefined : close())}>
      <SheetContent className="w-full gap-5 text-sm sm:max-w-lg" data-testid="booking-sheet">
        {booking && (
          <>
            <SheetHeader className="pe-10">
              <SheetTitle className="text-lg font-semibold">{booking.customer.name ?? t('booking.noName', 'No name given')}</SheetTitle>
              <SheetDescription className="text-sm">
                {words.serviceName(service)}
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
              <BookingFacts booking={booking} service={service} person={person} timeZone={timeZone} />
            ) : (
              <MovePanel
                booking={booking}
                service={service}
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
                    {booking.status === 'confirmed' &&
                      (booking.checked_in_at ? (
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
              serviceName={words.serviceName(service)}
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

function BookingFacts({
  booking,
  service,
  person,
  timeZone,
}: {
  booking: CalendarBooking;
  service: CalendarService | null;
  person: CalendarStaff | null;
  timeZone: string;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });

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
  rows.push({
    label: t('booking.fact.with', 'With'),
    value: person ? <ActorChip name={person.display_name} /> : t('booking.fact.removedPerson', 'Someone no longer on the calendar'),
  });
  rows.push({ label: t('booking.fact.service', 'Service'), value: words.serviceName(service) });
  if (booking.room_name) {
    rows.push({ label: t('booking.fact.room', 'Room'), value: words.label(booking.room_name), testId: 'booking-room' });
  }
  if (booking.checked_in_at) {
    rows.push({
      label: t('booking.fact.checkedIn', 'Checked in'),
      value: <span dir="ltr" className="tabular-nums">{dates.time(booking.checked_in_at) ?? '—'}</span>,
      testId: 'booking-checked-in',
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
  staff,
  timeZone,
  suggest,
  onCancel,
  onMoved,
}: {
  booking: CalendarBooking;
  service: CalendarService | null;
  staff: CalendarStaff[];
  timeZone: string;
  /** Suggest the time to the customer instead of moving the booking. */
  suggest: boolean;
  onCancel: () => void;
  onMoved: (booking: CalendarBooking) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const [person, setPerson] = useState<string>(String(booking.profile_id));
  const [date, setDate] = useState<CalendarDate>(dateInZone(new Date(booking.starts_at), timeZone));
  const [slot, setSlot] = useState<CalendarSlot | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const providers = service && service.staff_profile_ids.length > 0
    ? staff.filter((s) => service.staff_profile_ids.includes(s.profile_id))
    : staff;
  const profileId = person === ANYONE ? null : Number(person);
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
      const target = profileId ?? slot.profile_ids[0];
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
            { value: ANYONE, label: t('booking.field.anyone', 'Anyone who is free') },
            ...providers.map((s) => ({ value: String(s.profile_id), label: s.display_name })),
          ]}
        />
      </div>
      <SlotPicker
        id="move"
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
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const confirm = async () => {
    setSaving(true);
    setProblem(null);
    try {
      onCancelled(await calendarApi.cancelBooking(booking.id, reason.trim()));
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
