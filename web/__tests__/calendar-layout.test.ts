/**
 * The calendar's layout arithmetic (WHIT-944): week start, weekend, the
 * workspace's zone rather than the browser's, and reading order.
 *
 * These run in jsdom's default zone (UTC on CI). That is the point of the zone
 * tests: a shop in Amman must see 09:00 for a 06:00Z booking on a laptop that
 * thinks it is in London or New York.
 */

import {
  addDays,
  assignLanes,
  dateKey,
  groupByDay,
  isWeekend,
  isoWeekday,
  monthWeeks,
  parseDateKey,
  placeInDay,
  rangeQuery,
  startOfWeek,
  stepAnchor,
  viewRange,
  weekDays,
  zonedIsoFor,
} from '@/lib/calendar/layout';
import {
  offsetMinutesAt,
  toZonedIso,
  wallClockAt,
  zonedInstant,
} from '@whity/features/datetime';
import { formatCalendarLabel, formatTime } from '../../packages/features/src/datetime/format';

const AMMAN = 'Asia/Amman';
// Wednesday 11 November 2026.
const WED = { year: 2026, month: 11, day: 11 };

describe('week start', () => {
  it('starts the week on Saturday by default (the Jordan convention)', () => {
    const days = weekDays(WED, 'saturday');
    expect(days.map(dateKey)).toEqual([
      '2026-11-07',
      '2026-11-08',
      '2026-11-09',
      '2026-11-10',
      '2026-11-11',
      '2026-11-12',
      '2026-11-13',
    ]);
    expect(isoWeekday(days[0])).toBe(6);
  });

  it('follows the workspace setting', () => {
    expect(dateKey(startOfWeek(WED, 'monday'))).toBe('2026-11-09');
    expect(dateKey(startOfWeek(WED, 'sunday'))).toBe('2026-11-08');
    // A day that is itself the week start stays put.
    expect(dateKey(startOfWeek({ year: 2026, month: 11, day: 7 }, 'saturday'))).toBe('2026-11-07');
  });

  it('builds a month of whole weeks from the configured start', () => {
    const weeks = monthWeeks(WED, 'saturday');
    expect(weeks[0][0]).toEqual({ year: 2026, month: 10, day: 31 });
    expect(weeks.every((week) => week.length === 7)).toBe(true);
    expect(weeks.every((week) => isoWeekday(week[0]) === 6)).toBe(true);
    const last = weeks[weeks.length - 1];
    expect(last.some((d) => dateKey(d) === '2026-11-30')).toBe(true);
  });

  it('gives each view its days and an exclusive end', () => {
    expect(viewRange('day', WED, 'saturday').days).toHaveLength(1);
    const week = viewRange('week', WED, 'saturday');
    expect(dateKey(week.first)).toBe('2026-11-07');
    expect(dateKey(week.end)).toBe('2026-11-14');
    const month = viewRange('month', WED, 'saturday');
    expect(month.days.length % 7).toBe(0);
  });

  it('steps by a day, a week, or a month', () => {
    expect(dateKey(stepAnchor('day', WED, 1))).toBe('2026-11-12');
    expect(dateKey(stepAnchor('week', WED, -1))).toBe('2026-11-04');
    expect(dateKey(stepAnchor('month', { year: 2026, month: 1, day: 31 }, 1))).toBe('2026-02-28');
  });
});

describe('weekend', () => {
  it('greys Friday and Saturday by default, and whatever the workspace chose otherwise', () => {
    const days = weekDays(WED, 'saturday');
    expect(days.filter((d) => isWeekend(d, ['friday', 'saturday'])).map(dateKey)).toEqual([
      '2026-11-07',
      '2026-11-13',
    ]);
    expect(days.filter((d) => isWeekend(d, ['saturday', 'sunday'])).map(dateKey)).toEqual([
      '2026-11-07',
      '2026-11-08',
    ]);
    expect(days.filter((d) => isWeekend(d, []))).toHaveLength(0);
  });
});

