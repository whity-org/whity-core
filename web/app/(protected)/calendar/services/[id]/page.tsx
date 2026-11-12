'use client';

/**
 * /calendar/services/[id] (WHIT-944): one service, edited in place with the
 * save bar (section 21, decision P.3). Name in Arabic and English, length,
 * time kept free before and after, price and currency, whether customers can
 * book it online, whether it is offered at all, and who provides it.
 *
 * Only Overview: the API has no access list or history for a service yet, so
 * the Access and History tabs a record page usually has are left out rather
 * than shown empty (noted in the brand ask, WHIT-946).
 *
 * Money is typed and shown in normal units with the currency's decimals
 * (JOD 12.500) and sent as minor units (12500), through the shared money input.
 * Nothing is ever deleted: a service that is no longer offered is turned off.
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { CalendarNav } from '@/components/calendar/calendar-nav';
import { useCalendarAccess } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Checkbox } from '@whity/ui/checkbox';
import { FieldSelect } from '@/components/field-select';
import { MoneyInput } from '@whity/ui/money/money-input';
import { parseMoney, toDecimalString } from '@whity/ui/money/currency';
import { ReadOnlyNotice } from '@whity/ui/page-states';
import { SaveBar, ChangedField } from '@whity/ui/save-bar';
import { Skeleton } from '@whity/ui/skeleton';
import { Switch } from '@whity/ui/switch';
import { useTranslation } from '@whity/features/i18n';
import { calendarApi, type CalendarService, type CalendarServiceRequest } from '@/lib/calendar/api';
import { cleanNames, sameNames } from '@/lib/localized-name';

interface Draft {
  name: BilingualValue;
  description: BilingualValue;
  duration: string;
  before: string;
  after: string;
  price: string;
  currency: string;
  bookableOnline: boolean;
  needsApproval: boolean;
  /** The room it needs, as the select's value: a room id, or NO_ROOM. */
  room: string;
  active: boolean;
  staff: number[];
}

const NO_ROOM = 'none';

function draftOf(service: CalendarService): Draft {
  return {
    name: { ...service.name },
    description: { ...(service.description ?? {}) },
    duration: String(service.duration_minutes),
    before: String(service.buffer_before_minutes),
    after: String(service.buffer_after_minutes),
    price: service.price_minor === null ? '' : safeDecimal(service.price_minor, service.currency),
    currency: service.currency,
    bookableOnline: service.bookable_online,
    needsApproval: service.needs_approval ?? false,
    room: service.room_id === null || service.room_id === undefined ? NO_ROOM : String(service.room_id),
    active: service.active,
    staff: [...service.staff_profile_ids].sort((a, b) => a - b),
  };
}

function safeDecimal(minor: number, currency: string): string {
  try {
    return toDecimalString(minor, currency);
  } catch {
    return String(minor);
  }
}

