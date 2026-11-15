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
  /** CV.9: whether an item may be dragged, resized or moved with the keys. */
  canDrag?: (item: CalItem) => boolean;
  /** CV.8: click or drag on an empty time. A click gives no end: the default length applies. */
  onCreate?: (slot: GridSlot) => void;
  /** CV.9, CV.10: an item dropped at a new time or pulled to a new length. */
  onChange?: (item: CalItem, to: GridSlot & { endMinute: number }, how: 'move' | 'resize') => void;
  /** A change waiting for an answer, or the new booking being made: drawn dashed where it would go. */
  preview?: GridPreview | null;
  /** The quick booking beside a new slot (`preview` with no key). */
  renderDraft?: () => ReactNode;
}

/** A place on the grid: a day and minutes from its midnight. */
export interface GridSlot {
  date: CalendarDate;
  startMinute: number;
  endMinute: number | null;
}

export interface GridPreview {
  /** The item being changed; null for a new booking. */
  key: string | null;
  date: CalendarDate;
  startMinute: number;
  endMinute: number;
  how: 'move' | 'resize' | 'create';
}

/** Drags move in quarters of an hour (CV.9). */
const STEP = 15;
const snapDown = (minute: number) => Math.floor(minute / STEP) * STEP;
const snap = (minute: number) => Math.round(minute / STEP) * STEP;
/** How long a finger holds a booking before it lifts (CV.10). */
const HOLD_MS = 500;

interface Drag {
  how: 'move' | 'resize' | 'create';
  item: CalItem | null;
  touch: boolean;
  originX: number;
  originY: number;
  active: boolean;
  /** Minutes between the booking's start and where it was grabbed. */
  grab: number;
  dayIndex: number;
  start: number;
  end: number;
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
  canDrag,
  onCreate,
  onChange,
  preview = null,
  renderDraft,
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
  const columnRefs = useRef<(HTMLDivElement | null)[]>([]);
  const [drag, setDrag] = useState<Drag | null>(null);
  const dragRef = useRef<Drag | null>(null);
  const holdTimer = useRef<number | null>(null);
  const swallowClick = useRef(false);
  // CV.10: arrows move the selected booking; Enter saves, Esc puts it back.
  const [keyed, setKeyed] = useState<GridPreview | null>(null);
  const first = startHour * 60;
  const last = endHour * 60;

  /** Which day and minute a pointer is over; outside the columns, the nearest. */
  const locate = (x: number, y: number): { dayIndex: number; minute: number } => {
    let dayIndex = 0;
    let best = Infinity;
    columnRefs.current.forEach((column, i) => {
      if (column === null) return;
      const rect = column.getBoundingClientRect();
      const distance = x < rect.left ? rect.left - x : x > rect.right ? x - rect.right : 0;
      if (distance < best) {
        best = distance;
        dayIndex = i;
      }
    });
    const top = columnRefs.current[dayIndex]?.getBoundingClientRect().top ?? 0;
    return { dayIndex, minute: first + ((y - top) / HOUR_PX) * 60 };
  };

  const stopListening = useRef<() => void>(() => undefined);
  useEffect(() => () => stopListening.current(), []);

