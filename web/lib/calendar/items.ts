/**
 * The calendar's one list (the one-calendar model, WHIT-1109, WHIT-1116):
 * everything from `GET /api/calendar/items` turned into what the grid draws.
 * The computer and the phone use the same mapping, so a booking, a team
 * event, a meeting, time off and a task due look the same on both.
 *
 * - The key is the server's id ("booking:7", "meeting:3"), so a change can be
 *   matched to the item it came from.
 * - Kind is the type; a booking's state (waiting for payment, a request,
 *   cancelled) comes from its status and changes how it is drawn.
 * - Colour follows the person's own Colour by (CV.4): the organiser, the
 *   booking's service, or the room. Things with no service or room are grey.
 * - What may be moved or resized comes from the server's `can`.
 */

import type { components } from '@whity/api-client';
import type { CalendarBooking, CalendarEvent, CalendarRoom, CalendarService } from './api';
import type { AlsoShow } from './choices';
import { colourOf, NEUTRAL, type ColourBy, type Palette } from './colours';
import type { CalItem, ItemKind, ItemPerson } from '@/components/calendar/time-grid';

export type ServerItem = components['schemas']['CalendarItem'];

/** The kinds to ask for, from the person's Also show (CV.2). Tasks due only when ticked. */
export function kindsFor(alsoShow: readonly AlsoShow[]): string[] {
  const kinds = ['booking'];
  if (alsoShow.includes('team_events')) kinds.push('event', 'external');
  if (alsoShow.includes('meetings')) kinds.push('meeting');
  if (alsoShow.includes('time_off')) kinds.push('time_off');
  if (alsoShow.includes('tasks_due')) kinds.push('task_due');
  return kinds;
}

export interface ItemContext {
  colourBy: ColourBy;
  colours: Palette;
  staffName: Map<number, string>;
  services: Map<number, CalendarService>;
  rooms: Map<number, CalendarRoom>;
  hiddenPeople: Set<number>;
  hiddenRooms: Set<number>;
  hiddenServices: Set<number>;
  search: string;
  serviceName: (service: CalendarService | null | undefined) => string;
  label: (names: Record<string, string> | null | undefined) => string;
  /** The words the grid writes: "{name} · time off", "waiting for payment", and the like. */
  words: {
    noName: string;
    waitingPayment: string;
    request: string;
    cancelled: string;
    checkedIn: string;
    timeOff: (name: string) => string;
    guests: (count: number) => string;
  };
}

function firstName(name: string): string {
  return name.trim().split(/\s+/)[0] ?? name;
}

function bookingKind(status: string): ItemKind {
  return status === 'cancelled' ? 'cancelled' : status === 'pending_payment' ? 'wait' : status === 'requested' ? 'request' : 'booking';
}

/** A team event or meeting as the event dialog and the grid's moves read it. */
function asEvent(row: ServerItem): CalendarEvent | undefined {
  if (row.event === null || row.event === undefined) return undefined;
  return {
    id: row.event.id,
    profile_id: row.organiser_id,
    title: row.title,
    notes: row.event.notes,
    starts_at: row.starts_at,
    ends_at: row.ends_at,
    all_day: row.all_day,
    busy: row.busy,
    status: row.status,
    external_ref: row.event.external_ref,
    kind: row.kind === 'booking' ? 'event' : (row.kind as CalendarEvent['kind']),
    room_id: row.room_id,
    location: row.location,
    meeting_id: row.event.meeting_id,
    people: row.people,
  } as CalendarEvent;
}

export function toCalItems(rows: readonly ServerItem[], ctx: ItemContext): CalItem[] {
  const out: CalItem[] = [];
  for (const row of rows) {
    const booking = (row.booking ?? undefined) as CalendarBooking | undefined;
    if (ctx.hiddenPeople.has(row.organiser_id)) continue;
    if (row.room_id !== null && row.room_id !== undefined && ctx.hiddenRooms.has(row.room_id)) continue;
    if (booking && ctx.colourBy === 'service' && ctx.hiddenServices.has(booking.service_id)) continue;

    const person = ctx.staffName.get(row.organiser_id) ?? '';
    const roomName = row.room_id ? ctx.label(ctx.rooms.get(row.room_id)?.name) : '';
    const place = [roomName, row.location].filter((part) => part !== '' && part !== undefined && part !== null).join(' · ');
    const kind: ItemKind = booking
      ? bookingKind(row.status)
      : row.kind === 'meeting'
        ? 'meeting'
        : row.kind === 'time_off'
          ? 'timeoff'
          : (row.kind as string) === 'task_due'
            ? 'task'
            : 'event';
    const colour = booking
      ? colourOf(ctx.colourBy, ctx.colours, { profileId: row.organiser_id, serviceId: booking.service_id, roomId: row.room_id ?? null })
      : ctx.colourBy === 'person'
        ? colourOf('person', ctx.colours, { profileId: row.organiser_id })
        : ctx.colourBy === 'room' && row.room_id
          ? colourOf('room', ctx.colours, { roomId: row.room_id })
          : NEUTRAL;

    let title = row.title;
    let who = person;
    let where = place;
    if (booking) {
      title = ctx.serviceName(ctx.services.get(booking.service_id));
      who = [booking.customer.name ?? ctx.words.noName, firstName(person)].filter((part) => part !== '').join(' · ');
      const state =
        kind === 'wait'
          ? ctx.words.waitingPayment
          : kind === 'request'
            ? ctx.words.request
            : kind === 'cancelled'
              ? ctx.words.cancelled
              : booking.checked_in_at
                ? ctx.words.checkedIn
                : '';
      where = [booking.room_name ? ctx.label(booking.room_name) : roomName, state].filter((part) => part !== '').join(' · ');
    } else if (kind === 'timeoff') {
      // Someone else's arrives as "Away"; one's own keeps its note.
      title = ctx.words.timeOff(person);
      who = row.title;
    } else if (row.people.length > 0) {
      who = [person, ctx.words.guests(row.people.length)].filter((part) => part !== '').join(' · ');
    }

    out.push({
      key: row.id,
      kind,
      starts_at: row.starts_at,
      ends_at: row.ends_at,
      allDay: row.all_day,
      title,
      who,
      where,
      colour,
      booking,
      event: asEvent(row),
      organiserId: row.organiser_id,
      people: row.people as ItemPerson[],
      meetingId: row.event?.meeting_id ?? null,
      can: row.can,
    });
  }
  const needle = ctx.search.trim().toLowerCase();
  return needle === '' ? out : out.filter((item) => `${item.title} ${item.who} ${item.where}`.toLowerCase().includes(needle));
}
