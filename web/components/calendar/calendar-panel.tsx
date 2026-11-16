'use client';

/**
 * The calendar's left panel (design 3.5, CV.2, `TplCalendarWeek`): New booking
 * (the one main button), a mini month with the shown range banded, search,
 * People and Rooms and branches as coloured checkboxes, Also show, and the
 * time zone. Every tick is the person's own choice and is remembered.
 *
 * The checkboxes take the colour of what Colour by means (CV.4): People are
 * coloured when colouring by person, Rooms when by room; Services appear as
 * their own group only when colouring by service. The rest are plain.
 */

import { useState, type ReactNode } from 'react';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { offsetMinutesAt, useDateDisplay } from '@whity/features/datetime';
import { cn } from '@/lib/utils';
import {
  addDays,
  addMonths,
  dateKey,
  isoWeekday,
  labelInstant,
  monthWeeks,
  sameDate,
  type CalendarDate,
} from '@/lib/calendar/layout';
import type { AlsoShow } from '@/lib/calendar/choices';

export interface PanelEntry {
  id: number;
  label: string;
  /** The checkbox colour; null draws it plain (grey). */
  colour: string | null;
  shown: boolean;
}

export interface CalendarPanelProps {
  timeZone: string;
  weekStart: string;
  today: CalendarDate;
  /** The first and last day in view, banded on the mini month. */
  shownFrom: CalendarDate;
  shownTo: CalendarDate;
  onPickDate: (date: CalendarDate) => void;
  search: string;
  onSearch: (text: string) => void;
  people: PanelEntry[];
  rooms: PanelEntry[];
  /** Only while colouring by service. */
  services: PanelEntry[] | null;
  onToggle: (group: 'people' | 'rooms' | 'services', id: number, shown: boolean) => void;
  alsoShow: AlsoShow[];
  onAlsoShow: (what: AlsoShow, shown: boolean) => void;
  canBook: boolean;
  onNewBooking: () => void;
  /** Something the screen adds under the panel (the Requests waiting for an OK). */
  footer?: ReactNode;
}

/** "Amman (GMT+3)" from the zone's name and its offset now. */
export function zoneLabel(timeZone: string): string {
  const city = (timeZone.split('/').pop() ?? timeZone).replace(/_/g, ' ');
  try {
    // An offset, not a date: "GMT+3", "GMT+5:30", "GMT" for UTC itself.
    const minutes = offsetMinutesAt(new Date(), timeZone);
    const sign = minutes < 0 ? '-' : '+';
    const hours = Math.floor(Math.abs(minutes) / 60);
    const rest = Math.abs(minutes) % 60;
    const offset = minutes === 0 ? 'GMT' : `GMT${sign}${hours}${rest === 0 ? '' : `:${String(rest).padStart(2, '0')}`}`;
    return `${city} (${offset})`;
  } catch {
    return city;
  }
}

export function Check({
  entry,
  onChange,
  testId,
}: {
  entry: PanelEntry;
  onChange: (shown: boolean) => void;
  testId: string;
}) {
  const colour = entry.colour ?? 'var(--muted-foreground)';
  return (
    <label className="flex min-h-9 cursor-pointer items-center gap-2.5 text-sm text-foreground" data-testid={testId}>
      <input
        type="checkbox"
        className="peer sr-only"
        checked={entry.shown}
        onChange={(event) => onChange(event.target.checked)}
      />
      <span
        aria-hidden="true"
        className="grid size-5 shrink-0 place-items-center rounded-[5px] border-2 text-white peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring"
        style={{ borderColor: colour, background: entry.shown ? colour : 'transparent' }}
      >
        {entry.shown && <Icon name="check" size={14} />}
      </span>
      <span className="min-w-0 truncate">{entry.label}</span>
    </label>
  );
}

export function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div role="group" aria-label={title}>
      <h4 className="mb-0.5 text-xs font-semibold text-muted-foreground">{title}</h4>
      {children}
    </div>
  );
}

/** One letter a day, Monday first (ISO), as the cards write them (S M T W T F S; ح ن ث ر خ ج س). */
export function useDayLetters(): string[] {
  const t = useTranslation('calendar');
  return [
    t('panel.letter.mon', 'M'),
    t('panel.letter.tue', 'T'),
    t('panel.letter.wed', 'W'),
    t('panel.letter.thu', 'T'),
    t('panel.letter.fri', 'F'),
    t('panel.letter.sat', 'S'),
    t('panel.letter.sun', 'S'),
  ];
}

