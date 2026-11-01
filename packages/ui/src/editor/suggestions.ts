import type { Block, Inline, Mark } from "./contract"

/**
 * Suggestions as patches (IMPLEMENTATION-NOTES 3.3, editor M5), and the two
 * directions between a draft and what the editor shows. Pure: no React, no
 * ProseMirror, only the contract's blocks.
 *
 * - `renderSuggestions(draft, suggestions)` draws open suggestions onto the
 *   draft: a `replace-text` patch's matched text gets the `deletion` mark and
 *   its insert follows with the `insertion` mark; inserted and deleted blocks
 *   get the block `suggestion` attr; a `format` patch is returned as a range to
 *   underline, since the contract has no mark for it.
 * - `suggestEdit(before, after, …)` is Suggesting mode: given the blocks before
 *   and after an ordinary edit, it returns the blocks with that edit expressed
 *   as local suggestion marks (deleted text stays, struck through; typed text
 *   is marked inserted; deleting your own pending insertion just removes it),
 *   plus any formatting change as a pending format edit.
 * - `collectPatches(blocks, formats, isLocal)` is the flush: every local
 *   change, grouped per block and region, as a patch to POST.
 * - `stripSuggestions(blocks)` is the draft again: what Editing saves.
 *
 * Text is matched within a block's flattened inline text (`flatText`), as the
 * server does (DocumentSuggestionPatch): text runs as they are, and each field,
 * line break or formula as the one character U+FFFC. The occurrence-th match
 * is the occurrence-th start position, scanning one character at a time.
 */

/* ---------------------------------------------------------------- Patches */

export type SuggestionPatch =
  | { op: "insert-blocks"; afterBlockId: string | null; blocks: Block[] }
  | {
      op: "replace-text"
      blockId: string
      deleteText: string
      insert: Inline[]
      occurrence: number
      /** Pure insertions only: up to 40 characters of the block's text just before the insertion point. */
      before?: string
    }
  | { op: "format"; blockId: string; deleteText: ""; text: string; occurrence: number; mark: Mark; add: boolean }
  | { op: "delete-blocks"; blockIds: string[] }
  | { op: "move-block"; blockId: string; afterBlockId: string | null }

export type SuggestionKind = "text" | "format" | "block"

/** What rendering needs of an open suggestion. */
export interface PatchedSuggestion {
  id: string
  patch: SuggestionPatch
}

/** A pending formatting change, by character offsets in the (marked) block. */
export interface FormatEdit {
  id: string
  blockId: string
  from: number
  to: number
  mark: Mark
  add: boolean
}

/** One local change ready to POST: the local ids it replaces once the server answers. */
export interface LocalChange {
  localIds: string[]
  kind: SuggestionKind
  summary: string
  patch: SuggestionPatch
}

export const BEFORE_CHARS = 40
const OBJECT = String.fromCharCode(0xfffc)
const FORMAT_MARKS = new Set(["bold", "italic", "underline", "strike", "code", "link", "highlight"])

/* ------------------------------------------------------------------ Cells */

/** One character of a block's inline content, with its marks. */
interface Cell {
  ch: string
  /** For a break, field or formula: the inline itself (marks aside). */
  atom?: Inline
  marks: Mark[]
  /** Transient: format suggestions covering this character. */
  fmt?: string[]
}

function marksOf(run: Inline): Mark[] {
  return run.type === "text" || run.type === "field" ? (run.marks ?? []) : []
}

function toCells(content: Inline[]): Cell[] {
  const cells: Cell[] = []
  for (const run of content) {
    if (run.type === "text") {
      // One cell per UTF-16 unit, so offsets match ProseMirror's positions.
      for (let i = 0; i < run.text.length; i++) cells.push({ ch: run.text[i], marks: run.marks ?? [] })
    } else {
      const { marks: _m, ...atom } = run as Inline & { marks?: Mark[] }
      cells.push({ ch: OBJECT, atom: atom as Inline, marks: marksOf(run) })
    }
  }
  return cells
}

