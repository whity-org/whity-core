import { ForbiddenError } from '@/lib/forbidden';
import { STANDARD_REF, WORKSPACE_KEY, type ThemeRow, type ThemeSchema, type ThemeTokens } from './model';

/** `apiFetch` from `@/lib/api/client`, or a test's stand-in. */
export type ApiClient = (url: string, init?: RequestInit) => Promise<Response>;

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
  /** Every theme the workspace can use: Whity's standard first, then its own (not archived). */
  themes: ThemeRow[];
  /** The ref `documents.theme` points at: the workspace default. */
  workspaceRef: string;
  /** The trial this workspace is on, or null. */
  trial: Trial | null;
}

/**
 * The list side of the screen: the schema (with whether form printouts may be
 * customised), every theme with which one is the workspace's (`default_for`
 * holds `workspace`), and whether the workspace is on a trial. The
 * subscription is read for the trial alert only: a person who can't see it
 * (no settings:read) gets the screen without the alert.
 */
export async function loadThemeScreen(apiClient: ApiClient, fallback: string): Promise<ThemeScreen> {
  const [schema, list, subscription] = await Promise.all([
    json<ThemeSchema>(apiClient, `${BASE}/schema`, fallback),
    json<ThemeRow[]>(apiClient, BASE, fallback),
    apiClient('/api/v1/subscription')
      .then(async (r) => (r.ok ? ((await r.json()) as { data?: { status?: string | null; current_period_end?: string | null } }).data : null))
      .catch(() => null),
  ]);
  const themes = list.filter((row) => !row.archived).map((row) => ({ ...row, tokens: normaliseTokens(row.tokens) }));
  const workspaceRef = themes.find((row) => row.default_for.includes('workspace'))?.ref ?? STANDARD_REF;
  const trial = subscription?.status === 'trialing' ? { endsAt: subscription.current_period_end ?? null } : null;
  return { schema, themes, workspaceRef, trial };
}

/** One theme with what it prints with in this workspace (`resolved`). */
export async function loadTheme(apiClient: ApiClient, ref: string, fallback: string): Promise<ThemeRow> {
  const theme = await json<ThemeRow>(apiClient, `${BASE}/${encodeURIComponent(ref)}`, fallback);
  return { ...theme, tokens: normaliseTokens(theme.tokens) };
}

/** How many published templates print with a theme; they change at their next publish. */
export async function loadPublishedUsage(apiClient: ApiClient, ref: string): Promise<number> {
  const response = await apiClient(`${BASE}/${encodeURIComponent(ref)}/usage`).catch(() => null);
  if (!response?.ok) return 0;
  const body = (await response.json().catch(() => null)) as { data?: { published?: unknown[] } } | null;
  return Array.isArray(body?.data?.published) ? body.data.published.length : 0;
}

/** The company details the letterhead can print, for the preview. Empty when they can't be read. */
export interface CompanyLines {
  address: string;
  taxId: string;
  phone: string;
  email: string;
}

export async function loadCompanyLines(apiClient: ApiClient, ar: boolean): Promise<CompanyLines> {
  const empty = { address: '', taxId: '', phone: '', email: '' };
  const response = await apiClient('/api/v1/settings').catch(() => null);
  if (!response?.ok) return empty;
  const body = (await response.json().catch(() => null)) as { data?: { effective?: Record<string, unknown> } } | null;
  const s = body?.data?.effective ?? {};
  const text = (key: string) => (typeof s[key] === 'string' ? (s[key] as string) : '');
  return {
    address: (ar && text('company.address_ar')) || text('company.address'),
    taxId: text('company.tax_id'),
    phone: text('company.phone'),
    email: text('company.email'),
  };
}

/**
 * A new theme of the workspace's. Every workspace theme extends Whity's
 * standard (plugin themes come later), so a new one starts as a copy of the
 * settings of the theme it is made from. Returns the new theme's ref, or the
 * message to show.
 */
export async function createTheme(
  apiClient: ApiClient,
  theme: { key: string; name: string; name_ar: string; tokens: ThemeTokens },
  fallback: string
): Promise<{ ref: string } | { problem: string }> {
  const response = await apiClient(BASE, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...theme, extends: STANDARD_REF }),
  });
  if (!response.ok) return { problem: await errorOf(response, fallback) };
  return { ref: `tenant.${theme.key}` };
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