  const begin = (event: React.PointerEvent, start: Omit<Drag, 'touch' | 'originX' | 'originY' | 'active'>) => {
    if (event.button !== 0 || dragRef.current !== null) return;
    const touch = event.pointerType === 'touch';
    const current: Drag = { ...start, touch, originX: event.clientX, originY: event.clientY, active: false };
    dragRef.current = current;
    const activate = () => {
      const d = dragRef.current;
      if (d === null) return;
      d.active = true;
      setDrag({ ...d });
    };
    if (touch && start.how !== 'create') {
      holdTimer.current = window.setTimeout(() => {
        holdTimer.current = null;
        activate();
        navigator.vibrate?.(15);
      }, HOLD_MS);
    }
    const move = (ev: PointerEvent) => {
      const d = dragRef.current;
      if (d === null) return;
      const distance = Math.hypot(ev.clientX - d.originX, ev.clientY - d.originY);
      if (!d.active) {
        if (d.touch) {
          // A finger that moves before the hold is scrolling, not dragging.
          if (distance > 8) cancel();
          return;
        }
        if (distance < 4) return;
        d.active = true;
      }
      ev.preventDefault();
      const at = locate(ev.clientX, ev.clientY);
      if (d.how === 'create') {
        const to = Math.min(last, Math.max(d.start + STEP, Math.ceil(at.minute / STEP) * STEP));
        d.end = to;
      } else if (d.how === 'move') {
        const length = d.end - d.start;
        const start = Math.min(last - length, Math.max(first, snap(at.minute - d.grab)));
        d.dayIndex = at.dayIndex;
        d.start = start;
        d.end = start + length;
      } else {
        d.end = Math.min(last, Math.max(d.start + STEP, snap(at.minute)));
      }
      setDrag({ ...d });
    };
    const up = () => {
      const d = dragRef.current;
      finish();
      if (d === null) return;
      if (!d.active) {
        // A plain click on an empty time makes a slot as long as the service.
        if (d.how === 'create') onCreate?.({ date: days[d.dayIndex], startMinute: d.start, endMinute: null });
        return;
      }
      if (d.how === 'create') {
        onCreate?.({ date: days[d.dayIndex], startMinute: d.start, endMinute: d.end });
        return;
      }
      swallowClick.current = true;
      window.setTimeout(() => {
        swallowClick.current = false;
      }, 0);
      if (d.item !== null) onChange?.(d.item, { date: days[d.dayIndex], startMinute: d.start, endMinute: d.end }, d.how);
    };
    const cancel = () => {
      finish();
    };
    const finish = () => {
      if (holdTimer.current !== null) window.clearTimeout(holdTimer.current);
      holdTimer.current = null;
      dragRef.current = null;
      setDrag(null);
      stopListening.current();
    };
    window.addEventListener('pointermove', move, { passive: false });
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', cancel);
    stopListening.current = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      window.removeEventListener('pointercancel', cancel);
      stopListening.current = () => undefined;
    };
  };

  const shown: GridPreview | null =
    drag !== null && drag.active
      ? { key: drag.item?.key ?? null, date: days[drag.dayIndex], startMinute: drag.start, endMinute: drag.end, how: drag.how }
      : keyed ?? preview;

  const dayTag = (date: CalendarDate) => `${words.weekdayShort(isoWeekday(date))} ${date.day}`;
  const span = (minutes: number) =>
    minutes % 60 === 0
      ? t('grid.hours', '{count} hours', { count: minutes / 60 })
      : minutes < 60
        ? t('grid.minutesShort', '{count} minutes', { count: minutes })
        : t('grid.hoursMinutes', '{hours} h {minutes} min', { hours: Math.floor(minutes / 60), minutes: minutes % 60 });

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
          // Past the middle, things open on the start side so they stay on screen.
          const nearEnd = days.length > 1 && (dayIndex + 1) / days.length > 0.55;
          const beside: CSSProperties = wide
            ? { insetInlineEnd: 16 }
            : nearEnd
              ? { insetInlineEnd: 'calc(100% + 10px)' }
              : { insetInlineStart: 'calc(100% + 10px)' };
          const ghostHere = shown !== null && sameDate(shown.date, date);
          const ghostItem = ghostHere && shown.key !== null ? items.find((item) => item.key === shown.key) ?? null : null;
          const ghostTop = ghostHere ? ((shown.startMinute - first) / 60) * HOUR_PX : 0;
          const ghostTall = ghostHere ? ((shown.endMinute - shown.startMinute) / 60) * HOUR_PX : 0;
          return (
            <div
              key={dateKey(date)}
              ref={(element) => {
                columnRefs.current[dayIndex] = element;
              }}
              className={cn('relative border-s border-border', isWeekend(date, weekendDays) && 'bg-muted', onCreate && 'cursor-cell')}
              style={{
                height,
                backgroundImage: `repeating-linear-gradient(to bottom, var(--border) 0 1px, transparent 1px ${HOUR_PX}px)`,
              }}
              data-testid="calendar-day-column"
              data-date={dateKey(date)}
              onPointerDown={(event) => {
                if (event.target !== event.currentTarget || onCreate === undefined) return;
                // With details or a new slot open, a click elsewhere only closes them.
                if (selectedKey !== null || preview !== null) {
                  onSelect(null);
                  return;
                }
                const at = locate(event.clientX, event.clientY);
                const start = Math.min(last - STEP, Math.max(first, snapDown(at.minute)));
                begin(event, { how: 'create', item: null, grab: 0, dayIndex, start, end: start + STEP });
              }}
            >
              {placed.map(({ item, startMinute, endMinute, lane, lanes }) => {
                const top = ((startMinute - first) / 60) * HOUR_PX;
                const tall = ((endMinute - startMinute) / 60) * HOUR_PX;
                if (top + tall <= 0 || top >= height) return null;
                const from = dates.time(item.starts_at);
                const to = dates.time(item.ends_at);
                const long = endMinute - startMinute >= SECOND_LINE_MINUTES;
                const selected = item.key === selectedKey;
                const offset = lanes > 1 ? (lane * 34) / (lanes - 1) : 0;
                const draggable = canDrag?.(item) === true && onChange !== undefined;
                // Being moved: the old place stays, dashed (CV.9).
                const leaving = shown !== null && shown.key === item.key && shown.how === 'move';
                const resizing = shown !== null && shown.key === item.key && shown.how === 'resize';
                const height2 = resizing ? ((shown.endMinute - startMinute) / 60) * HOUR_PX : Math.min(tall, height - top);
                return (
                  <div key={item.key}>
                    <button
                      type="button"
                      onClick={() => {
                        if (swallowClick.current) return;
                        onSelect(selected ? null : item);
                      }}
                      onPointerDown={
                        draggable
                          ? (event) => {
                              const at = locate(event.clientX, event.clientY);
                              begin(event, { how: 'move', item, grab: at.minute - startMinute, dayIndex, start: startMinute, end: endMinute });
                            }
                          : undefined
                      }
                      onKeyDown={
                        draggable
                          ? (event) => {
                              const rtl = grid.current !== null && getComputedStyle(grid.current).direction === 'rtl';
                              const base = keyed !== null && keyed.key === item.key ? keyed : { key: item.key, date, startMinute, endMinute, how: 'move' as const };
                              const baseIndex = days.findIndex((d) => sameDate(d, base.date));
                              const length = base.endMinute - base.startMinute;
                              const shift = (dayStep: number, minutes: number) => {
                                const index = Math.min(days.length - 1, Math.max(0, baseIndex + dayStep));
                                const startAt = Math.min(last - length, Math.max(first, base.startMinute + minutes));
                                setKeyed({ key: item.key, date: days[index], startMinute: startAt, endMinute: startAt + length, how: 'move' });
                              };
                              if (event.key === 'ArrowUp') shift(0, -STEP);
                              else if (event.key === 'ArrowDown') shift(0, STEP);
                              else if (event.key === 'ArrowLeft') shift(rtl ? 1 : -1, 0);
                              else if (event.key === 'ArrowRight') shift(rtl ? -1 : 1, 0);
                              else if (event.key === 'Enter' && keyed !== null && keyed.key === item.key) {
                                setKeyed(null);
                                onChange?.(item, { date: keyed.date, startMinute: keyed.startMinute, endMinute: keyed.endMinute }, 'move');
                              } else if (event.key === 'Escape' && keyed !== null) {
                                setKeyed(null);
                                event.stopPropagation();
                              } else return;
                              event.preventDefault();
                            }
                          : undefined
                      }
                      data-testid="calendar-item"
                      data-kind={item.kind}
                      aria-label={itemLabel(item, from, to)}
                      aria-expanded={selected}
                      aria-keyshortcuts={draggable ? 'ArrowUp ArrowDown ArrowLeft ArrowRight Enter Escape' : undefined}
                      className={cn(
                        'absolute box-border grid content-start overflow-hidden rounded-md px-1.5 py-[3px] text-start text-xs leading-[18px] text-foreground outline-none select-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                        draggable && 'cursor-grab touch-manipulation',
                        selected && !leaving && 'z-[2] shadow-[0_0_0_2px_var(--card),0_0_0_4px_var(--primary)]',
                        resizing && 'z-[3] shadow-float'
                      )}
                      style={{
                        ...itemStyle(item.kind, item.colour),
                        ...(leaving ? { background: 'transparent', border: `1.5px dashed ${item.colour}`, color: 'var(--muted-foreground)' } : {}),
                        top: Math.max(top, 0) + 1,
                        height: Math.max(height2 - 3, 18),
                        insetInlineStart: `calc(${offset}% + 2px)`,
                        width: lanes > 1 ? 'calc(66% - 4px)' : 'calc(100% - 6px)',
                        zIndex: selected || resizing ? 3 : lane + 1,
                      }}
                      data-leaving={leaving ? '' : undefined}
                    >
                      <b className={cn('truncate font-semibold', item.kind === 'cancelled' && 'line-through')}>
                        {from !== null && <bdi dir="ltr" className="tabular-nums">{from}</bdi>} {item.title}
                      </b>
                      {long && item.who !== '' && <span className="truncate">{item.who}</span>}
                      {long && wide && item.where !== '' && <span className="truncate">{item.where}</span>}
                    </button>
                    {draggable && !leaving ? (
                      // Pull the bottom edge to change how long it is (CV.9).
                      <span
                        role="presentation"
                        className="absolute z-[4] h-2 cursor-ns-resize"
                        style={{
                          top: Math.max(top, 0) + Math.max(height2 - 3, 18) - 6,
                          insetInlineStart: `calc(${offset}% + 2px)`,
                          width: lanes > 1 ? 'calc(66% - 4px)' : 'calc(100% - 6px)',
                        }}
                        onPointerDown={(event) => {
                          event.stopPropagation();
                          begin(event, { how: 'resize', item, grab: 0, dayIndex, start: startMinute, end: endMinute });
                        }}
                        data-testid="calendar-resize"
                      >
                        {(selected || resizing) && (
                          <span className="absolute start-1/2 bottom-[3px] -ms-3.5 h-1 w-7 rounded-sm" style={{ background: item.colour }} />
                        )}
                      </span>
                    ) : null}
                    {resizing && (
                      <span
                        className="pointer-events-none absolute z-[5] rounded-md bg-foreground px-2 py-[3px] text-xs font-semibold whitespace-nowrap text-background"
                        style={{ top: Math.max(top, 0) + height2 + 2, insetInlineStart: `calc(${offset}% + 2px)` }}
                        data-testid="calendar-drag-label"
                      >
                        {t('grid.until', 'Until {time} · {length}', { time: minuteToTime(shown.endMinute), length: span(shown.endMinute - startMinute) })}
                      </span>
                    )}
                    {selected && renderDetails && shown === null && (
                      <div className="absolute z-[6]" style={{ top: Math.max(0, Math.min(top - 30, height - 320)), ...beside }}>
                        {renderDetails(item)}
                      </div>
                    )}
                  </div>
                );
              })}
              {ghostHere && shown.how === 'move' && ghostItem !== null && (
                <>
                  <div
                    className="pointer-events-none absolute z-[3] box-border grid content-start overflow-hidden rounded-md px-1.5 py-[3px] text-xs leading-[18px] text-foreground shadow-float"
                    style={{ ...itemStyle(ghostItem.kind, ghostItem.colour), top: ghostTop + 1, height: Math.max(ghostTall - 3, 18), insetInlineStart: 2, width: 'calc(100% - 6px)' }}
                    data-testid="calendar-ghost"
                  >
                    <b className="truncate font-semibold">
                      <bdi dir="ltr" className="tabular-nums">{minuteToTime(shown.startMinute)}</bdi> {ghostItem.title}
                    </b>
                    {ghostTall >= 40 && <span className="truncate">{ghostItem.who}</span>}
                  </div>
                  <span
                    className="pointer-events-none absolute z-[5] rounded-md bg-foreground px-2 py-[3px] text-xs font-semibold whitespace-nowrap text-background"
                    style={{ top: Math.max(0, ghostTop - 24), insetInlineStart: 2 }}
                    data-testid="calendar-drag-label"
                  >
                    <bdi>{dayTag(date)}</bdi> · <bdi dir="ltr">{minuteToTime(shown.startMinute)} – {minuteToTime(shown.endMinute)}</bdi>
                  </span>
                </>
              )}
              {ghostHere && shown.how === 'create' && (
                <>
                  <div
                    className="pointer-events-none absolute start-0.5 end-1.5 z-[3] box-border grid content-start rounded-md border-2 border-dashed border-primary px-2 py-[3px] text-xs leading-[18px] text-foreground"
                    style={{ top: ghostTop + 1, height: Math.max(ghostTall - 3, 18), background: 'color-mix(in oklab, var(--primary) 12%, var(--card))' }}
                    data-testid="calendar-new-slot"
                  >
                    <b className="font-semibold" dir="ltr">
                      {minuteToTime(shown.startMinute)} – {minuteToTime(shown.endMinute)}
                    </b>
                    {ghostTall >= 40 && <span>{t('toolbar.newBooking', 'New booking')}</span>}
                  </div>
                  {renderDraft && drag === null && (
                    <div className="absolute z-[6]" style={{ top: Math.max(0, Math.min(ghostTop - 30, height - 420)), ...beside }}>
                      {renderDraft()}
                    </div>
                  )}
                </>
              )}
              {sameDate(date, today) && now !== null && now >= first && now <= last && (
                <div
                  className="pointer-events-none absolute -start-px end-0 z-[2] border-t-2 border-destructive"
                  style={{ top: ((now - first) / 60) * HOUR_PX }}
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
