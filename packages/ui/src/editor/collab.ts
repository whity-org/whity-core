import type { Actor, CommentThread, Inline, Suggestion } from "./contract"
import type { SuggestionKind, SuggestionPatch } from "./suggestions"

/**
 * Comments and suggestions as the editor takes them (editor M5): the
 * contract's `Suggestion` and `CommentThread`, plus what the server keeps
 * beside them (the patch, the status, the thread's anchor).
 */

export type SuggestionStatus = "open" | "accepted" | "rejected" | "stale"

export interface RailSuggestion extends Suggestion {
  patch: SuggestionPatch
  status: SuggestionStatus
  baseVersion?: number
}

export interface RailThread extends CommentThread {
  /** The block the quote is in (E.39: the highlight is drawn by matching the quote there). */
  anchorBlockId: string
}

/** Someone who can be @mentioned. */
export interface MentionPerson {
  id: string
  name: string
  /** A second line, such as a unit ("Accounts"). */
  detail?: string
}

/** What a flush sends for one local change (POST /suggestions). */
export interface NewSuggestion {
  kind: SuggestionKind
  summary: string
  patch: SuggestionPatch
}

export interface NewComment {
  anchorBlockId: string
  quote: string
  body: Inline[]
  /** Profile ids. */
  mentions: string[]
}

/** Who decides is the server's to check (documents:write, E.29); the editor only offers it with canEdit. */
export type Decision = "accepted" | "rejected"

export interface DecideAllFilter {
  /** Only these suggestions. */
  ids?: string[]
  /** Only those from one agent principal (its id). */
  agent?: string
}

/**
 * The editor's comments and suggestions. Without it the editor has no rail,
 * no Suggesting mode and no Comment, as before M5.
 */
export interface EditorCollab {
  /** Open suggestions, in the server's id order. */
  suggestions: RailSuggestion[]
  /** Every thread, resolved ones included. */
  threads: RailThread[]
  /**
   * Bump when the draft or the open suggestions changed on the server (a
   * decision, someone else's suggestion): the editor draws the suggestions
   * onto the draft again, once nothing of yours is waiting to be sent.
   */
  revision?: number
  /** documents:suggest (E.10). */
  canSuggest?: boolean
  /** documents:comment. */
  canComment?: boolean
  /** The person at the keyboard: their pending suggestions and new comments show as theirs. */
  me?: Actor & { kind: "person" }
  /** Sends one local change; resolves to the stored suggestion (its id replaces the local one). */
  onSuggest?: (change: NewSuggestion) => Promise<RailSuggestion>
  onDecide?: (suggestionId: string, decision: Decision) => Promise<void>
  onDecideAll?: (decision: Decision, filter?: DecideAllFilter) => Promise<void>
  onComment?: (comment: NewComment) => Promise<RailThread>
  onReply?: (threadId: string, body: Inline[], mentions: string[]) => Promise<void>
  onResolve?: (threadId: string) => Promise<void>
  onReopen?: (threadId: string) => Promise<void>
  /** People to @mention: workspace people who can be notified. */
  searchPeople?: (query: string) => Promise<MentionPerson[]>
}
