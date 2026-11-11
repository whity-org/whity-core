'use client';

/**
 * The calendar's three layouts (WHIT-944): a time grid for Day and Week, an
 * agenda list for Week on a phone, and a month of weeks.
 *
 * DIRECTION. Columns are laid out in reading order along the inline axis and
 * every offset is a logical property (`insetInlineStart`, `ps-`, `border-s`),
 * so in Arabic the time column and the first day of the week sit on the right
 * with no branch on the language (docs/design/08-arabic.md). Times and day
 * numbers stay Western digits, left to right.
 *
 * WEEKEND days are tinted with the muted token, from the workspace's own
 * `weekend_days` (Friday and Saturday by default).
 *
 * STATES OF AN ITEM. A confirmed booking is the main tint with a solid edge; a
 * booking waiting for payment is dashed, because dashed means "not happened
 * yet" in this design system; a busy event is the info tint; an event that
 * does not block bookings is muted.
 */

import { useEffect, useRef } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import {
  assignLanes,
  dateKey,
  isWeekend,
  isoWeekday,
  labelInstant,
  overlapsDay,
  placeInDay,
  sameDate,
  type CalendarDate,
} from '@/lib/calendar/layout';
import { minuteToTime } from '@/lib/calendar/times';
import type { CalendarBooking, CalendarEvent } from '@/lib/calendar/api';
import { cn } from '@/lib/utils';
import { useCalendarWords } from './use-calendar-words';

/** One thing on the calendar, a booking or an event, ready to draw. */
export interface GridItem {
  key: string;
  starts_at: string;
  ends_at: string;
  allDay: boolean;
  title: string;
  subtitle: string;
  /** Who it belongs to, shown when the calendar shows everyone. */
  person: string;
  tone: 'booking' | 'pending' | 'busy' | 'free';
  booking?: CalendarBooking;
  event?: CalendarEvent;
}

const HOUR_PX = 48;

export interface GridProps {
  days: CalendarDate[];
  items: GridItem[];
  timeZone: string;
  weekendDays: string[];
  today: CalendarDate;
  showPerson: boolean;
  onOpen: (item: GridItem) => void;
  onPickDay?: (date: CalendarDate) => void;
}

function toneClass(tone: GridItem['tone']): string {
  switch (tone) {
    case 'booking':
      return 'border-s-4 border-primary bg-primary-tint text-primary-tint-foreground';
    case 'pending':
      return 'border border-dashed border-warning bg-warning-tint text-warning-text';
    case 'busy':
      return 'border-s-4 border-info bg-info-tint text-info-text';
    default:
      return 'border border-border bg-muted text-foreground';
  }
}

function useItemLabel() {
  const t = useTranslation('calendar');
  return (item: GridItem, from: string | null, to: string | null) =>
    from !== null && to !== null
      ? t('grid.itemLabel', '{title}, {subtitle}, {from} to {to}', { title: item.title, subtitle: item.subtitle, from, to })
      : t('grid.itemLabelNoTime', '{title}, {subtitle}', { title: item.title, subtitle: item.subtitle });
}

function DayHeading({
  date,
  timeZone,
  weekendDays,
  today,
  onPickDay,
}: {
  date: CalendarDate;
  timeZone: string;
  weekendDays: string[];
  today: CalendarDate;
  onPickDay?: (date: CalendarDate) => void;
}) {
  const dates = useDateDisplay({ timeZone });
  const words = useCalendarWords();
  const isToday = sameDate(date, today);
  const weekday = words.weekdayShort(isoWeekday(date));
  const day = dates.calendarLabel(labelInstant(date, timeZone), 'dayNumber');
  const content = (
    <span className="flex flex-col items-center leading-tight">
      <span className="text-xs text-muted-foreground">{weekday}</span>
      <span
        className={cn(
          'grid size-8 place-items-center rounded-full text-base font-medium tabular-nums',
          isToday && 'bg-primary text-primary-foreground'
        )}
        dir="ltr"
      >
        {day ?? ''}
      </span>
    </span>
  );
  return (
    <div
      className={cn('flex min-h-14 items-center justify-center border-s border-divider py-1', isWeekend(date, weekendDays) && 'bg-muted')}
      data-weekend={isWeekend(date, weekendDays) ? '' : undefined}
      data-testid="calendar-day-heading"
      data-date={dateKey(date)}
    >
      {onPickDay ? (
        <button
          type="button"
          onClick={() => onPickDay(date)}
          className="rounded-control px-2 outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
          aria-current={isToday ? 'date' : undefined}
        >
          {content}
        </button>
      ) : (
        content
      )}
    </div>
  );
}

