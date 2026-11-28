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
 * ($12.50, JOD 12.500) and sent as minor units, through the shared money input.
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
import { ServiceFormsCard } from '@/components/calendar/service-forms';
import { PlanTemplatesCard } from '@/components/calendar/treatment-plans';
import { useCalendarAccess } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { useWorkspaceCurrency } from '@/lib/branding-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
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
import { calendarApi, type CalendarService, type CalendarServicePerson, type CalendarServiceRequest } from '@/lib/calendar/api';
import { cleanLocalizedText, sameLocalizedText } from '@whity/ui/localized-text';

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
  /**
   * WHIT-1439 (AP.18): what it needs, in order, each as a select's value:
   * `r:<id>` for a specific room or piece of equipment, `g:<group>` for any
   * free one from a group.
   */
  needs: string[];
  active: boolean;
  staff: number[];
  /**
   * WHIT-1440 (AP.19): each person's own length, time free after and price,
   * by profile id; empty is the service's own.
   */
  own: Record<number, OwnValues>;
  /** WHIT-1445 (AP.26): none, a fixed amount (in normal units) or a percentage. */
  depositKind: 'none' | 'fixed' | 'percent';
  depositAmount: string;
  depositPercent: string;
}

interface OwnValues {
  duration: string;
  after: string;
  price: string;
}

const NO_OWN: OwnValues = { duration: '', after: '', price: '' };

function ownKey(own: Record<number, OwnValues>, staff: number[]): string {
  return staff.map((p) => `${p}:${own[p]?.duration ?? ''}/${own[p]?.after ?? ''}/${own[p]?.price ?? ''}`).join(',');
}

/** A need as the select's value. */
function needValue(need: { resource_id?: number | null; group_key?: string | null }): string {
  return need.resource_id !== null && need.resource_id !== undefined ? `r:${need.resource_id}` : `g:${need.group_key ?? ''}`;
}

