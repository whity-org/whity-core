'use client';

/**
 * The home's Appointments card (WHIT-1437, design section 39 §4.3, AP.8):
 * this month's due bookings, the no-show and late-cancel rates with their
 * trend against last month, how many are not marked, and the three customers
 * who missed most. It links to Reports › Appointments. Shown to people with
 * `calendar:reports`; the rates are always in words, never colour alone.
 */

import Link from 'next/link';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { useTranslation } from '@whity/features/i18n';
import { useFetch } from '@/hooks/useFetch';
import { calendarApi } from '@/lib/calendar/api';
import { percent, useTrend } from './report-words';

export function AppointmentsReportCard() {
  const t = useTranslation('calendar');
  const trend = useTrend();
  const { data } = useFetch(() => calendarApi.attendanceReport({ by: 'customer', period: 'this_month' }), []);
  if (!data) return null;
  const { totals, previous } = data;
  const missedMost = data.rows.filter((row) => row.no_show + row.late_cancels > 0).slice(0, 3);

  return (
    <Card data-testid="home-appointments">
      <CardHeader>
        <CardTitle>{t('reports.card.title', 'Appointments')}</CardTitle>
        <CardDescription>{t('reports.card.description', 'This month: who came, who didn’t and who cancelled late')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4 text-sm">
        <dl className="grid grid-cols-2 gap-3">
          <div>
            <dt className="text-muted-foreground">{t('reports.due', 'Due')}</dt>
            <dd className="text-xl font-semibold tabular-nums" dir="ltr">{totals.due}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('reports.unmarked', 'Not marked')}</dt>
            <dd className="text-xl font-semibold tabular-nums" dir="ltr">{totals.unmarked}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('reports.noShowRate', 'No-show rate')}</dt>
            <dd className="text-xl font-semibold tabular-nums" dir="ltr">{percent(totals.no_show_rate)}</dd>
            <dd className="text-muted-foreground">{trend(totals.no_show_rate, previous.no_show_rate)}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">{t('reports.lateCancelRate', 'Late-cancel rate')}</dt>
            <dd className="text-xl font-semibold tabular-nums" dir="ltr">{percent(totals.late_cancel_rate)}</dd>
            <dd className="text-muted-foreground">{trend(totals.late_cancel_rate, previous.late_cancel_rate)}</dd>
          </div>
        </dl>
        {missedMost.length > 0 && (
          <div className="space-y-1">
            <p className="font-medium text-foreground">{t('reports.card.missedMost', 'Missed most')}</p>
            <ul className="divide-y divide-divider" data-testid="home-appointments-missed">
              {missedMost.map((row) => (
                <li key={row.key} className="flex justify-between gap-3 py-2">
                  <span className="truncate">{String(row.label) || t('booking.noName', 'No name given')}</span>
                  <span className="shrink-0 text-muted-foreground">
                    {t('reports.card.missedCount', 'Missed {n}', { n: row.no_show + row.late_cancels })}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
        <Button asChild variant="outline" size="sm">
          <Link href="/calendar/reports">{t('reports.card.open', 'Open the report')}</Link>
        </Button>
      </CardContent>
    </Card>
  );
}