const markKey = (m: Mark) => JSON.stringify(m)
const sameMarkList = (a: Mark[], b: Mark[]) => a.length === b.length && a.every((m, i) => markKey(m) === markKey(b[i]))

const ORDER = ["link", "bold", "italic", "underline", "strike", "code", "highlight", "comment", "insertion", "deletion", "perDocument"]
const sortMarks = (marks: Mark[]) => [...marks].sort((a, b) => ORDER.indexOf(a.type) - ORDER.indexOf(b.type))

function fromCells(cells: Cell[]): Inline[] {
  const out: Inline[] = []
  for (const c of cells) {
    const marks = sortMarks(c.marks)
    if (c.atom) {
      const run = { ...c.atom } as Inline
      if ((run.type === "field" || run.type === "text") && marks.length) (run as { marks?: Mark[] }).marks = marks
      out.push(run)
      continue
    }
    const last = out[out.length - 1]
    if (last && last.type === "text" && sameMarkList(last.marks ?? [], marks)) last.text += c.ch
    else {
      const run: Inline = { type: "text", text: c.ch }
      if (marks.length) run.marks = marks
      out.push(run)
    }
  }
  return out.filter((r) => r.type !== "text" || r.text !== "")
}

const insertionOf = (c: Cell) => c.marks.find((m) => m.type === "insertion") as { suggestionId: string } | undefined
const deletionOf = (c: Cell) => c.marks.find((m) => m.type === "deletion") as { suggestionId: string } | undefined
const isBase = (c: Cell) => !insertionOf(c)
const withoutSuggestionMarks = (marks: Mark[]) => marks.filter((m) => m.type !== "insertion" && m.type !== "deletion")
const formatting = (marks: Mark[]) => marks.filter((m) => FORMAT_MARKS.has(m.type))

/** A block's inline text as patches match it. */
export function flatText(content: Inline[]): string {
  return toCells(content)
    .map((c) => c.ch)
    .join("")
}

/** The draft's text of a marked block: everything but suggested insertions. */
export function baseText(content: Inline[]): string {
  return toCells(content)
    .filter(isBase)
    .map((c) => c.ch)
    .join("")
}

/** How many matches of `needle` start before `limit` (every start position counts, overlapping or not). */
export function countMatches(haystack: string, needle: string, limit: number): number {
  if (!needle) return 0
  let n = 0
  for (let i = haystack.indexOf(needle); i >= 0 && i < limit; i = haystack.indexOf(needle, i + 1)) n++
  return n
}

/** The start of the occurrence-th match (0-based), or -1. */
export function nthMatch(haystack: string, needle: string, occurrence: number): number {
  if (!needle) return -1
  let i = haystack.indexOf(needle)
  for (let n = 0; i >= 0 && n < occurrence; n++) i = haystack.indexOf(needle, i + 1)
  return i
}

/* ----------------------------------------------------------------- Blocks */

type Content = { content: Inline[] }
const hasContent = (b: Block): b is Block & Content => "content" in b && Array.isArray((b as Partial<Content>).content)

/** Every block list in a document: the top level, toggles' children and both columns. */
function eachList(blocks: Block[], visit: (list: Block[]) => void) {
  visit(blocks)
  for (const b of blocks) {
    if (b.type === "toggle") eachList(b.children, visit)
    if (b.type === "columns") b.columns.forEach((col) => eachList(col, visit))
  }
}

function findBlock(blocks: Block[], id: string): { list: Block[]; index: number } | null {
  let found: { list: Block[]; index: number } | null = null
  eachList(blocks, (list) => {
    if (found) return
    const index = list.findIndex((b) => b.id === id)
    if (index >= 0) found = { list, index }
  })
  return found
}

const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T

/** Blocks without any suggestion attr or mark: what goes into a patch or the draft. */
function cleanBlock(block: Block): Block {
  const { suggestion: _s, ...rest } = block
  const b = rest as Block
  if (hasContent(b)) (b as Content).content = fromCells(toCells(b.content).map((c) => ({ ...c, marks: withoutSuggestionMarks(c.marks) })))
  if (b.type === "toggle") b.children = b.children.map(cleanBlock)
  if (b.type === "columns") b.columns = [b.columns[0].map(cleanBlock), b.columns[1].map(cleanBlock)]
  return b
}

