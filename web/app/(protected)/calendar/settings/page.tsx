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
import { BookingLinkCard } from '@/components/calendar/booking-link';
import { BranchZonesCard } from '@/components/calendar/branch-zones';
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
  instantBooking: boolean;
  /** WHIT-1173: one booking may hold several services back to back. */
  multiService: boolean;
  /** WHIT-1334: the walk-in queue at the front desk. */
  walkins: boolean;
  minNotice: string;
  maxAdvance: string;
  slotStep: string;
  reminder: string;
  hold: string;
  /** WHIT-1427 (design section 39 §4): did they come. */
  grace: string;
  autoNoShow: boolean;
  lateHours: string;
  flagAfter: string;
  windowMonths: string;
  countLate: boolean;
  weekStart: string;
  weekend: string[];
}

function draftOf(s: CalendarSettings): Draft {
  return {
    bookingPage: s.booking_page_enabled,
    instantBooking: s.instant_booking ?? true,
    multiService: s.multi_service_booking ?? false,
    walkins: s.walkins_enabled ?? false,
    minNotice: String(s.min_notice_minutes),
    maxAdvance: String(s.max_advance_days),
    slotStep: String(s.slot_step_minutes),
    // WHIT-1417: every reminder, such as "24, 2".
    reminder: (s.reminders ?? (s.reminder_hours_before > 0 ? [s.reminder_hours_before] : [])).join(', '),
    hold: String(s.payment_hold_minutes),
    grace: String(s.no_show_grace_minutes ?? 15),
    autoNoShow: s.auto_no_show ?? false,
    lateHours: String(s.late_cancel_hours ?? 24),
    flagAfter: String(s.no_show_flag_after ?? 2),
    windowMonths: String(s.no_show_window_months ?? 12),
    countLate: s.no_show_count_late_cancels ?? true,
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
    if (current.instantBooking !== saved.instantBooking) changes.push(t('settings.instantBooking', 'Instant booking'));
    if (current.multiService !== saved.multiService) changes.push(t('settings.multiService', 'Several services in one booking'));
    if (current.walkins !== saved.walkins) changes.push(t('settings.walkins', 'Walk-ins'));
    if (current.minNotice !== saved.minNotice) changes.push(t('settings.minNotice', 'Least notice'));
    if (current.maxAdvance !== saved.maxAdvance) changes.push(t('settings.maxAdvance', 'How far ahead'));
    if (current.slotStep !== saved.slotStep) changes.push(t('settings.slotStep', 'Free times every'));
    if (current.reminder !== saved.reminder) changes.push(t('settings.reminders', 'Reminders'));
    if (current.hold !== saved.hold) changes.push(t('settings.hold', 'Payment hold'));
    if (current.grace !== saved.grace) changes.push(t('settings.grace', 'Ask after'));
    if (current.autoNoShow !== saved.autoNoShow) changes.push(t('settings.autoNoShow', 'Mark at the end of the day'));
    if (current.lateHours !== saved.lateHours) changes.push(t('settings.lateHours', 'Late cancel'));
    if (current.flagAfter !== saved.flagAfter) changes.push(t('settings.flagAfter', 'Flag after'));
    if (current.windowMonths !== saved.windowMonths) changes.push(t('settings.windowMonths', 'Count over'));
    if (current.countLate !== saved.countLate) changes.push(t('settings.countLate', 'Late cancels count'));
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
    const reminderParts = current.reminder.split(',').map((part) => part.trim()).filter((part) => part !== '');
    const reminderHours = reminderParts.map((part) => whole(part, 1, 168));
    const reminder =
      reminderHours.length <= 3 && reminderHours.every((n) => n !== null) && new Set(reminderHours).size === reminderHours.length
        ? (reminderHours as number[])
        : null;
    const hold = whole(current.hold, 1, 1440);
    if (minNotice === null) return setProblem(t('settings.error.minNotice', 'Least notice must be from 0 to 43200 minutes (30 days).'));
    if (maxAdvance === null) return setProblem(t('settings.error.maxAdvance', 'How far ahead must be from 1 to 730 days.'));
    if (reminder === null)
      return setProblem(t('settings.error.reminders', 'Reminders must be up to three different hours from 1 to 168, separated by commas, such as 24, 2.'));
    if (hold === null) return setProblem(t('settings.error.hold', 'The payment hold must be from 1 to 1440 minutes.'));
    const grace = whole(current.grace, 0, 240);
    const lateHours = whole(current.lateHours, 0, 168);
    const flagAfter = whole(current.flagAfter, 0, 20);
    const windowMonths = whole(current.windowMonths, 1, 36);
    if (grace === null) return setProblem(t('settings.error.grace', 'Ask after must be from 0 to 240 minutes.'));
    if (lateHours === null) return setProblem(t('settings.error.lateHours', 'A late cancel must be from 0 to 168 hours before.'));
    if (flagAfter === null) return setProblem(t('settings.error.flagAfter', 'Flag after must be from 0 to 20 missed bookings.'));
    if (windowMonths === null) return setProblem(t('settings.error.windowMonths', 'Count over must be from 1 to 36 months.'));

    const body: CalendarSettingsRequest = {
      booking_page_enabled: current.bookingPage,
      instant_booking: current.instantBooking,
      multi_service_booking: current.multiService,
      walkins_enabled: current.walkins,
      min_notice_minutes: minNotice,
      max_advance_days: maxAdvance,
      slot_step_minutes: Number(current.slotStep),
      reminders: reminder,
      payment_hold_minutes: hold,
      no_show_grace_minutes: grace,
      auto_no_show: current.autoNoShow,
      late_cancel_hours: lateHours,
      no_show_flag_after: flagAfter,
      no_show_window_months: windowMonths,
      no_show_count_late_cancels: current.countLate,
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
          {/* The address customers book at, to share (WHIT-1117). */}
          <BookingLinkCard
            link={data.booking_page_url ?? null}
            enabled={saved.bookingPage}
            canManage={access.canManage}
            onEnabled={refetch}
            shareOnOpen={typeof window !== 'undefined' && window.location.hash === '#share'}
          />
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

              <ChangedField changed={current.instantBooking !== saved.instantBooking}>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('settings.instantBooking', 'Instant booking')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('settings.instantBookingHint', 'On: customers book free times straight away. Off: every booking from the booking page is a request someone accepts first.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.instantBooking}
                    onCheckedChange={(instantBooking) => update({ instantBooking })}
                    aria-label={t('settings.instantBooking', 'Instant booking')}
                    data-testid="settings-instant-booking"
                  />
                </label>
              </ChangedField>

              <ChangedField changed={current.multiService !== saved.multiService}>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('settings.multiService', 'Several services in one booking')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('settings.multiServiceHint', 'On: one booking can hold several services back to back, such as a cut, a colour and a blow-dry, with one person or a different person for each.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.multiService}
                    onCheckedChange={(multiService) => update({ multiService })}
                    aria-label={t('settings.multiService', 'Several services in one booking')}
                    data-testid="settings-multi-service"
                  />
                </label>
              </ChangedField>

              <ChangedField changed={current.walkins !== saved.walkins}>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('settings.walkins', 'Walk-ins')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('settings.walkinsHint', 'On: the front desk keeps a queue of people who come in without a booking and hands each to whoever is free. It appears as Walk-ins in the menu.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.walkins}
                    onCheckedChange={(walkins) => update({ walkins })}
                    aria-label={t('settings.walkins', 'Walk-ins')}
                    data-testid="settings-walkins"
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
                    inputMode="numeric"
                    dir="ltr"
                    label={t('settings.remindersLabel', 'Reminders, hours before')}
                    helperText={t(
                      'settings.remindersHint',
                      'Up to three, separated by commas, such as 24, 2. Each goes by email, and by WhatsApp or SMS when connected. Leave empty to send none.'
                    )}
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

          <Card data-testid="settings-attendance">
            <CardHeader>
              <CardTitle>{t('settings.card.attendance', 'Did they come')}</CardTitle>
              <CardDescription>
                {t('settings.card.attendanceHint', 'How the desk records whether customers came, and when a customer’s missed bookings are pointed out to staff. Customers never see this.')}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <div className="grid gap-4 sm:grid-cols-2">
                <ChangedField changed={current.grace !== saved.grace}>
                  <Input
                    id="settings-grace"
                    type="number"
                    min={0}
                    max={240}
                    label={t('settings.graceLabel', 'Ask after, in minutes')}
                    helperText={t('settings.graceHint', 'How long after the start a booking appears in Did they come? 15 by default.')}
                    value={current.grace}
                    onChange={(e) => update({ grace: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.lateHours !== saved.lateHours}>
                  <Input
                    id="settings-late-hours"
                    type="number"
                    min={0}
                    max={168}
                    label={t('settings.lateHoursLabel', 'Late cancel, hours before')}
                    helperText={t('settings.lateHoursHint', 'A cancel by or for the customer closer to the start than this is late. 24 by default.')}
                    value={current.lateHours}
                    onChange={(e) => update({ lateHours: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.flagAfter !== saved.flagAfter}>
                  <Input
                    id="settings-flag-after"
                    type="number"
                    min={0}
                    max={20}
                    label={t('settings.flagAfterLabel', 'Point out a customer after this many missed bookings')}
                    helperText={t('settings.flagAfterHint', 'Staff see “Missed 2 of the last 5” on their bookings. 0 turns it off.')}
                    value={current.flagAfter}
                    onChange={(e) => update({ flagAfter: e.target.value })}
                  />
                </ChangedField>
                <ChangedField changed={current.windowMonths !== saved.windowMonths}>
                  <Input
                    id="settings-window-months"
                    type="number"
                    min={1}
                    max={36}
                    label={t('settings.windowMonthsLabel', 'Count over, in months')}
                    helperText={t('settings.windowMonthsHint', 'Only missed bookings this recent count. 12 by default.')}
                    value={current.windowMonths}
                    onChange={(e) => update({ windowMonths: e.target.value })}
                  />
                </ChangedField>
              </div>
              <ChangedField changed={current.countLate !== saved.countLate}>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('settings.countLate', 'Late cancels count')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('settings.countLateHint', 'On: a late cancel counts as a missed booking, like not coming.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.countLate}
                    onCheckedChange={(countLate) => update({ countLate })}
                    aria-label={t('settings.countLate', 'Late cancels count')}
                    data-testid="settings-count-late"
                  />
                </label>
              </ChangedField>
              <ChangedField changed={current.autoNoShow !== saved.autoNoShow}>
                <label className="flex min-h-10 items-start justify-between gap-3">
                  <span className="space-y-0.5">
                    <span className="block text-sm font-medium text-foreground">{t('settings.autoNoShow', 'Mark at the end of the day')}</span>
                    <span className="block text-sm text-muted-foreground">
                      {t('settings.autoNoShowHint', 'On: at the end of each day, bookings nobody marked become Didn’t come, marked by Whity. Anyone can change a mark afterwards. Off by default.')}
                    </span>
                  </span>
                  <Switch
                    checked={current.autoNoShow}
                    onCheckedChange={(autoNoShow) => update({ autoNoShow })}
                    aria-label={t('settings.autoNoShow', 'Mark at the end of the day')}
                    data-testid="settings-auto-no-show"
                  />
                </label>
              </ChangedField>
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

          {/* WHIT-1440 (AP.16): a branch in another zone can have its own. */}
          <BranchZonesCard canChange={access.canManage} />

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
