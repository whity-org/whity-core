/**
 * Quotes as the web uses them (WHIT-1341, design section 34 §3, register
 * QT.1-QT.12). Types come from the generated schema; every call goes through
 * the typed `api` client, which carries the session cookie and refreshes it.
 *
 * ERRORS. A 403 on a read becomes `ForbiddenError` (the access-denied state);
 * a refused write keeps the server's sentence for 409 and 422, which says what
 * to change (a valid-until date that has passed, a quote that already went
 * out), and its `code` (`route_needed`, `vetoed`) for the screen to branch on.
 */

import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import { ForbiddenError } from '@/lib/forbidden';

type Schemas = components['schemas'];

export type SalesQuote = Schemas['SalesQuote'];
export type SalesQuoteLine = Schemas['SalesQuoteLine'];
export type SalesQuoteRequest = Schemas['SalesQuoteRequest'];
export type SalesQuoteLineRequest = Schemas['SalesQuoteLineRequest'];
export type SalesQuoteTarget = Schemas['SalesQuoteTarget'];
export type QuoteStatus = SalesQuote['status'];

export const QUOTES_READ = 'quotes:read';
export const QUOTES_WRITE = 'quotes:write';

/** The statuses the list filters by, in the order a quote moves through them. */
export const QUOTE_STATUSES: QuoteStatus[] = ['draft', 'waiting_approval', 'ready', 'sent', 'accepted', 'declined', 'expired', 'converted', 'cancelled'];

