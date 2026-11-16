/**
 * The public booking API as the customer's pages use it (WHIT-945, ADR 0017
 * section 6). Nobody is signed in here, so these are plain `fetch` calls to
 * the same-origin proxy, never `apiClient` (which would try to refresh a
 * session and send a signed-out visitor to /login).
 *
 * ERRORS. The server answers `{error, details?}` in English with a status.
 * {@see PublicBookingError} keeps the status and the field the server named,
 * and the pages turn the statuses a person can act on into their own words.
 */

import type { components } from '@whity/api-client';
import { groupByDay, rangeQuery, addDays, type CalendarDate } from '@/lib/calendar/layout';

type Schemas = components['schemas'];

export type PublicBookingPage = Schemas['PublicBookingPage'];
export type PublicService = PublicBookingPage['services'][number];
export type PublicStaff = PublicBookingPage['staff'][number];
export type PublicBooking = Schemas['PublicBooking'];
export type PublicBookingRequest = Schemas['PublicBookingRequest'];

/** A free time. The manage link's slots carry no `profile_ids`. */
export interface FreeTime {
  starts_at: string;
  ends_at: string;
  profile_ids?: number[];
}

/** What a payment plugin holding the booking asks for (null without one). */
export interface BookingPayment {
  url: string;
  amount_minor: number;
  currency: string;
  label?: string | null;
  hold_expires_at?: string | null;
}

/** A new booking, its manage token, and a payment to make when one is held. */
export interface BookedResult {
  booking: PublicBooking;
  manageToken: string | null;
  payment: BookingPayment | null;
}

/** A refused request: the status the screen branches on and the field the server named. */
export class PublicBookingError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly field: string | null = null
  ) {
    super(message);
    this.name = 'PublicBookingError';
  }
}

/** The server waits for at least this long on the page before taking a booking. */
export const MIN_FILL_MS = 3000;

/** A manage token is 64 lowercase hex characters; anything else is not a link we sent. */
export function isManageToken(token: string): boolean {
  return /^[a-f0-9]{64}$/.test(token);
}

const PAGE = (workspace: string) => `/api/v1/public/booking/${encodeURIComponent(workspace)}`;
const LINK = (token: string) => `/api/v1/public/bookings/${encodeURIComponent(token)}`;

/** The calendar file for a booking, as a link the browser downloads. */
export function icsUrl(token: string): string {
  return `${LINK(token)}/ics`;
}

async function failure(res: Response): Promise<PublicBookingError> {
  let message = '';
  let field: string | null = null;
  try {
    const body = (await res.json()) as { error?: unknown; details?: Record<string, unknown> };
    if (typeof body.error === 'string') message = body.error;
    const details = body.details ?? {};
    if (typeof details.field === 'string') field = details.field;
    else if (details.bot_token !== undefined) field = 'bot_token';
  } catch {
    // No body to read: the status says enough.
  }
  return new PublicBookingError(message || `HTTP ${res.status}`, res.status, field);
}

async function send<T>(url: string, init?: RequestInit): Promise<T> {
  const go = () =>
    fetch(url, {
      ...init,
      headers: {
        Accept: 'application/json',
        ...(init?.body ? { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } : {}),
        ...(init?.headers ?? {}),
      },
    });
  let res = await go();
  // These routes never ask for a session, yet a read sent at the same moment
  // as another one has been seen to come back 401 (WHIT-945 follow-up for the
  // server). A read is safe to ask again once; a write is never repeated.
  if (res.status === 401 && (init?.method ?? 'GET') === 'GET') {
    await new Promise((resolve) => setTimeout(resolve, 250));
    res = await go();
  }
  if (!res.ok) throw await failure(res);
  return (await res.json()) as T;
}

/** The fields the customer typed, and what the page knows about the visit. */
export interface BookingDraft {
  serviceId: number;
  /** One person, or null for anyone. */
  profileId: number | null;
  /** The chosen time as ISO with the workspace's offset. */
  startsAt: string;
  name: string;
  email: string;
  phone: string;
  note: string;
  /** The hidden field. A person never fills it; it is sent exactly as it is. */
  website: string;
  /** Milliseconds since the page loaded. */
  elapsedMs: number;
  botToken: string | null;
}

/**
 * The body of `POST .../bookings`. Optional fields are left out when empty;
 * `website` and `elapsed_ms` are always sent, because the server reads a
 * missing `elapsed_ms` as a bot.
 */
export function bookingRequestBody(draft: BookingDraft): PublicBookingRequest {
  const body: PublicBookingRequest = {
    service_id: draft.serviceId,
    starts_at: draft.startsAt,
    name: draft.name.trim(),
    email: draft.email.trim(),
    website: draft.website,
    elapsed_ms: Math.max(0, Math.round(draft.elapsedMs)),
  };
  if (draft.profileId !== null) body.profile_id = draft.profileId;
  if (draft.phone.trim() !== '') body.phone = draft.phone.trim();
  if (draft.note.trim() !== '') body.note = draft.note.trim();
  if (draft.botToken) body.bot_token = draft.botToken;
  return body;
}

