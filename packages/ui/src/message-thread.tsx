"use client"

import * as React from "react"

import { Avatar, type ActorKind } from "./avatar"
import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

/**
 * A message thread where agents are members (CH.1, CH.2;
 * docs/design/components/TplChat; `.wk-msg`, `.wk-sys`, `.wk-working` in
 * bundle.css), for Chat and Messages.
 *
 * Everything an agent writes is marked so it is never mistaken for a person:
 * the robot face, an Agent tag, who it works for here, and a tinted edge on
 * its messages. Joining, hand-offs and "is drafting" are lines everyone sees.
 * An agent's proposal in the thread is a dashed card (not happened yet).
 */

export interface MessageAuthor {
  name: string
  kind?: ActorKind
  src?: string
  pattern?: string
  hue?: number
}

/** The messages, oldest first; new ones are announced politely. */
function MessageThread({ className, label, ...props }: React.ComponentProps<"div"> & { label: string }) {
  return (
    <div
      data-slot="message-thread"
      role="log"
      aria-label={label}
      aria-live="polite"
      className={cn("flex flex-col gap-3.5 px-4.5 py-3.5", className)}
      {...props}
    />
  )
}

/** The tag beside an agent's name. */
function AgentTag({ label = "Agent", className }: { label?: string; className?: string }) {
  return (
    <span
      data-slot="agent-tag"
      className={cn(
        "inline-flex h-[18px] items-center gap-[3px] rounded-[5px] bg-primary-tint px-1.5 text-[0.6875rem] font-semibold text-primary-tint-foreground",
        className
      )}
    >
      <Icon name="robot" size={10} />
      {label}
    </span>
  )
}

export interface MessageProps {
  author: MessageAuthor
  /** For an agent: the person it acts for here ("Amro"). */
  onBehalfOf?: string
  /** Already formatted ("09:02"). */
  time?: string
  children: React.ReactNode
  /** A proposal or attachment under the text. */
  attachment?: React.ReactNode
  labels?: { agent?: string; for?: string }
  className?: string
}

function Message({ author, onBehalfOf, time, children, attachment, labels, className }: MessageProps) {
  const L = { agent: "Agent", for: "for {name}", ...labels }
  const isAgent = author.kind === "agent"
  return (
    <article
      data-slot="message"
      data-kind={author.kind ?? "person"}
      className={cn("grid grid-cols-[32px_minmax(0,1fr)] gap-2.5 text-[0.9375rem] leading-normal", className)}
    >
      <Avatar
        name={author.name}
        kind={author.kind}
        src={author.src}
        pattern={author.pattern}
        hue={author.hue}
        size={32}
        labels={{ agent: L.agent.toLowerCase() }}
      />
      <div className="min-w-0">
        <header className="mb-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <b className="text-sm font-semibold text-foreground">{author.name}</b>
          {isAgent && <AgentTag label={L.agent} />}
          {isAgent && onBehalfOf && <span>{L.for.replace("{name}", onBehalfOf)}</span>}
          {time && <span dir="ltr">{time}</span>}
        </header>
        <div className={cn("break-words", isAgent && "border-s-2 border-primary-tint ps-2.5")}>
          {children}
          {attachment}
        </div>
      </div>
    </article>
  )
}

/** An agent's proposal in the thread: dashed until someone decides (who must is named in the footer). */
function MessageProposal({
  title,
  detail,
  status,
  actions,
  className,
}: {
  title: string
  detail?: React.ReactNode
  /** "Waiting for Amro", as a Badge. */
  status?: React.ReactNode
  /** Only the person who decides sees Approve. */
  actions?: React.ReactNode
  className?: string
}) {
  return (
    <div
      data-slot="message-proposal"
      className={cn(
        "mt-2 grid max-w-[520px] gap-1.5 rounded-control border-[1.5px] border-dashed border-input bg-background px-3.5 py-3",
        className
      )}
    >
      <b className="text-[0.9375rem] font-semibold">{title}</b>
      {detail && <span className="text-sm">{detail}</span>}
      {(status || actions) && (
        <footer className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          {status}
          {actions && <span className="ms-auto flex gap-1.5">{actions}</span>}
        </footer>
      )}
    </div>
  )
}

/** Who joined and from when they read, a hand-off, a rename: a quiet line everyone sees. */
function SystemLine({ icon = "info", children, className }: { icon?: IconName; children: React.ReactNode; className?: string }) {
  return (
    <div
      data-slot="system-line"
      className={cn(
        "inline-flex max-w-[90%] items-center gap-1.5 self-center rounded-full bg-muted px-3 py-1 text-center text-xs text-muted-foreground",
        className
      )}
    >
      <Icon name={icon} size={12} />
      <span>{children}</span>
    </div>
  )
}

/** "Marketing assistant is drafting the announcement": an agent at work, in words. */
function AgentWorking({ author, children, className }: { author: MessageAuthor; children: React.ReactNode; className?: string }) {
  return (
    <div data-slot="agent-working" role="status" className={cn("flex items-center gap-2 text-xs text-primary", className)}>
      <Avatar name={author.name} kind={author.kind ?? "agent"} size={20} />
      <span>{children}</span>
      <span aria-hidden="true" className="inline-flex gap-[3px]">
        <i className="block size-[5px] rounded-full bg-primary opacity-50" />
        <i className="block size-[5px] rounded-full bg-primary opacity-75" />
        <i className="block size-[5px] rounded-full bg-primary" />
      </span>
    </div>
  )
}

/** An @mention of a person or an agent inside a message. */
function Mention({ children }: { children: React.ReactNode }) {
  return (
    <span data-slot="mention" className="font-medium text-primary">
      {children}
    </span>
  )
}

export { AgentTag, AgentWorking, Mention, Message, MessageProposal, MessageThread, SystemLine }
