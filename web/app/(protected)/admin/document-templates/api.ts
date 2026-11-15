import { api } from '@/lib/api/client';
import type { useToast } from '@/lib/toast-context';
import type { DocumentNumberingRule, DocumentScope, TemplateKind, TemplateRow } from './types';

export type AddToast = ReturnType<typeof useToast>['addToast'];

/** The kinds this page lists, in the order they are fetched. */
export const TEMPLATE_KINDS: readonly TemplateKind[] = ['template', 'label'];

/** Thrown for a 403 so the page can show its access-denied state. */
export class ForbiddenError extends Error {
  constructor() {
    super('documents:read forbidden');
  }
}

/** The server's own message from an error body, or the fallback. */
function errorOf(error: unknown, fallback: string): string {
  const parsed = (error ?? null) as { error?: unknown } | null;
  return typeof parsed?.error === 'string' && parsed.error !== '' ? parsed.error : fallback;
}

function toNumberingRule(raw: unknown): DocumentNumberingRule | null {
  if (typeof raw !== 'object' || raw === null) return null;
  const { pattern, assign } = raw as { pattern?: unknown; assign?: unknown };
  if (typeof pattern !== 'string' || pattern === '') return null;

  return { pattern, assign: assign === 'on_final_approval' ? 'on_final_approval' : 'on_issue' };
}

/**
 * A row as the page uses it. Fields the server may leave out (an older server
 * without `starter_key`, a row without own fields) read as their empty value,
 * so a missing column never renders as a badge or a crash.
 */
export function toTemplateRow(raw: Record<string, unknown>): TemplateRow {
  const num = (v: unknown): number | null => (typeof v === 'number' ? v : null);
  const str = (v: unknown): string | null => (typeof v === 'string' && v !== '' ? v : null);
  return {
    id: Number(raw.id),
    kind: raw.kind === 'label' ? 'label' : 'template',
    title: typeof raw.title === 'string' ? raw.title : '',
    lang: raw.lang === 'ar' ? 'ar' : 'en',
    record_type: str(raw.record_type),
    own_fields: Array.isArray(raw.own_fields) ? (raw.own_fields as TemplateRow['own_fields']) : [],
    draft_version: Number(raw.draft_version ?? 0),
    published_version: num(raw.published_version),
    scope: (typeof raw.scope === 'string' ? raw.scope : 'personal') as DocumentScope,
    required_permission: str(raw.required_permission),
    owner_ou_id: num(raw.owner_ou_id),
    created_by: num(raw.created_by),
    updated_at: typeof raw.updated_at === 'string' ? raw.updated_at : '',
    starter_key: str(raw.starter_key),
    legacy_template_id: num(raw.legacy_template_id),
    numbering: toNumberingRule(raw.numbering),
  };
}

/** Every editor template and label sheet the caller may see (server-filtered). */
export async function listTemplates(fallback: string): Promise<TemplateRow[]> {
  const lists = await Promise.all(
    TEMPLATE_KINDS.map(async (kind) => {
      const { data, error, response } = await api.GET('/api/v1/document-sources', {
        params: { query: { kind } },
      });
      if (response.status === 403) throw new ForbiddenError();
      if (!response.ok) throw new Error(errorOf(error, fallback));
      const body = data as unknown as { data?: Record<string, unknown>[] };
      return (body.data ?? []).map(toTemplateRow);
    })
  );
  return lists.flat();
}

/**
 * Change who can see a row. Returns null on success or the message to show:
 * a 403 carries the server's own sentence ("Sharing a document requires
 * documents:publish"), which says it better than any rewording here.
 */
export async function patchTemplate(
  id: number,
  body: {
    scope?: DocumentScope;
    required_permission?: string | null;
    owner_ou_id?: number | null;
    numbering?: DocumentNumberingRule | null;
    public_name?: string | null;
  },
  fallback: string
): Promise<string | null> {
  const { error, response } = await api.PATCH('/api/v1/document-sources/{id}', {
    params: { path: { id } },
    // The spec has no null for `numbering`, which the server reads as "clear it".
    body: body as never,
  });
  return response.ok ? null : errorOf(error, fallback);
}

/** Delete a row. Returns null on success or the message to show. */
export async function deleteTemplate(id: number, fallback: string): Promise<string | null> {
  const { error, response } = await api.DELETE('/api/v1/document-sources/{id}', {
    params: { path: { id } },
  });
  return response.ok ? null : errorOf(error, fallback);
}

/**
 * "Use a copy": a personal, unpublished draft of the caller's own, whatever the
 * original's audience was. Resolves to the new row's id, or rejects with the
 * message to show.
 */
export async function copyTemplate(id: number, title: string, fallback: string): Promise<number> {
  const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/copy', {
    params: { path: { id } },
    body: title.trim() === '' ? {} : { title: title.trim() },
  });
  if (!response.ok) throw new Error(errorOf(error, fallback));
  const body = (data ?? null) as { data?: { id?: unknown } } | null;
  const newId = body?.data?.id;
  if (typeof newId !== 'number') throw new Error(fallback);
  return newId;
}

/** Where the document editor opens a row. */
export function editorHref(id: number): string {
  return `/admin/document-sources/${id}`;
}
