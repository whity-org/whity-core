'use client';

/**
 * Month and Year on a computer (design 3.5, CV.7, `TplCalendarViews`).
 *
 * MONTH: whole weeks; each day shows up to three bookings as a coloured dot,
 * the start time and the service, then "n more", which opens that day. Leave
 * and day-long events are bars across the days they cover; days of the
 * other months are grey; the weekend is shaded; today is filled.
 *
 * YEAR: twelve months, each day shaded in steps of the main colour by how
 * many bookings it has (the 2.7 rule for ordered data), the legend above,
 * today outlined. Clicking a day opens it in Day.
 *
 * Direction comes from logical properties and `dir` alone.
 */

import type { CSSProperties } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { cn } from '@/lib/utils';
import {
  addMonths,
  dateKey,
  isWeekend,
  isoWeekday,
  labelInstant,
  monthWeeks,
  overlapsDay,
  sameDate,
  type CalendarDate,
} from '@/lib/calendar/layout';
import { itemStyle, type CalItem } from './time-grid';
import { useCalendarWords } from './use-calendar-words';

const SHOWN_A_DAY = 3;

function useDayLabel(timeZone: string) {
  const dates = useDateDisplay({ timeZone });
  return (date: CalendarDate) => dates.calendarLabel(labelInstant(date, timeZone), 'fullDate') ?? dateKey(date);
}

export interface MonthViewProps {
  anchor: CalendarDate;
  weekStart: string;
  weekendDays: string[];
  today: CalendarDate;
  timeZone: string;
  items: CalItem[];
  onOpenItem: (item: CalItem) => void;
  onOpenDay: (date: CalendarDate) => void;
}

