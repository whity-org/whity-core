/**
 * The calendar's layout arithmetic (WHIT-944): which days a view shows, which
 * of them are the weekend, and where a booking sits in a day column. Pure, so
 * it is tested on its own (`web/__tests__/calendar-layout.test.ts`).
 *
 * THREE RULES THIS FILE KEEPS
 * ---------------------------
 * 1. Days are CALENDAR DATES in the workspace's zone, never browser-local
 *    `Date`s. A day column for Saturday 14 November in Amman runs from
 *    00:00 +03:00 to the next midnight, whatever zone the laptop is in
 *    (ADR 0017, section 2). Instants are read through `wallClockAt` and built
 *    through `zonedInstant`, which follow the server's rules for clock changes.
 * 2. The week starts on the workspace's `week_start` and the weekend is its
 *    `weekend_days` (decision register: Saturday, and Friday and Saturday, by
 *    default). Nothing here assumes Monday or Sunday.
 * 3. ORDER IS LOGICAL, NEVER VISUAL. `weekDays` returns the week in reading
 *    order and nothing here knows about direction. The grid lays the days out
 *    along the inline axis, so in Arabic the first day sits on the right and the
 *    time column on the right with no branch on the language. Reversing the
 *    array for RTL would mirror it twice.
 */

import {
  toZonedIso,
  wallClockAt,
  zonedInstant,
  type CalendarDate,
} from '@whity/features/datetime';

export type { CalendarDate };

export type CalendarView = 'day' | 'week' | 'month';

/** The API's weekday names, in ISO order (index + 1 is the ISO weekday). */
export const WEEKDAY_NAMES = [
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
  'saturday',
  'sunday',
] as const;

export type WeekdayName = (typeof WEEKDAY_NAMES)[number];

/** ISO weekday (1 Monday to 7 Sunday) for an API weekday name; Saturday when unreadable. */
export function isoWeekdayOf(name: string): number {
  const index = WEEKDAY_NAMES.indexOf(name as WeekdayName);
  return index === -1 ? 6 : index + 1;
}

/** The API weekday name for an ISO weekday. */
export function weekdayNameOf(isoWeekday: number): WeekdayName {
  return WEEKDAY_NAMES[(((isoWeekday - 1) % 7) + 7) % 7];
}

function utcOf(date: CalendarDate): Date {
  return new Date(Date.UTC(date.year, date.month - 1, date.day));
}

function fromUtc(value: Date): CalendarDate {
  return { year: value.getUTCFullYear(), month: value.getUTCMonth() + 1, day: value.getUTCDate() };
}

/** `date` moved by `days` calendar days. */
export function addDays(date: CalendarDate, days: number): CalendarDate {
  const moved = utcOf(date);
  moved.setUTCDate(moved.getUTCDate() + days);
  return fromUtc(moved);
}

/** `date` moved by whole months, on the 1st (a month view only needs the month). */
export function addMonths(date: CalendarDate, months: number): CalendarDate {
  const index = date.year * 12 + (date.month - 1) + months;
  return { year: Math.floor(index / 12), month: (index % 12) + 1, day: 1 };
}

/** The ISO weekday of a calendar date: 1 Monday to 7 Sunday. */
export function isoWeekday(date: CalendarDate): number {
  const day = utcOf(date).getUTCDay();
  return day === 0 ? 7 : day;
}

export function sameDate(a: CalendarDate, b: CalendarDate): boolean {
  return a.year === b.year && a.month === b.month && a.day === b.day;
}

/** Sort and compare key, also used in URLs: `2026-11-14`. */
export function dateKey(date: CalendarDate): string {
  return `${String(date.year).padStart(4, '0')}-${String(date.month).padStart(2, '0')}-${String(date.day).padStart(2, '0')}`;
}

/** A `YYYY-MM-DD` key back into a date, or null when it is not one. */
export function parseDateKey(key: string | null | undefined): CalendarDate | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(key ?? '');
  if (match === null) return null;
  const date = { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
  return dateKey(fromUtc(utcOf(date))) === key ? date : null;
}

/** The first day of the week holding `date`, for a week that starts on `weekStart`. */
export function startOfWeek(date: CalendarDate, weekStart: string): CalendarDate {
  const back = (isoWeekday(date) - isoWeekdayOf(weekStart) + 7) % 7;
  return addDays(date, -back);
}