/**
 * The draft from a marked document: suggested insertions and inserted blocks
 * removed, deletion marks dropped (their text is still the draft's), block
 * suggestion attrs cleared. What Editing saves (the server refuses marks in a draft).
 */
export function stripSuggestions(blocks: Block[]): Block[] {
  const out: Block[] = []
  for (const block of blocks) {
    if (block.suggestion?.action === "insert") continue
    const { suggestion: _s, ...rest } = block
    const b = rest as Block
    if (hasContent(b)) {
      ;(b as Content).content = fromCells(
        toCells(b.content)
          .filter(isBase)
          .map((c) => ({ ...c, marks: withoutSuggestionMarks(c.marks) }))
      )
    }
    if (b.type === "toggle") b.children = stripSuggestions(b.children)
    if (b.type === "columns") b.columns = [stripSuggestions(b.columns[0]), stripSuggestions(b.columns[1])]
    out.push(b)
  }
  return out
}

/** True when a document has anything `stripSuggestions` would take out. */
export function hasSuggestionMarks(blocks: Block[]): boolean {
  let found = false
  eachList(blocks, (list) => {
    for (const b of list) {
      if (b.suggestion) found = true
      if (hasContent(b) && toCells(b.content).some((c) => insertionOf(c) || deletionOf(c))) found = true
    }
  })
  return found
}

/* -------------------------------------------------------------- Rendering */

export interface RenderedFormat {
  suggestionId: string
  blockId: string
  /** Character offsets in the rendered block. */
  from: number
  to: number
}

export interface Rendered {
  blocks: Block[]
  /** Format suggestions: ranges to underline (dashed). */
  formats: RenderedFormat[]
  /** Suggestions whose anchor wasn't found in this draft (stale on the server's next accept). */
  unplaced: string[]
}

