'use client';

/**
 * Treatment plans (WHIT-1444, design section 39 §7.4, AP.22): a customer, the
 * person who treats them, and visits in order with a gap in days between them.
 *
 * - **On a booking:** "Laser, 6 sessions · visit 3 of 6", and Book the next
 *   visit when one is still to book.
 * - **On the customer record:** each plan with its visits (done, didn't come,
 *   booked, to book), Book the next visit (the first free time in the gap with
 *   the same person, which staff confirm), Book all (each one checked like any
 *   booking; the ones that can't be placed are said so), and New plan, from a
 *   service's ready-made plan or by hand.
 * - **On a service:** its ready-made plans.
 *
 * The customer's name and email are always the contact's own: a plan keeps no
 * copy. Each booking keeps what the customer was told, as every booking does.
 */

import { useState } from 'react';
import Link from 'next/link';
import { Input } from '@/components/ui/input';
import { FieldSelect } from '@/components/field-select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import {
  calendarApi,
  type CalendarBooking,
  type CalendarPlan,
  type CalendarPlanNext,
  type CalendarPlanStep,
  type CalendarPlanStepInput,
  type CalendarPlanTemplate,
} from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

const MAX_STEPS = 30;
const MAX_TEMPLATES = 10;

// ── on a booking ────────────────────────────────────────────────────────

export function BookingPlanSection({ booking, canChange }: { booking: CalendarBooking; canChange: boolean }) {
  const t = useTranslation('calendar');
  const { data, refetch } = useFetch(() => calendarApi.bookingPlan(booking.id), [booking.id]);
  if (data === null || data === undefined || typeof data.plan_id !== 'number') return null;

  return (
    <section className="space-y-2 rounded-card border border-border px-4 py-3" data-testid="booking-plan">
      <h3 className="m-0 text-sm font-semibold text-foreground">{t('plans.title', 'Treatment plan')}</h3>
      <p className="m-0 text-sm text-foreground">
        {data.title}
        {' · '}
        <span data-testid="booking-plan-step">{t('plans.visitOf', 'Visit {step} of {total}', { step: data.step, total: data.total })}</span>
      </p>
      <p className="m-0 text-sm text-muted-foreground">{t('plans.doneOf', '{done} of {total} done', { done: data.done, total: data.total })}</p>
      <div className="flex flex-wrap items-center gap-3">
        {canChange && data.status === 'active' && data.next_to_book && (
          <NextVisit planId={data.plan_id} staffName={booking.staff_name ?? ''} onBooked={refetch} />
        )}
        {typeof booking.contact_id === 'number' && (
          <Link href={`/contacts/${booking.contact_id}`} className="inline-block text-sm text-primary hover:underline">
            {t('plans.openCustomer', 'See the whole plan')}
          </Link>
        )}
      </div>
    </section>
  );
}

// ── Book the next visit ─────────────────────────────────────────────────

