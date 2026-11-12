"use client"

import * as React from "react"

import { AgentRunStatus, type AgentRunState } from "../agent-run-status"
import { Button } from "../button"
import { Icon } from "../icon"
import { IconButton } from "../icon-button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "../select"
import { cn } from "../utils"
import { isActive, type EditorAgentRequest, type EditorAgents } from "./agents"
import { fill, type EditorLabels } from "./labels"

/** What the sheet opens with: the selection (Rewrite) or nothing (Ask an agent). */
export interface AgentAskStart {
  request?: string
  quote?: string | null
  anchorBlockId?: string | null
}

const ANY = "any"

/** A request's state as AgentRunStatus draws it: a queued one is running ("Waiting for …"), stopped reads as done. */
function runState(r: EditorAgentRequest): AgentRunState {
  if (r.status === "failed") return "failed"
  if (r.status === "done" || r.status === "stopped") return "done"
  return "running"
}

/**
 * Ask an agent (EditorAgents card, M6). A dashed sheet, because nothing has
 * happened yet: the agent picker (the agents this person uses), context chips
 * (this document, the selection), the request, the line that says it writes
 * only suggestions and for whom, and Ask. Below it the person's requests on
 * this document, each as an AgentRunStatus with Stop while it waits or runs.
 *
 * With no agent connected the sheet says how to connect one instead of
 * failing (owner's answer Q3).
 */
function AgentPanel({
  agents,
  labels,
  me,
  start,
  onClose,
  className,
}: {
  agents: EditorAgents
  labels: EditorLabels
  /** The person at the keyboard, for "for {name}". */
  me?: string
  start?: AgentAskStart
  onClose: () => void
  className?: string
}) {
  const [agent, setAgent] = React.useState<string>(() => {
    const first = agents.choices.find((c) => c.id !== null)
    return first ? String(first.id) : ANY
  })
  const [text, setText] = React.useState(start?.request ?? "")
  const [busy, setBusy] = React.useState(false)
  const [failed, setFailed] = React.useState(false)
  const fieldId = React.useId()
  const area = React.useRef<HTMLTextAreaElement>(null)

  React.useEffect(() => {
    setText(start?.request ?? "")
    area.current?.focus()
  }, [start])

  const connected = agents.choices.length > 0
  const ask = async () => {
    const request = text.trim()
    if (!request || busy) return
    setBusy(true)
    setFailed(false)
    try {
      await agents.onAsk({
        agentId: agent === ANY ? null : Number(agent),
        request,
        anchorBlockId: start?.anchorBlockId ?? null,
        quote: start?.quote ?? null,
      })
      setText("")
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section
      aria-label={labels.agentAsk}
      data-slot="agent-panel"
      className={cn("grid gap-3 border-b border-border bg-card px-4 py-3", className)}
    >
      <div className="flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-1.5 text-sm font-semibold">
          <Icon name="robot" />
          {labels.agentAsk}
        </h2>
        <IconButton icon="close" label={labels.agentClose} size="sm" onClick={onClose} />
      </div>

      {!connected ? (
        <div className="grid gap-2 rounded-card border border-dashed border-input p-3 text-sm">
          <p>{labels.agentNoneConnected}</p>
          {agents.connectHref && (
            <a className="w-fit text-sm font-medium text-primary underline-offset-2 hover:underline" href={agents.connectHref}>
              {labels.agentConnect}
            </a>
          )}
        </div>
      ) : (
        <form
          className="grid gap-2.5 rounded-card border border-dashed border-input p-3"
          onSubmit={(e) => {
            e.preventDefault()
            void ask()
          }}
        >
          <div className="flex flex-wrap items-center gap-1.5">
            <Select value={agent} onValueChange={setAgent}>
              <SelectTrigger size="sm" aria-label={labels.agentPicker}>
                <Icon name="robot" />
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {agents.choices.map((c) => (
                  <SelectItem key={c.id ?? ANY} value={c.id === null ? ANY : String(c.id)}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <span className="inline-flex h-7 items-center gap-1 rounded-full bg-muted px-2.5 text-xs text-muted-foreground">
              <Icon name="document" size={14} />
              {labels.agentThisDocument}
            </span>
            {start?.quote && (
              <span className="inline-flex h-7 max-w-[16rem] items-center gap-1 truncate rounded-full bg-muted px-2.5 text-xs text-muted-foreground">
                “{start.quote}”
              </span>
            )}
          </div>
          <label htmlFor={fieldId} className="sr-only">
            {labels.agentRequest}
          </label>
          <textarea
            id={fieldId}
            ref={area}
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
                e.preventDefault()
                void ask()
              }
              if (e.key === "Escape") onClose()
            }}
            placeholder={labels.agentRequestPlaceholder}
            rows={3}
            maxLength={4000}
            className="min-h-[72px] w-full resize-y rounded-control border-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/30"
          />
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <Icon name="robot" size={14} />
              {me ? fill(labels.agentWritesFor, { name: me }) : labels.agentWrites}
            </span>
            <Button type="submit" variant="outline" size="sm" icon="send" disabled={!text.trim() || busy}>
              {labels.agentAskButton}
            </Button>
          </div>
          {failed && (
            <p role="alert" className="text-xs text-error-text">
              {labels.collabFailed}
            </p>
          )}
        </form>
      )}

      {agents.requests.length > 0 && (
        <ul className="grid gap-2" aria-label={labels.agentRequests}>
          {agents.requests.slice(0, 3).map((r) => (
            <li key={r.id}>
              <AgentRunStatus
                status={runState(r)}
                agent={r.agent?.name ?? labels.agentAny}
                onBehalfOf={me}
                title={
                  r.status === "queued"
                    ? labels.agentWaiting
                    : r.status === "stopped"
                      ? labels.agentStopped
                      : (r.title ?? (r.status === "running" ? labels.agentWorking : r.request))
                }
                progress={r.status === "running" ? (r.progress ?? undefined) : undefined}
                detail={r.status === "running" ? r.request : (r.detail ?? undefined)}
                next={r.status === "failed" ? labels.agentFailedNext : undefined}
                onStop={isActive(r.status) && !r.stopping ? () => void agents.onStop(r.id) : undefined}
                onRetry={r.status === "failed" ? () => setText(r.request) : undefined}
                labels={{
                  running: r.status === "queued" ? labels.agentQueued : labels.agentRunning,
                  done: r.status === "stopped" ? labels.agentStoppedBadge : labels.agentDone,
                  failed: labels.agentFailed,
                  for: labels.actorFor,
                  stop: labels.agentStop,
                  retry: labels.agentRetry,
                }}
                className="p-3"
              />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

export { AgentPanel }