/** Draws open suggestions onto a draft, in the order given (the server's id order). */
export function renderSuggestions(draft: Block[], suggestions: PatchedSuggestion[]): Rendered {
  const blocks = clone(draft)
  const unplaced: string[] = []
  const cellsById = new Map<string, Cell[]>()
  const cellsOf = (id: string): Cell[] | null => {
    if (cellsById.has(id)) return cellsById.get(id)!
    const at = findBlock(blocks, id)
    if (!at) return null
    const block = at.list[at.index]
    if (!hasContent(block)) return null
    const cells = toCells(block.content)
    cellsById.set(id, cells)
    return cells
  }
  /** The cell index of the base character at `offset` (or the end). */
  const cellAt = (cells: Cell[], offset: number) => {
    let n = 0
    for (let i = 0; i < cells.length; i++) {
      if (!isBase(cells[i])) continue
      if (n === offset) return i
      n++
    }
    return cells.length
  }

  for (const s of suggestions) {
    const p = s.patch
    if (p.op === "replace-text") {
      const cells = cellsOf(p.blockId)
      if (!cells) {
        unplaced.push(s.id)
        continue
      }
      const base = cells.filter(isBase).map((c) => c.ch).join("")
      let start: number
      if (p.deleteText) start = nthMatch(base, p.deleteText, p.occurrence)
      else if (!p.before) start = 0
      else {
        const at = nthMatch(base, p.before, p.occurrence)
        start = at < 0 ? -1 : at + p.before.length
      }
      if (start < 0) {
        unplaced.push(s.id)
        continue
      }
      const from = cellAt(cells, start)
      const to = p.deleteText ? cellAt(cells, start + p.deleteText.length - 1) + 1 : from
      // Two suggestions deleting the same text can't both show; the later one waits.
      if (cells.slice(from, to).some((c) => deletionOf(c))) {
        unplaced.push(s.id)
        continue
      }
      for (let i = from; i < to; i++) if (isBase(cells[i])) cells[i] = { ...cells[i], marks: [...cells[i].marks, { type: "deletion", suggestionId: s.id }] }
      const inserted = toCells(p.insert).map((c) => ({ ...c, marks: [...withoutSuggestionMarks(c.marks), { type: "insertion" as const, suggestionId: s.id }] }))
      cells.splice(to, 0, ...inserted)
    } else if (p.op === "format") {
      const cells = cellsOf(p.blockId)
      const base = cells ? cells.filter(isBase).map((c) => c.ch).join("") : ""
      const start = cells ? nthMatch(base, p.text, p.occurrence) : -1
      if (!cells || start < 0) {
        unplaced.push(s.id)
        continue
      }
      const from = cellAt(cells, start)
      const to = cellAt(cells, start + p.text.length - 1) + 1
      for (let i = from; i < to; i++) if (isBase(cells[i])) cells[i] = { ...cells[i], fmt: [...(cells[i].fmt ?? []), s.id] }
    } else if (p.op === "insert-blocks") {
      const added = p.blocks.map((b) => ({ ...cleanBlock(clone(b)), suggestion: { id: s.id, action: "insert" as const } }))
      if (p.afterBlockId === null) {
        blocks.unshift(...added)
        continue
      }
      const at = findBlock(blocks, p.afterBlockId)
      if (!at) {
        unplaced.push(s.id)
        continue
      }
      // After the anchor and after any blocks other suggestions already inserted there.
      let i = at.index + 1
      while (i < at.list.length && at.list[i].suggestion?.action === "insert") i++
      at.list.splice(i, 0, ...added)
    } else if (p.op === "delete-blocks" || p.op === "move-block") {
      const ids = p.op === "delete-blocks" ? p.blockIds : [p.blockId]
      const places = ids.map((id) => findBlock(blocks, id))
      if (places.some((at) => !at || at.list[at.index].suggestion)) {
        unplaced.push(s.id)
        continue
      }
      for (const at of places) {
        const b = at!.list[at!.index]
        at!.list[at!.index] = { ...b, suggestion: { id: s.id, action: p.op === "delete-blocks" ? "delete" : "move" } }
      }
    }
  }

  const formats: RenderedFormat[] = []
  eachList(blocks, (list) => {
    list.forEach((block, index) => {
      const cells = cellsById.get(block.id)
      if (!cells || !hasContent(block)) return
      for (let i = 0; i < cells.length; i++) {
        for (const id of cells[i].fmt ?? []) {
          if (i > 0 && cells[i - 1].fmt?.includes(id)) continue
          let j = i
          while (j < cells.length && (cells[j].fmt?.includes(id) || !isBase(cells[j]))) j++
          while (j > i && !cells[j - 1].fmt?.includes(id)) j--
          formats.push({ suggestionId: id, blockId: block.id, from: i, to: j })
        }
      }
      list[index] = { ...block, content: fromCells(cells) } as Block
    })
  })
  return { blocks, formats, unplaced }
}

/* ------------------------------------------------------- Suggesting mode */

export interface SuggestEditOptions {
  /** The id local changes made by this edit get. */
  localId: string
  /** Whether a suggestion id is one of this person's pending (unsent) changes. */
  isLocal: (id: string) => boolean
  /**
   * Where the caret was before the edit (block id and character offset), so a
   * Backspace over text leaves the caret before the struck-through text.
   */
  caretBefore?: { blockId: string; offset: number } | null
  /** Where the caret is after the edit, to map into the result. */
  caretAfter?: { blockId: string; offset: number } | null
}

export interface SuggestEditResult {
  blocks: Block[]
  /** New pending format edits (offsets in the resulting blocks). */
  formats: FormatEdit[]
  /** The caret in the result, when `caretAfter` was given. */
  caret: { blockId: string; offset: number } | null
}

const asJson = (v: unknown) => JSON.stringify(v)
const sameBlockShape = (a: Block, b: Block) => {
  const { content: _a, suggestion: _sa, ...ra } = a as Block & Partial<Content>
  const { content: _b, suggestion: _sb, ...rb } = b as Block & Partial<Content>
  return asJson(ra) === asJson(rb)
}
const cellKey = (c: Cell) => `${c.ch}|${c.atom ? asJson(c.atom) : ""}|${insertionOf(c)?.suggestionId ?? ""}|${deletionOf(c)?.suggestionId ?? ""}`

