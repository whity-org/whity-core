'use client';

/**
 * /calendar/settings (WHIT-944): how booking works in this workspace, for
 * `calendar:manage`. The booking page on or off, the least notice, how far
 * ahead, how often free times are offered, the reminder, how long a payment
 * step holds a time, and the week's layout (first day and weekend, decision
 * register: Saturday, and Friday and Saturday, by default).
 *
 * The time zone is shown, not edited: it is the workspace's own setting, and
 * the API changes it in Settings. What changing it does is said here, because
 * it is the one surprise (ADR 0017 section 2): working hours move with it,
 * bookings already made keep their time.
 */

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { AdminHeader } from '@/components/admin/admin-header';
import { Input } from '@/components/ui/input';
import { FieldSelect } from '@/components/field-select';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { CalendarNav } from '@/components/calendar/calendar-nav';
import { useCalendarAccess } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { SaveBar, ChangedField } from '@whity/ui/save-bar';
import { Skeleton } from '@whity/ui/skeleton';
import { Switch } from '@whity/ui/switch';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import { calendarApi, type CalendarSettings, type CalendarSettingsRequest } from '@/lib/calendar/api';
import { WEEKDAY_NAMES, isoWeekdayOf } from '@/lib/calendar/layout';
import { weekOrder } from '@/lib/calendar/hours';

interface Draft {
  bookingPage: boolean;
  minNotice: string;
  maxAdvance: string;
  slotStep: string;
  reminder: string;
  hold: string;
  weekStart: string;
  weekend: string[];
}

function draftOf(s: CalendarSettings): Draft {
  return {
    bookingPage: s.booking_page_enabled,
    minNotice: String(s.min_notice_minutes),
    maxAdvance: String(s.max_advance_days),
    slotStep: String(s.slot_step_minutes),
    reminder: String(s.reminder_hours_before),
    hold: String(s.payment_hold_minutes),
    weekStart: s.week_start,
    weekend: [...s.weekend_days].sort(),
  };
}

const STEPS = ['5', '10', '15', '20', '30', '45', '60', '90', '120'];

