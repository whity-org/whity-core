'use client';

/**
 * A service's intake forms (WHIT-1443, design section 39 §7.2, AP.20), on the
 * service's page: which forms customers fill, on booking or before the visit,
 * for their first visit or every one, and whether the desk shows a missing
 * one. Saved on its own, with its own button, since it is a separate list.
 */

import { useState } from 'react';
import { FieldSelect } from '@/components/field-select';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { api } from '@/lib/api/client';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Switch } from '@whity/ui/switch';
import { useTranslation } from '@whity/features/i18n';
import { calendarApi, type CalendarServiceForm } from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

interface Row {
  form_id: number;
  fill_when: 'on_booking' | 'before_visit';
  who: 'new' | 'every';
  required: boolean;
}

type FormChoice = { id: number; name: Record<string, string>; public_enabled?: boolean; status?: string };

export function ServiceFormsCard({ serviceId, canManage }: { serviceId: number; canManage: boolean }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const { addToast } = useToast();
  const { data, refetch } = useFetch(async () => {
    const [linked, all] = await Promise.all([
      calendarApi.serviceForms(serviceId),
      api.GET('/api/v1/forms', { params: { query: { status: 'published' } } }).then(({ data: body }) => ((body as { data?: FormChoice[] } | undefined)?.data ?? []) as FormChoice[]).catch(() => [] as FormChoice[]),
    ]);
    return { linked, all };
  }, [serviceId]);
  const [rows, setRows] = useState<Row[] | null>(null);
  const [saving, setSaving] = useState(false);

  if (data === null || data === undefined) return null;
  const saved: Row[] = data.linked.map((f: CalendarServiceForm) => ({ form_id: f.form_id, fill_when: f.fill_when, who: f.who, required: f.required }));
  const current = rows ?? saved;
  const nameOf = (id: number) => {
    const form = data.all.find((f) => f.id === id);
    const linked = data.linked.find((f) => f.form_id === id);
    return form ? words.label(form.name) : linked ? words.label(linked.names ?? { en: linked.name }) : '';
  };
  const isPublic = (id: number) => data.all.find((f) => f.id === id)?.public_enabled ?? data.linked.find((f) => f.form_id === id)?.public ?? false;
  const free = data.all.filter((f) => !current.some((r) => r.form_id === f.id));
  const change = (i: number, patch: Partial<Row>) => setRows(current.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const dirty = JSON.stringify(current) !== JSON.stringify(saved);

  const save = async () => {
    setSaving(true);
    try {
      await calendarApi.saveServiceForms(serviceId, current);
      setRows(null);
      addToast(t('serviceForms.saved', 'Forms saved'), 'success');
      refetch();
    } catch (error) {
      addToast(words.errorText(error, t('serviceForms.error', 'The forms could not be saved. Try again.')), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card data-testid="service-forms">
      <CardHeader>
        <CardTitle>{t('serviceForms.title', 'Forms to fill')}</CardTitle>
        <CardDescription>
          {t(
            'serviceForms.hint',
            'Customers get a link to each form in their booking email. The desk sees whether it’s done, and a missing form never stops check-in. A form needs its public link turned on under Forms.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {current.length === 0 ? (
          <p className="m-0 text-sm text-muted-foreground">{t('serviceForms.none', 'No forms: customers fill nothing in.')}</p>
        ) : (
          <ul className="m-0 grid gap-3 p-0">
            {current.map((row, i) => (
              <li key={row.form_id} className="grid gap-2 rounded-card border border-border px-3 py-2 sm:grid-cols-[1fr_auto_auto_auto_auto] sm:items-center">
                <span className="text-sm font-medium text-foreground">
                  {nameOf(row.form_id)}
                  {!isPublic(row.form_id) && <span className="block text-sm font-normal text-warning-text">{t('serviceForms.notPublic', 'Its public link is off, so customers can’t open it.')}</span>}
                </span>
                <div className="w-44">
                  <FieldSelect
                    id={`service-form-when-${row.form_id}`}
                    value={row.fill_when}
                    disabled={!canManage}
                    onChange={(v) => change(i, { fill_when: v === 'on_booking' ? 'on_booking' : 'before_visit' })}
                    options={[
                      { value: 'on_booking', label: t('serviceForms.onBooking', 'On booking') },
                      { value: 'before_visit', label: t('serviceForms.beforeVisit', 'Before the visit') },
                    ]}
                  />
                </div>
                <div className="w-44">
                  <FieldSelect
                    id={`service-form-who-${row.form_id}`}
                    value={row.who}
                    disabled={!canManage}
                    onChange={(v) => change(i, { who: v === 'new' ? 'new' : 'every' })}
                    options={[
                      { value: 'every', label: t('serviceForms.every', 'Every visit') },
                      { value: 'new', label: t('serviceForms.new', 'First visit only') },
                    ]}
                  />
                </div>
                <label className="flex min-h-10 items-center gap-2 text-sm">
                  <Switch checked={row.required} disabled={!canManage} onCheckedChange={(on) => change(i, { required: on })} aria-label={t('serviceForms.required', 'Required')} />
                  {t('serviceForms.required', 'Required')}
                </label>
                {canManage && (
                  <Button variant="ghost" size="sm" onClick={() => setRows(current.filter((_, j) => j !== i))}>
                    {t('serviceForms.remove', 'Remove')}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
        {canManage && (
          <div className="flex flex-wrap items-center gap-2">
            {free.length > 0 && current.length < 8 && (
              <div className="w-64">
                <FieldSelect
                  id="service-form-add"
                  value=""
                  placeholder={t('serviceForms.add', 'Add a form')}
                  onChange={(v) => setRows([...current, { form_id: Number(v), fill_when: 'before_visit', who: 'every', required: true }])}
                  options={free.map((f) => ({ value: String(f.id), label: words.label(f.name) }))}
                />
              </div>
            )}
            {dirty && (
              <Button variant="outline" onClick={() => void save()} loading={saving} data-testid="service-forms-save">
                {t('serviceForms.save', 'Save forms')}
              </Button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
