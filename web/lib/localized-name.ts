/**
 * Names of stored things in more than one language (#821).
 *
 * A workspace or a unit has an ORIGINAL name, in `originalLanguage`, and may
 * have the same name in other languages. For many institutions here the Arabic
 * name is the legal one, so English is never assumed to be the original.
 */
export type LocalizedNames = Record<string, string | undefined>;

/** The languages a name field offers. */
export const NAME_LANGUAGES = ['ar', 'en'] as const;
export type NameLanguage = (typeof NAME_LANGUAGES)[number];

/**
 * The name to show a reader of `language`: theirs when it exists, otherwise the
 * original, which is the legal name; then anything set; then `fallback`.
 */
export function localizedName(
  names: LocalizedNames | null | undefined,
  language: string | null | undefined,
  originalLanguage: string | null | undefined,
  fallback = ''
): string {
  const map = names ?? {};
  for (const code of [language, originalLanguage]) {
    if (!code) continue;
    const text = map[code];
    if (typeof text === 'string' && text.trim() !== '') return text;
  }
  if (fallback.trim() !== '') return fallback;
  for (const text of Object.values(map)) {
    if (typeof text === 'string' && text.trim() !== '') return text;
  }
  return fallback;
}

/** Trimmed, with blank languages left out — what the API stores. */
export function cleanNames(names: LocalizedNames): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [code, text] of Object.entries(names)) {
    if (typeof text === 'string' && text.trim() !== '') out[code] = text.trim();
  }
  return out;
}

/** Whether two name maps say the same thing once blanks are ignored. */
export function sameNames(a: LocalizedNames, b: LocalizedNames): boolean {
  const left = cleanNames(a);
  const right = cleanNames(b);
  const keys = new Set([...Object.keys(left), ...Object.keys(right)]);
  for (const key of keys) {
    if (left[key] !== right[key]) return false;
  }
  return true;
}

/** The original language for a name typed without one: Arabic letters mean Arabic. */
export function guessNameLanguage(text: string): NameLanguage {
  return /[؀-ۿݐ-ݿࢠ-ࣿ]/.test(text) ? 'ar' : 'en';
}
