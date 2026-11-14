import type { useAuth } from '@/lib/auth-context';
import { ForbiddenError } from '@/lib/forbidden';
import { STANDARD_REF, WORKSPACE_KEY, type ThemeRow, type ThemeSchema, type ThemeTokens } from './model';

export type ApiClient = ReturnType<typeof useAuth>['apiClient'];

const BASE = '/api/v1/document-themes';

/** The server's own message from an error body, or the fallback. */
export async function errorOf(response: Response, fallback: string): Promise<string> {
  const parsed = (await response.json().catch(() => null)) as { error?: unknown } | null;
  return typeof parsed?.error === 'string' && parsed.error !== '' ? parsed.error : fallback;
}

async function json<T>(apiClient: ApiClient, url: string, fallback: string): Promise<T> {
  const response = await apiClient(url);
  if (response.status === 403) throw new ForbiddenError();
  if (!response.ok) throw new Error(await errorOf(response, fallback));
  return ((await response.json()) as { data: T }).data;
}

export interface Trial {
  /** Days left, or null when the end isn't known. */
  endsAt: string | null;
}

export interface ThemeScreen {
  schema: ThemeSchema;
  /** The workspace's own theme, or null while it uses Whity's standard. */
  theme: ThemeRow | null;
  /** The workspace's other themes, to pick one as the workspace's instead of making one. */
  others: ThemeRow[];
  /** The trial this workspace is on, or null. */
  trial: Trial | null;
}

/**
 * Everything the screen needs: the schema (with whether form printouts may be
 * customised), which theme is the workspace's (`default_for` holds
 * `workspace`), that theme with its resolution, and whether the workspace is
 * on a trial. The subscription is read for the trial alert only: a person who
 * can't see it (no settings:read) gets the screen without the alert.
 */
export async function loadThemeScreen(apiClient: ApiClient, fallback: string): Promise<ThemeScreen> {
  const [schema, list, subscription] = await Promise.all([
    json<ThemeSchema>(apiClient, `${BASE}/schema`, fallback),
    json<ThemeRow[]>(apiClient, BASE, fallback),
    apiClient('/api/v1/subscription')
      .then(async (r) => (r.ok ? ((await r.json()) as { data?: { status?: string | null; current_period_end?: string | null } }).data : null))
      .catch(() => null),
  ]);
  const current = list.find((row) => row.default_for.includes('workspace'));
  const others = list.filter((row) => row.scope === 'tenant' && !row.archived && row.ref !== current?.ref);
  const trial = subscription?.status === 'trialing' ? { endsAt: subscription.current_period_end ?? null } : null;
  if (!current || current.ref === STANDARD_REF) {
    return { schema, theme: null, others, trial };
  }
  const theme = await json<ThemeRow>(apiClient, `${BASE}/${encodeURIComponent(current.ref)}`, fallback);
  return { schema, theme: { ...theme, tokens: normaliseTokens(theme.tokens) }, others, trial };
}

/** The server answers an empty token set as `{}`; anything else that isn't an object reads as empty. */
function normaliseTokens(tokens: unknown): ThemeTokens {
  return tokens !== null && typeof tokens === 'object' && !Array.isArray(tokens) ? (tokens as ThemeTokens) : {};
}

/**
 * Make the workspace theme (or reuse one already made with the key) and point
 * `documents.theme` at it, so every document and form printout uses it.
 * Returns null on success or the message to show.
 */
export async function makeWorkspaceTheme(
  apiClient: ApiClient,
  names: { name: string; name_ar: string },
  fallback: string
): Promise<string | null> {
  const created = await apiClient(BASE, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ key: WORKSPACE_KEY, ...names, extends: STANDARD_REF, tokens: {} }),
  });
  if (!created.ok && created.status !== 409) return errorOf(created, fallback);
  return setWorkspaceTheme(apiClient, `tenant.${WORKSPACE_KEY}`, fallback);
}

/** Point `documents.theme` at a theme. Returns null on success or the message to show. */
export async function setWorkspaceTheme(apiClient: ApiClient, ref: string, fallback: string): Promise<string | null> {
  const response = await apiClient('/api/v1/settings', {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ settings: { 'documents.theme': ref } }),
  });
  return response.ok ? null : errorOf(response, fallback);
}

export type PreviewResult =
  | { kind: 'pdf'; url: string }
  /** No response yet (404), or the render was refused: the paper mock shows with the message. */
  | { kind: 'none'; message: string | null };

/**
 * The live preview: the latest form response printed with the unsaved options.
 * A PDF comes back as an object URL the caller revokes when it's replaced.
 */
export async function fetchPreview(
  apiClient: ApiClient,
  body: Record<string, unknown>,
  signal?: AbortSignal
): Promise<PreviewResult> {
  const response = await apiClient(`${BASE}/form-printout-preview`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,
  });
  if (response.status === 404) return { kind: 'none', message: null };
  if (!response.ok) return { kind: 'none', message: await errorOf(response, '') || null };
  const blob = await response.blob();
  return { kind: 'pdf', url: URL.createObjectURL(blob) };
}
