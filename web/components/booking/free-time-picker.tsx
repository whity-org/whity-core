'use client';

/**
 * Pick a free time on the public booking pages (WHIT-945): a week of days on
 * the WORKSPACE's clock, then the times free on the chosen day. Used by the
 * booking page and by Move on the customer's manage page.
 *
 * Days are calendar dates in the workspace's zone (ADR 0017 section 2): a slot
 * at 23:30 in Amman sits on Amman's day whatever zone the visitor's phone is
 * in. The times on the buttons are the VALUE being chosen (`HH:MM` in the
 * workspace's zone, like the staff calendar's slot picker), so they stay
 * Western digits and left to right in Arabic.
 *
 * A week with nothing free says so and offers the next week, up to `latest`.
 * `reloadKey` fetches again: the pages bump it after a 409, so the time just
 * taken disappears and the rest are fresh.
 */

import { useEffect, useMemo, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Skeleton } from '@whity/ui/skeleton';
import { AreaError } from '@whity/ui/page-states';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import {
  addDays,
  dateKey,
  labelInstant,
  sameDate,
  timeFieldValue,
  type CalendarDate,
} from '@/lib/calendar/layout';
import { daysOfWeek, weekQuery, PublicBookingError, type FreeTime } from '@/lib/booking/public-api';
import { cn } from '@/lib/utils';

export interface FreeTimePickerProps {
  id: string;
  timeZone: string;
  /** The first day that can be booked (today in the workspace's zone). */
  earliest: CalendarDate;
  /** The last day that can be booked, or null when the page does not say. */
  latest: CalendarDate | null;
  /** Fetch the free times between two instants (ISO with the workspace's offset). */
  load: (range: { from: string; to: string }) => Promise<FreeTime[]>;
  /** Changes when what is being booked changes (service, person): fetch again. */
  loadKey: string;
  selected: FreeTime | null;
  onSelect: (slot: FreeTime | null) => void;
  reloadKey?: number;
  /** A time to leave out, such as the booking's own when moving it. */
  exclude?: { starts_at: string } | null;
}

const WEEK = 7;

