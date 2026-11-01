import { Extension } from "@tiptap/core"
import type { Node as PmNode, Schema } from "@tiptap/pm/model"
import { Plugin, PluginKey, TextSelection, type EditorState, type Transaction } from "@tiptap/pm/state"
import { Decoration, DecorationSet } from "@tiptap/pm/view"

import type { Block, Mark as DocMark } from "./contract"
import { blockFromPm, blockToPm } from "./convert"
import { suggestEdit, type FormatEdit, type RenderedFormat } from "./suggestions"

/**
 * Suggesting mode and the collaboration decorations (editor M5, E.10, E.39).
 *
 * While suggesting, every edit is let through and then rewritten by
 * `appendTransaction`: the top-level blocks it touched are diffed before and
 * after (`suggestEdit`, pure and tested), and replaced with the same change
 * expressed as local `insertion` / `deletion` marks and block `suggestion`
 * attrs. A formatting change is undone and kept as a pending format edit,
 * drawn with a dashed underline. An edit that can't be a suggestion yet is
 * undone (and counted in `refused`, so the editor can say so).
 *
 * The same plugin draws, on screen only (never in the document):
 * - comment highlights by matching each open thread's quote within its anchor
 *   block (warning tint and underline, E.39, not the highlight colour);
 * - suggested blocks, dashed (inserted) or struck (deleted);
 * - format suggestions, dashed underline.
 */

export const LOCAL_PREFIX = "local-"
let localSeq = 0
export const nextLocalId = () => `${LOCAL_PREFIX}${Date.now().toString(36)}${(localSeq++).toString(36)}`

/** Pending format edit in document positions. */
interface PmFormat {
  id: string
  from: number
  to: number
  mark: DocMark
  add: boolean
}

export interface CollabThreadAnchor {
  id: string
  blockId: string
  quote: string
}

interface SuggestingState {
  enabled: boolean
  formats: PmFormat[]
  /** Server format suggestions, by block offsets. */
  serverFormats: RenderedFormat[]
  threads: CollabThreadAnchor[]
  active: string | null
  /** How many edits were refused since the plugin started (the editor shows a note when it grows). */
  refused: number
}

export type SuggestingMeta =
  | { enabled: boolean }
  | { skip: true }
  | { addFormats: PmFormat[] }
  | { dropFormats: string[] }
  | { renameFormat: { from: string; to: string } }
  | { serverFormats: RenderedFormat[] }
  | { threads: CollabThreadAnchor[] }
  | { active: string | null }
  | { refused: true }

export const suggestingKey = new PluginKey<SuggestingState>("suggesting")

export interface SuggestingOptions {
  /** Whether an id is one of this person's pending, unsent changes. */
  isLocal: (id: string) => boolean
  /** A click on a highlight or a suggestion: the card to make active. */
  onActivate: (id: string | null) => void
}

/** The node and position of a block by id, nested blocks included. */
export function findBlockNode(doc: PmNode, id: string): { node: PmNode; pos: number } | null {
  let found: { node: PmNode; pos: number } | null = null
  doc.descendants((node, pos) => {
    if (found) return false
    if (node.attrs.id === id) {
      found = { node, pos }
      return false
    }
    return true
  })
  return found
}

/** A textblock's text as patches and quotes count it (one character per inline atom). */
export function blockTextOf(node: PmNode): string {
  return node.textBetween(0, node.content.size, undefined, "\uFFFC")
}

/** Where an open thread's quote is, in document positions. */
export function threadRange(doc: PmNode, t: CollabThreadAnchor): { from: number; to: number } | null {
  if (!t.quote) return null
  const at = findBlockNode(doc, t.blockId)
  if (!at || !at.node.isTextblock) return null
  const index = blockTextOf(at.node).indexOf(t.quote)
  if (index < 0) return null
  return { from: at.pos + 1 + index, to: at.pos + 1 + index + t.quote.length }
}

