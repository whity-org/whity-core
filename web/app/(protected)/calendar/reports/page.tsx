'use client';

/**
 * Reports › Appointments (WHIT-1437, design section 39 §4.2–§4.3, AP.6–AP.9):
 * who came, who didn't and who cancelled late, for a period, broken down one
 * way at a time, with a trend against the period before.
 *
 * Due = came + didn't come + late cancels. Bookings that have started and
 * nobody marked are shown as "Not marked: n" and left out of every rate; above
 * 10% the rates say "Not all bookings are marked", so a forgetful desk is never
 * a false 0%. Everything is worked out from the bookings each time, in the
 * workspace's time zone.
 *
 * `calendar:reports` sees everyone; anyone else on the calendar sees only
 * their own bookings (AP.9). The one main button downloads the rows as CSV.
 */

import { useState } from 'react';
import { AdminHeader } from '@/components/admin/admin-header';
import { FieldSelect } from '@/components/field-select';
import { LoadProblem } from '@/components/load-problem';
import { NoAccess } from '@/components/no-access';
import { CalendarNav } from '@/components/calendar/calendar-nav';
import { useCalendarAccess, useCalendarBasics } from '@/components/calendar/use-calendar-basics';
import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { percent, useTrend } from '@/components/calendar/report-words';
import { useFetch } from '@/hooks/useFetch';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@whity/ui/skeleton';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import { toZonedIso, zonedInstant } from '@whity/features/datetime';
import {
  attendanceReportCsvUrl,
  calendarApi,
  type CalendarAttendanceReport,
  type CalendarAttendanceReportRow,
  type CalendarReportBy,
  type CalendarReportPeriod,
  type CalendarReportQuery,
} from '@/lib/calendar/api';

const PERIODS: CalendarReportPeriod[] = ['this_week', 'this_month', 'last_month', 'last_3_months', 'last_6_months', 'last_12_months'];
const CHOSEN = 'chosen';
const EVERYONE = 'everyone';

function dateParts(value: string): { year: number; month: number; day: number } | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  return m ? { year: Number(m[1]), month: Number(m[2]), day: Number(m[3]) } : null;
}

