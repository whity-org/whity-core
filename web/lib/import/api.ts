/**
 * The shared import screen's API (WHIT-1326, design section 34 §6.5).
 *
 * `GET /api/v1/imports/kinds` describes every kind the person may import:
 * contacts, items, opening stock, supplier prices and any kind added later.
 * Every kind's endpoints have one shape under its `path` (upload, check, run,
 * undo, skipped.csv), so the screen calls them through {@link apiFetch} with
 * the path the server gave and knows nothing else about a kind.
 *
 * ERRORS. A 403 on loading the kinds becomes `ForbiddenError`; any other
 * refusal is an {@link ImportRequestError} carrying the server's sentence,
 * which the screen shows for a 409 or 422 and replaces with its own words
 * otherwise.
 */

import type { components } from '@whity/api-client';
import { api, apiFetch } from '@/lib/api/client';
import { ForbiddenError } from '@/lib/forbidden';

export type ImportKind = components['schemas']['ImportKind'];
export type ImportKindOption = ImportKind['options'][number];
export type LocalText = ImportKind['label'];

export interface ImportColumn {
  index: number;
  header: string;
  examples: string[];
  empty: boolean;
  suggestion: { target: string; confidence: 'sure' | 'doubtful' | string } | null;
}

export interface ImportSummaryRow {
  row: number;
  action: 'create' | 'update' | 'skip';
  name: string;
  reason?: string;
  changes?: string[];
}

export interface ImportSummary {
  create: number;
  update: number;
  skip: number;
  rows?: ImportSummaryRow[];
  fixes?: { row: number; column: string; from: string; to: string }[];
  tags?: string[];
}

/** One import, as every kind's endpoints answer it. */
export interface ImportJob {
  id: number;
  file_name: string;
  status: 'uploaded' | 'checked' | 'imported' | 'undone' | string;
  row_count: number;
  columns: ImportColumn[];
  /** The targets for this file, the workspace's own fields included (`field:…`). */
  targets: { key: string; label: string }[];
  options: Record<string, unknown> | null;
  summary: ImportSummary | null;
  imported_at: string | null;
  undone_at: string | null;
  undo_until: string | null;
  can_undo: boolean;
  created_at: string;
}

export class ImportRequestError extends Error {
  constructor(
    public readonly status: number,
    public readonly serverMessage: string | null
  ) {
    super(serverMessage ?? `HTTP ${status}`);
    this.name = 'ImportRequestError';
  }
}

/** The server's sentence for a refusal worth showing (409, 422), else null. */
export function importProblem(error: unknown): string | null {
  if (error instanceof ImportRequestError && (error.status === 409 || error.status === 422)) {
    return error.serverMessage;
  }
  return null;
}

/** A local name in the reader's language, English when there is no other. */
export function localText(text: LocalText | null | undefined, language: string | null): string {
  if (!text) return '';
  return (language === 'ar' ? text.ar : text.en) || text.en || text.ar || '';
}

async function json<T>(response: Response): Promise<T> {
  let body: unknown = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }
  if (!response.ok) {
    const text = (body as { error?: unknown } | null)?.error;
    throw new ImportRequestError(response.status, typeof text === 'string' && text !== '' ? text : null);
  }
  return (body as { data: T }).data;
}

function post(url: string, body: unknown): Promise<Response> {
  return apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body ?? {}) });
}

export const importApi = {
  kinds: async (area?: string): Promise<ImportKind[]> => {
    const result = await api.GET('/api/v1/imports/kinds', { params: { query: area ? { area } : {} } });
    if (result.response.status === 403) throw new ForbiddenError();
    if (!result.response.ok || !result.data) throw new ImportRequestError(result.response.status, null);
    return result.data.data;
  },
  upload: async (kind: ImportKind, file: File): Promise<ImportJob> => {
    const form = new FormData();
    form.append('file', file, file.name);
    return json<ImportJob>(await apiFetch(kind.path, { method: 'POST', body: form }));
  },
  check: async (kind: ImportKind, id: number, body: Record<string, unknown>): Promise<ImportJob> =>
    json<ImportJob>(await post(`${kind.path}/${id}/check`, body)),
  run: async (kind: ImportKind, id: number): Promise<ImportJob> => json<ImportJob>(await post(`${kind.path}/${id}/run`, {})),
  undo: async (kind: ImportKind, id: number): Promise<ImportJob> => json<ImportJob>(await post(`${kind.path}/${id}/undo`, {})),
  skippedUrl: (kind: ImportKind, id: number) => `${kind.path}/${id}/skipped.csv`,
};
