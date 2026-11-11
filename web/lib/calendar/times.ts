/**
 * Times of day as the calendar's fields offer them: `HH:MM`, 24-hour, Western
 * digits (decision register). A list rather than a free text box, so a person
 * over 50 on a phone picks 09:30 instead of typing it, and nothing reads 9.30
 * or 9:30 PM.
 */

/** `HH:MM` every `step` minutes from 00:00; with `withMidnight`, `24:00` last (an end time). */
export function timeOptions(step = 15, withMidnight = false): string[] {
  const out: string[] = [];
  for (let minute = 0; minute < 1440; minute += step) {
    out.push(minuteToTime(minute));
  }
  if (withMidnight) out.push('24:00');
  return out;
}

export function minuteToTime(minute: number): string {
  return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
}

/** Minutes after midnight for `HH:MM`, or null when it is not one. `24:00` is 1440. */
export function timeToMinute(time: string): number | null {
  const match = /^(\d{2}):(\d{2})$/.exec(time);
  if (match === null) return null;
  const minute = Number(match[1]) * 60 + Number(match[2]);
  return Number(match[2]) < 60 && minute <= 1440 ? minute : null;
}
