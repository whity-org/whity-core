'use client';

/**
 * One day with a column for each person and each room (design 3.3,
 * `TplAppointments`), drawn with the kit's time grid (WHIT-958).
 *
 * A booking sits in its person's column and, when its service needs a room,
 * in that room's column too. A busy event is blocked time in its person's
 * column. Booked is the main tint, checked in green, a request dashed amber,
 * blocked grey; the kit draws a red line at now on today.
 *
 * Times go in as the wall clock of the workspace's zone, "HH:MM", so the grid
 * reads the same for everyone whatever their own zone. Something that started
 * the day before or ends the day after is cut at midnight.
 */

import { useEffect, useState } from 'react';
import { Avatar } from '@whity/ui/avatar';
import { Icon } from '@whity/ui/icon';
import { TimeGrid, type TimeBlock, type TimeBlockState, type TimeGridColumn } from '@whity/ui/time-grid';
import { useTranslation } from '@whity/features/i18n';
import { zonedInstant } from '@whity/features/datetime';
import { sameDate, type CalendarDate } from '@/lib/calendar/layout';
import type { CalendarRoom, CalendarStaff } from '@/lib/calendar/api';
import type { GridItem } from './calendar-grid';
import { useCalendarWords } from './use-calendar-words';

const DAY_MINUTES = 24 * 60;

function hhmm(minutes: number): string {
  const m = Math.max(0, Math.min(DAY_MINUTES, Math.round(minutes)));
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
}

function stateOf(tone: GridItem['tone']): TimeBlockState {
  switch (tone) {
    case 'checkedIn':
      return 'checked-in';
    case 'request':
    case 'pending':
      return 'request';
    case 'busy':
      return 'blocked';
    default:
      return 'booked';
  }
}

export interface DayColumnsProps {
  day: CalendarDate;
  today: CalendarDate;
  timeZone: string;
  items: GridItem[];
  staff: CalendarStaff[];
  rooms: CalendarRoom[];
  /** The branch shown, or null for all. */
  unitId: number | null;
  /** The grid's accessible name: the day, and the branch when one is chosen. */
  label: string;
  onOpen: (item: GridItem) => void;
}

/** Whether a day is worth columns: more than one person or room to show. */
export function wantsColumns(staff: CalendarStaff[], rooms: CalendarRoom[]): boolean {
  return staff.length + rooms.filter((r) => r.active).length > 1;
}

export function DayColumns({ day, today, timeZone, items, staff, rooms, unitId, label, onOpen }: DayColumnsProps) {
  const t = useTranslation('calendar');
  const words = useCalendarWords();
  // The red line moves once a minute.
  const [clock, setClock] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setClock(Date.now()), 60_000);
    return () => window.clearInterval(timer);
  }, []);

  const people = staff.filter((s) => unitId === null || s.unit_id === unitId);
  const shownRooms = rooms.filter((r) => r.active && (unitId === null || r.unit_id === unitId));

  const columns: TimeGridColumn[] = [
    ...people.map((s) => ({
      id: `p${s.profile_id}`,
      header: (
        <>
          <Avatar name={s.display_name} size={28} />
          <span className="min-w-0 truncate">{s.display_name}</span>
        </>
      ),
    })),
    ...shownRooms.map((r) => ({
      id: `r${r.id}`,
      header: (
        <>
          <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-muted" aria-hidden="true">
            <Icon name="tenant" className="size-4" />
          </span>
          <span className="min-w-0 truncate">
            {words.label(r.name)}
            <span className="sr-only"> {t('columns.room', '(room)')}</span>
          </span>
        </>
      ),
    })),
  ];

  const dayStart = zonedInstant(day, 0, timeZone).getTime();
  const minutesOf = (iso: string) => (new Date(iso).getTime() - dayStart) / 60_000;

  const blocks: TimeBlock[] = [];
  let first = 8 * 60;
  let last = 18 * 60;
  for (const item of items) {
    if (item.allDay) continue;
    const start = Math.max(0, minutesOf(item.starts_at));
    const end = Math.min(DAY_MINUTES, minutesOf(item.ends_at));
    if (end <= 0 || start >= DAY_MINUTES || end <= start) continue;
    first = Math.min(first, Math.floor(start / 60) * 60);
    last = Math.max(last, Math.ceil(end / 60) * 60);
    const owner = item.booking?.profile_id ?? item.event?.profile_id;
    const targets: string[] = [];
    if (owner !== undefined) targets.push(`p${owner}`);
    if (item.booking?.room_id !== null && item.booking?.room_id !== undefined) targets.push(`r${item.booking.room_id}`);
    for (const column of targets) {
      blocks.push({
        id: `${item.key}-${column}`,
        column,
        start: hhmm(start),
        end: hhmm(end),
        title: item.title,
        detail: <span className="block">{item.subtitle}</span>,
        state: stateOf(item.tone),
        onOpen: () => onOpen(item),
      });
    }
  }

  let now: string | undefined;
  if (sameDate(day, today)) now = hhmm((clock - dayStart) / 60_000);

  if (columns.length === 0) {
    return (
      <p className="text-sm text-muted-foreground" data-testid="calendar-columns-none">
        {t('columns.none', 'Nobody and no room in this branch.')}
      </p>
    );
  }

  return (
    <div data-testid="calendar-day-columns">
      <TimeGrid columns={columns} blocks={blocks} startHour={first / 60} endHour={last / 60} now={now} label={label} />
    </div>
  );
}
