'use client';

/**
 * The services of a booking of several (WHIT-1173), in order: each with its
 * time and who does it. Shown in the booking's details and its sheet; moving
 * or cancelling acts on them all.
 */

import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import type { CalendarBooking, CalendarBookingItem } from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

/** True when the booking holds more than one service. */
export function isSeveral(booking: CalendarBooking | null | undefined): boolean {
  return (booking?.items?.length ?? 0) > 1;
}

/** "Cut + Colour + Blow-dry" in the reader's language. */
export function useServicesName(): (items: readonly CalendarBookingItem[]) => string {
  const words = useCalendarWords();
  return (items) => items.map((item) => words.label(item.service_name)).filter((name) => name !== '').join(' + ');
}

export function ServiceList({ items, timeZone }: { items: readonly CalendarBookingItem[]; timeZone: string }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  return (
    <ol className="m-0 grid list-none gap-1 p-0" data-testid="booking-services">
      {items.map((item) => (
        <li key={item.position} className="grid gap-0.5">
          <span className="font-medium">{words.label(item.service_name)}</span>
          <small className="text-xs text-muted-foreground">
            {t('details.serviceLine', '{from} – {to} · {name}', {
              from: dates.time(item.starts_at) ?? '',
              to: dates.time(item.ends_at) ?? '',
              name: item.staff_name ?? '',
            })}
          </small>
        </li>
      ))}
    </ol>
  );
}
