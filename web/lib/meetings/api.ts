/**
 * The formal-meetings API as the web uses it (WHIT-1115, ADR 0018). A meeting's
 * record (body, agenda, attendance, decisions) is Convening's; its date, place
 * and guests live on a calendar event. Every call goes through the typed `api`
 * client. Refusals become plain words of our own; only a 422's sentence,
 * which says which value is wrong, is passed on.
 */

import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import { ForbiddenError } from '@/lib/forbidden';

type Schemas = components['schemas'];

export type Meeting = Schemas['Meeting'];
export type MeetingDetail = Schemas['MeetingDetailResponse']['data'];
export type MeetingAgendaItem = Schemas['MeetingAgendaItem'];
export type MeetingDecision = Schemas['MeetingDecision'];
export type MeetingInvitation = Schemas['MeetingInvitation'];
export type MeetingAttendee = Schemas['MeetingAttendee'];
export type ConveningBody = Schemas['ConveningBody'];
export type ConveningBodyMember = Schemas['ConveningBodyMember'];
export type MeetingStatus = Meeting['status'];
export type MeetingVerdict = MeetingDecision['verdict'];
export type AttendeeCapacity = MeetingAttendee['capacity'];

export const CONVENING_READ = 'convening:read';
export const CONVENING_MANAGE = 'convening:manage';
export const CONVENING_DECIDE = 'convening:decide';

/** A refused meetings request, with the HTTP status the screen branches on. */
export class MeetingRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly serverMessage: string | null
  ) {
    super(message);
    this.name = 'MeetingRequestError';
  }
}

interface Result {
  data?: unknown;
  error?: unknown;
  response: Response;
}

function serverText(error: unknown): string | null {
  const text = (error as { error?: unknown } | null | undefined)?.error;
  return typeof text === 'string' && text !== '' ? text : null;
}

function read<T>(result: Result, fallback: string): T {
  if (result.response.status === 403) throw new ForbiddenError();
  if (!result.response.ok) throw new MeetingRequestError(fallback, result.response.status, serverText(result.error));
  return (result.data as { data: T }).data;
}

function send<T>(result: Result, fallback: string): T {
  if (!result.response.ok) throw new MeetingRequestError(fallback, result.response.status, serverText(result.error));
  return (result.data as { data: T }).data;
}

export const meetingsApi = {
  list: async (params: { bodyId?: number | null; status?: string | null } = {}) =>
    read<Meeting[]>(
      await api.GET('/api/v1/meetings', {
        params: {
          query: {
            ...(params.bodyId ? { body_id: params.bodyId } : {}),
            ...(params.status ? { status: params.status } : {}),
          } as never,
        },
      }),
      'The meetings could not be loaded'
    ),
  get: async (id: number) =>
    read<MeetingDetail>(
      await api.GET('/api/v1/meetings/{id}', { params: { path: { id } } }),
      'The meeting could not be loaded'
    ),
  create: async (body: { body_id: number; title: string }) =>
    send<Meeting>(await api.POST('/api/v1/meetings', { body }), 'The meeting could not be created'),
  schedule: async (id: number, body: { scheduled_at: string; location?: string }) =>
    send<Meeting>(
      await api.POST('/api/v1/meetings/{id}/schedule', { params: { path: { id } }, body }),
      'The meeting could not be scheduled'
    ),
  hold: async (id: number) =>
    send<Meeting>(
      await api.POST('/api/v1/meetings/{id}/hold', { params: { path: { id } }, body: {} }),
      'The meeting could not be marked as held'
    ),
  cancel: async (id: number) =>
    send<Meeting>(
      await api.POST('/api/v1/meetings/{id}/cancel', { params: { path: { id } } }),
      'The meeting could not be cancelled'
    ),
  invite: async (id: number) =>
    send<MeetingInvitation[]>(
      await api.POST('/api/v1/meetings/{id}/invitations', { params: { path: { id } } }),
      'The invitations could not be sent'
    ),
  respond: async (id: number, status: 'accepted' | 'declined' | 'tentative') =>
    send<MeetingInvitation>(
      await api.POST('/api/v1/meetings/{id}/invitations/respond', { params: { path: { id } }, body: { status } }),
      'Your answer could not be saved'
    ),
  addAgendaItem: async (id: number, body: { title: string; notes?: string; allow_held?: boolean }) =>
    send<MeetingAgendaItem>(
      await api.POST('/api/v1/meetings/{id}/agenda', { params: { path: { id } }, body }),
      'The agenda item could not be added'
    ),
  removeAgendaItem: async (id: number, itemId: number) => {
    const result = (await api.DELETE('/api/v1/meetings/{id}/agenda/{itemId}', {
      params: { path: { id, itemId } },
    })) as Result;
    if (!result.response.ok) {
      throw new MeetingRequestError('The agenda item could not be removed', result.response.status, serverText(result.error));
    }
  },
  decide: async (
    id: number,
    itemId: number,
    body: { verdict: MeetingVerdict; rationale?: string }
  ) =>
    send<MeetingDecision>(
      await api.POST('/api/v1/meetings/{id}/agenda/{itemId}/decision', { params: { path: { id, itemId } }, body }),
      'The decision could not be recorded'
    ),
  recordAttendance: async (
    id: number,
    attendees: { profile_id?: number | null; attendee_name?: string | null; capacity?: AttendeeCapacity }[]
  ) =>
    send<MeetingAttendee[]>(
      await api.PUT('/api/v1/meetings/{id}/attendance', { params: { path: { id } }, body: { attendees } }),
      'The attendance could not be saved'
    ),

  bodies: async () =>
    read<ConveningBody[]>(await api.GET('/api/v1/convening-bodies'), 'The meeting bodies could not be loaded'),
  body: async (id: number) =>
    read<ConveningBody & { members: ConveningBodyMember[] }>(
      await api.GET('/api/v1/convening-bodies/{id}', { params: { path: { id } } }),
      'The meeting body could not be loaded'
    ),
  createBody: async (body: { body_key: string; name: string; description?: string }) =>
    send<ConveningBody>(await api.POST('/api/v1/convening-bodies', { body }), 'The body could not be created'),
};

/** A plain sentence for a refused request. A 422 says which value is wrong, so its words are kept. */
export function meetingErrorText(error: unknown, fallback: string): string {
  if (error instanceof MeetingRequestError) {
    if (error.status === 422 && error.serverMessage) return error.serverMessage;
    if (error.status === 403) return 'You are not allowed to do that.';
    if (error.status === 404) return 'That meeting is gone.';
  }
  return fallback;
}