/** Where a suggestion shows: its first marked text or suggested block. */
export function suggestionPos(doc: PmNode, id: string, formats: RenderedFormat[] = []): number | null {
  let at: number | null = null
  doc.descendants((node, pos) => {
    if (at !== null) return false
    if (node.attrs.suggestion?.id === id) {
      at = pos
      return false
    }
    if (node.isText && node.marks.some((m) => (m.type.name === "insertion" || m.type.name === "deletion") && m.attrs.suggestionId === id)) {
      at = pos
      return false
    }
    return true
  })
  if (at !== null) return at
  const f = formats.find((x) => x.suggestionId === id)
  const b = f && findBlockNode(doc, f.blockId)
  return b ? b.pos + 1 + f!.from : null
}

function caretOf(state: EditorState): { blockId: string; offset: number } | null {
  const sel = state.selection
  if (!sel.empty) return null
  const parent = sel.$from.parent
  const id = parent.attrs.id as string | null
  return id ? { blockId: id, offset: sel.$from.parentOffset } : null
}

const toBlocks = (nodes: PmNode[]): Block[] => nodes.map((n) => blockFromPm(n.toJSON())).filter((b): b is Block => b !== null)

/**
 * The Suggesting-mode rewrite of one or more transactions (exported for tests).
 * Returns the transaction to append, or null when there is nothing to do.
 */
export function suggestTransaction(
  trs: readonly Transaction[],
  oldState: EditorState,
  newState: EditorState,
  isLocal: (id: string) => boolean
): Transaction | null {
  const st = suggestingKey.getState(newState)
  const edits = trs.filter((t) => t.docChanged && !t.getMeta(suggestingKey) && !t.getMeta("history$") && !t.getMeta("appendedTransaction"))
  if (!edits.length) return null
  // Editing: typed or pasted text is plain draft text, never part of a suggestion around it.
  if (!st?.enabled) return stripInsertedSuggestionMarks(trs, newState)

  const a = oldState.doc
  const b = newState.doc
  let i = 0
  while (i < a.childCount && i < b.childCount && a.child(i).eq(b.child(i))) i++
  let j = 0
  while (j < a.childCount - i && j < b.childCount - i && a.child(a.childCount - 1 - j).eq(b.child(b.childCount - 1 - j))) j++
  const oldNodes: PmNode[] = []
  const newNodes: PmNode[] = []
  for (let k = i; k < a.childCount - j; k++) oldNodes.push(a.child(k))
  for (let k = i; k < b.childCount - j; k++) newNodes.push(b.child(k))
  let start = 0
  for (let k = 0; k < i; k++) start += b.child(k).nodeSize
  const newEnd = start + newNodes.reduce((n, node) => n + node.nodeSize, 0)

  const result = suggestEdit(toBlocks(oldNodes), toBlocks(newNodes), {
    localId: nextLocalId(),
    isLocal,
    caretBefore: oldState.selection.empty ? caretOf(oldState) : selectionEnd(oldState),
    caretAfter: caretOf(newState),
  })

  const tr = newState.tr
  const schema: Schema = newState.schema
  if (!result) {
    // Not expressible as a suggestion yet: put the touched blocks back as they were.
    tr.replaceWith(start, newEnd, oldNodes)
    tr.setSelection(oldState.selection.getBookmark().resolve(tr.doc))
    tr.setMeta(suggestingKey, { refused: true } satisfies SuggestingMeta)
    return tr.setMeta("addToHistory", false)
  }
  const nodes = result.blocks.map((block) => schema.nodeFromJSON(blockToPm(block)))
  const same = nodes.length === newNodes.length && nodes.every((n, k) => n.eq(newNodes[k]))
  if (same && !result.formats.length) return null
  if (!same) tr.replaceWith(start, newEnd, nodes)
  tr.setMeta(suggestingKey, { skip: true } satisfies SuggestingMeta)
  if (!newState.selection.empty && tr.doc.content.size === newState.doc.content.size) {
    // A formatting change keeps its selection.
    tr.setSelection(TextSelection.create(tr.doc, newState.selection.from, newState.selection.to))
  } else if (result.caret) {
    const at = findBlockNode(tr.doc, result.caret.blockId)
    if (at) {
      const pos = Math.min(at.pos + 1 + result.caret.offset, at.pos + at.node.nodeSize - 1)
      tr.setSelection(TextSelection.create(tr.doc, pos))
    }
  }
  if (result.formats.length) {
    const formats = result.formats
      .map((f: FormatEdit) => {
        const at = findBlockNode(tr.doc, f.blockId)
        return at ? { id: f.id, from: at.pos + 1 + f.from, to: at.pos + 1 + f.to, mark: f.mark, add: f.add } : null
      })
      .filter((f): f is PmFormat => f !== null)
    // A second meta key can't share the plugin key: formats ride on their own.
    tr.setMeta(FORMATS_META, formats)
  }
  return tr
}

