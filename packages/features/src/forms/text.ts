import type { FormStatus, LocalizedMap } from "./types"

/** The text for a language, then English, then whatever the author wrote. */
export function pickText(value: LocalizedMap | [] | string | null | undefined, language: string | null): string {
  if (typeof value === "string") return value
  if (value === null || value === undefined || Array.isArray(value)) return ""
  for (const key of [language ?? "en", "en", "ar"]) {
    const candidate = value[key]
    if (typeof candidate === "string" && candidate !== "") return candidate
  }
  for (const candidate of Object.values(value)) {
    if (typeof candidate === "string" && candidate !== "") return candidate
  }
  return ""
}

export function asMap(value: LocalizedMap | [] | null | undefined): LocalizedMap {
  return value === null || value === undefined || Array.isArray(value) ? {} : value
}

/** A key the server accepts: starts with a letter, lower case, digits, hyphens, underscores. */
export function keyFrom(text: string, taken: ReadonlySet<string>, fallback: string): string {
  const base =
    text
      .toLowerCase()
      .normalize("NFKD")
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "")
      .slice(0, 40) || fallback
  const start = /^[a-z]/.test(base) ? base : `${fallback}_${base}`
  let candidate = start
  let n = 2
  while (taken.has(candidate)) {
    candidate = `${start}_${n}`
    n += 1
  }
  return candidate
}

export function statusVariant(status: FormStatus): "success" | "warning" | "outline" {
  if (status === "published") return "success"
  if (status === "draft") return "warning"
  return "outline"
}
