/**
 * The calendar API as the web uses it (WHIT-944, ADR 0017). Types come from
 * the generated schema; every call goes through the typed `api` client, which carries the
 * session cookie and refreshes it.
 *
 * ERRORS. The server answers in English with an HTTP status and no stable code
 * (`{error: "..."}`), so this turns the statuses a person can act on into
 * plain words of our own: 409 is "that time was just taken", 403 names the
 * missing permission in words, 404 says the thing is gone. Only a 422, whose
 * sentence says which value is wrong, falls back to the server's text.
 */

import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import { ForbiddenError } from '@/lib/forbidden';

type Schemas = components['schemas'];

export type CalendarSettings = Schemas['CalendarSettings'];
export type CalendarSettingsRequest = Schemas['CalendarSettingsRequest'];
export type CalendarStaff = Schemas['CalendarStaff'];
export type CalendarHours = Schemas['CalendarHours'];
export type CalendarTimeOff = Schemas['CalendarTimeOff'];
export type CalendarService = Schemas['CalendarService'];
export type CalendarServiceRequest = Schemas['CalendarServiceRequest'];
export type CalendarSlot = Schemas['CalendarSlot'];
export type CalendarBooking = Schemas['CalendarBooking'];
export type CalendarBookingCreateRequest = Schemas['CalendarBookingCreateRequest'];
/** One service of a booking of several, and who does it (null: anyone). WHIT-1173. */
export interface ServiceChoice {
  service_id: number;
  profile_id: number | null;
}
export type CalendarBookingItem = Schemas['CalendarBookingItem'];
export type CalendarEvent = Schemas['CalendarEvent'];
export type CalendarEventRequest = Schemas['CalendarEventRequest'];
export type CalendarRoom = Schemas['CalendarRoom'];
export type CalendarRoomRequest = Schemas['CalendarRoomRequest'];
export type CalendarToday = Schemas['CalendarToday'];
/** WHIT-1427: did they come. */
export type CalendarAttendance = 'attended' | 'no_show' | null;
export type CalendarCustomerAttendance = Schemas['CalendarCustomerAttendance'];
export type CalendarAttendanceRates = Schemas['CalendarAttendanceRates'];
export type CalendarAttendanceReport = Schemas['CalendarAttendanceReport'];
export type CalendarAttendanceReportRow = Schemas['CalendarAttendanceReportRow'];
export type CalendarReportBy = 'staff' | 'service' | 'customer' | 'source' | 'weekday_hour' | 'reminder' | 'returning';
export type CalendarReportPeriod = 'this_week' | 'this_month' | 'last_month' | 'last_3_months' | 'last_6_months' | 'last_12_months';
export interface CalendarReportQuery {
  by: CalendarReportBy;
  period?: CalendarReportPeriod | null;
  from?: string | null;
  to?: string | null;
  profileId?: number | null;
  serviceId?: number | null;
}

function reportQuery(q: CalendarReportQuery): Record<string, string | number | null | undefined> {
  return {
    by: q.by,
    period: q.from && q.to ? null : q.period ?? 'this_month',
    from: q.from,
    to: q.to,
    profile_id: q.profileId,
    service_id: q.serviceId,
  };
}

/** WHIT-1437: where the report downloads as a spreadsheet, in the reader's language. */
export function attendanceReportCsvUrl(q: CalendarReportQuery, language: string): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries({ ...reportQuery(q), format: 'csv', lang: language })) {
    if (value !== null && value !== undefined && value !== '') params.set(key, String(value));
  }
  return `/api/v1/calendar/attendance/report?${params.toString()}`;
}

export const CALENDAR_READ = 'calendar:read';
export const CALENDAR_WRITE = 'calendar:write';
export const CALENDAR_MANAGE = 'calendar:manage';
/** WHIT-1437 (AP.9): everyone's rates; without it, a person sees their own. */
export const CALENDAR_REPORTS = 'calendar:reports';

/** A refused calendar request, with the HTTP status the screen branches on. */
export class CalendarRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly serverMessage: string | null,
    /** Why a time is not free (design 3.5, CV.9): person_busy, room_taken or closed. */
    public readonly reason: string | null = null
  ) {
    super(message);
    this.name = 'CalendarRequestError';
  }
}

/** What `api.*` resolves to; the typed client never throws on a status. */
interface Result {
  data?: unknown;
  error?: unknown;
  response: Response;
}

function serverReason(error: unknown): string | null {
  // The server sends it as `details.reason` on the 409 (CalendarRejectedException::notFree).
  const reason = (error as { details?: { reason?: unknown } } | null | undefined)?.details?.reason;
  return typeof reason === 'string' && reason !== '' ? reason : null;
}

