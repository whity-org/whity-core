/**
 * A person's weekly hours as the editor holds them (WHIT-944): ranges per ISO
 * weekday, wall-clock `HH:MM`. The API takes and returns a flat list of rows;
 * the editor works by day.
 */

import type { CalendarHours } from './api';
import { timeToMinute } from './times';

export type HoursRange = { start: string; end: string };
export type HoursWeek = Record<number, HoursRange[]>;

export function weekOf(rows: CalendarHours[]): HoursWeek {
  const week: HoursWeek = { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [], 7: [] };
  for (const row of rows) {
    week[row.weekday]?.push({ start: row.start_time.slice(0, 5), end: row.end_time.slice(0, 5) });
  }
  for (const day of Object.keys(week)) {
    week[Number(day)].sort((a, b) => a.start.localeCompare(b.start));
  }
  return week;
}

export function rowsOf(week: HoursWeek): CalendarHours[] {
  return Object.entries(week).flatMap(([day, ranges]) =>
    ranges.map((r) => ({ weekday: Number(day), start_time: r.start, end_time: r.end }))
  );
}

/** The first ISO weekday whose ranges are out of order or overlap, or null. */
export function firstBadDay(week: HoursWeek): number | null {
  for (const [day, ranges] of Object.entries(week)) {
    const sorted = [...ranges].sort((a, b) => a.start.localeCompare(b.start));
    for (let i = 0; i < sorted.length; i++) {
      const start = timeToMinute(sorted[i].start);
      const end = timeToMinute(sorted[i].end);
      if (start === null || end === null || end <= start || start >= 1440) return Number(day);
      if (i > 0 && start < (timeToMinute(sorted[i - 1].end) ?? 0)) return Number(day);
    }
  }
  return null;
}

/** The ISO weekdays in the order the workspace's week runs, from its first day. */
export function weekOrder(firstIsoWeekday: number): number[] {
  return Array.from({ length: 7 }, (_, i) => ((firstIsoWeekday - 1 + i) % 7) + 1);
}
