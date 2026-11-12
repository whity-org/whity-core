/**
 * The calendar API as the web uses it (WHIT-944, ADR 0017). Types come from
 * the generated schema; every call goes through `apiClient`, which carries the
 * session cookie and refreshes it.
 *
 * ERRORS. The server answers in English with an HTTP status and no stable code
 * (`{error: "..."}`), so this turns the statuses a person can act on into
 * plain words of our own: 409 is "that time was just taken", 403 names the
 * missing permission in words, 404 says the thing is gone. Only a 422, whose
 * sentence says which value is wrong, falls back to the server's text.
 */

import type { components } from '@/lib/api/schema';
import { apiClient } from '@/lib/api-client';
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
export type CalendarEvent = Schemas['CalendarEvent'];
export type CalendarEventRequest = Schemas['CalendarEventRequest'];
export type CalendarRoom = Schemas['CalendarRoom'];
export type CalendarRoomRequest = Schemas['CalendarRoomRequest'];
export type CalendarToday = Schemas['CalendarToday'];

export const CALENDAR_READ = 'calendar:read';
export const CALENDAR_WRITE = 'calendar:write';
export const CALENDAR_MANAGE = 'calendar:manage';

const BASE = '/api/v1/calendar';

/** A refused calendar request, with the HTTP status the screen branches on. */
export class CalendarRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly serverMessage: string | null
  ) {
    super(message);
    this.name = 'CalendarRequestError';
  }
}

async function serverText(res: Response): Promise<string | null> {
  try {
    const body = (await res.json()) as { error?: unknown };
    return typeof body.error === 'string' && body.error !== '' ? body.error : null;
  } catch {
    return null;
  }
}

/** Throws ForbiddenError on 403 (for `useFetch`'s access-denied state), CalendarRequestError otherwise. */
async function read<T>(res: Response, fallback: string): Promise<T> {
  if (res.status === 403) throw new ForbiddenError();
  if (!res.ok) {
    const text = await serverText(res);
    throw new CalendarRequestError(fallback, res.status, text);
  }
  const body = (await res.json()) as { data: T };
  return body.data;
}