/** A refused quotes request, with the HTTP status and code the screen branches on. */
export class SalesRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly serverMessage: string | null,
    public readonly code: string | null = null
  ) {
    super(message);
    this.name = 'SalesRequestError';
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

function serverCode(error: unknown): string | null {
  const details = (error as { details?: { code?: unknown } } | null | undefined)?.details;
  return typeof details?.code === 'string' ? details.code : null;
}

function refused(result: Result, fallback: string): SalesRequestError {
  return new SalesRequestError(fallback, result.response.status, serverText(result.error), serverCode(result.error));
}

function read<T>(result: Result, fallback: string): T {
  if (result.response.status === 403) throw new ForbiddenError();
  if (!result.response.ok) throw refused(result, fallback);
  return (result.data as { data: T }).data;
}

function send<T>(result: Result, fallback: string): T {
  if (!result.response.ok) throw refused(result, fallback);
  return (result.data as { data: T }).data;
}

/** The language a printed quote is in: its template's, not the reader's. */
export type PrintLanguage = 'en' | 'ar';

/** A printed quote: its PDF, or none when this server makes no PDFs (it is still issued). */
export interface Printed {
  pdf: Blob | null;
  documentId: number;
  number: string | null;
}

export interface Sent {
  quote: SalesQuote;
  /** The customer's link, shown once: only its hash is kept. */
  link: string;
  emailed: boolean;
  documentId: number | null;
}

export const salesApi = {
  quotes: async (p: { status?: QuoteStatus | null; contactId?: number; limit?: number; offset?: number } = {}) =>
    read<SalesQuote[]>(
      await api.GET('/api/v1/sales/quotes', {
        params: {
          query: {
            ...(p.status ? { status: p.status } : {}),
            ...(p.contactId ? { contact_id: p.contactId } : {}),
            limit: p.limit ?? 100,
            ...(p.offset ? { offset: p.offset } : {}),
          },
        },
      }),
      'The quotes could not be loaded'
    ),
  quote: async (id: number) =>
    read<SalesQuote>(await api.GET('/api/v1/sales/quotes/{id}', { params: { path: { id } } }), 'The quote could not be loaded'),
  create: async (body: SalesQuoteRequest) =>
    send<SalesQuote>(await api.POST('/api/v1/sales/quotes', { body }), 'The quote could not be saved'),
  update: async (id: number, body: Partial<SalesQuoteRequest>) =>
    send<SalesQuote>(await api.PATCH('/api/v1/sales/quotes/{id}', { params: { path: { id } }, body }), 'The quote could not be saved'),
  submit: async (id: number) =>
    send<SalesQuote>(
      await api.POST('/api/v1/sales/quotes/{id}/submit', { params: { path: { id } }, body: {} as never }),
      'The quote could not be sent for approval'
    ),
  send: async (id: number, email: boolean): Promise<Sent> => {
    const result = await api.POST('/api/v1/sales/quotes/{id}/send', { params: { path: { id } }, body: { email } });
    if (!result.response.ok) throw refused(result, 'The quote could not be sent');
    const body = result.data as { data: SalesQuote; link: string; emailed: boolean; document_id?: number | null };
    return { quote: body.data, link: body.link, emailed: body.emailed, documentId: body.document_id ?? null };
  },
  newLink: async (id: number): Promise<string> => {
    const result = await api.POST('/api/v1/sales/quotes/{id}/link', { params: { path: { id } }, body: {} as never });
    if (!result.response.ok) throw refused(result, 'A new link could not be made');
    return (result.data as { link: string }).link;
  },
  decide: async (id: number, body: { accepted: boolean; name?: string; reason?: string }) =>
    send<SalesQuote>(
      await api.POST('/api/v1/sales/quotes/{id}/decision', { params: { path: { id } }, body }),
      'The answer could not be recorded'
    ),
  revise: async (id: number) =>
    send<SalesQuote>(
      await api.POST('/api/v1/sales/quotes/{id}/revise', { params: { path: { id } }, body: {} as never }),
      'A new revision could not be made'
    ),
  cancel: async (id: number) =>
    send<SalesQuote>(
      await api.POST('/api/v1/sales/quotes/{id}/cancel', { params: { path: { id } }, body: {} as never }),
      'The quote could not be withdrawn'
    ),
  targets: async (id: number) =>
    read<SalesQuoteTarget[]>(await api.GET('/api/v1/sales/quotes/{id}/targets', { params: { path: { id } } }), 'What it can turn into could not be loaded'),
  convert: async (id: number, target: string) =>
    send<unknown>(
      await api.POST('/api/v1/sales/quotes/{id}/convert', { params: { path: { id } }, body: { target } }),
      'The quote could not be turned into that'
    ),
  print: async (id: number, lang: PrintLanguage): Promise<Printed> => {
    const result = await api.POST('/api/v1/sales/quotes/{id}/print', { params: { path: { id } }, body: { lang }, parseAs: 'blob' });
    if (result.response.status === 403) throw new ForbiddenError();
    if (!result.response.ok) throw refused(result, 'The quote could not be printed');
    if (result.response.status === 202) {
      const body = JSON.parse(await (result.data as Blob).text()) as { data: { document_id: number; number: string | null } };
      return { pdf: null, documentId: body.data.document_id, number: body.data.number };
    }
    return { pdf: result.data as Blob, documentId: Number(result.response.headers.get('X-Document-Id') ?? 0), number: null };
  },
};

/** The server's sentence for a refusal worth showing (409, 422), else our own words. */
export function salesErrorText(error: unknown): string {
  if (error instanceof SalesRequestError) {
    return (error.status === 422 || error.status === 409) && error.serverMessage !== null ? error.serverMessage : error.message;
  }
  return error instanceof Error ? error.message : String(error);
}

/** Basis points as a percent for a field: 1600 as "16", 1250 as "12.5". */
export function percentText(bp: number): string {
  return String(Math.round(bp) / 100);
}

/** A typed percent as basis points: "16" as 1600; null when it isn't 0 to 100. */
export function percentToBp(text: string): number | null {
  const clean = text.trim().replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace('٫', '.').replace('%', '');
  if (clean === '') return 0;
  if (!/^\d+(\.\d{1,2})?$/.test(clean)) return null;
  const bp = Math.round(Number(clean) * 100);
  return bp <= 10000 ? bp : null;
}
