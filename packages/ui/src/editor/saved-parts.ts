import type { Block, Inline, PerDocumentKey, PerDocumentPart } from "./contract"

/**
 * Per-document parts of a synced saved block (E.23, E.23a). In the original,
 * text carries the `perDocument` mark and a merge field sets `perDocument`.
 * Since design 3.4 each part has a stable key, `pd_` + 8 characters, minted
 * once when the creator marks it and kept through edits, moves and
 * translation; adjacent runs with the same key are one part, and a
 * document's `overrides` fill parts by key.
 *
 * Rows from before 3.4 carry no key: each run of consecutive marked text and
 * fields in a block's line is one part, named by where it sits,
 * `<block id>#<n>`. That name (`legacy`) is still worked out for every part,
 * so a value stored under it keeps filling the same part. Migration 270
 * gave stored rows their keys; Whity's printer (SavedBlockExpander,
 * PerDocumentParts) finds parts the same way.
 */

export type Segment = { part: null; runs: Inline[] } | { part: PerDocumentKey; legacy: string; runs: Inline[] }

/** The blocks whose `content` holds a line of text (and so can hold a part). */
const TEXT_TYPES = new Set(["paragraph", "heading", "quote", "bullet", "number", "checklist", "callout"])

const ALPHABET = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-"

/** A fresh part key: `pd_` + 8 characters of a nanoid alphabet (E.23a). */
export function mintPartKey(): PerDocumentKey {
  const bytes = new Uint8Array(8)
  crypto.getRandomValues(bytes)
  return "pd_" + Array.from(bytes, (b) => ALPHABET[b & 63]).join("")
}

/** The part a run belongs to: null when it isn't one, "" for a part with no key yet, else its key. */
export function partKeyOf(run: Inline): string | null {
  // Rows from before 3.4 have the mark without a key.
  const mark = (run.type === "text" || run.type === "field" ? run.marks?.find((m) => m.type === "perDocument") : undefined) as { key?: unknown } | undefined
  const markKey = mark ? (typeof mark.key === "string" && mark.key ? mark.key : "") : null
  if (run.type === "text") return markKey
  if (run.type === "field") {
    const own = run.perDocument as unknown
    if (typeof own === "string" && own) return own
    if (own === true) return markKey ?? ""
    return markKey
  }
  return null
}

export function isPerDocument(run: Inline): boolean {
  return partKeyOf(run) !== null
}

/** A block's line split into plain runs and parts, in order. */
export function segments(blockId: string, content: Inline[]): Segment[] {
  const out: Segment[] = []
  let n = 0
  let current: string | null = null
  for (const run of content) {
    const key = partKeyOf(run)
    const last = out[out.length - 1]
    if (last && key === current && (key !== null || last.part === null)) {
      last.runs.push(run)
    } else if (key === null) {
      out.push({ part: null, runs: [run] })
    } else {
      const legacy = `${blockId}#${n++}`
      out.push({ part: key || legacy, legacy, runs: [run] })
    }
    current = key
  }
  return out
}

/** What a document holds for a part: by its key, then by its pre-3.4 name. Undefined when nothing. */
export function lookupPart(overrides: Partial<Record<string, Inline[]>>, segment: Segment): Inline[] | undefined {
  if (segment.part === null) return undefined
  for (const name of [segment.part, segment.legacy]) {
    const value = overrides[name]
    if (value && value.length) return value
  }
  return undefined
}

function textContent(block: Block): Inline[] | null {
  return TEXT_TYPES.has(block.type) && "content" in block && Array.isArray(block.content) ? (block.content as Inline[]) : null
}

function collect(blocks: Block[], found: Map<string, Inline[]>): void {
  for (const block of blocks) {
    const content = textContent(block)
    if (content) for (const s of segments(block.id, content)) if (s.part !== null) found.set(s.part, [...(found.get(s.part) ?? []), ...s.runs])
    if (block.type === "toggle") collect(block.children, found)
    if (block.type === "columns") {
      collect(block.columns[0], found)
      collect(block.columns[1], found)
    }
  }
}

