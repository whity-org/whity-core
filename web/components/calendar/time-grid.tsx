'use client';

/**
 * Day, 4 days and Week on a computer (design 3.5, CV.5–CV.7, `TplCalendarWeek`,
 * `TplCalendarViews`).
 *
 * - 48px an hour, from the earliest opening to the latest closing hour, so
 *   the working day fits without scrolling; the weekend shaded; an All day
 *   row with leave and day-long events as bars (time off striped); now as a
 *   red line with a dot; today's date filled.
 * - A booking is a 20% tint of its colour with a 4px bar at the start: the
 *   start time and service, then the customer and person from 54 minutes, and
 *   on Day its room. Waiting for payment is dashed, team events grey, time off
 *   striped, a cancelled booking struck through. Overlapping bookings cascade
 *   (66% wide, offset). The name is always written, so colour is never the
 *   only signal (CV.4).
 * - Clicking a booking opens its details beside it (`renderDetails`).
 *
 * Direction: columns follow the inline axis and every offset is logical, so
 * Arabic puts the first day and the hours on the right with no branch.
 */

import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay, wallClockAt } from '@whity/features/datetime';
import { cn } from '@/lib/utils';
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
import type { CalendarBooking, CalendarEvent, CalendarTimeOff } from '@/lib/calendar/api';
import { useCalendarWords } from './use-calendar-words';

export const HOUR_PX = 48;
/** From this long a booking shows its second line (CV.5). */
const SECOND_LINE_MINUTES = 54;

export type ItemKind = 'booking' | 'wait' | 'request' | 'event' | 'timeoff' | 'cancelled';

/** One thing on the calendar, ready to draw. */
export interface CalItem {
  key: string;
  kind: ItemKind;
  starts_at: string;
  ends_at: string;
  allDay: boolean;
  /** After the time: the service, the event's title, or "Name · leave". */
  title: string;
  /** The second line: "Customer · First name". */
  who: string;
  /** On Day only: the room, and the state when it is not a plain booking. */
  where: string;
  colour: string;
  booking?: CalendarBooking;
  event?: CalendarEvent;
  timeOff?: CalendarTimeOff;
}

export interface TimeGridProps {
  days: CalendarDate[];
  items: CalItem[];
  /** The first and last hour drawn (CV.6). */
  startHour: number;
  endHour: number;
  timeZone: string;
  weekendDays: string[];
  today: CalendarDate;
  /** The short zone shown over the hours, "GMT+3". */
  zone: string;
  selectedKey: string | null;
  onSelect: (item: CalItem | null) => void;
  /** The details beside the selected booking. */
  renderDetails?: (item: CalItem) => ReactNode;
  onPickDay?: (date: CalendarDate) => void;
}

function nowMinute(timeZone: string): number | null {
  try {
    const clock = wallClockAt(new Date(), timeZone);
    return clock.hour * 60 + clock.minute;
  } catch {
    return null;
  }
}

function useNowMinute(timeZone: string): number | null {
  const [, setTick] = useState(0);
  useEffect(() => {
    const id = window.setInterval(() => setTick((tick) => tick + 1), 60_000);
    return () => window.clearInterval(id);
  }, []);
  return nowMinute(timeZone);
}

/** The tint and edge of a kind of item, in its colour (CV.5). */
export function itemStyle(kind: ItemKind, colour: string): CSSProperties {
  const tint = `color-mix(in oklab, ${colour} 20%, var(--card))`;
  switch (kind) {
    case 'wait':
    case 'request':
      return { background: 'var(--card)', border: `1.5px dashed ${colour}`, borderInlineStartWidth: 4, borderInlineStartStyle: 'solid', borderInlineStartColor: colour };
    case 'event':
      return { background: 'var(--muted)', borderInlineStart: `4px solid ${colour}` };
    case 'timeoff':
      return {
        background: `repeating-linear-gradient(135deg, color-mix(in oklab, ${colour} 22%, var(--card)) 0 6px, var(--card) 6px 12px)`,
        borderInlineStart: `4px solid ${colour}`,
      };
    case 'cancelled':
      return { background: 'var(--card)', border: '1px solid var(--border)', borderInlineStart: `4px solid ${colour}`, color: 'var(--muted-foreground)' };
    default:
      return { background: tint, borderInlineStart: `4px solid ${colour}` };
  }
}

function useItemLabel() {
  const t = useTranslation('calendar');
  return (item: CalItem, from: string | null, to: string | null) =>
    [item.title, item.who, item.where, from !== null && to !== null ? t('grid.fromTo', '{from} to {to}', { from, to }) : '']
      .filter((part) => part !== '')
      .join(', ');
}

/** Rows for the All day bars: each bar takes the first row free across its days. */
function stackBars(bars: { first: number; last: number }[]): number[] {
  const rows: number[][] = [];
  return bars.map((bar) => {
    let row = rows.findIndex((taken) => taken.every((day) => day < bar.first || day > bar.last));
    if (row === -1) {
      row = rows.length;
      rows.push([]);
    }
    for (let day = bar.first; day <= bar.last; day++) rows[row].push(day);
    return row;
  });
}

