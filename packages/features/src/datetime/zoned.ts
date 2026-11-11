/**
 * Wall-clock time in a named time zone — the arithmetic half of showing a
 * calendar in the WORKSPACE's zone rather than the browser's (WHIT-944, ADR 0017
 * section 2).
 *
 * WHY THIS LIVES IN THE SANCTIONED DIRECTORY
 * ------------------------------------------
 * Reading "what day and hour is this instant in Asia/Amman" needs
 * `Intl.DateTimeFormat#formatToParts`, which `scripts/ci-date-display-guard.php`
 * refuses anywhere but here. Nothing in this file puts a date on a screen: it
 * returns NUMBERS (year, month, day, hour, minute, weekday) for a layout to
 * place things by, and instants for a request body. Words for a person still go
 * through {@see useDateDisplay}, which is where `ui.hide_dates` is honoured.
 *
 * THE RULES, WHICH MATCH THE SERVER
 * ---------------------------------
 * The server turns working hours into instants with PHP's
 * `new DateTimeImmutable('Y-m-d H:i', $zone)`. A screen that offered a time the
 * server reads differently would show a free slot in one place and book it in
 * another, so {@see zonedInstant} copies PHP exactly:
 *  - a local time that does not exist (the spring-forward gap) is read with the
 *    offset from BEFORE the gap, which lands after it: 01:30 on the London
 *    change day is 02:30 BST;
 *  - a local time that happens twice (fall-back) is the FIRST occurrence.
 */

/** A day on the calendar, with no time and no zone: 1-based month. */
export interface CalendarDate {
  year: number
  month: number
  day: number
}

/** What a wall clock in some zone reads at an instant. `weekday` is ISO: 1 Monday to 7 Sunday. */
export interface WallClock extends CalendarDate {
  hour: number
  minute: number
  second: number
  weekday: number
}

const WEEKDAYS: Record<string, number> = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 }

const formatters = new Map<string, Intl.DateTimeFormat>()

function partsFormatter(timeZone: string): Intl.DateTimeFormat {
  let formatter = formatters.get(timeZone)
  if (formatter === undefined) {
    // en-US with latn digits is a fixed, parseable shape; nothing here is shown.
    formatter = new Intl.DateTimeFormat('en-US-u-nu-latn', {
      timeZone,
      hourCycle: 'h23',
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      hour: 'numeric',
      minute: 'numeric',
      second: 'numeric',
      weekday: 'short',
    })
    formatters.set(timeZone, formatter)
  }
  return formatter
}

/** Whether the runtime knows this IANA zone name. */
export function isValidTimeZone(timeZone: string | null | undefined): boolean {
  if (typeof timeZone !== 'string' || timeZone === '') return false
  try {
    partsFormatter(timeZone)
    return true
  } catch {
    return false
  }
}

/** The zone to use: the one given when the runtime knows it, otherwise UTC (what the server defaults to). */
export function safeTimeZone(timeZone: string | null | undefined): string {
  return isValidTimeZone(timeZone) ? (timeZone as string) : 'UTC'
}

/** What a wall clock in `timeZone` reads at `instant`. */
export function wallClockAt(instant: Date, timeZone: string): WallClock {
  const parts: Record<string, string> = {}
  for (const part of partsFormatter(timeZone).formatToParts(instant)) {
    parts[part.type] = part.value
  }
  const hour = Number(parts.hour)
  return {
    year: Number(parts.year),
    month: Number(parts.month),
    day: Number(parts.day),
    // Some engines print midnight as 24 even with h23.
    hour: hour === 24 ? 0 : hour,
    minute: Number(parts.minute),
    second: Number(parts.second),
    weekday: WEEKDAYS[parts.weekday] ?? 1,
  }
}

/** The zone's offset from UTC at `instant`, in minutes (Amman: +180). */
export function offsetMinutesAt(instant: Date, timeZone: string): number {
  const wall = wallClockAt(instant, timeZone)
  const asUtc = Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute, wall.second)
  const whole = Math.floor(instant.getTime() / 1000) * 1000
  return Math.round((asUtc - whole) / 60000)
}

/**
 * The instant a wall clock in `timeZone` reads `minutes` after midnight on
 * `date`, under PHP's rules for gaps and repeats (see the module note).
 * `minutes` may be 1440 or more: it is wall-clock time counted from that
 * midnight, so `24:00` is the next day's midnight.
 */
export function zonedInstant(date: CalendarDate, minutes: number, timeZone: string): Date {
  const local = Date.UTC(date.year, date.month - 1, date.day, 0, minutes)
  const day = 86_400_000
  const before = offsetMinutesAt(new Date(local - day), timeZone)
  const after = offsetMinutesAt(new Date(local + day), timeZone)

  const reads = (candidate: number) => {
    const wall = wallClockAt(new Date(candidate), timeZone)
    return Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute) === local
  }

  const candidates = [local - before * 60000, local - after * 60000]
    .filter(reads)
    .sort((a, b) => a - b)
  if (candidates.length > 0) return new Date(candidates[0])

  // A gap: read with the offset from before it, which lands just after it.
  return new Date(local - before * 60000)
}

/** `+03:00` for 180 minutes. */
function offsetText(minutes: number): string {
  const sign = minutes < 0 ? '-' : '+'
  const abs = Math.abs(minutes)
  return `${sign}${String(Math.floor(abs / 60)).padStart(2, '0')}:${String(abs % 60).padStart(2, '0')}`
}

/**
 * An instant as ISO 8601 with the zone's own offset, which is what the calendar
 * API asks for: `2026-11-12T09:00:00+03:00`.
 */
export function toZonedIso(instant: Date, timeZone: string): string {
  const wall = wallClockAt(instant, timeZone)
  const pad = (n: number, width = 2) => String(n).padStart(width, '0')
  return (
    `${pad(wall.year, 4)}-${pad(wall.month)}-${pad(wall.day)}` +
    `T${pad(wall.hour)}:${pad(wall.minute)}:${pad(wall.second)}` +
    offsetText(offsetMinutesAt(instant, timeZone))
  )
}

/** The calendar date it is in `timeZone` at `instant` (default now). */
export function zonedDate(timeZone: string, instant: Date = new Date()): CalendarDate {
  const wall = wallClockAt(instant, timeZone)
  return { year: wall.year, month: wall.month, day: wall.day }
}