/** A description sent as `[]` when empty, or as names: null when there is none. */
export function descriptionOf(service: PublicService): Record<string, string | undefined> | null {
  const value = service.description as unknown;
  if (value === null || value === undefined || Array.isArray(value) || typeof value !== 'object') return null;
  const names = value as Record<string, unknown>;
  const out: Record<string, string | undefined> = {};
  for (const [code, text] of Object.entries(names)) {
    if (typeof text === 'string' && text.trim() !== '') out[code] = text;
  }
  return Object.keys(out).length > 0 ? out : null;
}

function readPayment(value: unknown): BookingPayment | null {
  if (value === null || typeof value !== 'object') return null;
  const p = value as Record<string, unknown>;
  if (typeof p.url !== 'string' || p.url === '' || typeof p.amount_minor !== 'number' || typeof p.currency !== 'string') return null;
  // Only a web address leaves this page; anything else is not followed.
  if (!/^https?:\/\//i.test(p.url) && !p.url.startsWith('/')) return null;
  return {
    url: p.url,
    amount_minor: p.amount_minor,
    currency: p.currency,
    label: typeof p.label === 'string' ? p.label : null,
    hold_expires_at: typeof p.hold_expires_at === 'string' ? p.hold_expires_at : null,
  };
}

export const publicBookingApi = {
  async page(workspace: string): Promise<PublicBookingPage> {
    return (await send<{ data: PublicBookingPage }>(PAGE(workspace))).data;
  },

  async slots(
    workspace: string,
    query: { serviceId: number; profileId: number | null; from: string; to: string }
  ): Promise<FreeTime[]> {
    const params = new URLSearchParams({ service_id: String(query.serviceId), from: query.from, to: query.to });
    if (query.profileId !== null) params.set('profile_id', String(query.profileId));
    return (await send<{ data: FreeTime[] }>(`${PAGE(workspace)}/slots?${params.toString()}`)).data;
  },

  async book(workspace: string, body: PublicBookingRequest): Promise<BookedResult> {
    const res = await send<{ data: PublicBooking & { manage_token?: string | null; payment?: unknown }; payment?: unknown }>(
      `${PAGE(workspace)}/bookings`,
      { method: 'POST', body: JSON.stringify(body) }
    );
    // The payment may sit beside `data` or inside it; either is read.
    return {
      booking: res.data,
      manageToken: res.data.manage_token ?? null,
      payment: readPayment(res.payment ?? res.data.payment ?? null),
    };
  },

  async show(token: string): Promise<PublicBooking> {
    return (await send<{ data: PublicBooking }>(LINK(token))).data;
  },

  async manageSlots(token: string, query: { from: string; to: string }): Promise<FreeTime[]> {
    const params = new URLSearchParams({ from: query.from, to: query.to });
    return (await send<{ data: FreeTime[] }>(`${LINK(token)}/slots?${params.toString()}`)).data;
  },

  async move(token: string, startsAt: string): Promise<PublicBooking> {
    return (await send<{ data: PublicBooking }>(`${LINK(token)}/move`, {
      method: 'POST',
      body: JSON.stringify({ starts_at: startsAt }),
    })).data;
  },

  /** Take the time the business suggested for a request (design 3.3). The link changes: the new token comes back. */
  async acceptSuggestion(token: string): Promise<PublicBooking & { manage_token?: string | null }> {
    return (await send<{ data: PublicBooking & { manage_token?: string | null } }>(`${LINK(token)}/accept`, { method: 'POST', body: '{}' })).data;
  },

  async cancel(token: string, reason: string): Promise<PublicBooking> {
    return (await send<{ data: PublicBooking }>(`${LINK(token)}/cancel`, {
      method: 'POST',
      body: JSON.stringify({ reason: reason.trim() }),
    })).data;
  },
};

/** A week of days from `first`, and the `from`/`to` to ask for its free times. */
export function weekQuery(first: CalendarDate, timeZone: string, days = 7): { from: string; to: string } {
  return rangeQuery({ first, end: addDays(first, days) }, timeZone);
}

/**
 * Free times grouped by the day they start on IN THE WORKSPACE'S ZONE, one
 * entry per day of the week shown (days with nothing free have no times), so
 * a 23:30 slot in Amman is on Amman's day whatever zone the visitor is in.
 */
export function daysOfWeek<T extends { starts_at: string }>(
  first: CalendarDate,
  slots: T[],
  timeZone: string,
  days = 7
): { date: CalendarDate; items: T[] }[] {
  const grouped = groupByDay(slots, timeZone);
  return Array.from({ length: days }, (_, i) => {
    const date = addDays(first, i);
    const found = grouped.find((g) => g.date.year === date.year && g.date.month === date.month && g.date.day === date.day);
    return { date, items: found ? found.items : [] };
  });
}