/**
 * Suggesting mode for one edit. `before` and `after` are the same stretch of
 * sibling blocks (the editor passes the blocks the edit touched). Returns null
 * when the edit can't be expressed as a suggestion yet (see the limits in
 * README), so the editor refuses it.
 */
export function suggestEdit(before: Block[], after: Block[], opts: SuggestEditOptions): SuggestEditResult | null {
  const { localId, isLocal } = opts
  const oldIndex = new Map(before.map((b, i) => [b.id, i]))
  const newIds = new Set(after.map((b) => b.id).filter(Boolean))
  const out: Block[] = []
  const formats: FormatEdit[] = []
  let caret: SuggestEditResult["caret"] = null
  let pointer = 0

  const emitRemoved = (upTo: number) => {
    for (; pointer < upTo; pointer++) {
      const old = before[pointer]
      if (newIds.has(old.id)) return false
      if (old.suggestion?.action === "insert") {
        // Your own pending block goes; someone else's suggestion stays until it's decided.
        if (isLocal(old.suggestion.id)) continue
        out.push(old)
        continue
      }
      out.push(old.suggestion ? old : { ...old, suggestion: { id: localId, action: "delete" } })
    }
    return true
  }

  for (const nb of after) {
    const idx = nb.id ? oldIndex.get(nb.id) : undefined
    if (idx === undefined) {
      // A new block: a whole-block insertion, its text unmarked inside it.
      const added = { ...cleanBlock(nb), suggestion: { id: localId, action: "insert" as const } }
      out.push(added)
      if (opts.caretAfter && opts.caretAfter.blockId === nb.id) caret = opts.caretAfter
      continue
    }
    if (idx < pointer) return null // moved: move suggestions aren't made from the editor yet
    if (!emitRemoved(idx)) return null
    pointer = idx + 1
    const old = before[idx]
    const diffed = diffBlock(old, nb, opts, formats, (c) => (caret = c))
    if (!diffed) return null
    out.push(diffed)
  }
  if (!emitRemoved(before.length)) return null
  return { blocks: out, formats, caret }
}

