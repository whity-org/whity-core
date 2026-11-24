/**
 * One value in Arabic and English (SDK 1.165, decision D8): the same shape as
 * the PHP SDK's `Whity\Sdk\I18n\LocalizedText`, on the wire and in storage.
 *
 *     { ar: 'فاتورة', en: 'Invoice' }
 *
 * Either language may be missing; an empty object means nothing was written.
 * This replaces the several copies of the shape the frontends kept
 * (`LocalizedTextValue`, `LocalizedMap` and the rest).
 */
export interface LocalizedText {
  ar?: string;
  en?: string;
}

/** The languages a {@link LocalizedText} holds, in the order the PHP SDK lists them. */
export const LANGUAGES = ['ar', 'en'] as const;

/**
 * The text to show someone reading in `language`, as `LocalizedText::resolve()`
 * answers it: the reader's language, then the other one; `null` when the value
 * has no text at all. Regional codes such as `ar-JO` count as their base
 * language, and any language other than Arabic reads English first.
 */
export function resolveText(value: LocalizedText | null | undefined, language: string): string | null {
  if (value === null || value === undefined) {
    return null;
  }
  const order = language.slice(0, 2).toLowerCase() === 'ar' ? (['ar', 'en'] as const) : (['en', 'ar'] as const);
  for (const candidate of order) {
    const text = value[candidate];
    if (typeof text === 'string' && text.trim() !== '') {
      return text;
    }
  }

  return null;
}

/**
 * Read a value from a form or a response, as `LocalizedText::normalize()` does:
 * text is trimmed and blank languages are dropped. `null` when `raw` is not this
 * shape (another key, or a language that isn't text). The result may be empty,
 * so a field that needs text must check for that.
 */
export function normalizeText(raw: unknown): LocalizedText | null {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    return null;
  }
  const record = raw as Record<string, unknown>;
  if (Object.keys(record).some((key) => !(LANGUAGES as readonly string[]).includes(key))) {
    return null;
  }

  const value: LocalizedText = {};
  for (const language of LANGUAGES) {
    const text = record[language];
    if (text === null || text === undefined) {
      continue;
    }
    if (typeof text !== 'string') {
      return null;
    }
    const trimmed = text.trim();
    if (trimmed !== '') {
      value[language] = trimmed;
    }
  }

  return value;
}
