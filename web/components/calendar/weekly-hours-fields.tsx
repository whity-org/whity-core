'use client';

/**
 * A week of hours as fields (TplCalendarStaff: hours with several ranges):
 * one row per day, "Not working" or one or more from–to ranges, a second range
 * being a break. Controlled: it edits a {@link HoursWeek} and says nothing about
 * saving, so the calendar's Hours page and a plugin setup form (the
 * `weeklyHours` block, SDK 1.113) draw the same thing.
 */

import { useCalendarWords } from '@/components/calendar/use-calendar-words';
import { FieldSelect } from '@/components/field-select';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Switch } from '@whity/ui/switch';
import { useTranslation } from '@whity/features/i18n';
import type { HoursRange, HoursWeek } from '@/lib/calendar/hours';
import { timeOptions, timeToMinute } from '@/lib/calendar/times';

const STARTS = timeOptions(15);
const ENDS = timeOptions(15, true);
const START_OPTIONS = STARTS.map((v) => ({ value: v, label: v }));
const END_OPTIONS = ENDS.map((v) => ({ value: v, label: v }));

/** The range a day gets when it is opened or a range is added after `last`. */
export function nextRange(last: HoursRange | undefined, opening: HoursRange): HoursRange {
  if (last === undefined) return opening;
  const startMinute = timeToMinute(last.end) ?? 540;
  const start = startMinute >= 1440 ? '23:00' : last.end;
  const end = ENDS[Math.min(ENDS.indexOf(start) + 16, ENDS.length - 1)] ?? '24:00';
  return { start, end };
}

/**
 * A break in `range`: the range split around its middle with an hour off
 * (10:00–21:00 becomes 10:00–15:00 and 16:00–21:00). A range under three
 * hours has no room for one, so it is left as it is (null).
 */
export function splitForBreak(range: HoursRange): [HoursRange, HoursRange] | null {
  const start = timeToMinute(range.start);
  const end = timeToMinute(range.end);
  if (start === null || end === null || end - start < 180) return null;
  const off = Math.floor((start + (end - start) / 2 - 30) / 15) * 15;
  const back = off + 60;
  const at = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
  return [{ start: range.start, end: at(off) }, { start: at(back), end: range.end }];
}

export interface WeeklyHoursFieldsProps {
  /** Prefix for element ids and test ids. */
  idPrefix: string;
  week: HoursWeek;
  /** ISO weekdays in the order the week is drawn. */
  order: number[];
  disabled?: boolean;
  onChange: (week: HoursWeek) => void;
  /**
   * Show an open/closed switch on each day and a "Copy to every day" action,
   * as a setup form does. The Hours page keeps its quieter add/remove layout.
   */
  withDayToggles?: boolean;
}

export function WeeklyHoursFields({ idPrefix, week, order, disabled = false, onChange, withDayToggles = false }: WeeklyHoursFieldsProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();

  const setDay = (day: number, ranges: HoursRange[]) => onChange({ ...week, [day]: ranges });
  // What a day gets when it is switched on: the first open day's hours, so a
  // salon that opens 10 to 9 does not set 9 to 5 six times.
  const firstOpen = order.map((d) => week[d] ?? []).find((r) => r.length > 0);
  const opening: HoursRange = firstOpen?.[0] ?? { start: '09:00', end: '17:00' };

  const copyToAll = (day: number) => {
    const ranges = week[day] ?? [];
    const next: HoursWeek = { ...week };
    for (const d of order) {
      if ((next[d] ?? []).length > 0 || d === day) next[d] = ranges.map((r) => ({ ...r }));
    }
    onChange(next);
  };

  return (
    <ul className="divide-y divide-divider" data-testid={`${idPrefix}-week`}>
      {order.map((day) => {
        const ranges = week[day] ?? [];
        const open = ranges.length > 0;
        return (
          <li key={day} className="grid gap-2 py-3 sm:grid-cols-[9rem_1fr]" data-testid={`${idPrefix}-day-${day}`}>
            {withDayToggles ? (
              <label className="flex min-h-10 items-center gap-2 self-start text-sm font-medium text-foreground">
                <Switch
                  checked={open}
                  disabled={disabled}
                  aria-label={words.weekday(day)}
                  onCheckedChange={(on) => setDay(day, on ? [{ ...opening }] : [])}
                  data-testid={`${idPrefix}-open-${day}`}
                />
                {words.weekday(day)}
              </label>
            ) : (
              <span className="pt-2 text-sm font-medium text-foreground">{words.weekday(day)}</span>
            )}
            <div className="space-y-2">
              {!open && (
                <p className="flex min-h-10 items-center text-sm text-muted-foreground">
                  {withDayToggles ? t('hours.closedDay', 'Closed') : t('hours.closed', 'Not working')}
                </p>
              )}
              {ranges.map((range, index) => (
                <div key={index} className="flex flex-wrap items-center gap-2">
                  <div className="w-28">
                    <FieldSelect
                      id={`${idPrefix}-${day}-${index}-start`}
                      value={range.start}
                      disabled={disabled}
                      onChange={(start) => setDay(day, ranges.map((r, i) => (i === index ? { ...r, start } : r)))}
                      options={START_OPTIONS}
                    />
                  </div>
                  <span className="text-sm text-muted-foreground">{t('hours.to', 'to')}</span>
                  <div className="w-28">
                    <FieldSelect
                      id={`${idPrefix}-${day}-${index}-end`}
                      value={range.end}
                      disabled={disabled}
                      onChange={(end) => setDay(day, ranges.map((r, i) => (i === index ? { ...r, end } : r)))}
                      options={END_OPTIONS}
                    />
                  </div>
                  {!disabled && (!withDayToggles || ranges.length > 1) && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => setDay(day, ranges.filter((_, i) => i !== index))}>
                      {t('hours.removeRange', 'Remove')}
                    </Button>
                  )}
                </div>
              ))}
              {!disabled && (open || !withDayToggles) && (
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid={`${idPrefix}-add-${day}`}
                    onClick={() => {
                      const last = ranges[ranges.length - 1];
                      const split = withDayToggles && last !== undefined ? splitForBreak(last) : null;
                      setDay(day, split ? [...ranges.slice(0, -1), ...split] : [...ranges, nextRange(last, opening)]);
                    }}
                  >
                    <Icon name="plus" />
                    {ranges.length === 0
                      ? t('hours.addHours', 'Add hours')
                      : withDayToggles
                        ? t('hours.addBreak', 'Add a break')
                        : t('hours.addRange', 'Add another range')}
                  </Button>
                  {withDayToggles && open && (
                    <Button type="button" variant="ghost" size="sm" data-testid={`${idPrefix}-copy-${day}`} onClick={() => copyToAll(day)}>
                      {t('hours.copyToAll', 'Copy to every open day')}
                    </Button>
                  )}
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/** Says which day's ranges end before they start or overlap. */
export function WeeklyHoursProblem({ day }: { day: number }) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  return (
    <p className="text-sm text-destructive" role="alert">
      {t('hours.error.overlap', 'On {day}, each end must come after its start, and the hours must not overlap.', { day: words.weekday(day) })}
    </p>
  );
}
