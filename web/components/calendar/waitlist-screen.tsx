'use client';

/**
 * Calendar › Waitlist (WHIT-1442, design section 39 §8, AP.23).
 *
 * The people who want a time that isn't free, oldest first: who, what, with
 * whom, which days and times of day, and whether a time has been offered.
 * When a cancel or a move frees a time, everyone who matches is offered it at
 * once and the first to take it gets it (owner, Q12); nothing here picks a
 * winner. Staff can add someone, offer a free time by hand, or take someone
 * off the list.
 */

import { useState } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { FieldSelect } from '@/components/field-select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { EmptyFirstTime } from '@whity/ui/page-states';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay, zonedDate } from '@whity/features/datetime';
import { calendarApi, type CalendarSlot, type CalendarWaitlistEntry, type CalendarService, type CalendarStaff } from '@/lib/calendar/api';
import { CalendarNav } from './calendar-nav';
import { SlotPicker } from './slot-picker';
import { useCalendarAccess, useCalendarBasics } from './use-calendar-basics';
import { useCalendarWords } from './use-calendar-words';

const TIMES = ['morning', 'afternoon', 'evening'] as const;
const ANYONE = 'anyone';

function useWaitlistWords() {
  const t = useTranslation('calendar');
  return {
    time: (time: string) =>
      ({
        morning: t('waitlist.time.morning', 'Mornings'),
        afternoon: t('waitlist.time.afternoon', 'Afternoons'),
        evening: t('waitlist.time.evening', 'Evenings'),
      })[time] ?? time,
    status: (status: string) =>
      ({
        waiting: t('waitlist.status.waiting', 'Waiting'),
        offered: t('waitlist.status.offered', 'Time offered'),
        booked: t('waitlist.status.booked', 'Booked'),
        removed: t('waitlist.status.removed', 'Taken off'),
      })[status] ?? status,
  };
}

