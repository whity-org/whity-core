/**
 * The workspace document theme screen's data (design 3.4, `TplFormPrintStyle`,
 * WHIT-1024): the shapes `/api/document-themes` answers with, and the pure
 * steps between them and the screen — what a control shows, what changed and
 * the PATCH body a save sends. Kept free of React so the tests pin the save
 * payload without rendering the page.
 */

/** One allowed value of an enum or list option, with its two labels. */
export interface OptionValue {
  value: string;
  label: string;
  labelAr: string;
}

/** A Documents-tab token from `GET /api/document-themes/schema`. */
export interface TokenSchema {
  key: string;
  type: 'color' | 'enum' | 'list' | 'boolean';
  overridable: boolean;
  label: string;
  labelAr: string;
  description?: string;
  descriptionAr?: string;
  max?: number;
  values?: OptionValue[];
}

/** A Form printouts option: `form.header`, `form.sectionStyle`, … */
export interface FormOptionSchema {
  key: string;
  standard: string;
  label: string;
  labelAr: string;
  values: OptionValue[];
}

export interface FooterPartSchema {
  key: string;
  label: string;
  labelAr: string;
  standard: boolean;
  /** Always printed (Page x of y): shown ticked and disabled. */
  fixed?: boolean;
}

export interface FormSchema {
  entitlement: string;
  /** Whether this workspace may customise its printouts (FP.2). Missing reads as allowed. */
  allowed?: boolean;
  options: FormOptionSchema[];
  footer: FooterPartSchema[];
  marks: { max: number; heightMm: number };
}

export interface ThemeSchema {
  tokens: TokenSchema[];
  form: FormSchema;
}

export interface Mark {
  src: string;
  alt: string;
}

export interface FormTokens {
  [option: string]: unknown;
  footer?: Partial<Record<string, boolean>>;
  marks?: Mark[];
}

export interface Letterhead {
  logo?: string;
  lines?: string[];
  layout?: string;
}

/** A theme's own token set: only what it sets; the rest is Whity's standard. */
export interface ThemeTokens {
  accent?: string;
  accentOnDark?: string;
  accentUse?: string;
  titleFace?: string;
  largePrint?: boolean;
  letterhead?: Letterhead;
  form?: FormTokens;
}

export interface ThemeRow {
  ref: string;
  scope: 'system' | 'tenant';
  key: string;
  name: string;
  name_ar: string;
  tokens: ThemeTokens;
  version: number;
  archived: boolean;
  default_for: string[];
  /** On `GET /{ref}`: the values it prints with in this workspace. */
  resolved?: { tokens?: ThemeTokens & { accent?: string } };
}

export const STANDARD_REF = 'whity.standard';
/** The key the workspace's own theme is made with when it has none. */
export const WORKSPACE_KEY = 'workspace';

/** `form.header` → `header`. */
export function formKey(schemaKey: string): string {
  return schemaKey.startsWith('form.') ? schemaKey.slice(5) : schemaKey;
}

/**
 * What an option prints with. A free workspace always prints the standard
 * layout (FP.2), so a locked control shows the standard, not what is stored.
 */
export function formValue(tokens: ThemeTokens, option: FormOptionSchema, locked: boolean): string {
  if (locked) return option.standard;
  const stored = tokens.form?.[formKey(option.key)];
  return typeof stored === 'string' && option.values.some((v) => v.value === stored) ? stored : option.standard;
}

export function footerValue(tokens: ThemeTokens, part: FooterPartSchema, locked: boolean): boolean {
  if (part.fixed || locked) return part.standard;
  const stored = tokens.form?.footer?.[part.key];
  return typeof stored === 'boolean' ? stored : part.standard;
}

export function withFormOption(tokens: ThemeTokens, key: string, value: string): ThemeTokens {
  return { ...tokens, form: { ...(tokens.form ?? {}), [key]: value } };
}

export function withFooterPart(tokens: ThemeTokens, key: string, on: boolean): ThemeTokens {
  return { ...tokens, form: { ...(tokens.form ?? {}), footer: { ...(tokens.form?.footer ?? {}), [key]: on } } };
}

export function withMarks(tokens: ThemeTokens, marks: Mark[]): ThemeTokens {
  const form = { ...(tokens.form ?? {}) };
  if (marks.length === 0) delete form.marks;
  else form.marks = marks;
  return { ...tokens, form };
}

