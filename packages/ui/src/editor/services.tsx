"use client"

import * as React from "react"

import type { Inline, RecordRef, SavedBlock } from "./contract"
import { EDITOR_LABELS, type EditorLabels } from "./labels"

/** What a Record block shows (E.16): the record as the reader may see it. */
export interface RecordView {
  /** The record type's name, e.g. "Invoice". */
  typeLabel: string
  /** "INV-1042 · Example Clinic" */
  title: string
  subtitle?: string
  /** A formatted amount, e.g. "JOD 301.600". */
  amount?: string
  status?: { label: string; tone: "success" | "warning" | "destructive" | "info" | "secondary" }
  /** Where the record opens. */
  href?: string
}

/** What a Chart block draws: one series of labelled values. */
export interface ChartData {
  points: { label: string; value: number }[]
  /** What the values are, for the table view and screen readers. */
  valueLabel?: string
  /** What the series covers, e.g. "Last 6 months". */
  subtitle?: string
}

export interface UploadedFile {
  fileId: string
  /** Where the file is served, for images. */
  url: string
  name: string
  size: number
  mime: string
}

/**
 * How rich blocks reach live data and storage. The editor never fetches on its
 * own: the screen passes these in, and every call is answered within the
 * reader's permissions (E.16, E.31). `null` from a resolver means the reader
 * may not see it.
 */
export interface EditorServices {
  resolveRecord?: (ref: RecordRef) => Promise<RecordView | null>
  loadChart?: (source: { query: string; params?: Record<string, string> }) => Promise<ChartData | null>
  uploadFile?: (file: File) => Promise<UploadedFile>
  /** The download address of a stored file. */
  fileUrl?: (fileId: string) => string
  /** What a Chart block can read from, for its source menu. */
  chartSources?: { key: string; label: string }[]
  /** What a Record block can point at, for its record picker. */
  recordTypes?: { key: string; label: string }[]
  /**
   * Saved blocks (E.21–E.24): this person's own and the workspace library's.
   * Synced ones are drawn from here wherever a document uses them.
   */
  savedBlocks?: SavedBlock[]
  /** Saves a selection as a block; without it the editor offers no Save as block. */
  saveBlock?: (block: NewSavedBlock) => Promise<SavedBlock>
  /** E.22: whether "Everyone in the workspace" is offered (people who manage the library). */
  canShareSavedBlocks?: boolean
  /** E.17–E.20: the blocks the workspace's plugins add. */
  pluginBlocks?: PluginBlocks
  /**
   * Draws a plugin block's view (display blocks with its data already filled
   * in) with Whity's own components. The editor never runs plugin code.
   */
  renderPluginView?: (view: unknown[]) => React.ReactNode
  /** Switches a workspace plugin on, from the plugin-off placeholder (E.19). */
  enablePlugin?: (plugin: string) => Promise<void>
  /** The reader's language, for plugins' labels. */
  language?: "en" | "ar"
}

/** Text in English and Arabic, as plugins declare it. */
export type Localized = { en: string; ar: string }

/** One field of a plugin block's Edit form (SDK 1.103). */
export interface PluginBlockField {
  key: string
  label: Localized
  type: "text" | "longText" | "number" | "date" | "select"
  required?: boolean
  options?: { value: string; label: Localized }[]
}

/**
 * A block a plugin adds (E.17–E.20): a declaration Whity draws. `view` and
 * `print` are display blocks of the screen vocabulary, naming fields as
 * `{{data.key}}`; without `print`, notes and wiki pages only (E.18).
 */
export interface PluginBlockDefinition {
  name: string
  blockType: string
  label: Localized
  description?: Localized | null
  icon: string
  keywords: string[]
  fields: PluginBlockField[]
  view: unknown[]
  print: unknown[] | null
  allowedIn: string[]
}

export interface PluginBlockSource {
  plugin: string
  label: Localized
  /** Whity's mark, from Whity's marks only (VERIFIED-PLUGINS-SECURITY.md). */
  verified: boolean
  firstParty: boolean
  blocks: PluginBlockDefinition[]
}

/** The workspace's plugin blocks, and its plugins that are installed but off (E.19). */
export interface PluginBlocks {
  plugins: PluginBlockSource[]
  off: { plugin: string; label: Localized }[]
  /** Whether the person may switch a plugin on from the placeholder. */
  canEnable: boolean
}

/** What Save as block sends: the contract's SavedBlock without what the library assigns. */
export type NewSavedBlock = Pick<SavedBlock, "name" | "mode" | "sharing" | "blocks" | "perDocumentParts">

/**
 * Preview with a record (E.12): each field's value for that record, by path.
 * `null` is a missing value, flagged on the error tint (E.27).
 */
export interface FieldPreview {
  /** "INV-1042 · Example Clinic" */
  label: string
  values: Record<string, string | null>
  /**
   * The values are the fields' examples, not a record's: a template with no
   * record type previews with them instead of flagging every field missing.
   * There is no record to change.
   */
  examples?: boolean
  /** No record behind the values (examples, or values typed to issue): nothing to change. */
  noRecord?: boolean
}

interface ServicesValue {
  services: EditorServices
  labels: EditorLabels
  /** Set while previewing with a record; fields then show values, not chips. */
  preview?: FieldPreview | null
}

const ServicesContext = React.createContext<ServicesValue>({ services: {}, labels: EDITOR_LABELS })

export function EditorServicesProvider({ services, labels, preview = null, children }: ServicesValue & { children: React.ReactNode }) {
  const value = React.useMemo(() => ({ services, labels, preview }), [services, labels, preview])
  return <ServicesContext.Provider value={value}>{children}</ServicesContext.Provider>
}

export function useEditorServices(): ServicesValue {
  return React.useContext(ServicesContext)
}

/** Inline content as plain text; a merge field shows as {{its label}}. */
export function inlineText(content: Inline[] | null | undefined): string {
  return (content ?? [])
    .map((run) => (run.type === "text" ? run.text : run.type === "break" ? "\n" : run.type === "math" ? run.tex : `{{${run.label || run.path}}}`))
    .join("")
}

/** Plain text back to inline content, keeping `{{label}}` fields that were there before. */
export function textToInline(text: string, previous: Inline[] | null | undefined): Inline[] {
  const fields = new Map((previous ?? []).filter((r) => r.type === "field").map((r) => [`{{${(r as { label: string; path: string }).label || (r as { path: string }).path}}}`, r]))
  // Scans for the known tokens rather than a {{…}} regex: only fields that were
  // there before come back, and a run of "{" stays linear (CodeQL js/polynomial-redos).
  const out: Inline[] = []
  let from = 0
  let i = text.indexOf("{{")
  while (i !== -1) {
    let token: string | undefined
    for (const key of fields.keys()) {
      if (text.startsWith(key, i)) {
        token = key
        break
      }
    }
    if (token) {
      if (i > from) out.push({ type: "text", text: text.slice(from, i) })
      out.push(fields.get(token)!)
      from = i + token.length
      i = text.indexOf("{{", from)
    } else {
      i = text.indexOf("{{", i + 1)
    }
  }
  if (from < text.length) out.push({ type: "text", text: text.slice(from) })
  return out
}

/** "482 KB", "1.2 MB" — Western digits (3.7b). */
export function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