export function TimeGrid({
  days,
  items,
  startHour,
  endHour,
  timeZone,
  weekendDays,
  today,
  zone,
  selectedKey,
  onSelect,
  renderDetails,
  onPickDay,
}: TimeGridProps) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  const words = useCalendarWords();
  const itemLabel = useItemLabel();
  const now = useNowMinute(timeZone);
  const wide = days.length === 1;
  const columns: CSSProperties = { gridTemplateColumns: `64px repeat(${days.length}, minmax(0, 1fr))` };
  const height = (endHour - startHour) * HOUR_PX;
  const grid = useRef<HTMLDivElement>(null);

  // Esc closes the details (CV.6).
  useEffect(() => {
    if (selectedKey === null) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onSelect(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [selectedKey, onSelect]);

  const allDay = items.filter((item) => item.allDay);
  const timed = items.filter((item) => !item.allDay);
  const bars = allDay
    .map((item) => {
      const on = days.map((date, i) => (overlapsDay(item, date, timeZone) ? i : -1)).filter((i) => i >= 0);
      return on.length === 0 ? null : { item, first: on[0], last: on[on.length - 1] };
    })
    .filter((bar): bar is { item: CalItem; first: number; last: number } => bar !== null);
  const barRows = stackBars(bars);

  return (
    <div
      ref={grid}
      className="rounded-xl border border-border bg-card text-xs"
      data-testid="calendar-time-grid"
      onClick={(event) => {
        if (event.target === event.currentTarget) onSelect(null);
      }}
    >
      <div className="grid border-b border-border" style={columns}>
        <div className="self-end px-2 py-1.5 text-end text-xs text-muted-foreground" dir="ltr">
          {zone}
        </div>
        {days.map((date) => {
          const isToday = sameDate(date, today);
          const off = isWeekend(date, weekendDays);
          return (
            <div
              key={dateKey(date)}
              className={cn('grid justify-items-center gap-0.5 border-s border-border py-2', off && 'bg-muted')}
              data-testid="calendar-day-heading"
              data-date={dateKey(date)}
              data-weekend={off ? '' : undefined}
            >
              <span className={cn('text-xs font-medium text-muted-foreground', isToday && 'font-semibold text-primary')}>
                {words.weekdayShort(isoWeekday(date))}
              </span>
              <button
                type="button"
                onClick={() => onPickDay?.(date)}
                disabled={onPickDay === undefined || wide}
                aria-current={isToday ? 'date' : undefined}
                aria-label={dates.calendarLabel(labelInstant(date, timeZone), 'fullDate') ?? dateKey(date)}
                className={cn(
                  'grid size-10 place-items-center rounded-full text-xl font-medium tabular-nums outline-none focus-visible:outline-2 focus-visible:outline-ring enabled:hover:bg-muted',
                  isToday && 'bg-primary font-semibold text-primary-foreground enabled:hover:bg-primary'
                )}
                dir="ltr"
              >
                {date.day}
              </button>
            </div>
          );
        })}
      </div>

      <div className="grid min-h-[34px] border-b border-border" style={columns} data-testid="calendar-all-day">
        <div className="self-center px-2 text-end text-xs text-muted-foreground" style={{ gridColumn: 1, gridRow: 1 }}>
          {t('grid.allDay', 'All day')}
        </div>
        {days.map((date, i) => (
          <div
            key={dateKey(date)}
            className={cn('border-s border-border', isWeekend(date, weekendDays) && 'bg-muted')}
            style={{ gridColumn: i + 2, gridRow: `1 / span ${Math.max(1, barRows.length === 0 ? 1 : Math.max(...barRows) + 1)}` }}
          />
        ))}
        {bars.map(({ item, first, last }, i) => (
          <button
            key={item.key}
            type="button"
            onClick={() => onSelect(item)}
            className="z-[1] mx-[3px] my-1 flex h-[26px] min-w-0 items-center overflow-hidden rounded-md px-2 text-start text-xs font-semibold whitespace-nowrap text-foreground outline-none focus-visible:outline-2 focus-visible:outline-ring"
            style={{ ...itemStyle(item.kind === 'timeoff' ? 'timeoff' : 'booking', item.colour), gridColumn: `${first + 2} / span ${last - first + 1}`, gridRow: barRows[i] + 1 }}
            aria-label={itemLabel(item, null, null)}
            data-testid="calendar-bar-item"
          >
            <span className="truncate">{item.title}</span>
          </button>
        ))}
      </div>

      <div className="grid" style={columns}>
        <div className="relative" style={{ height }} aria-hidden="true">
          {Array.from({ length: endHour - startHour - 1 }, (_, i) => startHour + i + 1).map((hour) => (
            <span
              key={hour}
              className="absolute end-2 -translate-y-1/2 text-xs text-muted-foreground tabular-nums"
              style={{ top: (hour - startHour) * HOUR_PX }}
              dir="ltr"
            >
              {minuteToTime(hour * 60)}
            </span>
          ))}
        </div>
        {days.map((date, dayIndex) => {
          const placed = assignLanes(
            timed
              .map((item) => ({ item, place: placeInDay(item, date, timeZone) }))
              .filter((entry) => entry.place !== null)
              .map((entry) => ({ item: entry.item, startMinute: entry.place!.startMinute, endMinute: entry.place!.endMinute }))
          );
          const nearEnd = dayIndex >= days.length - 2 && days.length > 2;
          return (
            <div
              key={dateKey(date)}
              className={cn('relative border-s border-border', isWeekend(date, weekendDays) && 'bg-muted')}
              style={{
                height,
                backgroundImage: `repeating-linear-gradient(to bottom, var(--border) 0 1px, transparent 1px ${HOUR_PX}px)`,
              }}
              data-testid="calendar-day-column"
              data-date={dateKey(date)}
            >
              {placed.map(({ item, startMinute, endMinute, lane, lanes }) => {
                const top = ((startMinute - startHour * 60) / 60) * HOUR_PX;
                const tall = ((endMinute - startMinute) / 60) * HOUR_PX;
                if (top + tall <= 0 || top >= height) return null;
                const from = dates.time(item.starts_at);
                const to = dates.time(item.ends_at);
                const long = endMinute - startMinute >= SECOND_LINE_MINUTES;
                const selected = item.key === selectedKey;
                const offset = lanes > 1 ? (lane * 34) / (lanes - 1) : 0;
                return (
                  <div key={item.key}>
                    <button
                      type="button"
                      onClick={() => onSelect(selected ? null : item)}
                      data-testid="calendar-item"
                      data-kind={item.kind}
                      aria-label={itemLabel(item, from, to)}
                      aria-expanded={selected}
                      className={cn(
                        'absolute box-border grid content-start overflow-hidden rounded-md px-1.5 py-[3px] text-start text-xs leading-[18px] text-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                        selected && 'z-[2] shadow-[0_0_0_2px_var(--card),0_0_0_4px_var(--primary)]'
                      )}
                      style={{
                        ...itemStyle(item.kind, item.colour),
                        top: Math.max(top, 0) + 1,
                        height: Math.max(Math.min(tall, height - top) - 3, 18),
                        insetInlineStart: `calc(${offset}% + 2px)`,
                        width: lanes > 1 ? 'calc(66% - 4px)' : 'calc(100% - 6px)',
                        zIndex: selected ? 2 : lane + 1,
                      }}
                    >
                      <b className={cn('truncate font-semibold', item.kind === 'cancelled' && 'line-through')}>
                        {from !== null && <bdi dir="ltr" className="tabular-nums">{from}</bdi>} {item.title}
                      </b>
                      {long && item.who !== '' && <span className="truncate">{item.who}</span>}
                      {long && wide && item.where !== '' && <span className="truncate">{item.where}</span>}
                    </button>
                    {selected && renderDetails && (
                      <div
                        className="absolute z-[6]"
                        style={{
                          top: Math.max(0, Math.min(top - 30, height - 320)),
                          ...(wide
                            ? { insetInlineEnd: 16 }
                            : nearEnd
                              ? { insetInlineEnd: 'calc(100% + 10px)' }
                              : { insetInlineStart: 'calc(100% + 10px)' }),
                        }}
                      >
                        {renderDetails(item)}
                      </div>
                    )}
                  </div>
                );
              })}
              {sameDate(date, today) && now !== null && now >= startHour * 60 && now <= endHour * 60 && (
                <div
                  className="pointer-events-none absolute -start-px end-0 z-[2] border-t-2 border-destructive"
                  style={{ top: ((now - startHour * 60) / 60) * HOUR_PX }}
                  data-testid="calendar-now"
                  aria-hidden="true"
                >
                  <span className="absolute -start-1.5 -top-[7px] size-3 rounded-full bg-destructive" />
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

/** The hours to draw: the earliest opening to the latest closing, widened to fit what is booked. */
export function hoursToDraw(
  openings: { start_time: string; end_time: string }[],
  items: CalItem[],
  days: CalendarDate[],
  timeZone: string
): { startHour: number; endHour: number } {
  let start = 24;
  let end = 0;
  for (const range of openings) {
    const from = Number(range.start_time.slice(0, 2));
    const to = Math.ceil(Number(range.end_time.slice(0, 2)) + Number(range.end_time.slice(3, 5)) / 60);
    if (Number.isFinite(from) && Number.isFinite(to)) {
      start = Math.min(start, from);
      end = Math.max(end, to);
    }
  }
  if (start >= end) {
    start = 8;
    end = 19;
  }
  for (const item of items) {
    if (item.allDay) continue;
    for (const date of days) {
      const place = placeInDay(item, date, timeZone);
      if (place === null) continue;
      start = Math.min(start, Math.floor(place.startMinute / 60));
      end = Math.max(end, Math.ceil(place.endMinute / 60));
    }
  }
  return { startHour: Math.max(0, start), endHour: Math.min(24, Math.max(end, start + 1)) };
}
