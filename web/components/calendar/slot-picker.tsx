'use client';

/**
 * Pick a free time (WHIT-944): a day, then the times free on it for a service,
 * from `GET /calendar/availability`. Used by New booking and by Move.
 *
 * The times on the buttons are the VALUE being chosen, in the workspace's zone
 * (`HH:MM` from `timeFieldValue`), not a record date printed for reading. Like
 * a date input, the control keeps working when the workspace hides dates on
 * screen (`useDateDisplay`'s rule: an editable control is not hidden).
 *
 * `reloadKey` refetches: the booking dialog bumps it after a 409, so the time
 * that was just taken disappears and the others are fresh.
 */

import { useFetch } from '@/hooks/useFetch';
import { Input } from '@/components/ui/input';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { calendarApi, type CalendarSlot } from '@/lib/calendar/api';
import {
  addDays,
  dateKey,
  parseDateKey,
  rangeQuery,
  timeFieldValue,
  type CalendarDate,
} from '@/lib/calendar/layout';
import { cn } from '@/lib/utils';

export interface SlotPickerProps {
  id: string;
  serviceId: number | null;
  /** One person, or null for whoever is free. */
  profileId: number | null;
  date: CalendarDate;
  onDateChange: (date: CalendarDate) => void;
  timeZone: string;
  selected: CalendarSlot | null;
  onSelect: (slot: CalendarSlot | null) => void;
  reloadKey?: number;
  /** A time to leave out, such as the booking's own when moving it. */
  exclude?: string | null;
}

export function SlotPicker({
  id,
  serviceId,
  profileId,
  date,
  onDateChange,
  timeZone,
  selected,
  onSelect,
  reloadKey = 0,
  exclude = null,
}: SlotPickerProps) {
  const t = useTranslation('calendar');

  const { data, loading, error, refetch } = useFetch<CalendarSlot[]>(async () => {
    if (serviceId === null) return [];
    const range = rangeQuery({ first: date, end: addDays(date, 1) }, timeZone);
    return calendarApi.availability(serviceId, { ...range, profileId });
  }, [serviceId, profileId, dateKey(date), timeZone, reloadKey]);

  const slots = (data ?? []).filter((slot) => slot.starts_at !== exclude);

  return (
    <div className="space-y-3" data-testid={`${id}-slots`}>
      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-44 flex-1">
          <label htmlFor={`${id}-date`} className="mb-1 block text-sm font-medium text-foreground">
            {t('slots.day', 'Day')}
          </label>
          <Input
            id={`${id}-date`}
            type="date"
            value={dateKey(date)}
            onChange={(event) => {
              const next = parseDateKey(event.target.value);
              if (next !== null) {
                onDateChange(next);
                onSelect(null);
              }
            }}
          />
        </div>
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            onDateChange(addDays(date, -1));
            onSelect(null);
          }}
        >
          <Icon name="chevron-left" />
          {t('slots.previousDay', 'Day before')}
        </Button>
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            onDateChange(addDays(date, 1));
            onSelect(null);
          }}
        >
          {t('slots.nextDay', 'Day after')}
          <Icon name="chevron-right" />
        </Button>
      </div>

      <div role="group" aria-label={t('slots.label', 'Free times')} aria-busy={loading || undefined}>
        {serviceId === null ? (
          <p className="text-sm text-muted-foreground">{t('slots.chooseService', 'Choose a service to see its free times.')}</p>
        ) : error ? (
          <AreaError
            message={t('slots.error', 'Free times could not be loaded.')}
            retryLabel={t('action.retry', 'Try again')}
            onRetry={refetch}
          />
        ) : loading && data === null ? (
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
            {Array.from({ length: 8 }, (_, i) => (
              <Skeleton key={i} className="h-10 rounded-control" />
            ))}
          </div>
        ) : slots.length === 0 ? (
          <p className="text-sm text-muted-foreground" data-testid={`${id}-no-slots`}>
            {t('slots.none', 'No free times on this day. Try another day.')}
          </p>
        ) : (
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
            {slots.map((slot) => {
              const on = selected?.starts_at === slot.starts_at;
              return (
                <button
                  key={slot.starts_at}
                  type="button"
                  aria-pressed={on}
                  data-testid={`${id}-slot`}
                  onClick={() => onSelect(on ? null : slot)}
                  className={cn(
                    'h-10 rounded-control border border-border bg-card text-sm font-medium text-foreground tabular-nums transition-colors outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                    on && 'border-primary bg-primary-tint text-primary-tint-foreground hover:bg-primary-tint'
                  )}
                  dir="ltr"
                >
                  {timeFieldValue(slot.starts_at, timeZone)}
                </button>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