/** The mini month: jumps to any date; the range in view is banded, today filled. */
export function MiniMonth({
  timeZone,
  weekStart,
  today,
  shownFrom,
  shownTo,
  onPickDate,
}: Pick<CalendarPanelProps, 'timeZone' | 'weekStart' | 'today' | 'shownFrom' | 'shownTo' | 'onPickDate'>) {
  const t = useTranslation('calendar');
  const dates = useDateDisplay({ timeZone });
  // The month shown follows the calendar until the person pages it themselves.
  const [paged, setPaged] = useState<{ base: string; month: CalendarDate } | null>(null);
  const base = { year: shownFrom.year, month: shownFrom.month, day: 1 };
  const month = paged !== null && paged.base === dateKey(base) ? paged.month : base;
  const weeks = monthWeeks(month, weekStart);
  const letters = useDayLetters();
  const from = dateKey(shownFrom);
  const to = dateKey(shownTo);

  return (
    <div className="grid gap-1" data-testid="calendar-mini-month">
      <div className="flex items-center justify-between">
        <b className="text-sm font-semibold text-foreground">{dates.calendarLabel(labelInstant(month, timeZone), 'monthYear') ?? ''}</b>
        <span className="flex">
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('panel.previousMonth', 'Previous month')}
            title={t('panel.previousMonth', 'Previous month')}
            onClick={() => setPaged({ base: dateKey(base), month: addMonths(month, -1) })}
          >
            <Icon name="chevron-left" className="rtl:-scale-x-100" />
          </Button>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={t('panel.nextMonth', 'Next month')}
            title={t('panel.nextMonth', 'Next month')}
            onClick={() => setPaged({ base: dateKey(base), month: addMonths(month, 1) })}
          >
            <Icon name="chevron-right" className="rtl:-scale-x-100" />
          </Button>
        </span>
      </div>
      <div className="grid grid-cols-7 gap-y-0.5 text-center text-xs tabular-nums">
        {weeks[0].map((date) => (
          <span key={`h${dateKey(date)}`} className="grid h-8 place-items-center font-medium text-muted-foreground" aria-hidden="true">
            {letters[isoWeekday(date) - 1]}
          </span>
        ))}
        {weeks.flat().map((date) => {
          const key = dateKey(date);
          const inRange = key >= from && key <= to;
          const first = key === from || (inRange && dateKey(addDays(date, -1)) < from);
          const last = key === to;
          const isToday = sameDate(date, today);
          return (
            <button
              key={key}
              type="button"
              onClick={() => onPickDate(date)}
              aria-label={dates.calendarLabel(labelInstant(date, timeZone), 'fullDate') ?? key}
              aria-current={isToday ? 'date' : undefined}
              data-date={key}
              data-shown={inRange ? '' : undefined}
              className={cn(
                'grid h-8 place-items-center outline-none focus-visible:outline-2 focus-visible:outline-ring',
                date.month !== month.month && 'text-muted-foreground',
                inRange && 'bg-primary-tint text-primary-tint-foreground',
                inRange && first && 'rounded-s-full',
                inRange && last && 'rounded-e-full',
                !inRange && 'rounded-full hover:bg-muted'
              )}
              dir="ltr"
            >
              <span
                className={cn(
                  'grid size-[30px] place-items-center rounded-full',
                  isToday && 'bg-primary font-semibold text-primary-foreground'
                )}
              >
                {date.day}
              </span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

export function CalendarPanel(props: CalendarPanelProps) {
  const t = useTranslation('calendar');
  const also = (what: AlsoShow, label: string) => (
    <Check
      key={what}
      testId={`calendar-also-${what}`}
      entry={{ id: 0, label, colour: null, shown: props.alsoShow.includes(what) }}
      onChange={(shown) => props.onAlsoShow(what, shown)}
    />
  );

  return (
    <aside className="grid content-start gap-[18px]" aria-label={t('panel.label', 'Calendar choices')} data-testid="calendar-panel">
      {props.canBook && (
        <Button size="lg" className="w-full" onClick={props.onNewBooking} data-testid="calendar-new-booking">
          <Icon name="plus" />
          {t('toolbar.newBooking', 'New booking')}
        </Button>
      )}
      <MiniMonth
        timeZone={props.timeZone}
        weekStart={props.weekStart}
        today={props.today}
        shownFrom={props.shownFrom}
        shownTo={props.shownTo}
        // @date-display-ignore: a callback that jumps to a date, not a timestamp rendered here
        onPickDate={props.onPickDate}
      />
      <label className="flex h-10 items-center gap-2 rounded-control border border-input bg-background ps-3 pe-1 text-sm text-muted-foreground focus-within:outline-2 focus-within:outline-ring">
        <Icon name="search" />
        <span className="sr-only">{t('panel.search', 'Search bookings and people')}</span>
        <input
          type="search"
          value={props.search}
          onChange={(event) => props.onSearch(event.target.value)}
          placeholder={t('panel.search', 'Search bookings and people')}
          className="min-w-0 flex-1 bg-transparent text-foreground outline-none placeholder:text-muted-foreground [&::-webkit-search-cancel-button]:hidden"
          data-testid="calendar-search"
        />
      </label>
      {props.services !== null && props.services.length > 0 && (
        <Group title={t('panel.services', 'Services')}>
          {props.services.map((entry) => (
            <Check key={entry.id} testId="calendar-service-check" entry={entry} onChange={(shown) => props.onToggle('services', entry.id, shown)} />
          ))}
        </Group>
      )}
      {props.people.length > 0 && (
        <Group title={t('panel.people', 'People')}>
          {props.people.map((entry) => (
            <Check key={entry.id} testId="calendar-person-check" entry={entry} onChange={(shown) => props.onToggle('people', entry.id, shown)} />
          ))}
        </Group>
      )}
      {props.rooms.length > 0 && (
        <Group title={t('panel.rooms', 'Rooms and branches')}>
          {props.rooms.map((entry) => (
            <Check key={entry.id} testId="calendar-room-check" entry={entry} onChange={(shown) => props.onToggle('rooms', entry.id, shown)} />
          ))}
        </Group>
      )}
      <Group title={t('panel.alsoShow', 'Also show')}>
        {also('team_events', t('panel.teamEvents', 'Team events'))}
        {also('time_off', t('panel.timeOff', 'Time off'))}
        {also('cancelled', t('panel.cancelled', 'Cancelled bookings'))}
      </Group>
      <p className="m-0 text-xs text-muted-foreground" data-testid="calendar-zone">
        {t('toolbar.zone', 'Times in {zone}', { zone: zoneLabel(props.timeZone) })}
      </p>
      {props.footer}
    </aside>
  );
}
