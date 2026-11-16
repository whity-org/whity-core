import type { FormStatus, LocalizedMap } from "./types"

export function asMap(value: LocalizedMap | [] | null | undefined): LocalizedMap {
  return value === null || value === undefined || Array.isArray(value) ? {} : value
}

/**
 * Drops leading and trailing underscores by walking in from both ends. An
 * anchored `/_+$/` backtracks over every run of underscores in a long name,
 * and the name comes from whatever the author typed.
 */
function trimUnderscores(value: string): string {
  let start = 0
  let end = value.length
  while (start < end && value[start] === "_") start += 1
  while (end > start && value[end - 1] === "_") end -= 1
  return value.slice(start, end)
}

/** A key the server accepts: starts with a letter, lower case, digits, hyphens, underscores. */
export function keyFrom(text: string, taken: ReadonlySet<string>, fallback: string): string {
  const base = trimUnderscores(text.toLowerCase().normalize("NFKD").replace(/[^a-z0-9]+/g, "_")).slice(0, 40) || fallback
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
