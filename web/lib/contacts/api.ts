/**
 * The contacts API as the web uses it (WHIT-953, design 3.3 A8). Types come
 * from the generated schema; every call goes through `apiClient`, which
 * carries the session cookie and refreshes it.
 *
 * ERRORS. The server answers in English with an HTTP status. A 403 becomes
 * `ForbiddenError` (the access-denied state); a 422 keeps the server's
 * sentence, which says which value is wrong; anything else gets our own words.
 */

import type { components } from '@whity/api-client';
import { apiClient } from '@/lib/api-client';
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

async function serverText(res: Response): Promise<string | null> {
  try {
    const body = (await res.json()) as { error?: unknown };
    return typeof body.error === 'string' && body.error !== '' ? body.error : null;
  } catch {
    return null;
  }
}

async function readBody<T>(res: Response, fallback: string): Promise<T> {
  if (res.status === 403) throw new ForbiddenError();
  if (!res.ok) {
    throw new ContactRequestError(fallback, res.status, await serverText(res));
  }
  return (await res.json()) as T;
}

async function read<T>(res: Response, fallback: string): Promise<T> {
  return (await readBody<{ data: T }>(res, fallback)).data;
}

async function send<T>(path: string, method: string, body: unknown, fallback: string): Promise<T> {
  const res = await apiClient(`${BASE}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) {
    throw new ContactRequestError(fallback, res.status, await serverText(res));
  }
  return ((await res.json()) as { data: T }).data;
}

function query(params: Record<string, string | number | boolean | null | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '' && value !== false) search.set(key, String(value));
  }
  const text = search.toString();
  return text === '' ? '' : `?${text}`;
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
      await apiClient(
        `${BASE}${query({
          kind: p.kind,
          tag_id: p.tagId,
          q: p.q?.trim(),
          archived: p.archived === true ? 'true' : null,
          page: p.page,
          per_page: p.perPage,
          ...fieldParams,
        })}`
      ),
      'The contacts could not be loaded'
    );
  },
  get: async (id: number) => read<Contact>(await apiClient(`${BASE}/${id}`), 'The contact could not be loaded'),
  create: (body: ContactRequest) => send<Contact>('', 'POST', body, 'The contact could not be added'),
  update: (id: number, body: ContactRequest) => send<Contact>(`/${id}`, 'PATCH', body, 'The contact could not be saved'),
  archive: (id: number) => send<Contact>(`/${id}/archive`, 'POST', {}, 'The contact could not be archived'),
  restore: (id: number) => send<Contact>(`/${id}/restore`, 'POST', {}, 'The contact could not be brought back'),
  tags: async () => read<ContactTag[]>(await apiClient(`${BASE}/tags`), 'The tags could not be loaded'),

  imports: async () => read<ContactImportListItem[]>(await apiClient(`${BASE}/imports`), 'The imports could not be loaded'),
  import: async (id: number) => read<ContactImport>(await apiClient(`${BASE}/imports/${id}`), 'The import could not be loaded'),
  upload: async (file: File) => {
    const form = new FormData();
    form.append('file', file, file.name);
    const res = await apiClient(`${BASE}/imports`, { method: 'POST', body: form });
    return read<ContactImport>(res, 'The file could not be read');
  },
  check: (id: number, body: ContactImportCheckRequest) =>
    send<ContactImport>(`/imports/${id}/check`, 'POST', body, 'The import could not be checked'),
  run: (id: number) => send<ContactImport>(`/imports/${id}/run`, 'POST', {}, 'The import could not be run'),
  undo: (id: number) => send<ContactImport>(`/imports/${id}/undo`, 'POST', {}, 'The import could not be undone'),
  skippedUrl: (id: number) => `${BASE}/imports/${id}/skipped.csv`,
};

const FIELDS = '/api/v1/custom-fields';

async function sendField<T>(path: string, method: string, body: unknown, fallback: string): Promise<T> {
  const res = await apiClient(`${FIELDS}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body ?? {}),
  });
  if (!res.ok) {
    throw new ContactRequestError(fallback, res.status, await serverText(res));
  }
  return ((await res.json()) as { data: T }).data;
}

/** The workspace's own fields (WHIT-954). */
export const customFieldsApi = {
  recordTypes: async () =>
    read<CustomFieldRecordType[]>(await apiClient(`${FIELDS}/record-types`), 'The record types could not be loaded'),
  list: async (recordType: string, archived = false) =>
    read<CustomField[]>(
      await apiClient(`${FIELDS}${query({ record_type: recordType, archived: archived ? 'true' : null })}`),
      'The fields could not be loaded'
    ),
  create: (body: CustomFieldRequest) => sendField<CustomField>('', 'POST', body, 'The field could not be added'),
  update: (id: number, body: CustomFieldRequest) => sendField<CustomField>(`/${id}`, 'PATCH', body, 'The field could not be saved'),
  archive: (id: number) => sendField<CustomField>(`/${id}/archive`, 'POST', {}, 'The field could not be archived'),
  restore: (id: number) => sendField<CustomField>(`/${id}/restore`, 'POST', {}, 'The field could not be brought back'),
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
