import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { EditorCollab, RailSuggestion, RailThread } from "./collab"
import { CollabDrawer, CollabRail, type RailActions, type RailItem } from "./collab-rail"
import type { Actor, EditorMode, WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import { EDITOR_LABELS } from "./labels"
import { SAMPLE_DOCUMENT } from "./sample"

/**
 * Editor M5, the EditorCollab card: the rail with comments and suggestions,
 * Suggesting mode, resolved threads and the drawer under 1024px. Example data
 * follows the card (Rana Haddad, Omar Khalil, the Billing assistant).
 */
const meta = {
  title: "Editor/Comments and suggestions",
  parameters: { layout: "fullscreen", fullBleed: true },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const t = (text: string) => ({ type: "text" as const, text })
const RANA: Actor & { kind: "person" } = { kind: "person", id: "11", name: "Rana Haddad" }
const OMAR: Actor = { kind: "person", id: "12", name: "Omar Khalil" }
const AGENT: Actor = { kind: "agent", id: "billing-assistant", name: "Billing assistant", onBehalfOf: { id: "11", name: "Rana" }, scope: ["documents:suggest"] }

const LETTER: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  kind: "letter",
  title: "Payment reminder",
  blocks: [
    { id: "p1", type: "paragraph", content: [t("Invoice INV-1042 was due on 14 October. Please pay within 30 days of this letter.")] },
    { id: "p2", type: "paragraph", content: [t("Payments usually take two working days to reach us.")] },
    { id: "p3", type: "paragraph", content: [t("If you have already paid, thank you, and please ignore this letter.")] },
  ],
}

const SUGGESTIONS: RailSuggestion[] = [
  {
    id: "41",
    by: RANA,
    at: "2026-10-29T13:52:00",
    kind: "text",
    summary: "Replace “30 days” with “14 days”",
    status: "open",
    patch: { op: "replace-text", blockId: "p1", deleteText: "30 days", insert: [t("14 days")], occurrence: 0 },
  },
  {
    id: "42",
    by: RANA,
    at: "2026-10-29T13:53:00",
    kind: "text",
    summary: "Add “, or call us on 06 555 0100”",
    status: "open",
    patch: { op: "replace-text", blockId: "p1", deleteText: "", insert: [t(", or call us on 06 555 0100")], before: "of this letter", occurrence: 0 },
  },
  {
    id: "43",
    by: AGENT,
    at: "2026-10-29T13:55:00",
    kind: "text",
    summary: "Delete “usually ”",
    status: "open",
    request: "Make the payment paragraph firmer",
    permission: "documents:suggest",
    patch: { op: "replace-text", blockId: "p2", deleteText: "usually ", insert: [], occurrence: 0 },
  },
]

const THREADS: RailThread[] = [
  {
    id: "7",
    anchorBlockId: "p1",
    quote: "Please pay within",
    messages: [
      { id: "71", by: OMAR, at: "2026-10-29T13:40:00", body: [t("Should we mention the 2% late fee here?")], mentions: [] },
      { id: "72", by: RANA, at: "2026-10-29T13:44:00", body: [t("Yes, after the due date sentence.")], mentions: [] },
    ],
  },
  {
    id: "5",
    anchorBlockId: "p3",
    quote: "already paid",
    messages: [{ id: "51", by: OMAR, at: "2026-10-29T12:05:00", body: [t("Use the customer’s legal name")], mentions: [] }],
    resolved: { by: RANA, at: "2026-10-29T12:10:00" },
  },
]

const PEOPLE = [
  { id: "12", name: "Omar Khalil", detail: "Accounts" },
  { id: "13", name: "Lina Saad", detail: "HR" },
]

/** An in-memory server: suggestions get ids, decisions drop them, threads resolve and reopen. */
function useFakeCollab(canComment = true): EditorCollab {
  const [suggestions, setSuggestions] = React.useState(SUGGESTIONS)
  const [threads, setThreads] = React.useState(THREADS)
  const next = React.useRef(100)
  const now = () => new Date().toISOString()
  return {
    suggestions,
    threads,
    canSuggest: true,
    canComment,
    me: RANA,
    onSuggest: async (change) => {
      const saved: RailSuggestion = { id: String(next.current++), by: RANA, at: now(), status: "open", ...change }
      setSuggestions((s) => [...s, saved])
      return saved
    },
    onDecide: async (id) => setSuggestions((s) => s.filter((x) => x.id !== id)),
    onDecideAll: async (_d, filter) => setSuggestions((s) => (filter?.agent ? s.filter((x) => x.by.kind !== "agent" || x.by.id !== filter.agent) : [])),
    onComment: async (c) => {
      const thread: RailThread = {
        id: String(next.current++),
        anchorBlockId: c.anchorBlockId,
        quote: c.quote,
        messages: [{ id: String(next.current++), by: RANA, at: now(), body: c.body, mentions: [] }],
      }
      setThreads((ts) => [...ts, thread])
      return thread
    },
    onReply: async (threadId, body) =>
      setThreads((ts) => ts.map((x) => (x.id === threadId ? { ...x, messages: [...x.messages, { id: String(next.current++), by: RANA, at: now(), body, mentions: [] }] } : x))),
    onResolve: async (threadId) => setThreads((ts) => ts.map((x) => (x.id === threadId ? { ...x, resolved: { by: RANA, at: now() } } : x))),
    onReopen: async (threadId) => setThreads((ts) => ts.map((x) => (x.id === threadId ? { ...x, resolved: undefined } : x))),
    searchPeople: async (q) => PEOPLE.filter((p) => p.name.toLowerCase().includes(q.toLowerCase())),
  }
}

function Live({ canEdit = true, startMode }: { canEdit?: boolean; startMode?: EditorMode }) {
  const [doc, setDoc] = React.useState(LETTER)
  const [mode, setMode] = React.useState<EditorMode | undefined>(startMode)
  const collab = useFakeCollab()
  return (
    <div style={{ height: "100vh" }}>
      <DocEditor
        document={doc}
        onChange={setDoc}
        canEdit={canEdit}
        mode={mode}
        onModeChange={setMode}
        back={{ label: "Back to templates", onClick: () => {} }}
        path="Templates · Letters"
        save={{ state: "saved", at: "14:02" }}
        collab={collab}
      />
    </div>
  )
}

/** The rail beside the sheet: a thread, Rana's two suggestions and the agent's, dashed. */
export const RailWithCommentsAndSuggestions: Story = { render: () => <Live /> }

/** Suggesting mode: the mode button on the tint; typing adds, deleting strikes through. */
export const SuggestingMode: Story = { render: () => <Live startMode="suggesting" /> }

/** Someone without edit permission: Suggesting, with Editing locked in the mode menu (E.10). */
export const WithoutEditPermission: Story = { render: () => <Live canEdit={false} /> }

const ACTIONS: RailActions = {
  canEdit: true,
  canComment: true,
  onDecide: () => {},
  onDecideAll: () => {},
  onReply: async () => {},
  onResolve: () => {},
  onReopen: () => {},
  searchPeople: async (q) => PEOPLE.filter((p) => p.name.toLowerCase().includes(q.toLowerCase())),
}
const ITEMS: RailItem[] = [
  { kind: "thread", id: "7", thread: THREADS[0] },
  ...SUGGESTIONS.map((s) => ({ kind: "suggestion" as const, id: s.id, suggestion: s })),
]
const RESOLVED: RailItem[] = [{ kind: "thread", id: "5", thread: THREADS[1] }]

/** The rail on its own, with "1 resolved" open: the resolved card and Reopen. */
export const ResolvedThreads: Story = {
  render: () => (
    <div style={{ width: 300, minHeight: 900, marginInlineStart: 24 }}>
      <CollabRail
        items={ITEMS}
        resolved={RESOLVED}
        labels={EDITOR_LABELS}
        actions={ACTIONS}
        active="7"
        onActive={() => {}}
        anchorTop={() => null}
        layoutKey={0}
        defaultShowResolved
      />
    </div>
  ),
}

/** Under 1024px: the same cards in a drawer with All, Comments, Suggestions and Resolved (E.6). */
export const NarrowDrawer: Story = { render: () => <DrawerDemo /> }

function DrawerDemo() {
  const [open, setOpen] = React.useState(true)
  const [active, setActive] = React.useState<string | null>(null)
  return (
    <CollabDrawer open={open} onOpenChange={setOpen} items={ITEMS} resolved={RESOLVED} labels={EDITOR_LABELS} actions={ACTIONS} active={active} onActive={setActive} />
  )
}
