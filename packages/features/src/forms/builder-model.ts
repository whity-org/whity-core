import { keyFrom, pickText } from "./text"
import type { FieldCondition, FieldDraft, FieldKind, FieldOption, LocalizedMap } from "./types"

/** Kinds that carry choices the author writes. */
export const CHOICE_KINDS: readonly string[] = ["select", "multiselect"]

/** The kinds the builder can offer, in the order the picker shows them. */
export const KIND_ORDER: readonly FieldKind[] = [
  "text",
  "textarea",
  "number",
  "date",
  "select",
  "multiselect",
  "checkbox",
  "file",
  "profile_ref",
  "ou_ref",
  "repeating",
  "signature",
]

/** Kinds a condition can read: the answer is one of a short, known list. */
export function canControl(field: Pick<FieldDraft, "field_type">): boolean {
  return field.field_type === "select" || field.field_type === "multiselect" || field.field_type === "checkbox"
}

export function newField(kind: FieldKind, fields: readonly FieldDraft[], sectionKey: string | null): FieldDraft {
  const taken = new Set(fields.map((f) => f.field_key))
  const base: FieldDraft = {
    field_key: keyFrom("", taken, "question"),
    field_type: kind,
    label: {},
    help_text_i18n: {},
    is_required: false,
    options: [],
    validation: [],
    prefill_source: null,
    section_key: sectionKey,
    visible_when: null,
    audience: "applicant",
  }
  if (kind === "select" || kind === "multiselect") base.options = [blankOption([])]
  if (kind === "repeating") {
    base.validation = {
      columns: [{ key: "column_1", type: "text", label: {}, required: false }],
    }
  }
  return base
}

export function blankOption(existing: readonly FieldOption[]): FieldOption {
  const taken = new Set(existing.map((o) => o.value))
  return { value: keyFrom("", taken, "choice"), label: {} }
}

/** The value an option is stored under, from the words the author wrote for it. */
export function optionValueFrom(label: LocalizedMap, existing: readonly FieldOption[], self: string): string {
  const words = (label.en ?? "").trim() || (label.ar ?? "").trim() || pickText(label, null).trim()
  const taken = new Set(existing.filter((o) => o.value !== self).map((o) => o.value))
  if (words === "") return self
  let candidate = words.slice(0, 120)
  let n = 2
  while (taken.has(candidate)) {
    candidate = `${words.slice(0, 110)} ${n}`
    n += 1
  }
  return candidate
}

/**
 * A question's key, from its wording. Only a question that has not been saved
 * may change key: answers already stored are filed under it. Rules in other
 * questions that read it follow.
 */
export function relabel(fields: readonly FieldDraft[], index: number, label: LocalizedMap): FieldDraft[] {
  const target = fields[index]
  const next = fields.map((f) => ({ ...f }))
  next[index] = { ...target, label }
  if (target.id !== undefined) return next
  const words = (label.en ?? "").trim() || (label.ar ?? "").trim()
  if (words === "") return next
  const taken = new Set(fields.filter((_, i) => i !== index).map((f) => f.field_key))
  const key = keyFrom(words, taken, "question")
  if (key === target.field_key) return next
  next[index] = { ...next[index], field_key: key }
  return next.map((f) =>
    f.visible_when !== null && f.visible_when.field === target.field_key
      ? { ...f, visible_when: { ...f.visible_when, field: key } as FieldCondition }
      : f
  )
}

/** Questions before this one that a condition may read. */
export function controllersFor(fields: readonly FieldDraft[], index: number): FieldDraft[] {
  return fields.slice(0, index).filter(canControl)
}

/** Remove a question, and any rule that read it. */
export function removeField(fields: readonly FieldDraft[], index: number): FieldDraft[] {
  const key = fields[index].field_key
  return fields.filter((_, i) => i !== index).map((f) => (f.visible_when?.field === key ? { ...f, visible_when: null } : f))
}

/** A copy of a question, with its own key and no history. */
export function duplicateField(fields: readonly FieldDraft[], index: number): FieldDraft[] {
  const source = fields[index]
  const taken = new Set(fields.map((f) => f.field_key))
  const copy: FieldDraft = { ...source, id: undefined, field_key: keyFrom(`${source.field_key}_copy`, taken, "question") }
  const next = [...fields]
  next.splice(index + 1, 0, copy)
  return next
}

/**
 * Move a question one place within its section: swap it with the nearest
 * neighbour that shares the section, so the order saved matches the order seen.
 */
export function moveField(fields: readonly FieldDraft[], index: number, direction: -1 | 1): FieldDraft[] {
  const section = fields[index].section_key
  let j = index + direction
  while (j >= 0 && j < fields.length && fields[j].section_key !== section) j += direction
  if (j < 0 || j >= fields.length) return [...fields]
  const next = [...fields]
  ;[next[index], next[j]] = [next[j], next[index]]
  return next
}

/** What a changed form would save: stable text, for comparing against what was loaded. */
export function snapshot(value: unknown): string {
  return JSON.stringify(value)
}

/** Questions shown with a bar per section: unsectioned first, then each section in order. */
export function groupBySection<T extends { section_key: string | null }>(
  fields: readonly T[],
  sectionKeys: readonly string[]
): Array<{ key: string | null; items: Array<{ field: T; index: number }> }> {
  const groups: Array<{ key: string | null; items: Array<{ field: T; index: number }> }> = [{ key: null, items: [] }]
  for (const key of sectionKeys) groups.push({ key, items: [] })
  fields.forEach((field, index) => {
    const group = groups.find((g) => g.key === field.section_key) ?? groups[0]
    group.items.push({ field, index })
  })
  return groups.filter((g, i) => i > 0 || g.items.length > 0)
}