function diffBlock(
  old: Block,
  nb: Block,
  opts: SuggestEditOptions,
  formats: FormatEdit[],
  setCaret: (c: { blockId: string; offset: number }) => void
): Block | null {
  const { isLocal } = opts
  const caretHere = opts.caretAfter && opts.caretAfter.blockId === nb.id ? opts.caretAfter.offset : null
  if (asJson(old) === asJson(nb)) {
    if (caretHere !== null) setCaret({ blockId: nb.id, offset: caretHere })
    return old
  }
  // Your own inserted block is yours to change freely until it's sent.
  if (old.suggestion?.action === "insert" && isLocal(old.suggestion.id)) {
    if (caretHere !== null) setCaret({ blockId: nb.id, offset: caretHere })
    return { ...cleanBlock(nb), suggestion: old.suggestion }
  }
  if (old.suggestion) return null // inside a suggested (inserted or struck) block of someone else
  if (!hasContent(old) || !hasContent(nb) || old.type !== nb.type || !sameBlockShape(old, nb)) return null

  const a = toCells(old.content)
  const b = toCells(nb.content)
  let p = 0
  while (p < a.length && p < b.length && cellKey(a[p]) === cellKey(b[p])) p++
  let s = 0
  while (s < a.length - p && s < b.length - p && cellKey(a[a.length - 1 - s]) === cellKey(b[b.length - 1 - s])) s++

  // "a b" + " " is ambiguous: the inserted (or deleted) run could sit anywhere it
  // slides to. Put it where the caret says it happened.
  const insLen = b.length - p - s
  const delLen = a.length - p - s
  const slide = (seq: Cell[], len: number, target: number) => {
    let q = p
    while (q > target && q > 0 && cellKey(seq[q - 1]) === cellKey(seq[q - 1 + len])) q--
    return q
  }
  if (delLen === 0 && insLen > 0 && caretHere !== null && caretHere - insLen < p) {
    p = slide(b, insLen, caretHere - insLen)
    s = a.length - p
  } else if (insLen === 0 && delLen > 0 && opts.caretBefore?.blockId === nb.id) {
    const c = opts.caretBefore.offset
    const target = c - delLen < p && c - delLen >= 0 ? c - delLen : c < p ? c : p
    p = slide(a, delLen, target)
    s = b.length - p
  }

  // One run of changes keeps one id: typing on from your pending text extends it.
  const near = [a[p - 1], ...a.slice(p, a.length - s), a[a.length - s]].filter(Boolean)
  const reuse = near
    .map((c) => (insertionOf(c) ?? deletionOf(c))?.suggestionId)
    .find((id): id is string => !!id && isLocal(id))
  const localId = reuse ?? opts.localId

  const result: Cell[] = []
  // Unchanged text: a formatting difference is a format suggestion, except in your own pending text.
  const keep = (oc: Cell, nc: Cell) => {
    const own = insertionOf(oc) && isLocal(insertionOf(oc)!.suggestionId)
    if (own || sameMarkList(formatting(oc.marks), formatting(nc.marks))) return { ...oc, marks: own ? nc.marks : oc.marks }
    return { ...oc, fmtChange: { from: formatting(oc.marks), to: formatting(nc.marks) } }
  }
  const prefix = a.slice(0, p).map((c, i) => keep(c, b[i]))
  const suffix = a.slice(a.length - s).map((c, i) => keep(c, b[b.length - s + i]))

  const removed = a.slice(p, a.length - s)
  const kept: Cell[] = []
  for (const c of removed) {
    const ins = insertionOf(c)
    if (ins && isLocal(ins.suggestionId)) continue // your own pending insertion: just removed
    if (ins || deletionOf(c)) kept.push(c) // already a suggestion: stays as it is
    else kept.push({ ...c, marks: [...c.marks, { type: "deletion", suggestionId: localId }] })
  }
  const added = b.slice(p, b.length - s).map((c) => ({ ...c, marks: [...withoutSuggestionMarks(c.marks), { type: "insertion" as const, suggestionId: localId }] }))
  result.push(...prefix, ...kept, ...added, ...suffix)

  // Format changes become pending format edits on the old text, grouped by mark and direction.
  type Changed = Cell & { fmtChange?: { from: Mark[]; to: Mark[] } }
  const changes = (result as Changed[]).map((c) => {
    if (!c.fmtChange) return [] as { mark: Mark; add: boolean }[]
    const fromKeys = new Set(c.fmtChange.from.map(markKey))
    const toKeys = new Set(c.fmtChange.to.map(markKey))
    return [
      ...c.fmtChange.to.filter((m) => !fromKeys.has(markKey(m))).map((mark) => ({ mark, add: true })),
      ...c.fmtChange.from.filter((m) => !toKeys.has(markKey(m))).map((mark) => ({ mark, add: false })),
    ]
  })
  const done = new Set<string>()
  changes.forEach((list, i) => {
    for (const ch of list) {
      const key = `${markKey(ch.mark)}|${ch.add}|${i}`
      if (done.has(key)) continue
      let j = i
      while (j < changes.length && changes[j].some((x) => x.add === ch.add && markKey(x.mark) === markKey(ch.mark))) {
        done.add(`${markKey(ch.mark)}|${ch.add}|${j}`)
        j++
      }
      formats.push({ id: localId, blockId: nb.id, from: i, to: j, mark: ch.mark, add: ch.add })
    }
  })
  for (const c of result as Changed[]) delete c.fmtChange

  if (caretHere !== null) {
    const insEnd = p + added.length
    let offset: number
    if (caretHere <= p && !(caretHere === p && added.length)) {
      // Backspace leaves the caret before the struck text; Delete and a range delete, after it.
      const backspace = opts.caretBefore?.blockId === nb.id && opts.caretBefore.offset === a.length - s && removed.length > 0
      offset = caretHere === p && !backspace ? p + kept.length : caretHere
    } else if (caretHere <= insEnd) offset = caretHere + kept.length
    else offset = caretHere - b.length + result.length
    setCaret({ blockId: nb.id, offset })
  }

  return { ...nb, content: fromCells(result) } as Block
}