/** Finds the first free time in the gap, and books it when staff say so. */
function NextVisit({ planId, staffName, onBooked }: { planId: number; staffName: string; onBooked: () => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay();
  const { addToast } = useToast();
  const [next, setNext] = useState<CalendarPlanNext | null>(null);
  const [busy, setBusy] = useState(false);

  const find = async () => {
    setBusy(true);
    try {
      setNext(await calendarApi.planNext(planId));
    } catch (error) {
      addToast(words.errorText(error, t('plans.errorFind', 'The next time could not be found. Try again.')), 'error');
    } finally {
      setBusy(false);
    }
  };
  const book = async (startsAt: string) => {
    setBusy(true);
    try {
      await calendarApi.planBookNext(planId, startsAt);
      addToast(t('plans.booked', 'Booked for {when}', { when: dates.dateTime(startsAt) ?? '' }), 'success');
      setNext(null);
      onBooked();
    } catch (error) {
      addToast(words.errorText(error, t('plans.errorBook', 'The visit could not be booked. Try again.')), 'error');
      setNext(null);
    } finally {
      setBusy(false);
    }
  };

  if (next === null) {
    return (
      <Button variant="outline" size="sm" loading={busy} onClick={() => void find()} data-testid={`plan-next-${planId}`}>
        {t('plans.bookNext', 'Book the next visit')}
      </Button>
    );
  }
  if (next.starts_at === null || next.starts_at === undefined) {
    return (
      <div className="space-y-2" data-testid={`plan-next-none-${planId}`}>
        <p className="m-0 text-sm text-foreground">
          {t('plans.noTime', '{name} has no free time between {from} and {to}. Book it from the calendar.', {
            name: staffName,
            from: dates.date(next.window?.from ?? null) ?? '',
            to: dates.date(next.window?.to ?? null) ?? '',
          })}
        </p>
        <Button variant="ghost" size="sm" onClick={() => setNext(null)}>
          {t('plans.notNow', 'Not now')}
        </Button>
      </div>
    );
  }
  const startsAt = next.starts_at;

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-card bg-muted px-3 py-2" data-testid={`plan-next-found-${planId}`}>
      <span className="min-w-0 flex-1 text-sm text-foreground">
        {t('plans.firstFree', 'First free time: {when} with {name}', { when: dates.dateTime(startsAt) ?? '', name: staffName })}
      </span>
      <Button variant="outline" size="sm" loading={busy} onClick={() => void book(startsAt)} data-testid={`plan-next-book-${planId}`}>
        {t('plans.bookIt', 'Book it')}
      </Button>
      <Button variant="ghost" size="sm" disabled={busy} onClick={() => setNext(null)}>
        {t('plans.notNow', 'Not now')}
      </Button>
    </div>
  );
}

// ── on the customer record ──────────────────────────────────────────────

export function TreatmentPlansCard({ contactId, canChange }: { contactId: number; canChange: boolean }) {
  const t = useTranslation('calendar');
  const { data, forbidden, error, refetch } = useFetch(() => calendarApi.plans(contactId), [contactId]);
  const [adding, setAdding] = useState(false);
  if (forbidden || error !== null) return null;
  if (data === null || data === undefined) return <div className="h-24 animate-pulse rounded-lg bg-muted" aria-busy="true" />;

  return (
    <section className="space-y-4 rounded-lg border border-border p-5" data-testid="treatment-plans">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="m-0 text-sm font-semibold text-muted-foreground">{t('plans.titleMany', 'Treatment plans')}</h2>
        {canChange && (
          <Button variant="outline" size="sm" onClick={() => setAdding(true)} data-testid="plan-new">
            {t('plans.new', 'New plan')}
          </Button>
        )}
      </div>
      {data.length === 0 ? (
        <p className="m-0 text-sm text-muted-foreground">{t('plans.none', 'No treatment plans yet. A plan books a series of visits with the same person, a set number of days apart.')}</p>
      ) : (
        <ul className="m-0 grid gap-4 p-0">
          {data.map((plan) => (
            <PlanItem key={plan.id} plan={plan} canChange={canChange} onChanged={refetch} />
          ))}
        </ul>
      )}
      {adding && (
        <NewPlanDialog
          contactId={contactId}
          onClose={() => setAdding(false)}
          onMade={() => {
            setAdding(false);
            refetch();
          }}
        />
      )}
    </section>
  );
}