const FORMATS_META = "suggestingFormats"

/**
 * Editing mode: text a transaction inserts must not carry another
 * suggestion's `insertion` or `deletion` mark. Typed inside a suggested
 * insertion, it would inherit the mark, and saving (which strips suggested
 * insertions from the draft) would silently drop it. Undo/redo, the editor's
 * own redraws and appended transactions keep their marks.
 */
export function stripInsertedSuggestionMarks(trs: readonly Transaction[], state: EditorState): Transaction | null {
  const ranges: { from: number; to: number }[] = []
  trs.forEach((t, ti) => {
    if (!t.docChanged || t.getMeta(suggestingKey) || t.getMeta("history$") || t.getMeta("appendedTransaction")) return
    t.mapping.maps.forEach((map, i) => {
      map.forEach((_oldStart, _oldEnd, newStart, newEnd) => {
        if (newEnd <= newStart) return
        // Into this transaction's final document, then through the transactions after it.
        let from = t.mapping.slice(i + 1).map(newStart, 1)
        let to = t.mapping.slice(i + 1).map(newEnd, -1)
        for (const later of trs.slice(ti + 1)) {
          from = later.mapping.map(from, 1)
          to = later.mapping.map(to, -1)
        }
        if (to > from) ranges.push({ from, to })
      })
    })
  })
  if (!ranges.length) return null
  const { insertion, deletion } = state.schema.marks
  const tr = state.tr
  for (const { from, to } of ranges) {
    let marked = false
    state.doc.nodesBetween(from, to, (node) => {
      if (node.isInline && node.marks.some((m) => m.type === insertion || m.type === deletion)) marked = true
      return !marked
    })
    if (!marked) continue
    if (insertion) tr.removeMark(from, to, insertion)
    if (deletion) tr.removeMark(from, to, deletion)
  }
  if (!tr.docChanged) return null
  return tr.setMeta(suggestingKey, { skip: true } satisfies SuggestingMeta)
}

function selectionEnd(state: EditorState): { blockId: string; offset: number } | null {
  const $to = state.selection.$to
  const id = $to.parent.attrs.id as string | null
  return id ? { blockId: id, offset: $to.parentOffset } : null
}

/** Pending format edits as block offsets, for the flush. */
export function pendingFormats(state: EditorState): FormatEdit[] {
  const st = suggestingKey.getState(state)
  if (!st) return []
  const out: FormatEdit[] = []
  for (const f of st.formats) {
    const $from = state.doc.resolve(f.from)
    const id = $from.parent.attrs.id as string | null
    if (!id || f.to <= f.from) continue
    const start = $from.start()
    out.push({ id: f.id, blockId: id, from: f.from - start, to: Math.min(f.to, $from.end()) - start, mark: f.mark, add: f.add })
  }
  return out
}