export default function CalendarReportsPage() {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const language = useLanguageCode() ?? 'en';
  const access = useCalendarAccess();
  const basics = useCalendarBasics();
  const trend = useTrend();
  const [by, setBy] = useState<CalendarReportBy>('staff');
  const [period, setPeriod] = useState<string>('this_month');
  const [fromDay, setFromDay] = useState('');
  const [toDay, setToDay] = useState('');
  const [person, setPerson] = useState<string>(EVERYONE);

  const timeZone = basics.data?.timeZone ?? 'UTC';
  const from = period === CHOSEN && dateParts(fromDay) ? toZonedIso(zonedInstant(dateParts(fromDay)!, 0, timeZone), timeZone) : null;
  const toParts = dateParts(toDay);
  // The chosen last day is included: the period ends at the start of the next day.
  const to = period === CHOSEN && toParts ? toZonedIso(zonedInstant(toParts, 24 * 60, timeZone), timeZone) : null;
  const waitingForDates = period === CHOSEN && (from === null || to === null);
  const query: CalendarReportQuery = {
    by,
    period: period === CHOSEN ? null : (period as CalendarReportPeriod),
    from,
    to,
    profileId: access.canReports && person !== EVERYONE ? Number(person) : null,
  };
  const { data, error, forbidden, loading, refetch } = useFetch<CalendarAttendanceReport | null>(
    () => (access.canRead && !waitingForDates ? calendarApi.attendanceReport(query) : Promise.resolve(null)),
    [access.canRead, by, period, from, to, person, waitingForDates]
  );

  if (!access.loading && !access.canRead) {
    return <NoAccess area={t('reports.area', 'appointment reports')} permission="calendar:read" />;
  }

  const breakdowns: { value: CalendarReportBy; label: string }[] = [
    { value: 'staff', label: t('reports.by.staff', 'Staff member') },
    { value: 'service', label: t('reports.by.service', 'Service') },
    { value: 'customer', label: t('reports.by.customer', 'Customer') },
    { value: 'weekday_hour', label: t('reports.by.weekdayHour', 'Day and hour') },
    { value: 'source', label: t('reports.by.source', 'How it was booked') },
    { value: 'reminder', label: t('reports.by.reminder', 'Reminder') },
    { value: 'returning', label: t('reports.by.returning', 'New or returning') },
  ];
  const periodLabels: Record<string, string> = {
    this_week: t('reports.period.thisWeek', 'This week'),
    this_month: t('reports.period.thisMonth', 'This month'),
    last_month: t('reports.period.lastMonth', 'Last month'),
    last_3_months: t('reports.period.last3', 'Last 3 months'),
    last_6_months: t('reports.period.last6', 'Last 6 months'),
    last_12_months: t('reports.period.last12', 'Last 12 months'),
    [CHOSEN]: t('reports.period.chosen', 'Choose dates'),
  };

  const rowLabel = (row: CalendarAttendanceReportRow): string => {
    const label = row.label as unknown;
    switch (by) {
      case 'service':
        return typeof label === 'object' && label !== null ? words.label(label as Record<string, string>) : String(label);
      case 'source':
        return words.source(row.key as 'staff' | 'public' | 'agent' | 'walkin');
      case 'reminder':
        return row.key === 'confirmed'
          ? t('reports.reminder.confirmed', 'Said they’re coming')
          : row.key === 'reminded'
            ? t('reports.reminder.reminded', 'Reminded')
            : t('reports.reminder.none', 'Not reminded');
      case 'returning':
        return row.key === 'new' ? t('reports.returning.new', 'First booking') : t('reports.returning.returning', 'Came before');
      case 'weekday_hour': {
        const [day, hour] = row.key.split('-').map(Number);
        return `${words.weekday(day)} ${String(hour).padStart(2, '0')}:00`;
      }
      case 'customer':
        return String(label) || t('booking.noName', 'No name given');
      default:
        return String(label);
    }
  };

  const totals = data?.totals;
  const previous = data?.previous;
  const tiles = totals
    ? [
        { id: 'due', label: t('reports.due', 'Due'), value: String(totals.due), hint: t('reports.dueHint', 'Came, didn’t come and late cancels') },
        { id: 'no-show', label: t('reports.noShowRate', 'No-show rate'), value: percent(totals.no_show_rate), hint: trend(totals.no_show_rate, previous?.no_show_rate) },
        { id: 'late', label: t('reports.lateCancelRate', 'Late-cancel rate'), value: percent(totals.late_cancel_rate), hint: trend(totals.late_cancel_rate, previous?.late_cancel_rate) },
        { id: 'missed', label: t('reports.missedRate', 'Missed rate'), value: percent(totals.missed_rate), hint: trend(totals.missed_rate, previous?.missed_rate) },
        { id: 'unmarked', label: t('reports.unmarked', 'Not marked'), value: String(totals.unmarked), hint: t('reports.unmarkedHint', 'Left out of the rates') },
      ]
    : [];

  return (
    <div className="space-y-4 pb-16">
      <AdminHeader
        title={t('reports.title', 'Appointment report')}
        description={
          data?.own
            ? t('reports.descriptionOwn', 'Your own bookings: who came, who didn’t and who cancelled late.')
            : t('reports.description', 'Who came, who didn’t and who cancelled late. Customers never see this.')
        }
        action={
          <Button asChild data-testid="reports-csv">
            <a href={attendanceReportCsvUrl(query, language)} download>
              <Icon name="download" />
              {t('reports.csv', 'Download as a spreadsheet')}
            </a>
          </Button>
        }
      />
      <CalendarNav active="reports" />

      <Card>
        <CardContent className="grid gap-4 pt-6 sm:grid-cols-2 lg:grid-cols-4">
          <div className="space-y-1">
            <label htmlFor="reports-period" className="text-sm font-medium text-foreground">
              {t('reports.period.label', 'Period')}
            </label>
            <FieldSelect
              id="reports-period"
              value={period}
              onChange={setPeriod}
              options={[...PERIODS, CHOSEN].map((p) => ({ value: p, label: periodLabels[p] }))}
            />
          </div>
          {period === CHOSEN && (
            <>
              <Input id="reports-from" type="date" dir="ltr" label={t('reports.from', 'From')} value={fromDay} onChange={(e) => setFromDay(e.target.value)} />
              <Input id="reports-to" type="date" dir="ltr" label={t('reports.to', 'To')} value={toDay} onChange={(e) => setToDay(e.target.value)} />
            </>
          )}
          {access.canReports && (
            <div className="space-y-1">
              <label htmlFor="reports-person" className="text-sm font-medium text-foreground">
                {t('reports.person', 'Staff member')}
              </label>
              <FieldSelect
                id="reports-person"
                value={person}
                onChange={setPerson}
                options={[
                  { value: EVERYONE, label: t('reports.everyone', 'Everyone') },
                  ...(basics.data?.staff ?? []).map((s) => ({ value: String(s.profile_id), label: s.display_name })),
                ]}
              />
            </div>
          )}
        </CardContent>
      </Card>

      {error ? (
        <LoadProblem forbidden={forbidden} message={t('reports.error', 'The report could not be loaded.')} onRetry={refetch} area={t('reports.area', 'appointment reports')} />
      ) : waitingForDates ? (
        <p className="text-sm text-muted-foreground">{t('reports.chooseDates', 'Choose the first and last day.')}</p>
      ) : loading || !data || !totals ? (
        <div role="status" aria-busy="true" aria-label={t('reports.loading', 'Loading the report')} className="space-y-3">
          <Skeleton className="h-24 w-full rounded-card" />
          <Skeleton className="h-64 w-full rounded-card" />
        </div>
      ) : (
        <>
          {totals.unmarked_share !== null && totals.unmarked_share !== undefined && totals.unmarked_share > 0.1 && (
            <Alert data-testid="reports-not-all-marked">
              <AlertDescription>
                {t('reports.notAllMarked', 'Not all bookings are marked: {n} have started and nobody said whether the customer came. The rates leave them out.', { n: totals.unmarked })}
              </AlertDescription>
            </Alert>
          )}
          {data.truncated && (
            <Alert>
              <AlertDescription>{t('reports.truncated', 'This period has more than 50,000 bookings; only the first 50,000 are counted. Choose a shorter period.')}</AlertDescription>
            </Alert>
          )}

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="reports-totals">
            {tiles.map((tile) => (
              <Card key={tile.id} data-testid={`reports-tile-${tile.id}`}>
                <CardContent className="space-y-1 pt-6">
                  <p className="text-sm text-muted-foreground">{tile.label}</p>
                  <p className="text-2xl font-semibold tabular-nums text-foreground" dir="ltr">
                    {tile.value}
                  </p>
                  <p className="text-sm text-muted-foreground">{tile.hint}</p>
                </CardContent>
              </Card>
            ))}
          </div>

          <Card>
            <CardHeader className="gap-3">
              <CardTitle>{t('reports.breakdown', 'Broken down by')}</CardTitle>
              <CardDescription>{t('reports.breakdownHint', 'Each row counts its own bookings. A booking of several services counts under its first.')}</CardDescription>
              <div data-testid="reports-by">
                <ToggleGroup
                  label={t('reports.breakdown', 'Broken down by')}
                  className="flex-wrap"
                  required
                  value={by}
                  onChange={(value) => typeof value === 'string' && setBy(value as CalendarReportBy)}
                  options={breakdowns}
                />
              </div>
            </CardHeader>
            <CardContent>
              {data.rows.length === 0 ? (
                <p className="text-sm text-muted-foreground" data-testid="reports-empty">
                  {t('reports.empty', 'No bookings in this period yet.')}
                </p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm" data-testid="reports-table">
                    <thead>
                      <tr className="h-12 border-b border-border text-start text-muted-foreground">
                        <th className="text-start font-medium">{breakdowns.find((b) => b.value === by)?.label}</th>
                        <th className="text-end font-medium">{t('reports.due', 'Due')}</th>
                        <th className="text-end font-medium">{t('reports.came', 'Came')}</th>
                        <th className="text-end font-medium">{t('booking.noShow', 'Didn’t come')}</th>
                        <th className="text-end font-medium">{t('reports.lateCancels', 'Late cancels')}</th>
                        <th className="ps-4 text-start font-medium">{t('reports.noShowRate', 'No-show rate')}</th>
                        <th className="text-end font-medium">{t('reports.unmarked', 'Not marked')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.rows.map((row) => (
                        <tr key={row.key} className="h-12 border-b border-divider" data-testid={`reports-row-${row.key}`}>
                          <td className="pe-4">{rowLabel(row)}</td>
                          <td className="text-end tabular-nums" dir="ltr">{row.due}</td>
                          <td className="text-end tabular-nums" dir="ltr">{row.came}</td>
                          <td className="text-end tabular-nums" dir="ltr">{row.no_show}</td>
                          <td className="text-end tabular-nums" dir="ltr">{row.late_cancels}</td>
                          <td className="min-w-40 ps-4">
                            <div className="flex items-center gap-2">
                              <span className="w-14 shrink-0 text-end tabular-nums" dir="ltr">
                                {percent(row.no_show_rate)}
                              </span>
                              <span className="h-2 flex-1 rounded-full bg-muted" aria-hidden>
                                <span className="block h-2 rounded-full bg-primary" style={{ width: `${Math.round((row.no_show_rate ?? 0) * 100)}%` }} />
                              </span>
                            </div>
                          </td>
                          <td className="text-end tabular-nums" dir="ltr">{row.unmarked}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