export function MonthView({ anchor, weekStart, weekendDays, today, timeZone, items, onOpenItem, onOpenDay }: MonthViewProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  const dates = useDateDisplay({ timeZone });
  const dayLabel = useDayLabel(timeZone);
  const weeks = monthWeeks(anchor, weekStart);
  const bars = items.filter((item) => item.allDay);
  const timed = items.filter((item) => !item.allDay).sort((a, b) => a.starts_at.localeCompare(b.starts_at));

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card" data-testid="calendar-month">
      <div className="grid grid-cols-7 border-b border-border">
        {weeks[0].map((date) => (
          <span key={dateKey(date)} className="p-2 text-center text-xs font-medium text-muted-foreground">
            {words.weekdayShort(isoWeekday(date))}
          </span>
        ))}
      </div>
      {weeks.map((week) => {
        const weekBars = bars
          .map((item) => {
            const on = week.map((date, i) => (overlapsDay(item, date, timeZone) ? i : -1)).filter((i) => i >= 0);
            return on.length === 0 ? null : { item, first: on[0], last: on[on.length - 1] };
          })
          .filter((bar): bar is { item: CalItem; first: number; last: number } => bar !== null);
        return (
          <div
            key={dateKey(week[0])}
            className="grid min-h-[132px] grid-cols-7 grid-rows-[34px_auto_1fr] border-b border-border last:border-b-0"
            data-testid="calendar-month-week"
          >
            {week.map((date, i) => {
              const outside = date.month !== anchor.month;
              const isToday = sameDate(date, today);
              const onDay = timed.filter((item) => overlapsDay(item, date, timeZone));
              const shown = onDay.slice(0, SHOWN_A_DAY);
              const label =
                date.day === 1 && outside
                  ? dates.calendarLabel(labelInstant(date, timeZone), 'dayMonth') ?? String(date.day)
                  : String(date.day);
              return (
                <div key={dateKey(date)} className="contents">
                  <div
                    className={cn('row-span-3 border-s border-border', i === 0 && 'border-s-0', isWeekend(date, weekendDays) && 'bg-muted')}
                    style={{ gridColumn: i + 1, gridRow: '1 / 4' }}
                    aria-hidden="true"
                  />
                  <button
                    type="button"
                    onClick={() => onOpenDay(date)}
                    className={cn(
                      'z-[1] grid h-[30px] min-w-[30px] place-items-center self-center justify-self-center rounded-full px-1.5 text-sm tabular-nums outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring',
                      outside && 'text-muted-foreground',
                      isToday && 'bg-primary font-semibold text-primary-foreground hover:bg-primary'
                    )}
                    style={{ gridColumn: i + 1, gridRow: 1 }}
                    aria-label={dayLabel(date)}
                    aria-current={isToday ? 'date' : undefined}
                    data-testid="calendar-month-day"
                    data-date={dateKey(date)}
                  >
                    <bdi>{label}</bdi>
                  </button>
                  <ul className="z-[1] m-0 grid min-w-0 list-none content-start gap-0.5 px-1 pt-0.5 pb-1.5" style={{ gridColumn: i + 1, gridRow: 3 }}>
                    {shown.map((item) => (
                      <li key={item.key} className="min-w-0">
                        <button
                          type="button"
                          onClick={() => onOpenItem(item)}
                          className="flex w-full min-w-0 items-center gap-1.5 overflow-hidden rounded px-1 text-start text-xs leading-5 whitespace-nowrap text-foreground outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring"
                          data-testid="calendar-month-chip"
                          data-kind={item.kind}
                        >
                          <i
                            className="size-2 shrink-0 rounded-full"
                            style={
                              item.kind === 'wait' || item.kind === 'request' || item.kind === 'cancelled'
                                ? { border: `1.5px solid ${item.colour}` }
                                : { background: item.colour }
                            }
                            aria-hidden="true"
                          />
                          <bdi dir="ltr" className="tabular-nums">
                            {dates.time(item.starts_at) ?? ''}
                          </bdi>
                          <span className={cn('truncate', item.kind === 'cancelled' && 'line-through')}>{item.title}</span>
                        </button>
                      </li>
                    ))}
                    {onDay.length > shown.length && (
                      <li>
                        <button
                          type="button"
                          onClick={() => onOpenDay(date)}
                          className="px-1 text-xs font-semibold text-primary outline-none hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                          data-testid="calendar-month-more"
                        >
                          {t('grid.more', '{count} more', { count: onDay.length - shown.length })}
                        </button>
                      </li>
                    )}
                  </ul>
                </div>
              );
            })}
            {weekBars.length > 0 && (
              <div className="grid grid-cols-7 gap-y-0.5" style={{ gridColumn: '1 / 8', gridRow: 2 }}>
                {weekBars.map(({ item, first, last }) => (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => onOpenItem(item)}
                    className="z-[1] mx-1 my-px flex h-6 min-w-0 items-center overflow-hidden rounded-md px-2 text-start text-xs font-semibold whitespace-nowrap text-foreground outline-none focus-visible:outline-2 focus-visible:outline-ring"
                    style={{ ...itemStyle(item.kind === 'timeoff' ? 'timeoff' : 'booking', item.colour), gridColumn: `${first + 1} / span ${last - first + 1}` }}
                    data-testid="calendar-month-bar"
                  >
                    <span className="truncate">{item.title}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

/** The shading step for a day's bookings (CV.7): none, 1–3, 4–6, 7 or more. */
export function yearStep(count: number): 0 | 1 | 2 | 3 {
  return count <= 0 ? 0 : count <= 3 ? 1 : count <= 6 ? 2 : 3;
}

const STEP_STYLE: Record<0 | 1 | 2 | 3, CSSProperties> = {
  0: {},
  1: { background: 'color-mix(in oklab, var(--primary) 15%, var(--card))' },
  2: { background: 'color-mix(in oklab, var(--primary) 38%, var(--card))' },
  3: { background: 'var(--primary)', color: 'var(--primary-foreground)' },
};

export interface YearViewProps {
  year: number;
  weekStart: string;
  today: CalendarDate;
  timeZone: string;
  /** Bookings a day, by `YYYY-MM-DD`. */
  counts: Map<string, number>;
  onOpenDay: (date: CalendarDate) => void;
}

export function YearLegend() {
  const t = useTranslation('calendar');
  const swatch = (step: 0 | 1 | 2 | 3) => (
    <span className="inline-block size-4 rounded border border-border align-middle" style={STEP_STYLE[step]} aria-hidden="true" />
  );
  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground" data-testid="calendar-year-legend">
      <span>{t('year.legend', 'Bookings a day:')}</span>
      {swatch(0)}
      <span>{t('year.none', 'none')}</span>
      {swatch(1)}
      <span dir="ltr">1–3</span>
      {swatch(2)}
      <span dir="ltr">4–6</span>
      {swatch(3)}
      <span>{t('year.many', '7 or more')}</span>
      <span className="flex-1" />
      <span>{t('year.hint', 'Click a day to open it')}</span>
    </div>
  );
}

export function YearView({ year, weekStart, today, timeZone, counts, onOpenDay }: YearViewProps) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const dayLabel = useDayLabel(timeZone);
  const months = Array.from({ length: 12 }, (_, i) => addMonths({ year, month: 1, day: 1 }, i));
  const letters = [
    t('panel.letter.mon', 'M'),
    t('panel.letter.tue', 'T'),
    t('panel.letter.wed', 'W'),
    t('panel.letter.thu', 'T'),
    t('panel.letter.fri', 'F'),
    t('panel.letter.sat', 'S'),
    t('panel.letter.sun', 'S'),
  ];

  return (
    <div className="grid grid-cols-4 gap-x-8 gap-y-6 rounded-xl border border-border bg-card p-5 max-xl:grid-cols-3" data-testid="calendar-year">
      {months.map((month) => {
        const weeks = monthWeeks(month, weekStart);
        return (
          <section key={month.month} aria-label={dates.calendarLabel(labelInstant(month, timeZone), 'monthYear') ?? ''}>
            <h3 className="m-0 mb-1.5 text-sm font-semibold text-foreground">{dates.calendarLabel(labelInstant(month, timeZone), 'month') ?? ''}</h3>
            <div className="grid grid-cols-7 gap-y-1 text-center text-xs tabular-nums">
              {weeks[0].map((date) => (
                <span key={`h${dateKey(date)}`} className="grid h-7 place-items-center text-muted-foreground" aria-hidden="true">
                  {letters[isoWeekday(date) - 1]}
                </span>
              ))}
              {weeks.flat().map((date) => {
                if (date.month !== month.month) return <span key={dateKey(date)} aria-hidden="true" />;
                const count = counts.get(dateKey(date)) ?? 0;
                const isToday = sameDate(date, today);
                return (
                  <button
                    key={dateKey(date)}
                    type="button"
                    onClick={() => onOpenDay(date)}
                    className={cn(
                      'mx-auto grid size-[30px] place-items-center rounded-md text-xs text-foreground outline-none hover:ring-1 hover:ring-border focus-visible:outline-2 focus-visible:outline-ring',
                      isToday && 'ring-2 ring-foreground hover:ring-2 hover:ring-foreground'
                    )}
                    style={STEP_STYLE[yearStep(count)]}
                    aria-label={t('year.dayLabel', '{day}: {count} bookings', { day: dayLabel(date), count })}
                    aria-current={isToday ? 'date' : undefined}
                    data-testid="calendar-year-day"
                    data-date={dateKey(date)}
                    data-step={yearStep(count)}
                    dir="ltr"
                  >
                    {date.day}
                  </button>
                );
              })}
            </div>
          </section>
        );
      })}
    </div>
  );
}