/** Swaps a local suggestion id for the server's in marks and block attrs. */
export function renameSuggestion(state: EditorState, from: string, to: string): Transaction {
  const tr = state.tr
  state.doc.descendants((node, pos) => {
    if (node.attrs.suggestion?.id === from) tr.setNodeMarkup(pos, undefined, { ...node.attrs, suggestion: { ...node.attrs.suggestion, id: to } })
    if (node.isText) {
      for (const m of node.marks) {
        if ((m.type.name === "insertion" || m.type.name === "deletion") && m.attrs.suggestionId === from) {
          tr.removeMark(pos, pos + node.nodeSize, m)
          tr.addMark(pos, pos + node.nodeSize, m.type.create({ suggestionId: to }))
        }
      }
    }
    return true
  })
  tr.setMeta(suggestingKey, { skip: true } satisfies SuggestingMeta)
  tr.setMeta("addToHistory", false)
  return tr
}

export const Suggesting = Extension.create<SuggestingOptions>({
  name: "suggesting",
  // After BlockIds, so a new block already has its id when the edit is rewritten.
  priority: 10,
  addOptions() {
    return { isLocal: (id: string) => id.startsWith(LOCAL_PREFIX), onActivate: () => {} }
  },
  addProseMirrorPlugins() {
    const options = this.options
    return [
      new Plugin<SuggestingState>({
        key: suggestingKey,
        state: {
          init: () => ({ enabled: false, formats: [], serverFormats: [], threads: [], active: null, refused: 0 }),
          apply(tr, prev) {
            let next = prev
            if (tr.docChanged && prev.formats.length) {
              next = {
                ...next,
                formats: prev.formats
                  .map((f) => ({ ...f, from: tr.mapping.map(f.from, 1), to: tr.mapping.map(f.to, -1) }))
                  .filter((f) => f.to > f.from),
              }
            }
            const added = tr.getMeta(FORMATS_META) as PmFormat[] | undefined
            if (added) next = { ...next, formats: [...next.formats, ...added] }
            const meta = tr.getMeta(suggestingKey) as SuggestingMeta | undefined
            if (!meta) return next
            if ("enabled" in meta) return { ...next, enabled: meta.enabled }
            if ("dropFormats" in meta) return { ...next, formats: next.formats.filter((f) => !meta.dropFormats.includes(f.id)) }
            if ("serverFormats" in meta) return { ...next, serverFormats: meta.serverFormats }
            if ("threads" in meta) return { ...next, threads: meta.threads }
            if ("active" in meta) return { ...next, active: meta.active }
            if ("refused" in meta) return { ...next, refused: next.refused + 1 }
            return next
          },
        },
        appendTransaction: (trs, oldState, newState) => suggestTransaction(trs, oldState, newState, options.isLocal),
        props: {
          decorations(state) {
            const st = suggestingKey.getState(state)
            if (!st) return null
            const decos: Decoration[] = []
            for (const t of st.threads) {
              const r = threadRange(state.doc, t)
              if (r) decos.push(Decoration.inline(r.from, r.to, { class: `wy-cmt${st.active === t.id ? " is-active" : ""}`, "data-thread": t.id }))
            }
            state.doc.descendants((node, pos) => {
              const s = node.attrs.suggestion as { id: string; action: string } | null | undefined
              if (s) {
                decos.push(
                  Decoration.node(pos, pos + node.nodeSize, {
                    class: `wy-sug-block is-${s.action}${st.active === s.id ? " is-active" : ""}`,
                    "data-suggestion": s.id,
                  })
                )
              }
              return true
            })
            for (const f of st.serverFormats) {
              const at = findBlockNode(state.doc, f.blockId)
              if (at) decos.push(Decoration.inline(at.pos + 1 + f.from, at.pos + 1 + f.to, { class: "wy-sug-fmt", "data-suggestion": f.suggestionId }))
            }
            for (const f of st.formats) decos.push(Decoration.inline(f.from, f.to, { class: "wy-sug-fmt is-pending" }))
            return DecorationSet.create(state.doc, decos)
          },
          handleClick(_view, _pos, event) {
            const el = (event.target as HTMLElement | null)?.closest?.("[data-thread],[data-suggestion]") as HTMLElement | null
            options.onActivate(el ? (el.dataset.thread ?? el.dataset.suggestion ?? null) : null)
            return false
          },
        },
      }),
    ]
  },
})