/** The seven days of the week holding `date`, in reading order. */
export function weekDays(date: CalendarDate, weekStart: string): CalendarDate[] {
  const first = startOfWeek(date, weekStart);
  return Array.from({ length: 7 }, (_, i) => addDays(first, i));
}

/** Whether `date` falls on one of the workspace's weekend days. */
export function isWeekend(date: CalendarDate, weekendDays: readonly string[]): boolean {
  return weekendDays.includes(weekdayNameOf(isoWeekday(date)));
}

/**
 * The weeks a month view shows: whole weeks from the one holding the 1st to
 * the one holding the last day, so 4 to 6 rows of 7.
 */
export function monthWeeks(date: CalendarDate, weekStart: string): CalendarDate[][] {
  const first = { year: date.year, month: date.month, day: 1 };
  const last = addDays(addMonths(first, 1), -1);
  const weeks: CalendarDate[][] = [];
  let cursor = startOfWeek(first, weekStart);
  while (dateKey(cursor) <= dateKey(last)) {
    weeks.push(Array.from({ length: 7 }, (_, i) => addDays(cursor, i)));
    cursor = addDays(cursor, 7);
  }
  return weeks;
}

/** The days a view shows, in reading order, and the range to ask the API for. */
export interface ViewRange {
  days: CalendarDate[];
  /** First day shown. */
  first: CalendarDate;
  /** The day after the last one shown (exclusive end). */
  end: CalendarDate;
}

export function viewRange(view: CalendarView, anchor: CalendarDate, weekStart: string): ViewRange {
  if (view === 'day') {
    return { days: [anchor], first: anchor, end: addDays(anchor, 1) };
  }
  if (view === 'week') {
    const days = weekDays(anchor, weekStart);
    return { days, first: days[0], end: addDays(days[6], 1) };
  }
  const days = monthWeeks(anchor, weekStart).flat();
  return { days, first: days[0], end: addDays(days[days.length - 1], 1) };
}

/** The anchor one step back or forward in a view. */
export function stepAnchor(view: CalendarView, anchor: CalendarDate, direction: 1 | -1): CalendarDate {
  if (view === 'day') return addDays(anchor, direction);
  if (view === 'week') return addDays(anchor, 7 * direction);
  const moved = addMonths(anchor, direction);
  // Keep the day of month where the month has it, so Week after Month lands near.
  const lastOfMonth = addDays(addMonths(moved, 1), -1).day;
  return { ...moved, day: Math.min(anchor.day, lastOfMonth) };
}

/** `from` and `to` for the API: the range's midnights in the workspace's zone, with the offset. */
export function rangeQuery(range: { first: CalendarDate; end: CalendarDate }, timeZone: string): { from: string; to: string } {
  return {
    from: toZonedIso(zonedInstant(range.first, 0, timeZone), timeZone),
    to: toZonedIso(zonedInstant(range.end, 0, timeZone), timeZone),
  };
}

/**
 * An instant inside `date` in the zone (its noon), as a wire string, for
 * `useDateDisplay().calendarLabel` to print a heading from. Noon, because
 * midnight is the one hour a clock change can skip.
 */
export function labelInstant(date: CalendarDate, timeZone: string): string {
  return zonedInstant(date, 12 * 60, timeZone).toISOString();
}

/** The calendar date an instant falls on in the zone. */
export function dateInZone(instant: Date, timeZone: string): CalendarDate {
  const wall = wallClockAt(instant, timeZone);
  return { year: wall.year, month: wall.month, day: wall.day };
}

/** Something with a start and an end on the wire. */
export interface Timed {
  starts_at: string;
  ends_at: string;
}

function instant(value: string): number {
  return new Date(value).getTime();
}

/** Whether an item overlaps `date` (its local midnight to the next) in the zone. */
export function overlapsDay(item: Timed, date: CalendarDate, timeZone: string): boolean {
  const dayStart = zonedInstant(date, 0, timeZone).getTime();
  const dayEnd = zonedInstant(addDays(date, 1), 0, timeZone).getTime();
  const start = instant(item.starts_at);
  const end = Math.max(instant(item.ends_at), start + 1);
  return start < dayEnd && end > dayStart;
}

/** Where an item sits in a day column, in wall-clock minutes from that day's midnight. */
export interface DayPlacement {
  startMinute: number;
  endMinute: number;
  /** The item started on an earlier day. */
  continuesBefore: boolean;
  /** The item ends on a later day. */
  continuesAfter: boolean;
}

