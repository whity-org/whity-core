'use client';

/**
 * The column beside the calendar (design 3.3, `TplAppointments`): the
 * customers' requests waiting for an OK, and today at a glance.
 *
 * REQUESTS. A request is a time a customer asked for that staff accept first
 * (the service needs approval, or instant booking is off). Accept is the
 * request's main action; Suggest another time opens the booking with the
 * time picker, and the customer takes the new time from their link. Neither
 * can be undone through the API, but neither loses anything: an accepted
 * booking can still be moved or cancelled.
 *
 * TODAY. Booked, checked in, open requests and the reminders sent today, for
 * the branch shown. Reminders are email in core; another channel (WhatsApp)
 * is a plugin's.
 */

import { useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { useFetch } from '@/hooks/useFetch';
import { useToast } from '@/lib/toast-context';
import { calendarApi, type CalendarBooking, type CalendarService, type CalendarSettings, type CalendarStaff } from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

export interface RequestsCardProps {
  requests: CalendarBooking[];
  services: CalendarService[];
  staff: CalendarStaff[];
  timeZone: string;
  canChange: boolean;
  onSuggest: (booking: CalendarBooking) => void;
  onChanged: () => void;
}

export function RequestsCard({ requests, services, staff, timeZone, canChange, onSuggest, onChanged }: RequestsCardProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const { addToast } = useToast();
  const [busy, setBusy] = useState<number | null>(null);

  const accept = async (booking: CalendarBooking) => {
    setBusy(booking.id);
    try {
      await calendarApi.acceptBooking(booking.id);
      addToast(t('request.accepted', 'Booked for {name}', { name: booking.customer.name ?? '' }), 'success');
      onChanged();
    } catch (error) {
      addToast(words.errorText(error, t('request.acceptError', 'The request could not be accepted. Try again.')), 'error');
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card data-testid="calendar-requests">
      <CardHeader>
        <CardTitle>{t('request.title', 'Requests')}</CardTitle>
        <CardDescription>{t('request.description', 'Customers asking for a time that needs your OK')}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {requests.length === 0 ? (
          <p className="text-sm text-muted-foreground" data-testid="calendar-requests-none">
            {t('request.none', 'No requests waiting.')}
          </p>
        ) : (
          requests.map((booking) => {
            const service = services.find((s) => s.id === booking.service_id);
            const person = staff.find((s) => s.profile_id === booking.profile_id);
            const name = booking.customer.name ?? t('booking.noName', 'No name given');
            return (
              <div key={booking.id} className="grid gap-2" data-testid={`calendar-request-${booking.id}`}>
                <div className="flex items-center gap-3">
                  <Avatar name={name} size={32} />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">{name}</p>
                    <p className="text-sm text-muted-foreground">
                      {t('request.line', '{service} · {when} with {person}', {
                        service: words.serviceName(service),
                        when: dates.dateTime(booking.starts_at) ?? '—',
                        person: person?.display_name ?? '',
                      })}
                    </p>
                    {booking.suggested_at ? (
                      <p className="text-sm text-muted-foreground">{t('request.suggested', 'You suggested this time. Waiting for the customer.')}</p>
                    ) : null}
                  </div>
                </div>
                {canChange && (
                  <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" onClick={() => onSuggest(booking)} data-testid={`calendar-request-suggest-${booking.id}`}>
                      {t('request.suggest', 'Suggest another time')}
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => void accept(booking)}
                      loading={busy === booking.id}
                      data-testid={`calendar-request-accept-${booking.id}`}
                    >
                      {t('request.accept', 'Accept')}
                    </Button>
                  </div>
                )}
              </div>
            );
          })
        )}
      </CardContent>
    </Card>
  );
}

export function TodayCard({ unitId, reloadKey, settings }: { unitId: number | null; reloadKey: number; settings: CalendarSettings }) {
  const t = useTranslation('calendar');
  const { data, error } = useFetch(() => calendarApi.today({ unitId }), [unitId, reloadKey]);
  const hours = settings.reminder_hours_before;

  const rows: { label: string; value: string; testId: string }[] = data
    ? [
        { label: t('today.booked', 'Booked'), value: String(data.booked), testId: 'today-booked' },
        { label: t('today.checkedIn', 'Checked in'), value: String(data.checked_in), testId: 'today-checked-in' },
        { label: t('today.requests', 'Requests'), value: String(data.requests), testId: 'today-requests' },
        {
          label: t('today.reminders', 'Reminders'),
          value:
            hours > 0
              ? t('today.remindersValue', '{n} sent · email, {hours} h before', { n: data.reminders_sent, hours })
              : t('today.remindersOff', 'Off'),
          testId: 'today-reminders',
        },
      ]
    : [];

  return (
    <Card data-testid="calendar-today-card">
      <CardHeader>
        <CardTitle>{t('today.title', 'Today')}</CardTitle>
      </CardHeader>
      <CardContent>
        {error ? (
          <p className="text-sm text-muted-foreground">{t('today.error', 'Today’s numbers could not be loaded.')}</p>
        ) : data === null ? (
          <Skeleton className="h-32 w-full rounded-control" />
        ) : (
          <dl className="divide-y divide-divider">
            {rows.map((row) => (
              <div key={row.testId} className="flex items-baseline justify-between gap-4 py-2" data-testid={row.testId}>
                <dt className="text-sm text-muted-foreground">{row.label}</dt>
                <dd className="text-end text-sm font-medium text-foreground tabular-nums">{row.value}</dd>
              </div>
            ))}
          </dl>
        )}
      </CardContent>
    </Card>
  );
}