/** Set (or, with undefined, clear) a Documents-tab token by its schema path. */
export function withToken(tokens: ThemeTokens, path: string, value: unknown): ThemeTokens {
  const next: ThemeTokens = { ...tokens };
  if (path.startsWith('letterhead.')) {
    const part = path.slice('letterhead.'.length) as keyof Letterhead;
    const letterhead: Letterhead = { ...(tokens.letterhead ?? {}) };
    if (value === undefined) delete letterhead[part];
    else (letterhead as Record<string, unknown>)[part] = value;
    if (Object.keys(letterhead).length === 0) delete next.letterhead;
    else next.letterhead = letterhead;
    return next;
  }
  const record = next as Record<string, unknown>;
  if (value === undefined) delete record[path];
  else record[path] = value;
  return next;
}

/** A token's own value by its schema path, or undefined when the theme doesn't set it. */
export function tokenValue(tokens: ThemeTokens, path: string): unknown {
  if (path.startsWith('letterhead.')) {
    return (tokens.letterhead as Record<string, unknown> | undefined)?.[path.slice('letterhead.'.length)];
  }
  return (tokens as Record<string, unknown>)[path];
}

/** Key order doesn't count as a change. */
function stable(value: unknown): string {
  return JSON.stringify(value, (_key, v: unknown) =>
    v !== null && typeof v === 'object' && !Array.isArray(v)
      ? Object.fromEntries(Object.entries(v as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)))
      : v
  );
}

export function sameTokens(a: ThemeTokens, b: ThemeTokens): boolean {
  return stable(a) === stable(b);
}

/**
 * What changed, as the schema keys of the options that differ, for the save
 * bar's "You changed …". Documents-tab tokens by path, form options as
 * `form.<key>`, then `form.footer` and `form.marks`.
 */
export function changedKeys(saved: ThemeTokens, draft: ThemeTokens, schema: ThemeSchema): string[] {
  const out: string[] = [];
  for (const token of schema.tokens) {
    if (stable(tokenValue(saved, token.key)) !== stable(tokenValue(draft, token.key))) out.push(token.key);
  }
  for (const option of schema.form.options) {
    const key = formKey(option.key);
    if (stable(saved.form?.[key]) !== stable(draft.form?.[key])) out.push(option.key);
  }
  if (stable(saved.form?.footer ?? {}) !== stable(draft.form?.footer ?? {})) out.push('form.footer');
  if (stable(saved.form?.marks ?? []) !== stable(draft.form?.marks ?? [])) out.push('form.marks');
  return out;
}

/**
 * The PATCH a save sends: the whole token set, since `tokens` replaces the
 * theme's set, with the version it was read at as If-Match so a change made
 * in between is refused (409) rather than overwritten.
 */
export function savePayload(theme: Pick<ThemeRow, 'ref' | 'version'>, draft: ThemeTokens): {
  url: string;
  init: { method: 'PATCH'; headers: Record<string, string>; body: string };
} {
  return {
    url: `/api/v1/document-themes/${encodeURIComponent(theme.ref)}`,
    init: {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'If-Match': `"${theme.version}"` },
      body: JSON.stringify({ tokens: draft }),
    },
  };
}

/** The live preview's body: the unsaved form, letterhead and print colour. */
export function previewBody(draft: ThemeTokens, lang: 'en' | 'ar'): Record<string, unknown> {
  const body: Record<string, unknown> = { form: draft.form ?? {}, lang };
  if (draft.letterhead) body.letterhead = draft.letterhead;
  if (draft.accent) body.accent = draft.accent;
  return body;
}

export const MARK_TYPES = ['image/png', 'image/jpeg', 'image/webp'] as const;
export const MARK_MAX_BYTES = 300 * 1024;

/** Why a file can't be a mark, or null when it can. */
export function markProblem(file: { type: string; size: number }): 'type' | 'size' | null {
  if (!(MARK_TYPES as readonly string[]).includes(file.type)) return 'type';
  if (file.size > MARK_MAX_BYTES) return 'size';
  return null;
}

/** Days left of a trial ending at `end`, never below 0. */
export function trialDaysLeft(end: string | null | undefined, now: Date = new Date()): number | null {
  if (!end) return null;
  const at = Date.parse(end);
  if (Number.isNaN(at)) return null;
  return Math.max(0, Math.ceil((at - now.getTime()) / 86_400_000));
}