/* ----------------------------------------------------------------- Flush */

export interface SummaryWords {
  replace: string // "Replace {from} with {to}"
  add: string // "Add {text}"
  delete: string // "Delete {text}"
  format: string // "Format {text}: {mark}"
  unformat: string // "Remove {mark} from {text}"
  addBlocks: string // "Add {n} blocks" / "Add {text}"
  deleteBlocks: string
  moveBlock: string
}

export const SUMMARY_EN: SummaryWords = {
  replace: "Replace {from} with {to}",
  add: "Add {text}",
  delete: "Delete {text}",
  format: "Make {text} {mark}",
  unformat: "Remove {mark} from {text}",
  addBlocks: "Add {text}",
  deleteBlocks: "Delete {text}",
  moveBlock: "Move {text}",
}

const fillIn = (t: string, v: Record<string, string>) => t.replace(/\{(\w+)\}/g, (_, k: string) => v[k] ?? "")
const shorten = (t: string, n = 60) => (t.length > n ? `${t.slice(0, n - 1)}…` : t)
const inlineText = (content: Inline[]) => flatText(content).replace(/\uFFFC/g, "…")

function blockText(block: Block): string {
  if (hasContent(block)) return inlineText(block.content)
  if (block.type === "code") return block.text
  if (block.type === "toggle") return inlineText(block.summary)
  return block.type
}

/** The card's sentence for a patch ("Replace 30 days with 14 days"). */
export function summarize(patch: SuggestionPatch, words: SummaryWords = SUMMARY_EN, quote = (t: string) => `“${shorten(t)}”`): string {
  switch (patch.op) {
    case "replace-text": {
      const ins = inlineText(patch.insert)
      if (patch.deleteText && ins) return fillIn(words.replace, { from: quote(patch.deleteText), to: quote(ins) })
      if (patch.deleteText) return fillIn(words.delete, { text: quote(patch.deleteText) })
      return fillIn(words.add, { text: quote(ins) })
    }
    case "format":
      return fillIn(patch.add ? words.format : words.unformat, { text: quote(patch.text), mark: patch.mark.type })
    case "insert-blocks":
      return fillIn(words.addBlocks, { text: quote(patch.blocks.map(blockText).join(" ").trim() || patch.blocks[0]?.type || "") })
    case "delete-blocks":
      return fillIn(words.deleteBlocks, { text: quote(patch.blockIds.join(", ")) })
    case "move-block":
      return fillIn(words.moveBlock, { text: quote(patch.blockId) })
  }
}

/**
 * Every local change in a marked document as patches, grouped per block:
 * consecutive inserted blocks are one `insert-blocks`, consecutive deleted
 * ones one `delete-blocks`; within a text block each run of local marks is one
 * `replace-text`; each pending format edit is one `format`.
 */