export default function CalendarServicePage() {
  const params = useParams<{ id: string | string[] }>();
  const id = Number(Array.isArray(params.id) ? params.id[0] : params.id);
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const { addToast } = useToast();

  const { data, error, forbidden, refetch } = useFetch(async () => {
    const [service, staff, rooms] = await Promise.all([calendarApi.service(id), calendarApi.staff(), calendarApi.rooms()]);
    return { service, staff, rooms };
  }, [id]);

  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const saved = useMemo(() => (data ? draftOf(data.service) : null), [data]);
  const current = draft ?? saved;
  const readOnly = !access.canManage;

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('services.area', 'services')} permission="calendar:read" />;
  }

  const changes: string[] = [];
  if (saved && current) {
    if (!sameNames(current.name, saved.name)) changes.push(t('services.field.name', 'Name'));
    if (!sameNames(current.description, saved.description)) changes.push(t('services.field.description', 'Description'));
    if (current.duration !== saved.duration || current.before !== saved.before || current.after !== saved.after)
      changes.push(t('services.field.time', 'Time'));
    if (current.price !== saved.price || current.currency !== saved.currency) changes.push(t('services.field.price', 'Price'));
    if (current.bookableOnline !== saved.bookableOnline || current.active !== saved.active || current.needsApproval !== saved.needsApproval)
      changes.push(t('services.field.offered', 'Where it is offered'));
    if (current.room !== saved.room) changes.push(t('services.field.room', 'Room it needs'));
    if (current.staff.join(',') !== saved.staff.join(',')) changes.push(t('services.field.staff', 'Who provides it'));
  }

  const update = (patch: Partial<Draft>) => {
    if (current === null) return;
    setDraft({ ...current, ...patch });
    setProblem(null);
  };

  const save = async () => {
    if (current === null) return;
    const names = cleanNames(current.name);
    if (Object.keys(names).length === 0) {
      setProblem(t('services.error.name', 'Give the service a name.'));
      return;
    }
    const whole = (text: string, min: number, max: number) => {
      const n = Number(text);
      return Number.isInteger(n) && n >= min && n <= max ? n : null;
    };
    const duration = whole(current.duration, 5, 1440);
    const before = whole(current.before, 0, 240);
    const after = whole(current.after, 0, 240);
    if (duration === null) {
      setProblem(t('services.error.duration', 'The length must be a whole number of minutes, from 5 to 1440.'));
      return;
    }
    if (before === null || after === null) {
      setProblem(t('services.error.buffer', 'Time kept free must be a whole number of minutes, from 0 to 240.'));
      return;
    }
    const currency = current.currency.trim().toUpperCase();
    if (!/^[A-Z]{3}$/.test(currency)) {
      setProblem(t('services.error.currency', 'Enter a three-letter currency code, such as JOD.'));
      return;
    }
    let priceMinor: number | null = null;
    if (current.price.trim() !== '') {
      try {
        priceMinor = parseMoney(current.price, currency);
      } catch {
        setProblem(t('services.error.price', 'Enter the price as a number, such as 12.500.'));
        return;
      }
    }

    const body: CalendarServiceRequest = {
      name: names,
      description: cleanNames(current.description),
      duration_minutes: duration,
      buffer_before_minutes: before,
      buffer_after_minutes: after,
      price_minor: priceMinor,
      currency,
      bookable_online: current.bookableOnline,
      needs_approval: current.needsApproval,
      room_id: current.room === NO_ROOM ? null : Number(current.room),
      active: current.active,
      staff_profile_ids: current.staff,
    };
    setSaving(true);
    setProblem(null);
    try {
      await calendarApi.updateService(id, body);
      setDraft(null);
      addToast(t('services.saved', 'Service saved'), 'success');
      refetch();
    } catch (error) {
      setProblem(words.errorText(error, t('services.error.save', 'The service could not be saved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  const title = data ? words.serviceName(data.service) : t('services.title', 'Services');

  return (
    <div className="space-y-4 pb-16">
      <AdminHeader
        title={title}
        breadcrumb={
          <Link href="/calendar/services" className="hover:underline">
            {t('services.title', 'Services')}
          </Link>
        }
      />
      <CalendarNav active="services" />

      {error ? (
        <LoadProblem
          forbidden={forbidden}
          message={t('services.error.loadOne', 'This service could not be loaded.')}
          onRetry={refetch}
          area={t('services.area', 'services')}
        />
      ) : current === null || saved === null || data === null ? (
        <div role="status" aria-busy="true" aria-label={t('loading.service', 'Loading the service')} className="space-y-3">
          <Skeleton className="h-40 w-full rounded-card" />
          <Skeleton className="h-40 w-full rounded-card" />
        </div>
      ) : (
        <>
          {readOnly && !access.loading && (
            <ReadOnlyNotice
              title={t('services.readOnly.title', 'You can see services but not change them')}
              description={t('services.readOnly.description', 'Someone who manages the calendar adds and changes services.')}
            />
          )}

          <fieldset disabled={readOnly} className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle>{t('services.card.about', 'About the service')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <ChangedField changed={!sameNames(current.name, saved.name)}>
                  <BilingualInput
                    id="service-name"
                    label={t('services.field.name', 'Name')}
                    value={current.name}
                    onChange={(name) => update({ name })}
                    arLabel={t('language.arabic', 'Arabic')}
                    enLabel={t('language.english', 'English')}
                    disabled={readOnly}
                  />
                </ChangedField>
                <ChangedField changed={!sameNames(current.description, saved.description)}>
                  <BilingualInput
                    id="service-description"
                    label={t('services.field.description', 'Description')}
                    description={t('services.field.descriptionHint', 'Optional. Customers see it on the booking page.')}
                    value={current.description}
                    onChange={(description) => update({ description })}
                    arLabel={t('language.arabic', 'Arabic')}
                    enLabel={t('language.english', 'English')}
                    disabled={readOnly}
                  />
                </ChangedField>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('services.field.time', 'Time')}</CardTitle>
                <CardDescription>
                  {t('services.card.timeHint', 'Time kept free before and after is not shown to the customer; it stops the next booking starting straight away.')}
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-3">
                <ChangedField changed={current.duration !== saved.duration}>
                  <Input
                    id="service-duration"
                    type="number"
                    min={5}
                    max={1440}
                    label={t('services.field.duration', 'Length in minutes')}
                    value={current.duration}
                    onChange={(e) => update({ duration: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.before !== saved.before}>
                  <Input
                    id="service-before"
                    type="number"
                    min={0}
                    max={240}
                    label={t('services.field.before', 'Free before, in minutes')}
                    value={current.before}
                    onChange={(e) => update({ before: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.after !== saved.after}>
                  <Input
                    id="service-after"
                    type="number"
                    min={0}
                    max={240}
                    label={t('services.field.after', 'Free after, in minutes')}
                    value={current.after}
                    onChange={(e) => update({ after: e.target.value })}
                  />
                </ChangedField>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('services.field.price', 'Price')}</CardTitle>
                <CardDescription>
                  {t('services.card.priceHint', 'Shown to customers. Leave it empty to show no price. Paying is set up separately.')}
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-[1fr_8rem]">
                <ChangedField changed={current.price !== saved.price}>
                  <label htmlFor="service-price" className="text-sm font-medium text-foreground">
                    {t('services.field.amount', 'Amount')}
                  </label>
                  <MoneyInput
                    id="service-price"
                    currency={/^[A-Z]{3}$/.test(current.currency) ? current.currency : 'JOD'}
                    value={current.price}
                    onValueChange={(price) => update({ price })}
                    placeholder="12.500"
                  />
                </ChangedField>
                <ChangedField changed={current.currency !== saved.currency}>
                  <Input
                    id="service-currency"
                    dir="ltr"
                    maxLength={3}
                    label={t('services.field.currency', 'Currency')}
                    value={current.currency}
                    onChange={(e) => update({ currency: e.target.value.toUpperCase() })}
                  />
                </ChangedField>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('services.field.offered', 'Where it is offered')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('services.field.active', 'Offered')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('services.field.activeHint', 'When off, nobody can book it, at the desk or online. Bookings already made stay.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.active}
                    onCheckedChange={(active) => update({ active })}
                    aria-label={t('services.field.active', 'Offered')}
                  />
                </label>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('services.field.online', 'Customers can book it online')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('services.field.onlineHint', 'Shown on the booking page when the booking page is turned on.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.bookableOnline}
                    onCheckedChange={(bookableOnline) => update({ bookableOnline })}
                    aria-label={t('services.field.online', 'Customers can book it online')}
                  />
                </label>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('services.field.approval', 'Needs your OK')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('services.field.approvalHint', 'Customers send a request instead of booking. The time is held until someone accepts it or suggests another time.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.needsApproval}
                    onCheckedChange={(needsApproval) => update({ needsApproval })}
                    aria-label={t('services.field.approval', 'Needs your OK')}
                    data-testid="service-needs-approval"
                  />
                </label>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('services.field.room', 'Room it needs')}</CardTitle>
                <CardDescription>
                  {t('services.card.roomHint', 'Booking this service also books the room, so two people can’t use it at the same time.')}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <ChangedField changed={current.room !== saved.room}>
                  <label htmlFor="service-room" className="sr-only">
                    {t('services.field.room', 'Room it needs')}
                  </label>
                  <FieldSelect
                    id="service-room"
                    value={current.room}
                    onChange={(room) => update({ room })}
                    options={[
                      { value: NO_ROOM, label: t('services.room.none', 'No room') },
                      ...data.rooms
                        .filter((room) => room.active || String(room.id) === current.room)
                        .map((room) => ({ value: String(room.id), label: words.label(room.name) })),
                    ]}
                  />
                </ChangedField>
                {data.rooms.length === 0 && (
                  <p className="mt-2 text-sm text-muted-foreground">
                    {t('services.room.noRooms', 'No rooms yet. Add them under Rooms.')}
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('services.field.staff', 'Who provides it')}</CardTitle>
                <CardDescription>
                  {t('services.card.staffHint', 'Only these people are offered for it. Add people to the calendar under Hours and time off.')}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {data.staff.length === 0 ? (
                  <p className="text-sm text-muted-foreground">{t('services.staff.noTeam', 'Nobody has a calendar yet.')}</p>
                ) : (
                  <ChangedField changed={current.staff.join(',') !== saved.staff.join(',')}>
                    <ul className="divide-y divide-divider">
                      {data.staff.map((person) => {
                        const checked = current.staff.includes(person.profile_id);
                        return (
                          <li key={person.profile_id} className="flex min-h-12 items-center gap-3">
                            <Checkbox
                              id={`service-staff-${person.profile_id}`}
                              checked={checked}
                              onCheckedChange={(next) =>
                                update({
                                  staff: (next === true
                                    ? [...current.staff, person.profile_id]
                                    : current.staff.filter((p) => p !== person.profile_id)
                                  ).sort((a, b) => a - b),
                                })
                              }
                            />
                            <label htmlFor={`service-staff-${person.profile_id}`} className="text-sm text-foreground">
                              {person.display_name}
                            </label>
                          </li>
                        );
                      })}
                    </ul>
                  </ChangedField>
                )}
              </CardContent>
            </Card>
          </fieldset>

          {problem && (
            <Alert variant="destructive" data-testid="service-problem">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}

          <SaveBar
            dirty={changes.length > 0}
            summary={t('services.changed', '{what} changed', { what: changes.join(', ') })}
            discardLabel={t('action.discard', 'Discard')}
            saveLabel={t('action.save', 'Save changes')}
            saving={saving}
            onDiscard={() => {
              setDraft(null);
              setProblem(null);
            }}
            onSave={() => void save()}
          />
        </>
      )}
    </div>
  );
}