function serverText(error: unknown): string | null {
  const text = (error as { error?: unknown } | null | undefined)?.error;
  return typeof text === 'string' && text !== '' ? text : null;
}

/** Throws ForbiddenError on 403 (for `useFetch`'s access-denied state), CalendarRequestError otherwise. */
function read<T>(result: Result, fallback: string): T {
  if (result.response.status === 403) throw new ForbiddenError();
  if (!result.response.ok) {
    throw new CalendarRequestError(fallback, result.response.status, serverText(result.error));
  }
  return (result.data as { data: T }).data;
}

function sendBody<B>(result: Result, fallback: string): B {
  if (!result.response.ok) {
    throw new CalendarRequestError(fallback, result.response.status, serverText(result.error), serverReason(result.error));
  }
  return result.data as B;
}

function send<T>(result: Result, fallback: string): T {
  return sendBody<{ data: T }>(result, fallback).data;
}

/** An event as saved, and the bookings it now sits over. */
export type CalendarEventSaved = Schemas['CalendarEventResponse'];

/** Drops the empty values (null, undefined, '') so they are not sent. */
function query(params: Record<string, string | number | null | undefined>): never {
  const out: Record<string, string | number> = {};
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') out[key] = value;
  }
  return out as never;
}

export interface RangeParams {
  from: string;
  to: string;
  profileId?: number | null;
  /** Only this branch (an organisational unit): bookings whose person or room is in it. */
  unitId?: number | null;
  /** Comma-separated statuses; the server's default is the live ones. */
  status?: string | null;
}