export function collectPatches(blocks: Block[], formats: FormatEdit[], isLocal: (id: string) => boolean): LocalChange[] {
  const changes: LocalChange[] = []
  const deletedText = new Map<string, string>()

  eachList(blocks, (list) => {
    let i = 0
    while (i < list.length) {
      const block = list[i]
      const s = block.suggestion
      if (s && isLocal(s.id) && (s.action === "insert" || s.action === "delete")) {
        let j = i
        while (j < list.length && list[j].suggestion?.action === s.action && isLocal(list[j].suggestion!.id)) j++
        const group = list.slice(i, j)
        const localIds = [...new Set(group.map((b) => b.suggestion!.id))]
        if (s.action === "insert") {
          // Anchored after the nearest block that is in the draft.
          let k = i - 1
          while (k >= 0 && list[k].suggestion?.action === "insert") k--
          const patch: SuggestionPatch = { op: "insert-blocks", afterBlockId: k >= 0 ? list[k].id : null, blocks: group.map(cleanBlock) }
          changes.push({ localIds, kind: "block", patch, summary: summarize(patch) })
        } else {
          const patch: SuggestionPatch = { op: "delete-blocks", blockIds: group.map((b) => b.id) }
          group.forEach((b) => deletedText.set(b.id, blockText(b)))
          changes.push({
            localIds,
            kind: "block",
            patch,
            summary: fillIn(SUMMARY_EN.deleteBlocks, { text: `“${shorten(group.map(blockText).join(" "))}”` }),
          })
        }
        i = j
        continue
      }
      if (!s && hasContent(block)) changes.push(...textChanges(block, isLocal))
      i++
    }
  })

  for (const f of formats) {
    if (!isLocal(f.id)) continue
    const at = findBlock(blocks, f.blockId)
    const block = at?.list[at.index]
    if (!block || !hasContent(block)) continue
    const cells = toCells(block.content)
    const baseBefore = cells.slice(0, f.from).filter(isBase).length
    const text = cells.slice(f.from, f.to).filter(isBase).map((c) => c.ch).join("")
    if (!text) continue
    const base = cells.filter(isBase).map((c) => c.ch).join("")
    const patch: SuggestionPatch = {
      op: "format",
      blockId: block.id,
      deleteText: "",
      text,
      occurrence: countMatches(base, text, baseBefore),
      mark: f.mark,
      add: f.add,
    }
    changes.push({ localIds: [f.id], kind: "format", patch, summary: summarize(patch) })
  }
  return changes
}

function textChanges(block: Block & Content, isLocal: (id: string) => boolean): LocalChange[] {
  const cells = toCells(block.content)
  const base = cells.filter(isBase).map((c) => c.ch).join("")
  const localOf = (c: Cell) => {
    const m = insertionOf(c) ?? deletionOf(c)
    return m && isLocal(m.suggestionId) ? m.suggestionId : null
  }
  const out: LocalChange[] = []
  let baseOffset = 0
  let i = 0
  while (i < cells.length) {
    if (!localOf(cells[i])) {
      if (isBase(cells[i])) baseOffset++
      i++
      continue
    }
    const start = baseOffset
    const ids = new Set<string>()
    const del: Cell[] = []
    const ins: Cell[] = []
    while (i < cells.length && localOf(cells[i])) {
      ids.add(localOf(cells[i])!)
      if (insertionOf(cells[i])) ins.push(cells[i])
      else {
        del.push(cells[i])
        baseOffset++
      }
      i++
    }
    const deleteText = del.map((c) => c.ch).join("")
    const insert = fromCells(ins.map((c) => ({ ...c, marks: withoutSuggestionMarks(c.marks) })))
    let patch: SuggestionPatch
    if (deleteText) {
      patch = { op: "replace-text", blockId: block.id, deleteText, insert, occurrence: countMatches(base, deleteText, start) }
    } else {
      const before = base.slice(Math.max(0, start - BEFORE_CHARS), start)
      patch = {
        op: "replace-text",
        blockId: block.id,
        deleteText: "",
        insert,
        before,
        occurrence: before ? countMatches(base, before, start - before.length) : 0,
      }
    }
    out.push({ localIds: [...ids], kind: "text", patch, summary: summarize(patch) })
  }
  return out
}

/** Swap a local id for the server's in a marked document (after a flush). */
export function replaceSuggestionId(blocks: Block[], from: string, to: string): Block[] {
  const swap = (marks?: Mark[]) =>
    marks?.map((m) => ((m.type === "insertion" || m.type === "deletion") && m.suggestionId === from ? { ...m, suggestionId: to } : m))
  const walk = (list: Block[]): Block[] =>
    list.map((b) => {
      let next: Block = b.suggestion?.id === from ? { ...b, suggestion: { ...b.suggestion, id: to } } : b
      if (hasContent(next)) {
        next = { ...next, content: next.content.map((r) => (r.type === "text" || r.type === "field" ? { ...r, marks: swap(r.marks) } : r)) } as Block
      }
      if (next.type === "toggle") next = { ...next, children: walk(next.children) }
      if (next.type === "columns") next = { ...next, columns: [walk(next.columns[0]), walk(next.columns[1])] }
      return next
    })
  return walk(blocks)
}
