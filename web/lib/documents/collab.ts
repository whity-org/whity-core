import type { Actor, Inline } from '@whity-org/ui/editor/contract';
import type { MentionPerson, NewComment, NewSuggestion, RailSuggestion, RailThread } from '@whity-org/ui/editor/collab';
import type { SuggestionPatch } from '@whity-org/ui/editor/suggestions';
import type {
  BulkDecisionResult,
  CollabChanges,
  CollabSnapshot,
  DecisionResult,
  DocumentEditorAdapter,
} from '@whity-org/features/document-editor';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

/**
 * Comments and suggestions on a document source (editor M5): the web half of
 * the routes under `/api/v1/document-sources/{id}/…` (suggestions, decisions,
 * comments, changes, mentionable), on the typed OpenAPI client. This maps the
 * server's rows to the editor's types (collab.ts in packages/ui): integer ids
 * become strings, an agent's `for` becomes `onBehalfOf`.
 */

type Schemas = components['schemas'];
type WireActor = Schemas['DocumentActor'];
type WireSuggestion = Schemas['DocumentSuggestion'];
type WireThread = Schemas['DocumentCommentThread'];
type WireMessage = Schemas['DocumentCommentMessage'];
type WirePatch = Schemas['DocumentSuggestionPatch'];
type WireInline = Schemas['DocumentCommentMessageCreateRequest']['body'];

/** The codes the collaboration routes answer refusals with (`{ error, details: { code } }`). */
export type CollabErrorCode =
  | 'suggestion_stale'
  | 'already_decided'
  | 'draft_changed'
  | 'patch_does_not_apply'
  | 'suggestion_in_draft';

/** A refusal from a collaboration route, with the server's sentence and code. */
export class CollabError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string | null
  ) {
    super(message);
    this.name = 'CollabError';
  }
}

function errorCode(error: unknown): string | null {
  const body = error as { code?: unknown; details?: { code?: unknown } } | undefined;
  const code = body?.details?.code ?? body?.code;
  return typeof code === 'string' ? code : null;
}

function failure(error: unknown, response: Response, what: string): CollabError {
  const message = (error as { error?: unknown } | undefined)?.error;
  return new CollabError(typeof message === 'string' ? message : `${what} (${response.status})`, response.status, errorCode(error));
}

const path = (id: string) => ({ id: Number(id) });

/* --------------------------------------------------------------- Mapping */

export function toActor(a: WireActor): Actor {
  if (a.kind === 'agent') {
    return {
      kind: 'agent',
      id: String(a.id ?? ''),
      name: a.name,
      onBehalfOf: a.for ? { id: String(a.for.id), name: a.for.name } : { id: '', name: '' },
      scope: [],
    };
  }
  if (a.kind === 'system') return { kind: 'system', name: 'Whity' };
  return { kind: 'person', id: String(a.id ?? ''), name: a.name };
}

export function toSuggestion(s: WireSuggestion): RailSuggestion {
  return {
    id: String(s.id),
    by: toActor(s.by),
    at: s.at,
    summary: s.summary,
    kind: s.kind,
    ...(s.request ? { request: s.request } : {}),
    ...(s.permission ? { permission: s.permission } : {}),
    // The editor's patch type is the wire's shape (`op` names what it does).
    patch: s.patch as unknown as SuggestionPatch,
    status: s.status,
    baseVersion: s.base_version,
  };
}

function toMessage(m: WireMessage): RailThread['messages'][number] {
  return { id: String(m.id), by: toActor(m.by), at: m.at, body: m.body as unknown as Inline[], mentions: m.mentions.map(toActor) };
}

export function toThread(t: WireThread): RailThread {
  return {
    id: String(t.id),
    anchorBlockId: t.anchor_block_id,
    quote: t.quote,
    messages: t.messages.map(toMessage),
    ...(t.resolved ? { resolved: { by: toActor(t.resolved.by), at: t.resolved.at } } : {}),
  };
}

const inline = (body: Inline[]) => body as unknown as WireInline;
const mentionIds = (ids: string[]) => ids.map(Number).filter((n) => Number.isInteger(n) && n > 0);

/* --------------------------------------------------------------- Adapter */

export const collabAdapter: Pick<
  DocumentEditorAdapter,
  'loadCollab' | 'collabChanges' | 'suggest' | 'decide' | 'decideAll' | 'comment' | 'reply' | 'resolveThread' | 'reopenThread' | 'searchPeople'