async function sendBody<B>(path: string, method: string, body: unknown, fallback: string): Promise<B> {
  const res = await apiClient(`${BASE}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) {
    const text = await serverText(res);
    throw new CalendarRequestError(fallback, res.status, text);
  }
  return (await res.json()) as B;
}

async function send<T>(path: string, method: string, body: unknown, fallback: string): Promise<T> {
  return (await sendBody<{ data: T }>(path, method, body, fallback)).data;
}

/** An event as saved, and the bookings it now sits over. */
export type CalendarEventSaved = Schemas['CalendarEventResponse'];

function query(params: Record<string, string | number | null | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') search.set(key, String(value));
  }
  const text = search.toString();
  return text === '' ? '' : `?${text}`;
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
  settings: async () => read<CalendarSettings>(await apiClient(`${BASE}/settings`), 'The calendar settings could not be loaded'),
  saveSettings: (body: CalendarSettingsRequest) =>
    send<CalendarSettings>('/settings', 'PUT', body, 'The calendar settings could not be saved'),

  staff: async () => read<CalendarStaff[]>(await apiClient(`${BASE}/staff`), 'The team could not be loaded'),
  saveStaff: (profileId: number, body: { bookable?: boolean; sort_order?: number; unit_id?: number | null }) =>
    send<CalendarStaff[]>(`/staff/${profileId}`, 'PUT', body, 'That person could not be updated'),

  hours: async (profileId: number) =>
    read<CalendarHours[]>(await apiClient(`${BASE}/staff/${profileId}/hours`), 'The working hours could not be loaded'),
  saveHours: (profileId: number, hours: CalendarHours[]) =>
    send<CalendarHours[]>(`/staff/${profileId}/hours`, 'PUT', { hours }, 'The working hours could not be saved'),

  timeOff: async ({ from, to, profileId }: RangeParams) =>
    read<CalendarTimeOff[]>(
      await apiClient(`${BASE}/time-off${query({ from, to, profile_id: profileId })}`),
      'Time off could not be loaded'
    ),
  addTimeOff: (body: Schemas['CalendarTimeOffRequest']) =>
    send<CalendarTimeOff>('/time-off', 'POST', body, 'The time off could not be added'),
  removeTimeOff: (id: number) => send<{ id: number; deleted: boolean }>(`/time-off/${id}`, 'DELETE', undefined, 'The time off could not be removed'),

  services: async () => read<CalendarService[]>(await apiClient(`${BASE}/services`), 'The services could not be loaded'),
  service: async (id: number) => read<CalendarService>(await apiClient(`${BASE}/services/${id}`), 'The service could not be loaded'),
  createService: (body: CalendarServiceRequest) =>
    send<CalendarService>('/services', 'POST', body, 'The service could not be added'),
  updateService: (id: number, body: CalendarServiceRequest) =>
    send<CalendarService>(`/services/${id}`, 'PATCH', body, 'The service could not be saved'),

  availability: async (serviceId: number, { from, to, profileId }: RangeParams) =>
    read<CalendarSlot[]>(
      await apiClient(`${BASE}/availability${query({ service_id: serviceId, from, to, profile_id: profileId })}`),
      'Free times could not be loaded'
    ),

  bookings: async ({ from, to, profileId, unitId, status }: RangeParams) =>
    read<CalendarBooking[]>(
      await apiClient(`${BASE}/bookings${query({ from, to, profile_id: profileId, unit_id: unitId, status })}`),
      'The bookings could not be loaded'
    ),
  booking: async (id: number) => read<CalendarBooking>(await apiClient(`${BASE}/bookings/${id}`), 'The booking could not be loaded'),
  createBooking: (body: CalendarBookingCreateRequest) =>
    send<CalendarBooking>('/bookings', 'POST', body, 'The booking could not be made'),
  moveBooking: (id: number, body: { starts_at: string; profile_id?: number }) =>
    send<CalendarBooking>(`/bookings/${id}/move`, 'POST', body, 'The booking could not be moved'),
  cancelBooking: (id: number, reason: string) =>
    send<CalendarBooking>(`/bookings/${id}/cancel`, 'POST', reason === '' ? {} : { reason }, 'The booking could not be cancelled'),

  /** Accept a customer's request: it becomes a booking (design 3.3). */
  acceptBooking: (id: number) => send<CalendarBooking>(`/bookings/${id}/accept`, 'POST', {}, 'The request could not be accepted'),
  /** Suggest another time for a request; the customer takes it from their link. */
  suggestBooking: (id: number, body: { starts_at: string; profile_id?: number }) =>
    send<CalendarBooking>(`/bookings/${id}/suggest`, 'POST', body, 'The other time could not be suggested'),
  /** The customer arrived (true), or did not after all (false). */
  checkIn: (id: number, arrived: boolean) =>
    send<CalendarBooking>(`/bookings/${id}/check-in`, arrived ? 'POST' : 'DELETE', undefined, 'The check-in could not be saved'),
  today: async ({ date, unitId }: { date?: string | null; unitId?: number | null }) =>
    read<CalendarToday>(await apiClient(`${BASE}/today${query({ date, unit_id: unitId })}`), 'Today could not be loaded'),

  rooms: async () => read<CalendarRoom[]>(await apiClient(`${BASE}/rooms`), 'The rooms could not be loaded'),
  createRoom: (body: CalendarRoomRequest) => send<CalendarRoom>('/rooms', 'POST', body, 'The room could not be added'),
  updateRoom: (id: number, body: CalendarRoomRequest) => send<CalendarRoom>(`/rooms/${id}`, 'PATCH', body, 'The room could not be saved'),

  events: async ({ from, to, profileId }: RangeParams) =>
    read<CalendarEvent[]>(
      await apiClient(`${BASE}/events${query({ from, to, profile_id: profileId })}`),
      'The events could not be loaded'
    ),
  createEvent: (body: CalendarEventRequest) =>
    sendBody<CalendarEventSaved>('/events', 'POST', body, 'The event could not be added'),
  updateEvent: (id: number, body: CalendarEventRequest) =>
    sendBody<CalendarEventSaved>(`/events/${id}`, 'PATCH', body, 'The event could not be saved'),
  removeEvent: (id: number) => send<{ id: number; deleted: boolean }>(`/events/${id}`, 'DELETE', undefined, 'The event could not be removed'),
};

/** Whether an error is the 409 the server sends when a time was just taken. */
export function isTaken(error: unknown): boolean {
  return error instanceof CalendarRequestError && error.status === 409;
}