export default function CalendarSettingsPage() {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const access = useCalendarAccess();
  const { addToast } = useToast();
  const { data, error, forbidden, refetch } = useFetch(() => calendarApi.settings(), []);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const saved = useMemo(() => (data ? draftOf(data) : null), [data]);
  const current = draft ?? saved;

  if (!access.loading && !access.canManage) {
    return <NoAccess area={t('settings.area', 'calendar settings')} permission="calendar:manage" />;
  }

  const update = (patch: Partial<Draft>) => {
    if (current === null) return;
    setDraft({ ...current, ...patch });
    setProblem(null);
  };

  const changes: string[] = [];
  if (saved && current) {
    if (current.bookingPage !== saved.bookingPage) changes.push(t('settings.bookingPage', 'Booking page'));
    if (current.minNotice !== saved.minNotice) changes.push(t('settings.minNotice', 'Least notice'));
    if (current.maxAdvance !== saved.maxAdvance) changes.push(t('settings.maxAdvance', 'How far ahead'));
    if (current.slotStep !== saved.slotStep) changes.push(t('settings.slotStep', 'Free times every'));
    if (current.reminder !== saved.reminder) changes.push(t('settings.reminder', 'Reminder'));
    if (current.hold !== saved.hold) changes.push(t('settings.hold', 'Payment hold'));
    if (current.weekStart !== saved.weekStart) changes.push(t('settings.weekStart', 'First day of the week'));
    if (current.weekend.join(',') !== saved.weekend.join(',')) changes.push(t('settings.weekend', 'Weekend'));
  }

  const save = async () => {
    if (current === null) return;
    const whole = (text: string, min: number, max: number) => {
      const n = Number(text);
      return Number.isInteger(n) && n >= min && n <= max ? n : null;
    };
    const minNotice = whole(current.minNotice, 0, 43200);
    const maxAdvance = whole(current.maxAdvance, 1, 730);
    const reminder = whole(current.reminder, 0, 168);
    const hold = whole(current.hold, 1, 1440);
    if (minNotice === null) return setProblem(t('settings.error.minNotice', 'Least notice must be from 0 to 43200 minutes (30 days).'));
    if (maxAdvance === null) return setProblem(t('settings.error.maxAdvance', 'How far ahead must be from 1 to 730 days.'));
    if (reminder === null) return setProblem(t('settings.error.reminder', 'The reminder must be from 0 to 168 hours before.'));
    if (hold === null) return setProblem(t('settings.error.hold', 'The payment hold must be from 1 to 1440 minutes.'));

    const body: CalendarSettingsRequest = {
      booking_page_enabled: current.bookingPage,
      min_notice_minutes: minNotice,
      max_advance_days: maxAdvance,
      slot_step_minutes: Number(current.slotStep),
      reminder_hours_before: reminder,
      payment_hold_minutes: hold,
      week_start: current.weekStart as CalendarSettingsRequest['week_start'],
      weekend_days: current.weekend as CalendarSettingsRequest['weekend_days'],
    };
    setSaving(true);
    setProblem(null);
    try {
      await calendarApi.saveSettings(body);
      setDraft(null);
      addToast(t('settings.saved', 'Calendar settings saved'), 'success');
      refetch();
    } catch (err) {
      setProblem(words.errorText(err, t('settings.error.save', 'The settings could not be saved. Try again.')));
    } finally {
      setSaving(false);
    }
  };

  // Weekday choices in the week's own order, from its first day.
  const ordered = current ? weekOrder(isoWeekdayOf(current.weekStart)) : [6, 7, 1, 2, 3, 4, 5];

  return (
    <div className="space-y-4 pb-16">
      <AdminHeader
        title={t('settings.title', 'Calendar settings')}
        description={t('settings.description', 'How bookings work in this workspace, and how its week is laid out.')}
      />
      <CalendarNav active="settings" />

      {error ? (
        <LoadProblem
          forbidden={forbidden}
          message={t('settings.error.load', 'The calendar settings could not be loaded.')}
          onRetry={refetch}
          area={t('settings.area', 'calendar settings')}
        />
      ) : current === null || saved === null || data === null ? (
        <div role="status" aria-busy="true" aria-label={t('loading.settings', 'Loading the settings')} className="space-y-3">
          <Skeleton className="h-40 w-full rounded-card" />
          <Skeleton className="h-40 w-full rounded-card" />
        </div>
      ) : (
        <>
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.card.booking', 'Booking')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-5">
              <ChangedField changed={current.bookingPage !== saved.bookingPage}>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('settings.bookingPage', 'Booking page')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('settings.bookingPageHint', 'Lets customers book services marked “online” themselves, on this workspace’s booking page.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.bookingPage}
                    onCheckedChange={(bookingPage) => update({ bookingPage })}
                    aria-label={t('settings.bookingPage', 'Booking page')}
                    data-testid="settings-booking-page"
                  />
                </label>
              </ChangedField>

              <div className="grid gap-4 sm:grid-cols-2">
                <ChangedField changed={current.minNotice !== saved.minNotice}>
                  <Input
                    id="settings-min-notice"
                    type="number"
                    min={0}
                    max={43200}
                    label={t('settings.minNoticeLabel', 'Least notice, in minutes')}
                    helperText={t('settings.minNoticeHint', 'How soon before it starts a time can still be booked. 120 is two hours.')}
                    value={current.minNotice}
                    onChange={(e) => update({ minNotice: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.maxAdvance !== saved.maxAdvance}>
                  <Input
                    id="settings-max-advance"
                    type="number"
                    min={1}
                    max={730}
                    label={t('settings.maxAdvanceLabel', 'How far ahead, in days')}
                    helperText={t('settings.maxAdvanceHint', 'The furthest ahead a booking can be made.')}
                    value={current.maxAdvance}
                    onChange={(e) => update({ maxAdvance: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.slotStep !== saved.slotStep}>
                  <label htmlFor="settings-slot-step" className="text-sm font-medium text-foreground">
                    {t('settings.slotStep', 'Free times every')}
                  </label>
                  <FieldSelect
                    id="settings-slot-step"
                    value={current.slotStep}
                    onChange={(slotStep) => update({ slotStep })}
                    options={STEPS.map((n) => ({ value: n, label: words.minutes(Number(n)) }))}
                  />
                  <p className="text-sm text-muted-foreground">{t('settings.slotStepHint', 'With 15 minutes, free times start at 09:00, 09:15, 09:30 and so on.')}</p>
                </ChangedField>
                <ChangedField changed={current.reminder !== saved.reminder}>
                  <Input
                    id="settings-reminder"
                    type="number"
                    min={0}
                    max={168}
                    label={t('settings.reminderLabel', 'Reminder, hours before')}
                    helperText={t('settings.reminderHint', 'One email to the customer. 0 sends none.')}
                    value={current.reminder}
                    onChange={(e) => update({ reminder: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.hold !== saved.hold}>
                  <Input
                    id="settings-hold"
                    type="number"
                    min={1}
                    max={1440}
                    label={t('settings.holdLabel', 'Payment hold, in minutes')}
                    helperText={t('settings.holdHint', 'When paying is set up, how long a time is kept for a customer who is paying.')}
                    value={current.hold}
                    onChange={(e) => update({ hold: e.target.value })}
                  />
                </ChangedField>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('settings.card.week', 'Week')}</CardTitle>
              <CardDescription>{t('settings.card.weekHint', 'How calendars in this workspace lay out a week.')}</CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <ChangedField changed={current.weekStart !== saved.weekStart}>
                <label htmlFor="settings-week-start" className="text-sm font-medium text-foreground">
                  {t('settings.weekStart', 'First day of the week')}
                </label>
                <div className="sm:w-64">
                  <FieldSelect
                    id="settings-week-start"
                    value={current.weekStart}
                    onChange={(weekStart) => update({ weekStart })}
                    options={WEEKDAY_NAMES.map((name, i) => ({ value: name, label: words.weekday(i + 1) }))}
                  />
                </div>
              </ChangedField>
              <ChangedField changed={current.weekend.join(',') !== saved.weekend.join(',')}>
                <span className="text-sm font-medium text-foreground" id="settings-weekend-label">
                  {t('settings.weekend', 'Weekend')}
                </span>
                <ToggleGroup
                  type="multiple"
                  label={t('settings.weekend', 'Weekend')}
                  className="flex-wrap"
                  value={current.weekend}
                  onChange={(value) => update({ weekend: (Array.isArray(value) ? value : []).slice().sort() })}
                  options={ordered.map((iso) => ({ value: WEEKDAY_NAMES[iso - 1], label: words.weekday(iso) }))}
                />
                <p className="text-sm text-muted-foreground">{t('settings.weekendHint', 'Weekend days are shaded on the calendar. They can still have working hours.')}</p>
              </ChangedField>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('settings.card.zone', 'Time zone')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p className="font-medium text-foreground" dir="ltr" data-testid="settings-zone">
                {data.time_zone}
              </p>
              <p className="text-muted-foreground">
                {t(
                  'settings.zoneHint',
                  'Every time in the calendar is on this clock. It is the workspace’s time zone, changed in Settings. Changing it moves working hours with it; bookings already made keep their time.'
                )}
              </p>
              <Link href="/admin/settings" className="font-medium text-primary hover:underline">
                {t('settings.zoneLink', 'Open workspace settings')}
              </Link>
            </CardContent>
          </Card>

          {problem && (
            <Alert variant="destructive" data-testid="settings-problem">
              <AlertDescription>{problem}</AlertDescription>
            </Alert>
          )}

          <SaveBar
            dirty={changes.length > 0}
            summary={t('settings.changed', '{what} changed', { what: changes.join(', ') })}
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
