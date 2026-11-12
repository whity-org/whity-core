import type { Block, Inline } from "./contract"

/**
 * Per-document parts of a synced saved block (E.23). In the original, text or
 * fields carry the `perDocument` mark (a field may instead set `perDocument`).
 * Each run of consecutive marked text and fields in a block is one part,
 * keyed `<block id>#<n>` (n counts from 0 within the block), the key a
 * document's `overrides` fills. The contract's mark carries no key of its
 * own, so the key comes from where the part is; Whity's printer
 * (SavedBlockExpander) finds parts the same way.
 */

export type Segment = { part: null; runs: Inline[] } | { part: string; runs: Inline[] }

/** The blocks whose `content` holds a line of text (and so can hold a part). */
const TEXT_TYPES = new Set(["paragraph", "heading", "quote", "bullet", "number", "checklist", "callout"])

export function isPerDocument(run: Inline): boolean {
  if (run.type === "text") return !!run.marks?.some((m) => m.type === "perDocument")
  if (run.type === "field") return !!run.perDocument || !!run.marks?.some((m) => m.type === "perDocument")
  return false
}

/** A block's line split into plain runs and parts, in order. */
export function segments(blockId: string, content: Inline[]): Segment[] {
  const out: Segment[] = []
  let n = 0
  for (const run of content) {
    const part = isPerDocument(run)
    const last = out[out.length - 1]
    if (last && (last.part !== null) === part) {
      last.runs.push(run)
    } else {
      out.push(part ? { part: `${blockId}#${n++}`, runs: [run] } : { part: null, runs: [run] })
    }
  }
  return out
}

function textContent(block: Block): Inline[] | null {
  return TEXT_TYPES.has(block.type) && "content" in block && Array.isArray(block.content) ? (block.content as Inline[]) : null
}

/** Every part's key in a saved block's blocks, nested toggles and columns included. */
export function perDocumentKeys(blocks: Block[]): string[] {
  const keys: string[] = []
  for (const block of blocks) {
    const content = textContent(block)
    if (content) keys.push(...segments(block.id, content).flatMap((s) => (s.part ? [s.part] : [])))
    if (block.type === "toggle") keys.push(...perDocumentKeys(block.children))
    if (block.type === "columns") keys.push(...perDocumentKeys(block.columns[0]), ...perDocumentKeys(block.columns[1]))
  }
  return keys
}

/**
 * The blocks with each filled part replaced by the document's own text (and
 * the per-document mark dropped): what Detach leaves in the document.
 */
export function applyOverrides(blocks: Block[], overrides: Record<string, Inline[]>): Block[] {
  return blocks.map((block) => {
    const content = textContent(block)
    let next: Block = block
    if (content) {
      const runs = segments(block.id, content).flatMap((s) => {
        if (s.part === null) return s.runs
        const filled = overrides[s.part]
        const own = filled && filled.length ? filled : s.runs
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
  const out = { ...run } as T & { perDocument?: boolean }
  if (marks && marks.length) out.marks = marks as T["marks"]
  else delete out.marks
  if (out.type === "field") delete out.perDocument
  return out
}

/** The plain text a part shows when the document hasn't filled it: the original's. */
export function partText(runs: Inline[]): string {
  return runs.map((r) => (r.type === "text" ? r.text : r.type === "field" ? r.label || r.path : r.type === "math" ? r.tex : "\n")).join("")
}
