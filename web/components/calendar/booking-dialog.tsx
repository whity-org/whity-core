'use client';

/**
 * New booking (WHIT-944): a service, a person or Anyone, a free time, and who
 * it is for. Posts `POST /calendar/bookings`.
 *
 * WHEN THE TIME WAS JUST TAKEN. Two people at the desk can pick the same free
 * time; the server serialises them per person and the loser gets 409 (ADR 0017
 * section 3). Here that is said in plain words, the chosen time is cleared, and
 * the free times are fetched again, so the next choice is from what is really
 * free. What the person typed about the customer is kept.
 */

import { useMemo, useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FieldSelect } from '@/components/field-select';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Textarea } from '@whity/ui/textarea';
import { useTranslation } from '@whity/features/i18n';
import { toZonedIso, zonedDate } from '@whity/features/datetime';
import {
  calendarApi,
  isTaken,
  type CalendarBooking,
  type CalendarService,
  type CalendarSlot,
  type CalendarStaff,
} from '@/lib/calendar/api';
import type { CalendarDate } from '@/lib/calendar/layout';
import { SlotPicker } from './slot-picker';
import { useCalendarWords } from './use-calendar-words';

const ANYONE = 'anyone';

export interface BookingDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  /** Start on this day; today in the workspace's zone otherwise. */
  initialDate?: CalendarDate | null;
  /** Start with this person chosen. */
  initialProfileId?: number | null;
  onBooked: (booking: CalendarBooking) => void;
}

export function BookingDialog(props: BookingDialogProps) {
  // Mounted only while open, so every opening starts from a clean form.
  return (
    <Dialog open={props.open} onOpenChange={props.onOpenChange}>
      {props.open && <BookingForm {...props} />}
    </Dialog>
  );
}

function BookingForm({
  onOpenChange,
  services,
  staff,
  timeZone,
  initialDate = null,
  initialProfileId = null,
  onBooked,
}: BookingDialogProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();

  const bookable = useMemo(() => services.filter((s) => s.active), [services]);
  const [serviceId, setServiceId] = useState<string>(bookable.length === 1 ? String(bookable[0].id) : '');
  const [person, setPerson] = useState<string>(initialProfileId !== null ? String(initialProfileId) : ANYONE);
  const [date, setDate] = useState<CalendarDate>(initialDate ?? zonedDate(timeZone));
  const [slot, setSlot] = useState<CalendarSlot | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);

  const service = bookable.find((s) => String(s.id) === serviceId) ?? null;
  // Only the people who provide the service; everyone when it names nobody yet.
  const providers = service && service.staff_profile_ids.length > 0
    ? staff.filter((s) => service.staff_profile_ids.includes(s.profile_id))
    : staff;
  const profileId = person === ANYONE ? null : Number(person);

  const nameMissing = name.trim() === '';
  const contactMissing = email.trim() === '' && phone.trim() === '';

  const submit = async () => {
    setShowErrors(true);
    setProblem(null);
    if (service === null || slot === null || nameMissing || contactMissing) return;
    setSaving(true);
    try {
      const booking = await calendarApi.createBooking({
        service_id: service.id,
        // A person chosen, or the first of those free at that time; the server
        // re-checks under the lock either way.
        ...(profileId !== null ? { profile_id: profileId } : {}),
        starts_at: toZonedIso(new Date(slot.starts_at), timeZone),
        customer: {
          name: name.trim(),
          ...(email.trim() !== '' ? { email: email.trim() } : {}),
          ...(phone.trim() !== '' ? { phone: phone.trim() } : {}),
          ...(note.trim() !== '' ? { note: note.trim() } : {}),
        },
      });
      onBooked(booking);
    } catch (error) {
      if (isTaken(error)) {
        setSlot(null);
        setReloadKey((k) => k + 1);
        setProblem(t('error.taken', 'That time was just taken. Pick another time.'));
      } else {
        setProblem(words.errorText(error, t('booking.error.create', 'The booking could not be made. Try again.')));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto" data-testid="booking-dialog">
      <DialogHeader>
        <DialogTitle>{t('booking.new.title', 'New booking')}</DialogTitle>
        <DialogDescription>
          {t('booking.new.description', 'Choose the service and a free time, then who it is for.')}
        </DialogDescription>
      </DialogHeader>

      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1">
            <label htmlFor="booking-service" className="text-sm font-medium text-foreground">
              {t('booking.field.service', 'Service')}
            </label>
            <FieldSelect
              id="booking-service"
              value={serviceId}
              onChange={(value) => {
                setServiceId(value);
                setSlot(null);
              }}
              placeholder={t('booking.field.servicePlaceholder', 'Choose a service')}
              invalid={showErrors && service === null}
              options={bookable.map((s) => ({
                value: String(s.id),
                label: `${words.serviceName(s)} · ${words.minutes(s.duration_minutes)}`,
              }))}
            />
            {showErrors && service === null && (
              <p className="text-sm text-error-text">{t('booking.field.serviceMissing', 'Choose a service.')}</p>
            )}
          </div>
          <div className="space-y-1">
            <label htmlFor="booking-person" className="text-sm font-medium text-foreground">
              {t('booking.field.person', 'With')}
            </label>
            <FieldSelect
              id="booking-person"
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
        </div>

        <fieldset className="space-y-2">
          <legend className="mb-1 text-sm font-medium text-foreground">{t('booking.field.time', 'Time')}</legend>
          <SlotPicker
            id="booking"
            serviceId={service?.id ?? null}
            profileId={profileId}
            date={date}
            onDateChange={setDate}
            timeZone={timeZone}
            selected={slot}
            onSelect={setSlot}
            reloadKey={reloadKey}
          />
          {showErrors && slot === null && service !== null && (
            <p className="text-sm text-error-text">{t('booking.field.timeMissing', 'Choose a free time.')}</p>
          )}
        </fieldset>

        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="mb-2 text-sm font-medium text-foreground">{t('booking.field.customer', 'Customer')}</legend>
          <Input
            id="booking-name"
            label={t('booking.field.name', 'Name')}
            required
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('booking.field.namePlaceholder', 'Rana Haddad')}
            errorText={showErrors && nameMissing ? t('booking.field.nameMissing', 'Enter the customer’s name.') : undefined}
            autoComplete="off"
          />
          <Input
            id="booking-email"
            type="email"
            dir="ltr"
            label={t('booking.field.email', 'Email')}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="rana@example-bakery.com"
            errorText={showErrors && contactMissing ? t('booking.field.contactMissing', 'Enter an email or a phone number.') : undefined}
            autoComplete="off"
          />
          <Input
            id="booking-phone"
            type="tel"
            dir="ltr"
            label={t('booking.field.phone', 'Phone')}
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            placeholder="+962 7 9000 0000"
            autoComplete="off"
          />
          <div className="space-y-1 sm:col-span-2">
            <label htmlFor="booking-note" className="text-sm font-medium text-foreground">
              {t('booking.field.note', 'Note for the team')}
            </label>
            <Textarea id="booking-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
        </fieldset>

        {problem && (
          <Alert variant="destructive" data-testid="booking-problem">
            <AlertDescription>{problem}</AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            {t('action.cancel', 'Cancel')}
          </Button>
          <Button type="submit" loading={saving} data-testid="booking-submit">
            {t('booking.new.submit', 'Book')}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