/** Every part's key in a saved block's blocks, in reading order, nested toggles and columns included. */
export function perDocumentKeys(blocks: Block[]): PerDocumentKey[] {
  const found = new Map<string, Inline[]>()
  collect(blocks, found)
  return [...found.keys()]
}

/**
 * The parts in reading order, each once, keeping what `previous` gave each key
 * (label, required, placeholder). A new part is labelled with its own text.
 */
export function perDocumentParts(blocks: Block[], previous: PerDocumentPart[] = []): PerDocumentPart[] {
  const found = new Map<string, Inline[]>()
  collect(blocks, found)
  const before = new Map(previous.map((p) => [p.key, p]))
  return [...found].map(([key, runs]) => {
    const kind: PerDocumentPart["kind"] = runs.every((r) => r.type === "field") ? "field" : "text"
    const old = before.get(key)
    const text = partText(runs).replace(/\s+/g, " ").trim().slice(0, 120)
    return { ...old, key, kind, label: old?.label?.en ? old.label : { en: text || key } }
  })
}

/** The run with its part's key written on its mark (and on a field's `perDocument`). */
function withKey(run: Inline, key: PerDocumentKey): Inline {
  if (run.type !== "text" && run.type !== "field") return run
  const out = { ...run } as typeof run
  if (run.marks) out.marks = run.marks.map((m) => (m.type === "perDocument" ? { type: "perDocument", key } : m)) as typeof run.marks
  if (out.type === "field" && out.perDocument !== undefined) out.perDocument = key
  return out
}

/**
 * Gives every part that has no key one (E.23a). `map` takes each part's
 * pre-3.4 name to its key, reused when present and filled in for every part.
 */
export function assignPartKeys(blocks: Block[], map: Map<string, PerDocumentKey> = new Map()): Block[] {
  return blocks.map((block) => {
    const content = textContent(block)
    let next: Block = block
    if (content) {
      const runs = segments(block.id, content).flatMap((s) => {
        if (s.part === null) return s.runs
        if (s.part !== s.legacy) {
          if (!map.has(s.legacy)) map.set(s.legacy, s.part)
          return s.runs
        }
        const key = map.get(s.legacy) ?? mintPartKey()
        map.set(s.legacy, key)
        return s.runs.map((r) => withKey(r, key))
      })
      next = { ...block, content: runs } as Block
    }
    if (next.type === "toggle") next = { ...next, children: assignPartKeys(next.children, map) }
    if (next.type === "columns") next = { ...next, columns: [assignPartKeys(next.columns[0], map), assignPartKeys(next.columns[1], map)] }
    return next
  })
}

/**
 * The blocks with each filled part replaced by the document's own text (and
 * the per-document mark dropped): what Detach leaves in the document.
 */
export function applyOverrides(blocks: Block[], overrides: Partial<Record<string, Inline[]>>): Block[] {
  return blocks.map((block) => {
    const content = textContent(block)
    let next: Block = block
    if (content) {
      const runs = segments(block.id, content).flatMap((s) => {
        if (s.part === null) return s.runs
        const own = lookupPart(overrides, s) ?? s.runs
        return own.map((r) => (r.type === "text" || r.type === "field" ? unmark(r) : r))
      })
      next = { ...block, content: runs } as Block
    }
    if (next.type === "toggle") next = { ...next, children: applyOverrides(next.children, overrides) }
    if (next.type === "columns") next = { ...next, columns: [applyOverrides(next.columns[0], overrides), applyOverrides(next.columns[1], overrides)] }
    return next
  })
}

function unmark<T extends Inline & { marks?: { type: string }[] }>(run: T): T {
  const marks = run.marks?.filter((m) => m.type !== "perDocument")
  const out = { ...run } as T & { perDocument?: unknown }
  if (marks && marks.length) out.marks = marks as T["marks"]
  else delete out.marks
  if (out.type === "field") delete out.perDocument
  return out
}

/** The plain text a part shows when the document hasn't filled it: the original's. */
export function partText(runs: Inline[]): string {
  return runs.map((r) => (r.type === "text" ? r.text : r.type === "field" ? r.label || r.path : r.type === "math" ? r.tex : "\n")).join("")
}