/** Day and Week on a wide screen: hours down the side, one column per day. */
export function TimeGrid({ days, items, timeZone, weekendDays, today, showPerson, onOpen, onPickDay }: GridProps) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const itemLabel = useItemLabel();
  const scroller = useRef<HTMLDivElement>(null);
  const columns = { gridTemplateColumns: `4rem repeat(${days.length}, minmax(0, 1fr))` };

  // Open at the start of the working day rather than at midnight.
  useEffect(() => {
    if (scroller.current) scroller.current.scrollTop = 7 * HOUR_PX;
  }, []);

  const allDay = items.filter((item) => item.allDay);
  const timed = items.filter((item) => !item.allDay);

  return (
    <div className="overflow-hidden rounded-card border border-border bg-card" data-testid="calendar-time-grid">
      <div className="grid border-b border-border" style={columns}>
        <div />
        {days.map((date) => (
          <DayHeading
            key={dateKey(date)}
            date={date}
            timeZone={timeZone}
            weekendDays={weekendDays}
            today={today}
            onPickDay={days.length > 1 ? onPickDay : undefined}
          />
        ))}
      </div>

      {allDay.length > 0 && (
        <div className="grid border-b border-border" style={columns}>
          <div className="flex items-center justify-end pe-2 text-xs text-muted-foreground">{t('grid.allDay', 'All day')}</div>
          {days.map((date) => (
            <div
              key={dateKey(date)}
              className={cn('space-y-1 border-s border-divider p-1', isWeekend(date, weekendDays) && 'bg-muted')}
            >
              {allDay
                .filter((item) => overlapsDay(item, date, timeZone))
                .map((item) => (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => onOpen(item)}
                    className={cn('block w-full truncate rounded-md px-2 py-1 text-start text-xs font-medium', toneClass(item.tone))}
                    aria-label={itemLabel(item, null, null)}
                  >
                    {item.title}
                  </button>
                ))}
            </div>
          ))}
        </div>
      )}

      <div ref={scroller} className="max-h-[65vh] overflow-y-auto">
        <div className="grid" style={columns}>
          <div className="relative" style={{ height: 24 * HOUR_PX }} aria-hidden="true">
            {Array.from({ length: 24 }, (_, hour) => (
              <div
                key={hour}
                className="absolute end-2 -translate-y-1/2 text-xs text-muted-foreground tabular-nums"
                style={{ top: hour * HOUR_PX }}
                dir="ltr"
              >
                {hour === 0 ? '' : minuteToTime(hour * 60)}
              </div>
            ))}
          </div>
          {days.map((date) => {
            const placed = assignLanes(
              timed
                .map((item) => ({ item, place: placeInDay(item, date, timeZone) }))
                .filter((entry) => entry.place !== null)
                .map((entry) => ({ item: entry.item, startMinute: entry.place!.startMinute, endMinute: entry.place!.endMinute }))
            );
            return (
              <div
                key={dateKey(date)}
                className={cn('relative border-s border-divider', isWeekend(date, weekendDays) && 'bg-muted')}
                style={{ height: 24 * HOUR_PX }}
                data-testid="calendar-day-column"
                data-date={dateKey(date)}
              >
                {Array.from({ length: 24 }, (_, hour) => (
                  <div key={hour} className="absolute inset-x-0 border-t border-divider" style={{ top: hour * HOUR_PX }} aria-hidden="true" />
                ))}
                {placed.map(({ item, startMinute, endMinute, lane, lanes }) => {
                  const from = dates.time(item.starts_at);
                  const to = dates.time(item.ends_at);
                  const height = ((endMinute - startMinute) / 60) * HOUR_PX;
                  return (
                    <button
                      key={item.key}
                      type="button"
                      onClick={() => onOpen(item)}
                      data-testid="calendar-item"
                      aria-label={itemLabel(item, from, to)}
                      className={cn(
                        'absolute overflow-hidden rounded-md px-1.5 py-0.5 text-start text-xs leading-snug outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                        toneClass(item.tone)
                      )}
                      style={{
                        top: (startMinute / 60) * HOUR_PX + 1,
                        height: Math.max(height - 2, 20),
                        insetInlineStart: `calc(${(lane / lanes) * 100}% + 2px)`,
                        width: `calc(${100 / lanes}% - 4px)`,
                      }}
                    >
                      <span className="block truncate font-medium">{item.title}</span>
                      {height >= 40 && (
                        <span className="block truncate opacity-90">
                          {from !== null && <><bdi dir="ltr" className="tabular-nums">{from}</bdi>{' '}</>}
                          {item.subtitle}
                          {showPerson && ` · ${item.person}`}
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/** Week on a phone, and any list of days: each day with what is on it, in time order. */
export function AgendaList({ days, items, timeZone, weekendDays, today, showPerson, onOpen, onPickDay }: GridProps) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const words = useCalendarWords();
  const itemLabel = useItemLabel();

  return (
    <ol className="divide-y divide-divider rounded-card border border-border bg-card" data-testid="calendar-agenda">
      {days.map((date) => {
        const onDay = items
          .filter((item) => overlapsDay(item, date, timeZone))
          .sort((a, b) => Number(b.allDay) - Number(a.allDay) || a.starts_at.localeCompare(b.starts_at));
        const isToday = sameDate(date, today);
        return (
          <li
            key={dateKey(date)}
            className={cn('px-4 py-3', isWeekend(date, weekendDays) && 'bg-muted')}
            data-testid="calendar-agenda-day"
            data-date={dateKey(date)}
          >
            <h3 className="mb-2 flex items-baseline gap-2 text-sm font-medium text-foreground">
              <button
                type="button"
                className="rounded-control outline-none hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                onClick={() => onPickDay?.(date)}
                aria-current={isToday ? 'date' : undefined}
              >
                {words.weekday(isoWeekday(date))}{' '}
                <span>{dates.calendarLabel(labelInstant(date, timeZone), 'dayMonth') ?? ''}</span>
              </button>
              {isToday && <span className="text-xs text-primary">{t('grid.today', 'Today')}</span>}
            </h3>
            {onDay.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t('grid.nothing', 'Nothing on')}</p>
            ) : (
              <ul className="space-y-2">
                {onDay.map((item) => {
                  const from = item.allDay ? null : dates.time(item.starts_at);
                  const to = item.allDay ? null : dates.time(item.ends_at);
                  return (
                    <li key={item.key}>
                      <button
                        type="button"
                        onClick={() => onOpen(item)}
                        data-testid="calendar-item"
                        aria-label={itemLabel(item, from, to)}
                        className={cn(
                          'flex min-h-12 w-full items-center gap-3 rounded-md px-3 py-2 text-start text-sm outline-none focus-visible:outline-2 focus-visible:outline-ring',
                          toneClass(item.tone)
                        )}
                      >
                        <span className="w-24 shrink-0 tabular-nums" dir="ltr">
                          {item.allDay ? t('grid.allDay', 'All day') : `${from ?? ''}–${to ?? ''}`}
                        </span>
                        <span className="min-w-0">
                          <span className="block truncate font-medium">{item.title}</span>
                          <span className="block truncate text-xs opacity-90">
                            {item.subtitle}
                            {showPerson && ` · ${item.person}`}
                          </span>
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </li>
        );
      })}
    </ol>
  );
}

/** Month: whole weeks; each day shows its first few items and how many more. */
export function MonthGrid({
  weeks,
  month,
  items,
  timeZone,
  weekendDays,
  today,
  onOpen,
  onPickDay,
}: Omit<GridProps, 'days' | 'showPerson'> & { weeks: CalendarDate[][]; month: number }) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const words = useCalendarWords();
  const itemLabel = useItemLabel();

  return (
    <div className="overflow-hidden rounded-card border border-border bg-card" data-testid="calendar-month-grid">
      <div className="grid grid-cols-7 border-b border-border">
        {weeks[0].map((date) => (
          <div
            key={dateKey(date)}
            className={cn('py-2 text-center text-xs font-medium text-muted-foreground', isWeekend(date, weekendDays) && 'bg-muted')}
            data-testid="calendar-month-weekday"
          >
            {words.weekdayShort(isoWeekday(date))}
          </div>
        ))}
      </div>
      {weeks.map((week) => (
        <div key={dateKey(week[0])} className="grid grid-cols-7 border-b border-divider last:border-b-0">
          {week.map((date) => {
            const onDay = items
              .filter((item) => overlapsDay(item, date, timeZone))
              .sort((a, b) => Number(b.allDay) - Number(a.allDay) || a.starts_at.localeCompare(b.starts_at));
            const outside = date.month !== month;
            const isToday = sameDate(date, today);
            const shown = onDay.slice(0, 3);
            return (
              <div
                key={dateKey(date)}
                className={cn(
                  'min-h-16 border-s border-divider p-1 first:border-s-0 md:min-h-28',
                  isWeekend(date, weekendDays) && 'bg-muted'
                )}
                data-testid="calendar-month-day"
                data-date={dateKey(date)}
              >
                <button
                  type="button"
                  onClick={() => onPickDay?.(date)}
                  className={cn(
                    'grid size-8 place-items-center rounded-full text-sm tabular-nums outline-none hover:bg-card focus-visible:outline-2 focus-visible:outline-ring',
                    outside && 'text-muted-foreground',
                    isToday && 'bg-primary font-medium text-primary-foreground hover:bg-primary'
                  )}
                  aria-label={t('grid.openDay', 'Open {day}', {
                    day: dates.calendarLabel(labelInstant(date, timeZone), 'fullDate') ?? '',
                  })}
                  aria-current={isToday ? 'date' : undefined}
                  dir="ltr"
                >
                  {dates.calendarLabel(labelInstant(date, timeZone), 'dayNumber') ?? ''}
                </button>
                {/* On a phone a cell is too narrow for titles: a count, which opens the day. */}
                {onDay.length > 0 && (
                  <button
                    type="button"
                    onClick={() => onPickDay?.(date)}
                    className="mt-1 block w-full rounded-md bg-primary-tint px-1 text-center text-xs font-medium text-primary-tint-foreground md:hidden"
                  >
                    {onDay.length}
                  </button>
                )}
                <ul className="mt-1 hidden space-y-1 md:block">
                  {shown.map((item) => {
                    const from = item.allDay ? null : dates.time(item.starts_at);
                    return (
                      <li key={item.key}>
                        <button
                          type="button"
                          onClick={() => onOpen(item)}
                          data-testid="calendar-item"
                          aria-label={itemLabel(item, from, item.allDay ? null : dates.time(item.ends_at))}
                          className={cn('block w-full truncate rounded px-1.5 py-0.5 text-start text-xs', toneClass(item.tone))}
                        >
                          {from !== null && <><bdi dir="ltr" className="tabular-nums">{from}</bdi>{' '}</>}
                          {item.title}
                        </button>
                      </li>
                    );
                  })}
                  {onDay.length > shown.length && (
                    <li>
                      <button
                        type="button"
                        onClick={() => onPickDay?.(date)}
                        className="px-1.5 text-xs font-medium text-primary hover:underline"
                      >
                        {t('grid.more', '{count} more', { count: onDay.length - shown.length })}
                      </button>
                    </li>
                  )}
                </ul>
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}
