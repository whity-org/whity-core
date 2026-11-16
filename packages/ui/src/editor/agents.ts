/**
 * Agents in the editor (M6, EditorAgents card; E.4, E.30, E.31). Whity hosts
 * no model: the person asks, their own agent picks the request up over MCP,
 * and everything it writes comes back as suggestions and comments on the
 * rail. The editor shows the request as it runs, with Stop.
 */

export type AgentRequestStatus = "queued" | "running" | "done" | "failed" | "stopped"

/** An agent this person may ask; `id` null is "any agent I have connected". */
export interface EditorAgentChoice {
  id: number | null
  name: string
}

export interface EditorAgentRequest {
  id: string
  request: string
  quote?: string | null
  status: AgentRequestStatus
  /** The agent asked or working on it; null while any of the person's agents may take it. */
  agent: { id: string; name: string } | null
  /** What it is doing now, in plain words. */
  title?: string | null
  progress?: { done: number; total: number } | null
  /** What it did, or what went wrong. */
  detail?: string | null
  /** Stop was pressed; the agent hasn't stopped yet. */
  stopping?: boolean
  /** ISO 8601. */
  at: string
}

export interface NewAgentRequest {
  agentId: number | null
  request: string
  anchorBlockId?: string | null
  quote?: string | null
}

export interface EditorAgents {
  /** The agents this person uses. Empty: the sheet says how to connect one. */
  choices: EditorAgentChoice[]
  /** This person's requests on this document, newest first. */
  requests: EditorAgentRequest[]
  /** Where to connect an agent (Account › Agents). */
  connectHref?: string
  onAsk: (request: NewAgentRequest) => Promise<void>
  onStop: (requestId: string) => Promise<void>
}

/** Waiting or running: the editor keeps polling while one of these is on the sheet. */
export function isActive(status: AgentRequestStatus): boolean {
  return status === "queued" || status === "running"
}