export function WaitlistScreen() {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const ww = useWaitlistWords();
  const access = useCalendarAccess();
  const basics = useCalendarBasics();
  const { addToast } = useToast();
  const { data, error, forbidden, refetch } = useFetch(() => calendarApi.waitlist(), []);
  const [adding, setAdding] = useState(false);
  const [offering, setOffering] = useState<CalendarWaitlistEntry | null>(null);
  const [removing, setRemoving] = useState<CalendarWaitlistEntry | null>(null);

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('waitlist.area', 'the waitlist')} permission="calendar:read" />;
  }
  const timeZone = basics.data?.timeZone ?? 'UTC';
  const entries = data?.data ?? [];

  return (
    <div className="space-y-4" data-testid="waitlist">
      <AdminHeader
        title={t('waitlist.title', 'Waitlist')}
        description={t('waitlist.description', 'People who want a time that isn’t free. When one frees up, everyone who matches is told at once; the first to take it gets it.')}
        action={
          access.canWrite ? (
            <Button onClick={() => setAdding(true)} disabled={!basics.data} data-testid="waitlist-add">
              {t('waitlist.add', 'Add to waitlist')}
            </Button>
          ) : undefined
        }
      />
      <CalendarNav active="waitlist" />

      {error ? (
        <LoadProblem forbidden={forbidden} message={t('waitlist.error.load', 'The waitlist could not be loaded.')} onRetry={refetch} area={t('waitlist.area', 'the waitlist')} />
      ) : data === null || data === undefined ? (
        <div role="status" aria-busy="true" aria-label={t('waitlist.loading', 'Loading the waitlist')} className="space-y-3">
          <Skeleton className="h-16 w-full rounded-card" />
          <Skeleton className="h-16 w-full rounded-card" />
        </div>
      ) : entries.length === 0 ? (
        <EmptyFirstTime
          title={t('waitlist.empty.title', 'Nobody is waiting')}
          description={t('waitlist.empty.description', 'Customers can join from the booking page when no time suits them, and you can add someone here.')}
        />
      ) : (
        <Card>
          <ul className="divide-y divide-divider" data-testid="waitlist-list">
            {entries.map((entry) => (
              <li key={entry.id} className="flex min-h-12 flex-wrap items-center gap-3 px-4 py-3" data-testid={`waitlist-row-${entry.id}`}>
                <Avatar name={entry.customer.name} size={32} />
                <div className="min-w-0 flex-1">
                  <p className="m-0 truncate text-sm font-medium text-foreground">{entry.customer.name}</p>
                  <p className="m-0 text-sm text-muted-foreground">
                    {words.label(entry.service_name ?? {})}
                    {' · '}
                    {entry.staff_name ?? t('waitlist.anyone', 'Anyone')}
                    {' · '}
                    <WaitDays entry={entry} timeZone={timeZone} />
                    {' · '}
                    {entry.times.length === 0 ? t('waitlist.anyTime', 'Any time') : entry.times.map(ww.time).join(', ')}
                  </p>
                </div>
                <Badge variant={entry.status === 'offered' ? 'info' : 'outline'}>{ww.status(entry.status)}</Badge>
                {access.canWrite && (
                  <div className="flex gap-2">
                    <Button variant="outline" size="sm" onClick={() => setOffering(entry)} data-testid={`waitlist-offer-${entry.id}`}>
                      {t('waitlist.offer', 'Offer a time')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setRemoving(entry)} data-testid={`waitlist-remove-${entry.id}`}>
                      {t('waitlist.remove', 'Take off the list')}
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {basics.data && (
        <>
          <AddDialog
            open={adding}
            services={basics.data.services.filter((s) => s.active)}
            staff={basics.data.staff}
            timeZone={timeZone}
            onClose={() => setAdding(false)}
            onAdded={(entry) => {
              setAdding(false);
              addToast(t('waitlist.added', '{name} is on the waitlist', { name: entry.customer.name }), 'success');
              refetch();
            }}
          />
          <OfferDialog
            entry={offering}
            timeZone={timeZone}
            onClose={() => setOffering(null)}
            onOffered={(name) => {
              setOffering(null);
              addToast(t('waitlist.offered', 'We’ve sent {name} the time', { name }), 'success');
              refetch();
            }}
          />
        </>
      )}
      <ConfirmAction
        open={removing !== null}
        onOpenChange={(open) => !open && setRemoving(null)}
        title={t('waitlist.removeTitle', 'Take {name} off the waitlist?', { name: removing?.customer.name ?? '' })}
        description={t('waitlist.removeBody', 'They won’t be offered times any more. You can add them again.')}
        confirmLabel={t('waitlist.remove', 'Take off the list')}
        cancelLabel={t('action.cancel', 'Cancel')}
        onConfirm={async () => {
          const entry = removing;
          setRemoving(null);
          if (entry === null) return;
          try {
            await calendarApi.removeFromWaitlist(entry.id);
            refetch();
          } catch (err) {
            addToast(words.errorText(err, t('waitlist.error.remove', 'They could not be taken off. Try again.')), 'error');
          }
        }}
      />
    </div>
  );
}

/** The days they want, written out ("16 Nov – 20 Nov"). */
function WaitDays({ entry, timeZone }: { entry: CalendarWaitlistEntry; timeZone: string }) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const day = (ymd: string) => dates.calendarLabel(`${ymd}T12:00:00Z`, 'dayMonth') ?? ymd;
  return entry.date_from === entry.date_to ? (
    <span>{day(entry.date_from)}</span>
  ) : (
    <span>{t('waitlist.days', '{from} to {to}', { from: day(entry.date_from), to: day(entry.date_to) })}</span>
  );
}

/** Add someone: who, the service and person, the days, the times of day. */
function AddDialog({
  open,
  services,
  staff,
  timeZone,
  onClose,
  onAdded,
}: {
  open: boolean;
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  onClose: () => void;
  onAdded: (entry: CalendarWaitlistEntry) => void;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const ww = useWaitlistWords();
  const today = zonedDate(timeZone);
  const ymd = `${today.year}-${String(today.month).padStart(2, '0')}-${String(today.day).padStart(2, '0')}`;
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [service, setService] = useState('');
  const [person, setPerson] = useState(ANYONE);
  const [from, setFrom] = useState(ymd);
  const [to, setTo] = useState(ymd);
  const [times, setTimes] = useState<(typeof TIMES)[number][]>([]);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const providers = staff.filter((p) => services.find((s) => String(s.id) === service)?.staff_profile_ids.includes(p.profile_id));

  const save = async () => {
    if (service === '') {
      setProblem(t('waitlist.error.service', 'Choose the service they want.'));
      return;
    }
    setSaving(true);
    setProblem(null);
    try {
      const entry = await calendarApi.addToWaitlist({
        service_id: Number(service),
        ...(person !== ANYONE ? { profile_id: Number(person) } : {}),
        name: name.trim(),
        ...(email.trim() !== '' ? { email: email.trim() } : {}),
        ...(phone.trim() !== '' ? { phone: phone.trim() } : {}),
        date_from: from,
        date_to: to,
        times,
      });
      setName('');
      setEmail('');
      setPhone('');
      onAdded(entry);
    } catch (error) {
      setProblem(words.errorText(error, t('waitlist.error.add', 'They could not be added. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      {open && (
        <DialogContent className="max-w-lg" data-testid="waitlist-add-dialog">
          <DialogHeader>
            <DialogTitle>{t('waitlist.add', 'Add to waitlist')}</DialogTitle>
            <DialogDescription>{t('waitlist.addBody', 'We tell them by email, and by WhatsApp or SMS when connected, as soon as a time that suits them frees up.')}</DialogDescription>
          </DialogHeader>
          <Input id="waitlist-name" label={t('waitlist.name', 'Their name')} value={name} onChange={(e) => setName(e.target.value)} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Input id="waitlist-email" type="email" dir="ltr" label={t('waitlist.email', 'Email')} value={email} onChange={(e) => setEmail(e.target.value)} />
            <Input id="waitlist-phone" dir="ltr" label={t('waitlist.phone', 'Phone')} value={phone} onChange={(e) => setPhone(e.target.value)} />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1">
              <label htmlFor="waitlist-service" className="text-sm font-medium text-foreground">{t('waitlist.service', 'Service')}</label>
              <FieldSelect
                id="waitlist-service"
                value={service}
                onChange={(value) => {
                  setService(value);
                  setPerson(ANYONE);
                }}
                placeholder={t('waitlist.chooseService', 'Choose a service')}
                options={services.map((s) => ({ value: String(s.id), label: words.serviceName(s) }))}
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="waitlist-person" className="text-sm font-medium text-foreground">{t('waitlist.with', 'With')}</label>
              <FieldSelect
                id="waitlist-person"
                value={person}
                onChange={setPerson}
                options={[{ value: ANYONE, label: t('waitlist.anyone', 'Anyone') }, ...providers.map((p) => ({ value: String(p.profile_id), label: p.display_name }))]}
              />
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Input id="waitlist-from" type="date" dir="ltr" label={t('waitlist.from', 'From')} value={from} min={ymd} onChange={(e) => setFrom(e.target.value)} />
            <Input id="waitlist-to" type="date" dir="ltr" label={t('waitlist.to', 'Until')} value={to} min={from} onChange={(e) => setTo(e.target.value)} />
          </div>
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium text-foreground">{t('waitlist.times', 'Times of day')}</legend>
            <div className="flex flex-wrap gap-4">
              {TIMES.map((time) => (
                <label key={time} className="flex min-h-10 items-center gap-2 text-sm">
                  <Checkbox checked={times.includes(time)} onCheckedChange={(on) => setTimes((current) => (on === true ? [...current, time] : current.filter((x) => x !== time)))} />
                  {ww.time(time)}
                </label>
              ))}
            </div>
            <p className="m-0 text-sm text-muted-foreground">{t('waitlist.timesHint', 'Leave them all empty for any time of day.')}</p>
          </fieldset>
          {problem && <p className="text-sm text-error-text" role="alert">{problem}</p>}
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>{t('action.cancel', 'Cancel')}</Button>
            <Button onClick={() => void save()} loading={saving} data-testid="waitlist-add-save">{t('waitlist.addSave', 'Add')}</Button>
          </DialogFooter>
        </DialogContent>
      )}
    </Dialog>
  );
}

/** Offer a free time by hand: they are sent a link to take it. */
function OfferDialog({ entry, timeZone, onClose, onOffered }: { entry: CalendarWaitlistEntry | null; timeZone: string; onClose: () => void; onOffered: (name: string) => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const [date, setDate] = useState(() => zonedDate(timeZone));
  const [slot, setSlot] = useState<CalendarSlot | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const offer = async () => {
    if (entry === null || slot === null) return;
    const profileId = entry.profile_id ?? slot.profile_ids[0];
    if (profileId === undefined) return;
    setSaving(true);
    setProblem(null);
    try {
      await calendarApi.offerFromWaitlist(entry.id, profileId, slot.starts_at);
      setSlot(null);
      onOffered(entry.customer.name);
    } catch (error) {
      setProblem(words.errorText(error, t('waitlist.error.offer', 'The time could not be offered. Pick another.')));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={entry !== null} onOpenChange={(open) => !open && onClose()}>
      {entry && (
        <DialogContent className="max-w-lg" data-testid="waitlist-offer-dialog">
          <DialogHeader>
            <DialogTitle>{t('waitlist.offerTitle', 'Offer {name} a time', { name: entry.customer.name })}</DialogTitle>
            <DialogDescription>{t('waitlist.offerBody', 'They get a link to take it. It stays free for anyone until someone books it.')}</DialogDescription>
          </DialogHeader>
          <SlotPicker
            id="waitlist-offer"
            serviceId={entry.service_id}
            profileId={entry.profile_id ?? null}
            date={date}
            onDateChange={setDate}
            timeZone={timeZone}
            selected={slot}
            onSelect={setSlot}
          />
          {slot && (
            <p className="m-0 text-sm text-foreground" data-testid="waitlist-offer-chosen">
              <span dir="ltr" className="tabular-nums">{dates.dateTime(slot.starts_at) ?? ''}</span>
            </p>
          )}
          {problem && <p className="text-sm text-error-text" role="alert">{problem}</p>}
          <DialogFooter>
            <Button variant="outline" onClick={onClose}>{t('action.cancel', 'Cancel')}</Button>
            <Button onClick={() => void offer()} loading={saving} disabled={slot === null} data-testid="waitlist-offer-send">
              {t('waitlist.offerSend', 'Send the offer')}
            </Button>
          </DialogFooter>
        </DialogContent>
      )}
    </Dialog>
  );
}
