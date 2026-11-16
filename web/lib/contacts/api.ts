/**
 * The contacts API as the web uses it (WHIT-953, design 3.3 A8). Types come
 * from the generated schema; every call goes through the typed `api` client, which
 * carries the session cookie and refreshes it.
 *
 * ERRORS. The server answers in English with an HTTP status. A 403 becomes
 * `ForbiddenError` (the access-denied state); a 422 keeps the server's
 * sentence, which says which value is wrong; anything else gets our own words.
 */

import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import { ForbiddenError } from '@/lib/forbidden';

type Schemas = components['schemas'];

export type Contact = Schemas['Contact'];
export type ContactRequest = Schemas['ContactRequest'];
export type ContactImport = Schemas['ContactImport'];
export type ContactImportListItem = Schemas['ContactImportListItem'];
export type ContactImportCheckRequest = Schemas['ContactImportCheckRequest'];
export type ContactPagination = Schemas['Pagination'];
export type CustomField = Schemas['CustomField'];
export type CustomFieldRequest = Schemas['CustomFieldRequest'];
export type CustomFieldRecordType = Schemas['CustomFieldRecordType'];
export type CustomFieldKind = CustomField['kind'];

export interface ContactTag {
  id: number;
  name: string;
  count: number;
}

export const CONTACTS_READ = 'contacts:read';
export const CONTACTS_WRITE = 'contacts:write';
export const CONTACTS_IMPORT = 'contacts:import';
export const FIELDS_MANAGE = 'fields:manage';

const BASE = '/api/v1/contacts';

/** A refused contacts request, with the HTTP status the screen branches on. */
export class ContactRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly serverMessage: string | null
  ) {
    super(message);
    this.name = 'ContactRequestError';
  }
}

/** What `api.*` resolves to; the typed client never throws on a status. */
interface Result {
  data?: unknown;
  error?: unknown;
  response: Response;
}

function serverText(error: unknown): string | null {
  const text = (error as { error?: unknown } | null | undefined)?.error;
  return typeof text === 'string' && text !== '' ? text : null;
}

function readBody<T>(result: Result, fallback: string): T {
  if (result.response.status === 403) throw new ForbiddenError();
  if (!result.response.ok) {
    throw new ContactRequestError(fallback, result.response.status, serverText(result.error));
  }
  return result.data as T;
}

function read<T>(result: Result, fallback: string): T {
  return readBody<{ data: T }>(result, fallback).data;
}

/** A write: a 403 is a plain refusal here, as before, not the access-denied state. */
function send<T>(result: Result, fallback: string): T {
  if (!result.response.ok) {
    throw new ContactRequestError(fallback, result.response.status, serverText(result.error));
  }
  return (result.data as { data: T }).data;
}

/** Drops the empty values (null, undefined, '', false) so they are not sent. */
function query(params: Record<string, string | number | boolean | null | undefined>): never {
  const out: Record<string, string | number | boolean> = {};
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '' && value !== false) out[key] = value;
  }
  return out as never;
}

export interface ContactListParams {
  kind?: 'customer' | 'supplier' | null;
  tagId?: number | null;
  q?: string;
  archived?: boolean;
  page?: number;
  perPage?: number;
  /** Custom field filters, field key => value (WHIT-954), sent as `field[key]=value`. */
  fields?: Record<string, string>;
}

export interface ContactPage {
  data: Contact[];
  pagination: ContactPagination;
}