/**
 * Wall-clock minutes, not elapsed ones: a column shows 24 rows of hours, so a
 * 09:00 booking on a clock-change day is still drawn at the 09:00 line.
 */
export function placeInDay(item: Timed, date: CalendarDate, timeZone: string): DayPlacement | null {
  if (!overlapsDay(item, date, timeZone)) return null;
  const dayStart = zonedInstant(date, 0, timeZone).getTime();
  const dayEnd = zonedInstant(addDays(date, 1), 0, timeZone).getTime();
  const start = instant(item.starts_at);
  const end = instant(item.ends_at);

  const minuteOf = (at: number) => {
    const wall = wallClockAt(new Date(at), timeZone);
    return wall.hour * 60 + wall.minute;
  };

  const continuesBefore = start < dayStart;
  const continuesAfter = end > dayEnd;
  const startMinute = continuesBefore ? 0 : minuteOf(start);
  const endMinute = continuesAfter || end >= dayEnd ? 1440 : minuteOf(end);
  return {
    startMinute,
    // At least a quarter of an hour tall, so a zero-length item can be clicked.
    endMinute: Math.max(endMinute, Math.min(startMinute + 15, 1440)),
    continuesBefore,
    continuesAfter,
  };
}

/** An item's place among the items it overlaps: lane `lane` of `lanes`, side by side. */
export interface Lane {
  lane: number;
  lanes: number;
}

/**
 * Side-by-side lanes for overlapping items in one day column. Items are
 * grouped into clusters that overlap each other; within a cluster each takes
 * the first free lane, and every item in the cluster shares the cluster's lane
 * count so their widths match.
 */
export function assignLanes<T extends { startMinute: number; endMinute: number }>(items: T[]): (T & Lane)[] {
  const sorted = items
    .map((item, index) => ({ item, index }))
    .sort((a, b) => a.item.startMinute - b.item.startMinute || b.item.endMinute - a.item.endMinute || a.index - b.index);

  const result: (T & Lane)[] = new Array(items.length);
  let cluster: { index: number; lane: number }[] = [];
  let laneEnds: number[] = [];
  let clusterEnd = -1;

  const close = () => {
    for (const member of cluster) {
      result[member.index] = { ...items[member.index], lane: member.lane, lanes: laneEnds.length };
    }
    cluster = [];
    laneEnds = [];
  };

  for (const { item, index } of sorted) {
    if (item.startMinute >= clusterEnd) close();
    let lane = laneEnds.findIndex((end) => end <= item.startMinute);
    if (lane === -1) {
      lane = laneEnds.length;
      laneEnds.push(item.endMinute);
    } else {
      laneEnds[lane] = item.endMinute;
    }
    cluster.push({ index, lane });
    clusterEnd = Math.max(clusterEnd, item.endMinute);
  }
  close();
  return result;
}

/** Free times grouped by the day they start on in the zone, in time order. */
export function groupByDay<T extends { starts_at: string }>(items: T[], timeZone: string): { date: CalendarDate; items: T[] }[] {
  const groups = new Map<string, { date: CalendarDate; items: T[] }>();
  const sorted = [...items].sort((a, b) => instant(a.starts_at) - instant(b.starts_at));
  for (const item of sorted) {
    const date = dateInZone(new Date(item.starts_at), timeZone);
    const key = dateKey(date);
    const group = groups.get(key) ?? { date, items: [] };
    group.items.push(item);
    groups.set(key, group);
  }
  return [...groups.values()];
}

/**
 * A wall-clock time on a date in the zone, as the ISO-with-offset string the
 * API asks for. `time` is `HH:MM`; returns null when it is not one.
 */
export function zonedIsoFor(date: CalendarDate, time: string, timeZone: string): string | null {
  const match = /^(\d{2}):(\d{2})$/.exec(time);
  if (match === null) return null;
  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 24 || minutes > 59 || (hours === 24 && minutes > 0)) return null;
  return toZonedIso(zonedInstant(date, hours * 60 + minutes, timeZone), timeZone);
}

/** `HH:MM` for an instant in the zone: the value of a time field, not a label. */
export function timeFieldValue(value: string, timeZone: string): string {
  const wall = wallClockAt(new Date(value), timeZone);
  return `${String(wall.hour).padStart(2, '0')}:${String(wall.minute).padStart(2, '0')}`;
}
