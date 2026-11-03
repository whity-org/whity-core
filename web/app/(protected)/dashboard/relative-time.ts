import { parseWireDate } from '@whity/features/datetime';

/** "2 days ago", "5 hr. ago": how long a thing has waited, in the viewer's language. */
export function ago(value: string | null | undefined, locale: string, now: number = Date.now()): string {
  const date = parseWireDate(value);
  if (date === null) return '';
  const seconds = Math.round((date.getTime() - now) / 1000);
  const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style: 'short' });
  const steps: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ];
  for (const [unit, size] of steps) {
    if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit);
  }
  return formatter.format(0, 'minute');
}

/** The part of the day, for the greeting. */
export function partOfDay(now: Date = new Date()): 'morning' | 'afternoon' | 'evening' {
  const hour = now.getHours();
  if (hour < 12) return 'morning';
  return hour < 18 ? 'afternoon' : 'evening';
}