describe('zone conversion', () => {
  it('reads an instant on the workspace clock, not the browser’s', () => {
    // 06:00Z is 09:00 in Amman (UTC+3 all year since 2022).
    const wall = wallClockAt(new Date('2026-11-14T06:00:00Z'), AMMAN);
    expect([wall.hour, wall.minute, wall.weekday]).toEqual([9, 0, 6]);
    expect(formatTime('2026-11-14T06:00:00Z', 'en', AMMAN)).toBe('09:00');
    expect(formatTime('2026-11-14T06:00:00Z', 'en', 'America/New_York')).toBe('01:00');
  });

  it('asks the API for the range as the zone’s midnights, with the offset', () => {
    const range = viewRange('week', WED, 'saturday');
    expect(rangeQuery(range, AMMAN)).toEqual({
      from: '2026-11-07T00:00:00+03:00',
      to: '2026-11-14T00:00:00+03:00',
    });
  });

  it('places a booking at its wall-clock time in the day column', () => {
    const placement = placeInDay(
      { starts_at: '2026-11-14T06:00:00Z', ends_at: '2026-11-14T06:45:00Z' },
      { year: 2026, month: 11, day: 14 },
      AMMAN
    );
    expect(placement).toEqual({ startMinute: 540, endMinute: 585, continuesBefore: false, continuesAfter: false });
    // The same instant is on the previous day in New York.
    expect(placeInDay({ starts_at: '2026-11-14T03:00:00Z', ends_at: '2026-11-14T04:00:00Z' }, { year: 2026, month: 11, day: 14 }, 'America/New_York')).toBeNull();
  });

  it('cuts an item that crosses midnight at the edges of each day', () => {
    const item = { starts_at: '2026-11-14T20:00:00Z', ends_at: '2026-11-14T23:00:00Z' }; // 23:00 to 02:00 Amman
    expect(placeInDay(item, { year: 2026, month: 11, day: 14 }, AMMAN)).toMatchObject({ startMinute: 1380, endMinute: 1440, continuesAfter: true });
    expect(placeInDay(item, { year: 2026, month: 11, day: 15 }, AMMAN)).toMatchObject({ startMinute: 0, endMinute: 120, continuesBefore: true });
  });

  it('builds the request time from a picked date and time in the zone', () => {
    expect(zonedIsoFor({ year: 2026, month: 11, day: 14 }, '09:30', AMMAN)).toBe('2026-11-14T09:30:00+03:00');
    expect(zonedIsoFor({ year: 2026, month: 11, day: 14 }, '9:30', AMMAN)).toBeNull();
  });

  it('follows the server across clock changes', () => {
    const london = 'Europe/London';
    // Spring forward, 29 March 2026: 01:30 does not exist and becomes 02:30 BST, as in PHP.
    expect(toZonedIso(zonedInstant({ year: 2026, month: 3, day: 29 }, 90, london), london)).toBe('2026-03-29T02:30:00+01:00');
    // Fall back, 25 October 2026: 01:30 happens twice; the first one is meant.
    expect(zonedInstant({ year: 2026, month: 10, day: 25 }, 90, london).toISOString()).toBe('2026-10-25T00:30:00.000Z');
    expect(offsetMinutesAt(new Date('2026-07-01T12:00:00Z'), 'America/New_York')).toBe(-240);
  });

  it('groups free times by the day they start on in the zone', () => {
    const groups = groupByDay(
      [
        { starts_at: '2026-11-14T22:00:00Z' }, // 01:00 on the 15th in Amman
        { starts_at: '2026-11-14T06:00:00Z' },
      ],
      AMMAN
    );
    expect(groups.map((g) => dateKey(g.date))).toEqual(['2026-11-14', '2026-11-15']);
  });

  it('prints headings in the zone with Levantine Arabic months and Western digits', () => {
    expect(formatCalendarLabel('2026-10-27T09:00:00Z', 'monthYear', 'ar', AMMAN)).toBe('تشرين الأول 2026');
    expect(formatCalendarLabel('2026-11-14T09:00:00Z', 'weekdayDay', 'en', AMMAN)).toMatch(/Sat.*14/);
    expect(formatCalendarLabel('2026-11-14T09:00:00Z', 'dayNumber', 'ar', AMMAN)).toBe('14');
  });
});

describe('reading order', () => {
  it('returns days in reading order with no knowledge of direction', () => {
    // The grid mirrors itself in Arabic through `dir`; reversing here would mirror it twice.
    const days = weekDays(WED, 'saturday');
    for (let i = 1; i < days.length; i++) {
      expect(dateKey(days[i]) > dateKey(days[i - 1])).toBe(true);
    }
    expect(dateKey(addDays(days[6], 1))).toBe('2026-11-14');
  });
});

describe('lanes', () => {
  it('puts overlapping items side by side and lets the rest take the full width', () => {
    const lanes = assignLanes([
      { id: 'a', startMinute: 540, endMinute: 600 },
      { id: 'b', startMinute: 570, endMinute: 630 },
      { id: 'c', startMinute: 700, endMinute: 760 },
    ]);
    expect(lanes.map((l) => [l.id, l.lane, l.lanes])).toEqual([
      ['a', 0, 2],
      ['b', 1, 2],
      ['c', 0, 1],
    ]);
  });
});

describe('date keys', () => {
  it('reads a URL date back and refuses one that is not a day', () => {
    expect(parseDateKey('2026-11-14')).toEqual({ year: 2026, month: 11, day: 14 });
    expect(parseDateKey('2026-02-30')).toBeNull();
    expect(parseDateKey('tomorrow')).toBeNull();
  });
});
