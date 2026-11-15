/**
 * Text people write in more than one language: one object of language code to
 * text, such as `{ ar: "مجلس المعايير", en: "Standards Board" }` (WHIT-1038).
 *
 * The TypeScript twin of `Whity\Core\i18n\LocalizedText`. The server sends the
 * whole object; the page picks for its reader with {@link localizedText}, by
 * the same rule the server uses when it can show only one string:
 *
 *  1. the reader's language;
 *  2. the text's original language, when it has one (a workspace or unit name:
 *     for many institutions the Arabic name is the legal one, so English is
 *     never assumed to be the original);
 *  3. English, then Arabic;
 *  4. any language that has text;
 *  5. `fallback`, or "".
 */

/** A language => text map, as the API sends it. Missing or blank languages are allowed. */
export type LocalizedText = Record<string, string | undefined>

/** What the readers below accept: a map whose languages may also be null. */
type LocalizedTextInput = Readonly<Record<string, string | null | undefined>>

/** Arabic and English: the two languages a bilingual field offers. */
export const BILINGUAL = ["ar", "en"] as const
export type BilingualLanguage = (typeof BILINGUAL)[number]

/**
 * One string for a reader of `language`, by the rule above. A plain string is
 * returned trimmed; `[]` (how PHP encodes an empty map) and null read as empty.
 */
export function localizedText(
  text: LocalizedTextInput | string | readonly unknown[] | null | undefined,
  language?: string | null,
  originalLanguage?: string | null,
  fallback = ""
): string {
  if (typeof text === "string") return text.trim() !== "" ? text.trim() : fallback
  if (text === null || text === undefined || Array.isArray(text)) return fallback

  const map = text as LocalizedTextInput
  for (const code of [language, originalLanguage, "en", "ar"]) {
    if (!code) continue
    const value = map[code]
    if (typeof value === "string" && value.trim() !== "") return value.trim()
  }
  for (const value of Object.values(map)) {
    if (typeof value === "string" && value.trim() !== "") return value.trim()
  }
  return fallback
}

/** Trimmed, with blank languages left out: what the API stores. */
export function cleanLocalizedText(text: LocalizedTextInput): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [code, value] of Object.entries(text)) {
    if (typeof value === "string" && value.trim() !== "") out[code] = value.trim()
  }
  return out
}

/** Whether two maps say the same thing once blanks are ignored. */
export function sameLocalizedText(a: LocalizedTextInput, b: LocalizedTextInput): boolean {
  const left = cleanLocalizedText(a)
  const right = cleanLocalizedText(b)
  const keys = new Set([...Object.keys(left), ...Object.keys(right)])
  for (const key of keys) {
    if (left[key] !== right[key]) return false
  }
  return true
}

/** The language of a text typed without one: Arabic letters mean Arabic. */
export function guessLanguage(text: string): BilingualLanguage {
  return /[؀-ۿݐ-ݿࢠ-ࣿ]/.test(text) ? "ar" : "en"
}
