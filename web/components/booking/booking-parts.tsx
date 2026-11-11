'use client';

/**
 * Pieces both public booking pages show (WHIT-945): which zone the times are
 * in, and a booking's facts.
 *
 * ZONES (ADR 0017 section 2). Times are always on the workspace's clock, and
 * the page names that zone. When the visitor's device is in another zone, a
 * chosen or booked time is shown in theirs too, so someone in London booking a
 * bakery in Amman is not an hour or three out.
 */

import { useMemo, type ReactNode } from 'react';
import { Badge } from '@whity/ui/badge';
import { useTranslation } from '@whity/features/i18n';
import { deviceTimeZone, useDateDisplay } from '@whity/features/datetime';
import type { PublicBooking } from '@/lib/booking/public-api';
import { useBookingWords } from './use-booking-words';

/** The visitor's zone when it differs from the workspace's, else null. */
export function useOtherZone(timeZone: string): string | null {
  return useMemo(() => {
    const device = deviceTimeZone();
    if (device === null || device === timeZone) return null;
    return device;
  }, [timeZone]);
}

/** "Times are in Asia/Amman", and the visitor's own zone when it differs. */
export function ZoneNote({ timeZone }: { timeZone: string }) {
  const t = useTranslation('booking');
  const other = useOtherZone(timeZone);
  return (
    <p className="text-sm text-muted-foreground" data-testid="zone-note">
      {t('zone.workspace', 'Times are in {zone} time.', { zone: timeZone })}
      {other !== null && (
        <>
          {' '}
          {t('zone.device', 'Your device is set to {zone}, so we show that too once you choose.', { zone: other })}
        </>
      )}
    </p>
  );
}

/** One instant in the visitor's zone, when it differs: "That is 07:30, Sun 15 Nov 2026, in Europe/London." */
export function InYourZone({ item, timeZone }: { item: { starts_at: string }; timeZone: string }) {
  const t = useTranslation('booking');
  const other = useOtherZone(timeZone);
  const theirs = useDateDisplay({ timeZone: other ?? timeZone });
  if (other === null) return null;
  const when = theirs.dateTime(item.starts_at);
  if (when === null) return null;
  return (
    <p className="text-sm text-muted-foreground" data-testid="your-zone">
      {t('zone.yours', 'Where you are ({zone}) that is {when}.', { zone: other, when })}
    </p>
  );
}

/**
 * "Sunday, 15 November 2026 at 10:30" on the workspace's clock, as one
 * translated sentence so the word order and the comma are the language's own.
 */
export function useWhenAt(timeZone: string): (value: string) => string {
  const t = useTranslation('booking');
  const dates = useDateDisplay({ timeZone });
  return (value: string) =>
    t('when.at', '{date} at {time}', {
      date: dates.calendarLabel(value, 'fullDate') ?? '—',
      time: dates.time(value) ?? '—',
    });
}

export function statusVariant(status: PublicBooking['status']): 'success' | 'warning' | 'outline' {
  if (status === 'confirmed') return 'success';
  if (status === 'pending_payment') return 'warning';
  return 'outline';
}

/** The booking's day and times on the workspace's clock, with the zone named. */
export function WhenText({ item, timeZone }: { item: { starts_at: string; ends_at: string }; timeZone: string }) {
  const t = useTranslation('booking');
  const dates = useDateDisplay({ timeZone });
  return (
    <span className="block">
      <span className="block">{dates.calendarLabel(item.starts_at, 'fullDate') ?? '—'}</span>
      <span className="block">
        <span dir="ltr" className="tabular-nums">
          {dates.time(item.starts_at) ?? '—'}–{dates.time(item.ends_at) ?? '—'}
        </span>{' '}
        <span className="text-muted-foreground">{t('zone.short', '({zone} time)', { zone: timeZone })}</span>
      </span>
    </span>
  );
}

export function FactList({ rows }: { rows: { label: string; value: ReactNode; testId?: string }[] }) {
  return (
    <dl className="divide-y divide-divider rounded-card border border-border">
      {rows.map((row) => (
        <div key={row.label} className="grid gap-1 px-4 py-3 sm:grid-cols-[9rem_1fr]" data-testid={row.testId}>
          <dt className="text-sm text-muted-foreground">{row.label}</dt>
          <dd className="text-foreground">{row.value}</dd>
        </div>
      ))}
    </dl>
  );
}

/** What was booked: service, person, time, price and state. */
export function BookingFacts({
  booking,
  timeZone,
  withStatus = true,
}: {
  booking: PublicBooking;
  timeZone: string;
  withStatus?: boolean;
}) {
  const t = useTranslation('booking');
  const words = useBookingWords();
  const dates = useDateDisplay({ timeZone });
  const rows: { label: string; value: ReactNode; testId?: string }[] = [];

  rows.push({
    label: t('fact.service', 'Service'),
    value: (
      <>
        {words.name(booking.service.name)}
        {words.duration(booking.service.duration_minutes) !== null && (
          <span className="text-muted-foreground"> · {words.duration(booking.service.duration_minutes)}</span>
        )}
      </>
    ),
    testId: 'fact-service',
  });
  if (booking.staff_name) {
    rows.push({ label: t('fact.with', 'With'), value: booking.staff_name, testId: 'fact-with' });
  }
  if (!dates.hidden) {
    rows.push({
      label: t('fact.when', 'When'),
      value: (
        <>
          <WhenText item={booking} timeZone={timeZone} />
          <InYourZone item={booking} timeZone={timeZone} />
        </>
      ),
      testId: 'fact-when',
    });
  }
  const price = words.price(booking.price_minor, booking.currency);
  if (price !== null) {
    rows.push({
      label: t('fact.price', 'Price'),
      value: (
        <span dir="ltr" className="tabular-nums">
          {price}
        </span>
      ),
      testId: 'fact-price',
    });
  }
  if (booking.customer.name || booking.customer.email) {
    rows.push({
      label: t('fact.bookedFor', 'Booked for'),
      value: (
        <>
          {booking.customer.name}
          {booking.customer.email && (
            <span className="block text-sm text-muted-foreground">
              <span dir="ltr">{booking.customer.email}</span>
            </span>
          )}
        </>
      ),
    });
  }
  if (withStatus) {
    rows.push({
      label: t('fact.status', 'Status'),
      value: (
        <Badge variant={statusVariant(booking.status)} data-testid="booking-status">
          {words.status(booking.status)}
        </Badge>
      ),
    });
  }
  return <FactList rows={rows} />;
}