function PlanItem({ plan, canChange, onChanged }: { plan: CalendarPlan; canChange: boolean; onChanged: () => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay();
  const { addToast } = useToast();
  const [busy, setBusy] = useState(false);
  const active = plan.status === 'active';
  const toBook = plan.steps.filter((s) => s.state === 'to_book').length;

  const bookAll = async () => {
    setBusy(true);
    try {
      const result = await calendarApi.planBookAll(plan.id);
      const booked = result.steps.filter((s) => s.booked).length;
      const left = result.steps.length - booked;
      addToast(
        left === 0
          ? t('plans.bookedAll', '{count} visits booked', { count: booked })
          : t('plans.bookedSome', '{count} visits booked. {left} could not be placed: book them from the calendar.', { count: booked, left }),
        left === 0 ? 'success' : 'warning'
      );
      onChanged();
    } catch (error) {
      addToast(words.errorText(error, t('plans.errorBookAll', 'The visits could not be booked. Try again.')), 'error');
    } finally {
      setBusy(false);
    }
  };
  const setStatus = async (status: 'active' | 'done' | 'stopped') => {
    setBusy(true);
    try {
      await calendarApi.setPlanStatus(plan.id, status);
      onChanged();
    } catch (error) {
      addToast(words.errorText(error, t('plans.errorStatus', 'The plan could not be changed. Try again.')), 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="space-y-3 rounded-card border border-border px-4 py-3" data-testid={`plan-${plan.id}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="min-w-0 flex-1 text-sm font-medium text-foreground">{plan.title}</span>
        <span className="text-sm text-muted-foreground" data-testid={`plan-progress-${plan.id}`}>
          {t('plans.doneOf', '{done} of {total} done', { done: plan.progress.done, total: plan.progress.total })}
        </span>
        {!active && <Badge variant="outline">{plan.status === 'done' ? t('plans.statusDone', 'Finished') : t('plans.statusStopped', 'Stopped')}</Badge>}
      </div>
      <p className="m-0 flex items-center gap-2 text-sm text-muted-foreground">
        <Avatar name={plan.staff.name} pattern={String(plan.staff.profile_id)} size={20} />
        {t('plans.with', 'With {name}', { name: plan.staff.name })}
      </p>
      <ol className="m-0 grid gap-1 p-0">
        {plan.steps.map((step, i) => (
          <li key={step.id} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="w-6 shrink-0 font-mono text-muted-foreground" dir="ltr">
              {i + 1}
            </span>
            <span className="min-w-0 flex-1 text-foreground">
              {words.label(step.service_name)}
              <span className="block text-muted-foreground">
                {step.booking ? dates.dateTime(step.booking.starts_at) : gapText(t, step, i)}
              </span>
            </span>
            <StepBadge step={step} />
          </li>
        ))}
      </ol>
      {canChange && (
        <div className="flex flex-wrap items-center gap-2">
          {active && toBook > 0 && <NextVisit planId={plan.id} staffName={plan.staff.name} onBooked={onChanged} />}
          {active && toBook > 1 && (
            <Button variant="outline" size="sm" loading={busy} onClick={() => void bookAll()} data-testid={`plan-book-all-${plan.id}`}>
              {t('plans.bookAll', 'Book all {count}', { count: toBook })}
            </Button>
          )}
          {active ? (
            <Button variant="ghost" size="sm" disabled={busy} onClick={() => void setStatus('stopped')} data-testid={`plan-stop-${plan.id}`}>
              {t('plans.stop', 'Stop the plan')}
            </Button>
          ) : (
            <Button variant="ghost" size="sm" disabled={busy} onClick={() => void setStatus('active')}>
              {t('plans.restart', 'Carry on with the plan')}
            </Button>
          )}
        </div>
      )}
    </li>
  );
}

function StepBadge({ step }: { step: CalendarPlanStep }) {
  const t = useTranslation('calendar');
  switch (step.state) {
    case 'done':
      return <Badge variant="success">{t('plans.stepDone', 'Done')}</Badge>;
    case 'missed':
      return <Badge variant="warning">{t('plans.stepMissed', 'Didn’t come')}</Badge>;
    case 'booked':
      return <Badge variant="secondary">{t('plans.stepBooked', 'Booked')}</Badge>;
    default:
      return <Badge variant="outline">{t('plans.stepToBook', 'To book')}</Badge>;
  }
}

type T = ReturnType<typeof useTranslation>;

function gapText(t: T, step: { gap_min_days?: number; gap_max_days?: number }, index: number): string {
  const min = step.gap_min_days ?? 0;
  const max = step.gap_max_days ?? min;
  const days = min === max ? String(min) : `${min}–${max}`;
  return index === 0
    ? min === 0 && max === 0
      ? t('plans.gapFirstNow', 'From today')
      : t('plans.gapFirst', '{days} days from today', { days })
    : t('plans.gap', '{days} days after the visit before', { days });
}

// ── New plan ────────────────────────────────────────────────────────────

function NewPlanDialog({ contactId, onClose, onMade }: { contactId: number; onClose: () => void; onMade: () => void }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const { data: basics } = useFetch(async () => {
    const [services, staff] = await Promise.all([calendarApi.services(), calendarApi.staff()]);
    return { services, staff };
  }, []);
  const [serviceId, setServiceId] = useState<number | null>(null);
  const { data: templates } = useFetch(
    async () => (serviceId === null ? ([] as CalendarPlanTemplate[]) : calendarApi.planTemplates(serviceId).catch(() => [] as CalendarPlanTemplate[])),
    [serviceId]
  );
  const [from, setFrom] = useState<string>('own');
  const [profileId, setProfileId] = useState<number | null>(null);
  const [title, setTitle] = useState('');
  const [steps, setSteps] = useState<CalendarPlanStepInput[]>([]);
  const [saving, setSaving] = useState(false);

  const services = basics?.services ?? [];
  const service = services.find((s) => s.id === serviceId) ?? null;
  const staff = (basics?.staff ?? []).filter((p) => service === null || service.staff_profile_ids.includes(p.profile_id));
  const template = (templates ?? []).find((tp) => String(tp.id) === from) ?? null;
  const ready = serviceId !== null && profileId !== null && (template !== null || (title.trim() !== '' && steps.length > 0));

  const chooseService = (id: number) => {
    setServiceId(id);
    setFrom('own');
    setProfileId(null);
    const chosen = services.find((s) => s.id === id);
    setTitle(chosen ? words.label(chosen.name) : '');
    setSteps([{ service_id: id, gap_min_days: 0, gap_max_days: 7 }]);
  };
  const make = async () => {
    if (!ready || profileId === null) return;
    setSaving(true);
    try {
      await calendarApi.createPlan(
        template !== null
          ? { contact_id: contactId, profile_id: profileId, template_id: template.id }
          : { contact_id: contactId, profile_id: profileId, title: title.trim(), steps }
      );
      addToast(t('plans.made', 'Plan made. Book the visits when you’re ready.'), 'success');
      onMade();
    } catch (error) {
      addToast(words.errorText(error, t('plans.errorMake', 'The plan could not be made. Try again.')), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-xl" data-testid="plan-new-dialog">
        <DialogHeader>
          <DialogTitle>{t('plans.new', 'New plan')}</DialogTitle>
          <DialogDescription>{t('plans.newBody', 'Every visit is booked with the same person. Nothing is booked until you say so.')}</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-1">
            <label htmlFor="plan-service" className="text-sm font-medium">
              {t('plans.service', 'Service')}
            </label>
            <FieldSelect
              id="plan-service"
              value={serviceId === null ? '' : String(serviceId)}
              placeholder={t('plans.chooseService', 'Choose a service')}
              onChange={(v) => chooseService(Number(v))}
              options={services.map((s) => ({ value: String(s.id), label: words.label(s.name) }))}
            />
          </div>
          {service !== null && (
            <>
              <div className="grid gap-1">
                <label htmlFor="plan-staff" className="text-sm font-medium">
                  {t('plans.staff', 'Who treats them')}
                </label>
                <FieldSelect
                  id="plan-staff"
                  value={profileId === null ? '' : String(profileId)}
                  placeholder={t('plans.chooseStaff', 'Choose a person')}
                  onChange={(v) => setProfileId(Number(v))}
                  options={staff.map((p) => ({ value: String(p.profile_id), label: p.display_name }))}
                />
              </div>
              <div className="grid gap-1">
                <label htmlFor="plan-from" className="text-sm font-medium">
                  {t('plans.from', 'Plan')}
                </label>
                <FieldSelect
                  id="plan-from"
                  value={from}
                  onChange={setFrom}
                  options={[
                    ...(templates ?? []).map((tp) => ({ value: String(tp.id), label: t('plans.fromTemplate', '{title} ({count} visits)', { title: tp.title, count: tp.steps.length }) })),
                    { value: 'own', label: t('plans.fromOwn', 'Make my own') },
                  ]}
                />
              </div>
              {template === null && (
                <>
                  <div className="grid gap-1">
                    <label htmlFor="plan-title" className="text-sm font-medium">
                      {t('plans.planTitle', 'Name of the plan')}
                    </label>
                    <Input id="plan-title" value={title} maxLength={200} onChange={(e) => setTitle(e.target.value)} />
                  </div>
                  <StepsEditor steps={steps} services={services} onChange={setSteps} />
                </>
              )}
            </>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('plans.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void make()} disabled={!ready} loading={saving} data-testid="plan-make">
            {t('plans.make', 'Make the plan')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** The visits of a plan or a ready-made plan: a service and its gap in days. */
function StepsEditor({
  steps,
  services,
  onChange,
  disabled = false,
  idPrefix = 'plan-step',
}: {
  steps: CalendarPlanStepInput[];
  services: { id: number; name: Record<string, string> }[];
  onChange: (steps: CalendarPlanStepInput[]) => void;
  disabled?: boolean;
  idPrefix?: string;
}) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const change = (i: number, patch: Partial<CalendarPlanStepInput>) => onChange(steps.map((s, j) => (j === i ? { ...s, ...patch } : s)));
  const days = (value: string) => Math.max(0, Math.min(365, Number.parseInt(value, 10) || 0));

  return (
    <fieldset className="m-0 grid gap-2 border-0 p-0">
      <legend className="mb-1 text-sm font-medium">{t('plans.visits', 'Visits')}</legend>
      <ol className="m-0 grid gap-2 p-0">
        {steps.map((step, i) => (
          <li key={i} className="grid gap-2 rounded-card border border-border px-3 py-2 sm:grid-cols-[1.5rem_1fr_auto_auto_auto] sm:items-center">
            <span className="font-mono text-sm text-muted-foreground" dir="ltr">
              {i + 1}
            </span>
            <FieldSelect
              id={`${idPrefix}-${i}-service`}
              value={String(step.service_id)}
              disabled={disabled}
              onChange={(v) => change(i, { service_id: Number(v) })}
              options={services.map((s) => ({ value: String(s.id), label: words.label(s.name) }))}
            />
            <label className="flex items-center gap-2 text-sm">
              <span className="text-muted-foreground">{i === 0 ? t('plans.withinFirst', 'From day') : t('plans.after', 'After')}</span>
              <Input
                className="w-20"
                inputMode="numeric"
                dir="ltr"
                aria-label={t('plans.gapMin', 'Least days')}
                value={String(step.gap_min_days ?? 0)}
                disabled={disabled}
                onChange={(e) => {
                  const min = days(e.target.value);
                  change(i, { gap_min_days: min, gap_max_days: Math.max(min, step.gap_max_days ?? min) });
                }}
              />
            </label>
            <label className="flex items-center gap-2 text-sm">
              <span className="text-muted-foreground">{t('plans.to', 'to')}</span>
              <Input
                className="w-20"
                inputMode="numeric"
                dir="ltr"
                aria-label={t('plans.gapMax', 'Most days')}
                value={String(step.gap_max_days ?? step.gap_min_days ?? 0)}
                disabled={disabled}
                onChange={(e) => change(i, { gap_max_days: days(e.target.value) })}
              />
              <span className="text-muted-foreground">{t('plans.days', 'days')}</span>
            </label>
            {!disabled && steps.length > 1 ? (
              <Button variant="ghost" size="sm" onClick={() => onChange(steps.filter((_, j) => j !== i))}>
                {t('plans.removeVisit', 'Remove')}
              </Button>
            ) : (
              <span />
            )}
          </li>
        ))}
      </ol>
      {!disabled && steps.length > 0 && steps.length < MAX_STEPS && (
        <div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              const last = steps[steps.length - 1];
              onChange([...steps, { service_id: last.service_id, gap_min_days: Math.max(1, last.gap_min_days ?? 0), gap_max_days: Math.max(1, last.gap_max_days ?? 0) }]);
            }}
          >
            {t('plans.addVisit', 'Add a visit')}
          </Button>
        </div>
      )}
    </fieldset>
  );
}

// ── on a service ────────────────────────────────────────────────────────

interface Draft {
  title: string;
  steps: CalendarPlanStepInput[];
}

export function PlanTemplatesCard({ serviceId, canManage }: { serviceId: number; canManage: boolean }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const { data, refetch } = useFetch(async () => {
    const [templates, services] = await Promise.all([calendarApi.planTemplates(serviceId), calendarApi.services()]);
    return { templates, services };
  }, [serviceId]);
  const [drafts, setDrafts] = useState<Draft[] | null>(null);
  const [saving, setSaving] = useState(false);

  if (data === null || data === undefined) return null;
  const saved: Draft[] = data.templates.map((tp) => ({ title: tp.title, steps: tp.steps }));
  const current = drafts ?? saved;
  const dirty = JSON.stringify(current) !== JSON.stringify(saved);
  const valid = current.every((d) => d.title.trim() !== '' && d.steps.length > 0);
  const change = (i: number, patch: Partial<Draft>) => setDrafts(current.map((d, j) => (j === i ? { ...d, ...patch } : d)));

  const save = async () => {
    setSaving(true);
    try {
      await calendarApi.savePlanTemplates(
        serviceId,
        current.map((d) => ({ title: d.title.trim(), steps: d.steps }))
      );
      setDrafts(null);
      addToast(t('planTemplates.saved', 'Ready-made plans saved'), 'success');
      refetch();
    } catch (error) {
      addToast(words.errorText(error, t('planTemplates.error', 'The ready-made plans could not be saved. Try again.')), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card data-testid="plan-templates">
      <CardHeader>
        <CardTitle>{t('planTemplates.title', 'Ready-made plans')}</CardTitle>
        <CardDescription>
          {t('planTemplates.hint', 'A series of visits a customer often needs, such as six sessions four weeks apart. Staff start a plan from one on the customer’s record.')}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {current.length === 0 ? (
          <p className="m-0 text-sm text-muted-foreground">{t('planTemplates.none', 'No ready-made plans for this service.')}</p>
        ) : (
          <ul className="m-0 grid gap-4 p-0">
            {current.map((draft, i) => (
              <li key={i} className="space-y-3 rounded-card border border-border px-3 py-3" data-testid={`plan-template-${i}`}>
                <div className="flex flex-wrap items-center gap-2">
                  <Input
                    className="min-w-0 flex-1"
                    aria-label={t('plans.planTitle', 'Name of the plan')}
                    value={draft.title}
                    maxLength={200}
                    disabled={!canManage}
                    onChange={(e) => change(i, { title: e.target.value })}
                  />
                  {canManage && (
                    <Button variant="ghost" size="sm" onClick={() => setDrafts(current.filter((_, j) => j !== i))}>
                      {t('planTemplates.remove', 'Remove plan')}
                    </Button>
                  )}
                </div>
                <StepsEditor steps={draft.steps} services={data.services} disabled={!canManage} idPrefix={`plan-template-${i}`} onChange={(steps) => change(i, { steps })} />
              </li>
            ))}
          </ul>
        )}
        {canManage && (
          <div className="flex flex-wrap items-center gap-2">
            {current.length < MAX_TEMPLATES && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setDrafts([...current, { title: '', steps: [{ service_id: serviceId, gap_min_days: 0, gap_max_days: 7 }] }])}
                data-testid="plan-template-add"
              >
                {t('planTemplates.add', 'Add a ready-made plan')}
              </Button>
            )}
            {dirty && (
              <Button variant="outline" onClick={() => void save()} disabled={!valid} loading={saving} data-testid="plan-templates-save">
                {t('planTemplates.save', 'Save ready-made plans')}
              </Button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