export const calendarApi = {
  settings: async () =>
    read<CalendarSettings>(await api.GET('/api/v1/calendar/settings'), 'The calendar settings could not be loaded'),
  saveSettings: async (body: CalendarSettingsRequest) =>
    send<CalendarSettings>(await api.PUT('/api/v1/calendar/settings', { body }), 'The calendar settings could not be saved'),

  staff: async () => read<CalendarStaff[]>(await api.GET('/api/v1/calendar/staff'), 'The team could not be loaded'),
  saveStaff: async (profileId: number, body: { bookable?: boolean; sort_order?: number; unit_id?: number | null }) =>
    send<CalendarStaff[]>(
      await api.PUT('/api/v1/calendar/staff/{profileId}', { params: { path: { profileId } }, body }),
      'That person could not be updated'
    ),

  hours: async (profileId: number) =>
    read<CalendarHours[]>(
      await api.GET('/api/v1/calendar/staff/{profileId}/hours', { params: { path: { profileId } } }),
      'The working hours could not be loaded'
    ),
  saveHours: async (profileId: number, hours: CalendarHours[]) =>
    send<CalendarHours[]>(
      await api.PUT('/api/v1/calendar/staff/{profileId}/hours', { params: { path: { profileId } }, body: { hours } }),
      'The working hours could not be saved'
    ),

  timeOff: async ({ from, to, profileId }: RangeParams) =>
    read<CalendarTimeOff[]>(
      await api.GET('/api/v1/calendar/time-off', { params: { query: query({ from, to, profile_id: profileId }) } }),
      'Time off could not be loaded'
    ),
  addTimeOff: async (body: Schemas['CalendarTimeOffRequest']) =>
    send<CalendarTimeOff>(await api.POST('/api/v1/calendar/time-off', { body }), 'The time off could not be added'),
  removeTimeOff: async (id: number) =>
    send<{ id: number; deleted: boolean }>(
      await api.DELETE('/api/v1/calendar/time-off/{id}', { params: { path: { id } } }),
      'The time off could not be removed'
    ),

  services: async () => read<CalendarService[]>(await api.GET('/api/v1/calendar/services'), 'The services could not be loaded'),
  service: async (id: number) =>
    read<CalendarService>(
      await api.GET('/api/v1/calendar/services/{id}', { params: { path: { id } } }),
      'The service could not be loaded'
    ),
  createService: async (body: CalendarServiceRequest) =>
    send<CalendarService>(await api.POST('/api/v1/calendar/services', { body }), 'The service could not be added'),
  updateService: async (id: number, body: CalendarServiceRequest) =>
    send<CalendarService>(
      await api.PATCH('/api/v1/calendar/services/{id}', { params: { path: { id } }, body }),
      'The service could not be saved'
    ),

  availability: async (serviceId: number, { from, to, profileId }: RangeParams) =>
    read<CalendarSlot[]>(
      await api.GET('/api/v1/calendar/availability', {
        params: { query: query({ service_id: serviceId, from, to, profile_id: profileId }) },
      }),
      'Free times could not be loaded'
    ),

  /**
   * Free times for several services back to back (WHIT-1173). Each person is
   * the one for that service, or null for anyone; `samePerson` asks for one
   * person who does them all. Each slot's `items` says who would do which.
   * `exceptBookingId`: moving that booking, so its own time counts as free.
   */
  availabilityMany: async (
    choices: ServiceChoice[],
    { from, to, samePerson = false, exceptBookingId = null }: { from: string; to: string; samePerson?: boolean; exceptBookingId?: number | null }
  ) =>
    read<CalendarSlot[]>(
      await api.GET('/api/v1/calendar/availability', {
        params: {
          query: query({
            service_ids: choices.map((c) => c.service_id).join(','),
            profile_ids: choices.some((c) => c.profile_id !== null) ? choices.map((c) => c.profile_id ?? 0).join(',') : null,
            same_person: samePerson ? 'true' : null,
            except_booking_id: exceptBookingId,
            from,
            to,
          }),
        },
      }),
      'Free times could not be loaded'
    ),

  /**
   * Everything on the calendar in one list (the one-calendar model, WHIT-1109):
   * bookings, team events, meetings, time off, synced busy time and, when asked,
   * tasks due. `unavailable` names the kinds that could not be read (Taskaat down).
   */
  items: async ({ from, to, kinds, cancelled }: { from: string; to: string; kinds: string[]; cancelled: boolean }) => {
    const result = await api.GET('/api/v1/calendar/items', {
      params: { query: query({ from, to, kinds: kinds.join(','), cancelled: cancelled ? '1' : null }) },
    });
    if (result.response.status === 403) throw new ForbiddenError();
    if (!result.response.ok) {
      throw new CalendarRequestError('The calendar could not be loaded', result.response.status, serverText(result.error));
    }
    const body = result.data as { data: Schemas['CalendarItem'][]; unavailable?: string[] };
    return { items: body.data, unavailable: body.unavailable ?? [] };
  },

  /** Answer an invitation: accepted, declined or tentative. */
  answerEvent: async (id: number, response: 'accepted' | 'declined' | 'tentative') =>
    send<CalendarEvent>(
      await api.POST('/api/v1/calendar/events/{id}/answer', { params: { path: { id } }, body: { response } }),
      'Your answer could not be saved'
    ),

  /** Live bookings a day by person, room and service, up to a year (design 3.5 Year view). */
  dayCounts: async ({ from, to }: RangeParams) =>
    read<Schemas['CalendarDayCount'][]>(
      await api.GET('/api/v1/calendar/day-counts', { params: { query: query({ from, to }) } }),
      'The bookings could not be counted'
    ),

  bookings: async ({ from, to, profileId, unitId, status }: RangeParams) =>
    read<CalendarBooking[]>(
      await api.GET('/api/v1/calendar/bookings', {
        params: { query: query({ from, to, profile_id: profileId, unit_id: unitId, status }) },
      }),
      'The bookings could not be loaded'
    ),
  booking: async (id: number) =>
    read<CalendarBooking>(
      await api.GET('/api/v1/calendar/bookings/{id}', { params: { path: { id } } }),
      'The booking could not be loaded'
    ),
  createBooking: async (body: CalendarBookingCreateRequest) =>
    send<CalendarBooking>(await api.POST('/api/v1/calendar/bookings', { body }), 'The booking could not be made'),
  moveBooking: async (id: number, body: Schemas['CalendarBookingMoveRequest']) =>
    send<CalendarBooking>(
      await api.POST('/api/v1/calendar/bookings/{id}/move', { params: { path: { id } }, body }),
      'The booking could not be moved'
    ),
  /** WHIT-1427 (AP.5): `cancelledFor` is whose cancel it is: the customer asked, or we cancelled. */
  cancelBooking: async (id: number, reason: string, cancelledFor?: 'customer' | 'business') =>
    send<CalendarBooking>(
      await api.POST('/api/v1/calendar/bookings/{id}/cancel', {
        params: { path: { id } },
        body: { ...(reason === '' ? {} : { reason }), ...(cancelledFor ? { cancelled_for: cancelledFor } : {}) },
      }),
      'The booking could not be cancelled'
    ),

  /** Accept a customer's request: it becomes a booking (design 3.3). */
  acceptBooking: async (id: number) =>
    send<CalendarBooking>(
      await api.POST('/api/v1/calendar/bookings/{id}/accept', { params: { path: { id } }, body: {} as never }),
      'The request could not be accepted'
    ),
  /** Suggest another time for a request; the customer takes it from their link. */
  suggestBooking: async (id: number, body: { starts_at: string; profile_id?: number }) =>
    send<CalendarBooking>(
      await api.POST('/api/v1/calendar/bookings/{id}/suggest', { params: { path: { id } }, body }),
      'The other time could not be suggested'
    ),
  /** The customer arrived (true), or did not after all (false). */
  checkIn: async (id: number, arrived: boolean) =>
    send<CalendarBooking>(
      arrived
        ? await api.POST('/api/v1/calendar/bookings/{id}/check-in', { params: { path: { id } } })
        : await api.DELETE('/api/v1/calendar/bookings/{id}/check-in', { params: { path: { id } } }),
      'The check-in could not be saved'
    ),
  /** WHIT-1439 (AP.18): the desk changes the room or equipment a booking holds, one for each need. */
  changeResources: async (id: number, resourceIds: number[]) =>
    send<CalendarBooking>(
      await api.POST('/api/v1/calendar/bookings/{id}/resources', { params: { path: { id } }, body: { resource_ids: resourceIds } }),
      'The room or equipment could not be changed'
    ),
  /** WHIT-1427 (AP.3): came, didn't come, or clear the mark. */
  markAttendance: async (id: number, attendance: CalendarAttendance) =>
    send<CalendarBooking>(
      await api.POST('/api/v1/calendar/bookings/{id}/attendance', { params: { path: { id } }, body: { attendance } }),
      'The mark could not be saved'
    ),
  /** WHIT-1427 (AP.4): Did they come? Today's bookings past their start that nobody marked. */
  unmarked: async ({ date, unitId }: { date?: string | null; unitId?: number | null }) =>
    read<CalendarBooking[]>(
      await api.GET('/api/v1/calendar/attendance/unmarked', { params: { query: query({ date, unit_id: unitId }) } }),
      'Did they come? could not be loaded'
    ),
  /** WHIT-1437 (AP.7): the rates broken down one way, with the period before. */
  attendanceReport: async (q: CalendarReportQuery) =>
    read<CalendarAttendanceReport>(
      await api.GET('/api/v1/calendar/attendance/report', { params: { query: query(reportQuery(q)) } }),
      'The report could not be loaded'
    ),
  /** WHIT-1427 (AP.8): one customer's line and flag. */
  customerAttendance: async ({ contactId, email }: { contactId?: number | null; email?: string | null }) =>
    read<CalendarCustomerAttendance>(
      await api.GET('/api/v1/calendar/customers/attendance', { params: { query: query({ contact_id: contactId, email }) } }),
      "The customer's bookings could not be counted"
    ),
  /** WHIT-1427 (AP.10): start a customer's count again from today. */
  resetCustomerAttendance: async (body: { contact_id?: number; email?: string }) =>
    send<CalendarCustomerAttendance>(
      await api.POST('/api/v1/calendar/customers/attendance/reset', { body }),
      'The count could not be started again'
    ),
  today: async ({ date, unitId }: { date?: string | null; unitId?: number | null }) =>
    read<CalendarToday>(
      await api.GET('/api/v1/calendar/today', { params: { query: query({ date, unit_id: unitId }) } }),
      'Today could not be loaded'
    ),

  rooms: async () => read<CalendarRoom[]>(await api.GET('/api/v1/calendar/rooms'), 'The rooms could not be loaded'),
  createRoom: async (body: CalendarRoomRequest) =>
    send<CalendarRoom>(await api.POST('/api/v1/calendar/rooms', { body }), 'The room could not be added'),
  updateRoom: async (id: number, body: CalendarRoomRequest) =>
    send<CalendarRoom>(
      await api.PATCH('/api/v1/calendar/rooms/{id}', { params: { path: { id } }, body }),
      'The room could not be saved'
    ),

  events: async ({ from, to, profileId }: RangeParams) =>
    read<CalendarEvent[]>(
      await api.GET('/api/v1/calendar/events', { params: { query: query({ from, to, profile_id: profileId }) } }),
      'The events could not be loaded'
    ),
  createEvent: async (body: CalendarEventRequest) =>
    sendBody<CalendarEventSaved>(await api.POST('/api/v1/calendar/events', { body }), 'The event could not be added'),
  updateEvent: async (id: number, body: CalendarEventRequest) =>
    sendBody<CalendarEventSaved>(
      await api.PATCH('/api/v1/calendar/events/{id}', { params: { path: { id } }, body }),
      'The event could not be saved'
    ),
  removeEvent: async (id: number) =>
    send<{ id: number; deleted: boolean }>(
      await api.DELETE('/api/v1/calendar/events/{id}', { params: { path: { id } } }),
      'The event could not be removed'
    ),
};

/** Whether an error is the 409 the server sends when a time was just taken. */
export function isTaken(error: unknown): boolean {
  return error instanceof CalendarRequestError && error.status === 409;
}

/** Why a 409 refused a time, when the server said (CV.9). */
export function takenReason(error: unknown): 'person_busy' | 'room_taken' | 'closed' | null {
  if (!(error instanceof CalendarRequestError) || error.status !== 409) return null;
  return error.reason === 'person_busy' || error.reason === 'room_taken' || error.reason === 'closed' ? error.reason : null;
}