/** A select's value as the API's need. */
function needOf(value: string): { resource_id: number | null; group_key: string | null } {
  return value.startsWith('r:') ? { resource_id: Number(value.slice(2)), group_key: null } : { resource_id: null, group_key: value.slice(2) };
}

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
    needs: (service.resources ?? (service.room_id ? [{ resource_id: service.room_id, group_key: null }] : [])).map(needValue),
    active: service.active,
    staff: [...service.staff_profile_ids].sort((a, b) => a - b),
    own: Object.fromEntries(
      (service.staff ?? []).map((person) => [
        person.profile_id,
        {
          duration: person.duration_minutes === null || person.duration_minutes === undefined ? '' : String(person.duration_minutes),
          after: person.buffer_after_minutes === null || person.buffer_after_minutes === undefined ? '' : String(person.buffer_after_minutes),
          price: person.price_minor === null || person.price_minor === undefined ? '' : safeDecimal(person.price_minor, service.currency),
        },
      ])
    ),
    depositKind: service.deposit?.kind ?? 'none',
    depositAmount: service.deposit?.kind === 'fixed' && typeof service.deposit.amount_minor === 'number' ? safeDecimal(service.deposit.amount_minor, service.currency) : '',
    depositPercent: service.deposit?.kind === 'percent' && typeof service.deposit.percent === 'number' ? String(service.deposit.percent) : '',
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
  const workspaceCurrency = useWorkspaceCurrency();

  const { data, error, forbidden, refetch } = useFetch(async () => {
    const [service, staff, rooms, settings] = await Promise.all([
      calendarApi.service(id),
      calendarApi.staff(),
      calendarApi.rooms(),
      // WHIT-1445: whether a payment provider takes deposits; only for the note.
      calendarApi.settings().catch(() => null),
    ]);
    return { service, staff, rooms, settings };
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
    if (!sameLocalizedText(current.name, saved.name)) changes.push(t('services.field.name', 'Name'));
    if (!sameLocalizedText(current.description, saved.description)) changes.push(t('services.field.description', 'Description'));
    if (current.duration !== saved.duration || current.before !== saved.before || current.after !== saved.after)
      changes.push(t('services.field.time', 'Time'));
    if (current.price !== saved.price || current.currency !== saved.currency) changes.push(t('services.field.price', 'Price'));
    if (current.depositKind !== saved.depositKind || current.depositAmount !== saved.depositAmount || current.depositPercent !== saved.depositPercent)
      changes.push(t('services.field.deposit', 'Deposit'));
    if (current.bookableOnline !== saved.bookableOnline || current.active !== saved.active || current.needsApproval !== saved.needsApproval)
      changes.push(t('services.field.offered', 'Where it is offered'));
    if (current.needs.join('|') !== saved.needs.join('|')) changes.push(t('services.field.needs', 'Rooms and equipment it needs'));
    if (current.staff.join(',') !== saved.staff.join(',') || ownKey(current.own, current.staff) !== ownKey(saved.own, saved.staff))
      changes.push(t('services.field.staff', 'Who provides it'));
  }

  const update = (patch: Partial<Draft>) => {
    if (current === null) return;
    setDraft({ ...current, ...patch });
    setProblem(null);
  };

  const save = async () => {
    if (current === null) return;
    const names = cleanLocalizedText(current.name);
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
      setProblem(t('services.error.currency', 'Enter a three-letter currency code, such as USD.'));
      return;
    }
    let priceMinor: number | null = null;
    if (current.price.trim() !== '') {
      try {
        priceMinor = parseMoney(current.price, currency);
      } catch {
        setProblem(t('services.error.price', 'Enter the price as a number, such as 12.50.'));
        return;
      }
    }

    // WHIT-1440 (AP.19): each person's own values; empty is the service's.
    const staff: CalendarServicePerson[] = [];
    for (const profileId of current.staff) {
      const own = current.own[profileId] ?? NO_OWN;
      const ownDuration = own.duration.trim() === '' ? null : whole(own.duration, 5, 1440);
      const ownAfter = own.after.trim() === '' ? null : whole(own.after, 0, 240);
      if ((own.duration.trim() !== '' && ownDuration === null) || (own.after.trim() !== '' && ownAfter === null)) {
        setProblem(t('services.error.own', 'A person’s own length must be 5 to 1440 minutes, and their time free after 0 to 240.'));
        return;
      }
      let ownPrice: number | null = null;
      if (own.price.trim() !== '') {
        try {
          ownPrice = parseMoney(own.price, currency);
        } catch {
          setProblem(t('services.error.price', 'Enter the price as a number, such as 12.50.'));
          return;
        }
      }
      staff.push({ profile_id: profileId, duration_minutes: ownDuration, buffer_after_minutes: ownAfter, price_minor: ownPrice });
    }

    // WHIT-1445 (AP.26): the deposit, in the service's currency.
    let deposit: CalendarServiceRequest['deposit'] = { kind: 'none' };
    if (current.depositKind === 'fixed') {
      let amountMinor: number;
      try {
        amountMinor = parseMoney(current.depositAmount, currency);
      } catch {
        setProblem(t('services.error.deposit', 'Enter the deposit as a number, such as 5.00.'));
        return;
      }
      if (amountMinor <= 0 || (priceMinor !== null && amountMinor > priceMinor)) {
        setProblem(t('services.error.depositAmount', 'The deposit must be more than nothing and no more than the price.'));
        return;
      }
      deposit = { kind: 'fixed', amount_minor: amountMinor };
    } else if (current.depositKind === 'percent') {
      const percent = whole(current.depositPercent, 1, 100);
      if (percent === null) {
        setProblem(t('services.error.depositPercent', 'Enter a percentage from 1 to 100.'));
        return;
      }
      if (priceMinor === null) {
        setProblem(t('services.error.depositNoPrice', 'Give the service a price before asking for a percentage of it.'));
        return;
      }
      deposit = { kind: 'percent', percent };
    }

    const body: CalendarServiceRequest = {
      name: names,
      description: cleanLocalizedText(current.description),
      duration_minutes: duration,
      buffer_before_minutes: before,
      buffer_after_minutes: after,
      price_minor: priceMinor,
      currency,
      bookable_online: current.bookableOnline,
      needs_approval: current.needsApproval,
      resources: current.needs.map(needOf),
      active: current.active,
      staff,
      deposit,
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
  // WHIT-1439: any free one from each group, then each active room or piece of equipment.
  const needOptions = (keep: string): { value: string; label: string }[] => {
    const rooms = data?.rooms ?? [];
    const groups = [...new Set(rooms.filter((r) => r.active && r.group_key).map((r) => r.group_key as string))].sort();
    return [
      ...groups.map((group) => ({ value: `g:${group}`, label: t('services.needs.anyOf', 'Any free one from {group}', { group }) })),
      ...rooms.filter((r) => r.active || `r:${r.id}` === keep).map((r) => ({ value: `r:${r.id}`, label: words.label(r.name) })),
    ];
  };

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
                <ChangedField changed={!sameLocalizedText(current.name, saved.name)}>
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
                <ChangedField changed={!sameLocalizedText(current.description, saved.description)}>
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
                    currency={/^[A-Z]{3}$/.test(current.currency) ? current.currency : workspaceCurrency}
                    value={current.price}
                    onValueChange={(price) => update({ price })}
                    placeholder={toDecimalString(0, /^[A-Z]{3}$/.test(current.currency) ? current.currency : workspaceCurrency)}
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

            {/* WHIT-1445 (AP.26): what online bookings pay first. */}
            <Card data-testid="service-deposit">
              <CardHeader>
                <CardTitle>{t('services.field.deposit', 'Deposit')}</CardTitle>
                <CardDescription>
                  {t(
                    'services.card.depositHint',
                    'Online bookings pay it when they book. It’s kept after a late cancel or if they don’t come, counts toward the bill when they come, and is refunded only when someone presses Refund.'
                  )}
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-[14rem_1fr]">
                <ChangedField changed={current.depositKind !== saved.depositKind}>
                  <label htmlFor="service-deposit-kind" className="text-sm font-medium text-foreground">
                    {t('services.deposit.kind', 'Ask for')}
                  </label>
                  <FieldSelect
                    id="service-deposit-kind"
                    value={current.depositKind}
                    disabled={readOnly}
                    onChange={(v) => update({ depositKind: v === 'fixed' || v === 'percent' ? v : 'none' })}
                    options={[
                      { value: 'none', label: t('services.deposit.none', 'No deposit') },
                      { value: 'fixed', label: t('services.deposit.fixed', 'A fixed amount') },
                      { value: 'percent', label: t('services.deposit.percent', 'A percentage of the price') },
                    ]}
                  />
                </ChangedField>
                {current.depositKind === 'fixed' && (
                  <ChangedField changed={current.depositAmount !== saved.depositAmount}>
                    <label htmlFor="service-deposit-amount" className="text-sm font-medium text-foreground">
                      {t('services.deposit.amount', 'Amount')}
                    </label>
                    <MoneyInput
                      id="service-deposit-amount"
                      currency={/^[A-Z]{3}$/.test(current.currency) ? current.currency : workspaceCurrency}
                      value={current.depositAmount}
                      disabled={readOnly}
                      onValueChange={(depositAmount) => update({ depositAmount })}
                      placeholder={toDecimalString(0, /^[A-Z]{3}$/.test(current.currency) ? current.currency : workspaceCurrency)}
                    />
                  </ChangedField>
                )}
                {current.depositKind === 'percent' && (
                  <ChangedField changed={current.depositPercent !== saved.depositPercent}>
                    <Input
                      id="service-deposit-percent"
                      dir="ltr"
                      inputMode="numeric"
                      label={t('services.deposit.percentLabel', 'Percent of each person’s price')}
                      value={current.depositPercent}
                      disabled={readOnly}
                      onChange={(e) => update({ depositPercent: e.target.value.replace(/[^\d]/g, '').slice(0, 3) })}
                    />
                  </ChangedField>
                )}
                {current.depositKind !== 'none' && data.settings?.deposits_connected === false && (
                  <p className="m-0 text-sm text-warning-text sm:col-span-2" data-testid="service-deposit-no-provider">
                    {t(
                      'services.deposit.noProvider',
                      'Connect a payment provider to take deposits. Until then, online bookings of this service are requests you approve.'
                    )}
                  </p>
                )}
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
                <CardTitle>{t('services.field.needs', 'Rooms and equipment it needs')}</CardTitle>
                <CardDescription>
                  {t(
                    'services.card.needsHint',
                    'Booking this service also books each of these, so two people can’t use one at the same time. For a group, the first free one is taken; customers never choose.'
                  )}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <ChangedField changed={current.needs.join('|') !== saved.needs.join('|')}>
                  {current.needs.length === 0 ? (
                    <p className="text-sm text-muted-foreground" data-testid="service-needs-none">
                      {t('services.needs.none', 'Nothing: only the person is booked.')}
                    </p>
                  ) : (
                    <ul className="space-y-2" data-testid="service-needs">
                      {current.needs.map((value, index) => (
                        <li key={index} className="flex items-center gap-2">
                          <div className="min-w-0 flex-1">
                            <label htmlFor={`service-need-${index}`} className="sr-only">
                              {t('services.needs.label', 'Need {n}', { n: index + 1 })}
                            </label>
                            <FieldSelect
                              id={`service-need-${index}`}
                              value={value}
                              disabled={readOnly}
                              onChange={(next) => update({ needs: current.needs.map((v, i) => (i === index ? next : v)) })}
                              options={needOptions(value)}
                            />
                          </div>
                          {!readOnly && (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => update({ needs: current.needs.filter((_, i) => i !== index) })}
                              data-testid={`service-need-remove-${index}`}
                            >
                              {t('services.needs.remove', 'Remove')}
                            </Button>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </ChangedField>
                {!readOnly && data.rooms.some((room) => room.active) && current.needs.length < 8 && (
                  <Button
                    className="mt-3"
                    variant="outline"
                    size="sm"
                    onClick={() => update({ needs: [...current.needs, needOptions('')[0]?.value ?? ''] })}
                    data-testid="service-need-add"
                  >
                    {t('services.needs.add', 'Add a room or equipment')}
                  </Button>
                )}
                {data.rooms.length === 0 && (
                  <p className="mt-2 text-sm text-muted-foreground">
                    {t('services.needs.noRooms', 'No rooms or equipment yet. Add them under Rooms and equipment.')}
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('services.field.staff', 'Who provides it')}</CardTitle>
                <CardDescription>
                  {t(
                    'services.card.staffOwnHint',
                    'Only these people are offered for it. Each can have their own length, time free after and price; leave them empty to use the service’s. Add people to the calendar under Hours and time off.'
                  )}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {data.staff.length === 0 ? (
                  <p className="text-sm text-muted-foreground">{t('services.staff.noTeam', 'Nobody has a calendar yet.')}</p>
                ) : (
                  <ChangedField
                    changed={current.staff.join(',') !== saved.staff.join(',') || ownKey(current.own, current.staff) !== ownKey(saved.own, saved.staff)}
                  >
                    <ul className="divide-y divide-divider">
                      {data.staff.map((person) => {
                        const checked = current.staff.includes(person.profile_id);
                        const own = current.own[person.profile_id] ?? NO_OWN;
                        const setOwn = (patch: Partial<OwnValues>) =>
                          update({ own: { ...current.own, [person.profile_id]: { ...own, ...patch } } });
                        const money = /^[A-Z]{3}$/.test(current.currency) ? current.currency : workspaceCurrency;
                        return (
                          <li key={person.profile_id} className="py-1">
                            <div className="flex min-h-12 items-center gap-3">
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
                            </div>
                            {checked && (
                              <div className="grid gap-3 pb-3 ps-9 sm:grid-cols-3" data-testid={`service-own-${person.profile_id}`}>
                                <Input
                                  id={`service-own-duration-${person.profile_id}`}
                                  type="number"
                                  min={5}
                                  max={1440}
                                  label={t('services.own.duration', 'Their length, in minutes')}
                                  placeholder={current.duration}
                                  value={own.duration}
                                  onChange={(e) => setOwn({ duration: e.target.value })}
                                />
                                <Input
                                  id={`service-own-after-${person.profile_id}`}
                                  type="number"
                                  min={0}
                                  max={240}
                                  label={t('services.own.after', 'Their time free after')}
                                  placeholder={current.after}
                                  value={own.after}
                                  onChange={(e) => setOwn({ after: e.target.value })}
                                />
                                <div className="space-y-1">
                                  <label htmlFor={`service-own-price-${person.profile_id}`} className="text-sm font-medium text-foreground">
                                    {t('services.own.price', 'Their price')}
                                  </label>
                                  <MoneyInput
                                    id={`service-own-price-${person.profile_id}`}
                                    currency={money}
                                    value={own.price}
                                    onValueChange={(price) => setOwn({ price })}
                                    placeholder={current.price !== '' ? current.price : toDecimalString(0, money)}
                                  />
                                </div>
                              </div>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  </ChangedField>
                )}
              </CardContent>
            </Card>
          </fieldset>

          {/* WHIT-1443 (AP.20): the forms customers fill for this service. */}
          <ServiceFormsCard serviceId={id} canManage={!readOnly} />
          {/* WHIT-1444 (AP.22): its ready-made treatment plans. */}
          <PlanTemplatesCard serviceId={id} canManage={!readOnly} />

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