export const contactsApi = {
  list: async (p: ContactListParams = {}) => {
    const fieldParams: Record<string, string> = {};
    for (const [key, value] of Object.entries(p.fields ?? {})) {
      if (value !== '') fieldParams[`field[${key}]`] = value;
    }
    return readBody<ContactPage>(
      await api.GET('/api/v1/contacts', {
        params: {
          query: query({
            kind: p.kind,
            tag_id: p.tagId,
            q: p.q?.trim(),
            archived: p.archived === true ? 'true' : null,
            page: p.page,
            per_page: p.perPage,
            ...fieldParams,
          }),
        },
      }),
      'The contacts could not be loaded'
    );
  },
  get: async (id: number) =>
    read<Contact>(await api.GET('/api/v1/contacts/{id}', { params: { path: { id } } }), 'The contact could not be loaded'),
  create: async (body: ContactRequest) =>
    send<Contact>(await api.POST('/api/v1/contacts', { body }), 'The contact could not be added'),
  update: async (id: number, body: ContactRequest) =>
    send<Contact>(await api.PATCH('/api/v1/contacts/{id}', { params: { path: { id } }, body }), 'The contact could not be saved'),
  archive: async (id: number) =>
    send<Contact>(
      await api.POST('/api/v1/contacts/{id}/archive', { params: { path: { id } }, body: {} as never }),
      'The contact could not be archived'
    ),
  restore: async (id: number) =>
    send<Contact>(
      await api.POST('/api/v1/contacts/{id}/restore', { params: { path: { id } }, body: {} as never }),
      'The contact could not be brought back'
    ),
  tags: async () => read<ContactTag[]>(await api.GET('/api/v1/contacts/tags'), 'The tags could not be loaded'),

  imports: async () =>
    read<ContactImportListItem[]>(await api.GET('/api/v1/contacts/imports'), 'The imports could not be loaded'),
  import: async (id: number) =>
    read<ContactImport>(
      await api.GET('/api/v1/contacts/imports/{id}', { params: { path: { id } } }),
      'The import could not be loaded'
    ),
  upload: async (file: File) => {
    const form = new FormData();
    form.append('file', file, file.name);
    // The spec describes this body as JSON; the server reads the multipart file.
    const result = await api.POST('/api/v1/contacts/imports', {
      body: form as never,
      bodySerializer: (b: unknown) => b as FormData,
    });
    return read<ContactImport>(result, 'The file could not be read');
  },
  check: async (id: number, body: ContactImportCheckRequest) =>
    send<ContactImport>(
      await api.POST('/api/v1/contacts/imports/{id}/check', { params: { path: { id } }, body }),
      'The import could not be checked'
    ),
  run: async (id: number) =>
    send<ContactImport>(
      await api.POST('/api/v1/contacts/imports/{id}/run', { params: { path: { id } }, body: {} as never }),
      'The import could not be run'
    ),
  undo: async (id: number) =>
    send<ContactImport>(
      await api.POST('/api/v1/contacts/imports/{id}/undo', { params: { path: { id } }, body: {} as never }),
      'The import could not be undone'
    ),
  skippedUrl: (id: number) => `${BASE}/imports/${id}/skipped.csv`,
};

/** The workspace's own fields (WHIT-954). */
export const customFieldsApi = {
  recordTypes: async () =>
    read<CustomFieldRecordType[]>(
      await api.GET('/api/v1/custom-fields/record-types'),
      'The record types could not be loaded'
    ),
  list: async (recordType: string, archived = false) =>
    read<CustomField[]>(
      await api.GET('/api/v1/custom-fields', {
        params: { query: query({ record_type: recordType, archived: archived ? 'true' : null }) },
      }),
      'The fields could not be loaded'
    ),
  create: async (body: CustomFieldRequest) =>
    send<CustomField>(await api.POST('/api/v1/custom-fields', { body }), 'The field could not be added'),
  update: async (id: number, body: CustomFieldRequest) =>
    send<CustomField>(
      await api.PATCH('/api/v1/custom-fields/{id}', { params: { path: { id } }, body }),
      'The field could not be saved'
    ),
  archive: async (id: number) =>
    send<CustomField>(
      await api.POST('/api/v1/custom-fields/{id}/archive', { params: { path: { id } }, body: {} as never }),
      'The field could not be archived'
    ),
  restore: async (id: number) =>
    send<CustomField>(
      await api.POST('/api/v1/custom-fields/{id}/restore', { params: { path: { id } }, body: {} as never }),
      'The field could not be brought back'
    ),
};

/** A field's value as text for a list or a record page. */
export function fieldText(field: CustomField, value: unknown, yes: string, no: string): string {
  if (value === null || value === undefined || value === '') return '';
  if (Array.isArray(value)) return value.map(String).join(', ');
  if (field.kind === 'checkbox') return value === true ? yes : no;
  return String(value);
}

/** The words to show for a failed call: the server's sentence on a 422, ours otherwise. */
export function contactErrorText(error: unknown): string {
  if (error instanceof ContactRequestError) {
    return error.status === 422 && error.serverMessage !== null ? error.serverMessage : error.message;
  }
  return error instanceof Error ? error.message : String(error);
}