export function FreeTimePicker({
  id,
  timeZone,
  earliest,
  latest,
  load,
  loadKey,
  selected,
  onSelect,
  reloadKey = 0,
  exclude = null,
}: FreeTimePickerProps) {
  const t = useTranslation('booking');
  const dates = useDateDisplay({ timeZone });
  const [first, setFirst] = useState<CalendarDate>(earliest);
  // The day the person picked; null until they do (the first day with times is shown).
  const [day, setDay] = useState<CalendarDate | null>(null);
  const [retry, setRetry] = useState(0);

  // What is being booked changed: start again from the first bookable week.
  const [seenLoadKey, setSeenLoadKey] = useState(loadKey);
  if (seenLoadKey !== loadKey) {
    setSeenLoadKey(loadKey);
    setFirst(earliest);
    setDay(null);
  }

  // The answer is filed under the request it answers, so a stale one is never shown as current.
  const requestKey = `${dateKey(first)}|${timeZone}|${loadKey}|${reloadKey}|${retry}`;
  const [answer, setAnswer] = useState<{ key: string; slots: FreeTime[] | null; failed: number | null } | null>(null);
  useEffect(() => {
    let cancelled = false;
    load(weekQuery(first, timeZone, WEEK))
      .then((found) => {
        if (!cancelled) setAnswer({ key: requestKey, slots: found, failed: null });
      })
      .catch((error: unknown) => {
        if (!cancelled) setAnswer({ key: requestKey, slots: null, failed: error instanceof PublicBookingError ? error.status : 0 });
      });
    return () => {
      cancelled = true;
    };
    // `load` is a new function on every render of the parent; `requestKey` says when it means something new.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestKey]);
  const current = answer !== null && answer.key === requestKey ? answer : null;
  const slots = current?.slots ?? null;
  const failed = current?.failed ?? null;

  const excluded = exclude?.starts_at ?? null;
  const week = useMemo(
    () => daysOfWeek(first, (slots ?? []).filter((slot) => slot.starts_at !== excluded), timeZone, WEEK),
    [first, slots, excluded, timeZone]
  );
  const anyFree = week.some((d) => d.items.length > 0);

  // The day shown: the one picked while it still has times, otherwise the first day that does.
  const picked = day ? week.find((d) => sameDate(d.date, day) && d.items.length > 0) : undefined;
  const chosenDay = picked ?? week.find((d) => d.items.length > 0) ?? null;

  // A chosen time that is no longer offered is let go.
  useEffect(() => {
    if (slots === null || selected === null) return;
    if (!week.some((d) => d.items.some((s) => s.starts_at === selected.starts_at))) onSelect(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [week, slots]);

  const last = addDays(first, WEEK - 1);
  const canGoBack = dateKey(first) > dateKey(earliest);
  const canGoOn = latest === null || dateKey(addDays(first, WEEK)) <= dateKey(latest);

  const move = (direction: 1 | -1) => {
    const next = addDays(first, direction * WEEK);
    setFirst(dateKey(next) < dateKey(earliest) ? earliest : next);
    setDay(null);
    onSelect(null);
  };

  const label = (date: CalendarDate, style: 'weekday' | 'dayNumber' | 'dayMonth' | 'fullDate') =>
    dates.calendarLabel(labelInstant(date, timeZone), style) ?? '—';

  return (
    <div className="space-y-4" data-testid={`${id}-picker`}>
      <div className="flex items-center justify-between gap-2">
        <Button
          type="button"
          variant="outline"
          size="icon"
          onClick={() => move(-1)}
          disabled={!canGoBack}
          aria-label={t('time.earlierWeek', 'Earlier week')}
          title={t('time.earlierWeek', 'Earlier week')}
          data-testid={`${id}-earlier`}
        >
          <Icon name="chevron-left" />
        </Button>
        <p className="text-center text-sm font-medium text-foreground" data-testid={`${id}-week`}>
          {label(first, 'dayMonth')}
          {' – '}
          {label(last, 'dayMonth')}
        </p>
        <Button
          type="button"
          variant="outline"
          size="icon"
          onClick={() => move(1)}
          disabled={!canGoOn}
          aria-label={t('time.laterWeek', 'Later week')}
          title={t('time.laterWeek', 'Later week')}
          data-testid={`${id}-later`}
        >
          <Icon name="chevron-right" />
        </Button>
      </div>

      {failed !== null ? (
        <AreaError
          message={
            failed === 429
              ? t('error.tooMany', 'Too many tries from this connection. Wait a few minutes and try again.')
              : t('time.error', 'The free times could not be loaded. Check your connection and try again.')
          }
          retryLabel={t('action.retry', 'Try again')}
          onRetry={() => setRetry((n) => n + 1)}
        />
      ) : slots === null ? (
        <div className="space-y-3" aria-busy="true" aria-label={t('time.loading', 'Loading free times')}>
          <div className="grid grid-cols-4 gap-2 sm:grid-cols-7">
            {Array.from({ length: WEEK }, (_, i) => (
              <Skeleton key={i} className="h-16 rounded-control" />
            ))}
          </div>
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
            {Array.from({ length: 6 }, (_, i) => (
              <Skeleton key={i} className="h-10 rounded-control" />
            ))}
          </div>
        </div>
      ) : !anyFree ? (
        <div className="rounded-card border border-dashed border-border px-4 py-6 text-center" data-testid={`${id}-none`}>
          <p className="text-sm text-foreground">
            {canGoOn
              ? t('time.noneThisWeek', 'Nothing is free between {from} and {to}.', {
                  from: label(first, 'dayMonth'),
                  to: label(last, 'dayMonth'),
                })
              : t('time.noneAtAll', 'Nothing is free in the time you can book ahead. Try again in a few days.')}
          </p>
          {canGoOn && (
            <Button type="button" variant="outline" className="mt-3" onClick={() => move(1)} data-testid={`${id}-next-week`}>
              {t('time.nextWeek', 'See the next week')}
              <Icon name="chevron-right" />
            </Button>
          )}
        </div>
      ) : (
        <>
          <div role="group" aria-label={t('time.days', 'Days')} className="grid grid-cols-4 gap-2 sm:grid-cols-7">
            {week.map(({ date, items }) => {
              const on = chosenDay !== null && sameDate(date, chosenDay.date);
              const none = items.length === 0;
              return (
                <button
                  key={dateKey(date)}
                  type="button"
                  disabled={none}
                  aria-pressed={on}
                  aria-label={
                    none
                      ? t('time.dayNone', '{day}: nothing free', { day: label(date, 'fullDate') })
                      : label(date, 'fullDate')
                  }
                  data-testid={`${id}-day`}
                  data-day={dateKey(date)}
                  onClick={() => {
                    setDay(date);
                    onSelect(null);
                  }}
                  className={cn(
                    'flex h-16 min-w-0 flex-col items-center justify-center gap-0.5 rounded-control border border-border bg-card px-1 text-foreground transition-colors outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                    on && 'border-primary bg-primary-tint text-primary-tint-foreground hover:bg-primary-tint',
                    none && 'cursor-not-allowed border-dashed bg-transparent text-muted-foreground hover:bg-transparent'
                  )}
                >
                  <span className="max-w-full truncate text-xs">{label(date, 'weekday')}</span>
                  <span className="text-base font-semibold tabular-nums" dir="ltr">
                    {label(date, 'dayNumber')}
                  </span>
                </button>
              );
            })}
          </div>

          {chosenDay && (
            <div className="space-y-2">
              <p className="text-sm font-medium text-foreground" data-testid={`${id}-day-title`}>
                {label(chosenDay.date, 'fullDate')}
              </p>
              <div role="group" aria-label={t('time.times', 'Free times')} className="grid grid-cols-3 gap-2 sm:grid-cols-5">
                {chosenDay.items.map((slot) => {
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
            </div>
          )}
        </>
      )}
    </div>
  );
}