> = {
  async loadCollab(id): Promise<CollabSnapshot> {
    const [s, c, changes] = await Promise.all([
      api.GET('/api/v1/document-sources/{id}/suggestions', { params: { path: path(id), query: { status: 'open' } } }),
      api.GET('/api/v1/document-sources/{id}/comments', { params: { path: path(id), query: { resolved: 'all' } } }),
      // The cursor to poll from: everything up to now.
      api.GET('/api/v1/document-sources/{id}/changes', { params: { path: path(id) } }),
    ]);
    if (s.data === undefined) throw failure(s.error, s.response, 'Failed to load the suggestions');
    if (c.data === undefined) throw failure(c.error, c.response, 'Failed to load the comments');
    return {
      suggestions: s.data.data.map(toSuggestion).filter((x) => x.status === 'open'),
      threads: c.data.data.map(toThread),
      cursor: changes.data?.data.cursor ?? null,
    };
  },

  async collabChanges(id, cursor): Promise<CollabChanges> {
    const { data, error, response } = await api.GET('/api/v1/document-sources/{id}/changes', {
      params: { path: path(id), query: cursor ? { since: cursor } : {} },
    });
    if (data === undefined) throw failure(error, response, 'Failed to load the changes');
    // Every status comes back: the screen keeps open ones and drops the rest from the rail.
    return {
      cursor: data.data.cursor,
      draftVersion: data.data.draft_version,
      suggestions: data.data.suggestions.map(toSuggestion),
      threads: data.data.comments.map(toThread),
    };
  },

  async suggest(id, change: NewSuggestion): Promise<RailSuggestion> {
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/suggestions', {
      params: { path: path(id) },
      body: { kind: change.kind, summary: change.summary, patch: change.patch as unknown as WirePatch },
    });
    if (data === undefined) throw failure(error, response, 'Failed to send the suggestion');
    return toSuggestion(data.data);
  },

  async decide(id, suggestionId, decision): Promise<DecisionResult> {
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/suggestions/{sid}/decision', {
      params: { path: { id: Number(id), sid: Number(suggestionId) } },
      body: { decision },
    });
    if (response.status === 409) {
      // Its text has gone, or someone decided first: either way the rail refreshes.
      const code = errorCode(error);
      if (code === 'already_decided') return { ok: false, reason: 'already_decided' };
      if (code === 'suggestion_stale' || code === null) return { ok: false, reason: 'stale' };
    }
    if (data === undefined) throw failure(error, response, 'Failed to decide');
    return { ok: true, draftVersion: data.data.draft_version };
  },

  async decideAll(id, decision, filter): Promise<BulkDecisionResult> {
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/suggestions/decisions', {
      params: { path: path(id) },
      body: {
        decision,
        ...(filter?.ids?.length ? { suggestion_ids: filter.ids.map(Number) } : {}),
        ...(filter?.agent ? { agent: filter.agent } : {}),
      },
    });
    if (data === undefined) throw failure(error, response, 'Failed to decide');
    return { decided: data.data.decided.map(String), stale: data.data.stale.map(String), draftVersion: data.data.draft_version };
  },

  async comment(id, comment: NewComment): Promise<RailThread> {
    const mentions = mentionIds(comment.mentions);
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/comments', {
      params: { path: path(id) },
      body: {
        anchor_block_id: comment.anchorBlockId,
        quote: comment.quote,
        body: inline(comment.body),
        ...(mentions.length ? { mentions } : {}),
      },
    });
    if (data === undefined) throw failure(error, response, 'Failed to comment');
    return toThread(data.data);
  },

  async reply(id, threadId, body, mentionList) {
    const mentions = mentionIds(mentionList);
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/comments/{tid}/messages', {
      params: { path: { id: Number(id), tid: Number(threadId) } },
      body: { body: inline(body), ...(mentions.length ? { mentions } : {}) },
    });
    if (data === undefined) throw failure(error, response, 'Failed to reply');
    return toMessage(data.data);
  },

  async resolveThread(id, threadId) {
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/comments/{tid}/resolve', {
      params: { path: { id: Number(id), tid: Number(threadId) } },
    });
    if (data === undefined) throw failure(error, response, 'Failed to resolve');
    return toThread(data.data);
  },

  async reopenThread(id, threadId) {
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/comments/{tid}/reopen', {
      params: { path: { id: Number(id), tid: Number(threadId) } },
    });
    if (data === undefined) throw failure(error, response, 'Failed to reopen');
    return toThread(data.data);
  },

  /**
   * People to @mention: workspace members who can read this document
   * (documents:comment). Never called with an empty query, which is a 422.
   */
  async searchPeople(id, query): Promise<MentionPerson[]> {
    const q = query.trim().slice(0, 100);
    if (!q) return [];
    const { data, error, response } = await api.GET('/api/v1/document-sources/{id}/mentionable', {
      params: { path: path(id), query: { q } },
    });
    if (data === undefined) throw failure(error, response, 'Failed to find people');
    return data.data.map((p) => ({ id: String(p.id), name: p.name, ...(p.email ? { detail: p.email } : {}) }));
  },
};
