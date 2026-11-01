"use client"

import * as React from "react"

import { ActorChip } from "../actor-chip"
import { Avatar } from "../avatar"
import { Button } from "../button"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from "../dropdown-menu"
import { Icon } from "../icon"
import { IconButton } from "../icon-button"
import { Sheet, SheetContent, SheetTitle } from "../sheet"
import { ToggleGroup } from "../toggle-group"
import { cn } from "../utils"
import type { Decision, DecideAllFilter, MentionPerson, RailSuggestion, RailThread } from "./collab"
import type { Actor, Inline } from "./contract"
import { fill, type EditorLabels } from "./labels"
import type { SuggestionPatch } from "./suggestions"

/**
 * The margin rail (E.6, the EditorCollab card): one card per open suggestion
 * and thread, beside its text and stacked without overlapping; the header
 * counts them and offers the All menu (E.29). Under 1024px the same cards go
 * in a drawer with All, Comments, Suggestions and Resolved. Classes follow the
 * card (`.wy-rc`, `.wy-edx__railhead`, `editor.css`).
 */

export type RailItem =
  | {
      kind: "suggestion"
      id: string
      suggestion: RailSuggestion
      pending?: boolean
      unplaced?: boolean
    }
  | { kind: "thread"; id: string; thread: RailThread }
  | { kind: "composer"; id: "composer"; quote: string }

export interface RailActions {
  canEdit: boolean
  canComment: boolean
  onDecide?: (id: string, decision: Decision) => void
  onDecideAll?: (decision: Decision, filter?: DecideAllFilter) => void
  onReply?: (threadId: string, body: Inline[], mentions: string[]) => Promise<void>
  onResolve?: (threadId: string) => void
  onReopen?: (threadId: string) => void
  onComment?: (body: Inline[], mentions: string[]) => Promise<void>
  onCancelComment?: () => void
  searchPeople?: (query: string) => Promise<MentionPerson[]>
}

/** Whether the rail fits beside the sheet: 1024px and up (E.6); below, the drawer. */
export function useWideRail(): boolean {
  const query = "(min-width: 1024px)"
  const [wide, setWide] = React.useState(() =>
    typeof window === "undefined" || typeof window.matchMedia !== "function" ? true : window.matchMedia(query).matches
  )
  React.useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return
    const mq = window.matchMedia(query)
    const on = () => setWide(mq.matches)
    on()
    mq.addEventListener("change", on)
    return () => mq.removeEventListener("change", on)
  }, [])
  return wide
}

/** 24-hour time in Western digits (stage 5). */
export function railTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ""
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`
}

const actorName = (a: Actor) => (a.kind === "system" ? "Whity" : a.name)

/** The card's sentence, with the quoted text bold ("Replace **30 days** with **14 days**"). */
export function summaryNode(patch: SuggestionPatch, labels: EditorLabels, fallback: string): React.ReactNode {
  const quote = (t: string) => `“${t.length > 60 ? `${t.slice(0, 59)}…` : t}”`
  const text = (content: Inline[]) => content.map((r) => (r.type === "text" ? r.text : r.type === "break" ? " " : "…")).join("")
  const blockText = (b: { content?: Inline[]; type: string }) => (Array.isArray(b.content) ? text(b.content) : "")
  const parts = (template: string, values: Record<string, string>) =>
    template.split(/(\{\w+\})/).map((part, i) => {
      const key = /^\{(\w+)\}$/.exec(part)?.[1]
      if (!key) return part
      return key === "mark" ? <React.Fragment key={i}>{values[key]}</React.Fragment> : <b key={i}>{values[key]}</b>
    })
  switch (patch.op) {
    case "replace-text": {
      const ins = text(patch.insert)
      if (patch.deleteText && ins) return parts(labels.sugReplace, { from: patch.deleteText, to: ins })
      if (patch.deleteText) return parts(labels.sugDelete, { text: quote(patch.deleteText) })
      return parts(labels.sugAdd, { text: quote(ins) })
    }
    case "format": {
      const markLabel = (labels as Record<string, string>)[patch.mark.type] ?? patch.mark.type
      return parts(patch.add ? labels.sugFormat : labels.sugUnformat, {
        text: quote(patch.text),
        mark: markLabel.toLowerCase(),
      })
    }
    case "insert-blocks": {
      const t = patch.blocks.map(blockText).join(" ").trim()
      return parts(labels.sugAddBlocks, {
        text: t ? quote(t) : labels.sugBlock,
      })
    }
    case "delete-blocks":
      return fallback || parts(labels.sugDeleteBlocks, { text: labels.sugBlock })
    case "move-block":
      return parts(labels.sugMoveBlock, { text: labels.sugBlock })
  }
}

function Who({ by, at, labels, mine }: { by: Actor; at?: string; labels: EditorLabels; mine?: boolean }) {
  if (by.kind === "agent")
    return <ActorChip kind="agent" name={by.name} onBehalfOf={by.onBehalfOf.name} labels={{ for: labels.actorFor }} />
  const name = mine ? labels.you : actorName(by)
  return (
    <>
      <Avatar name={actorName(by) || name} size={24} kind={by.kind === "system" ? "system" : "person"} />
      <span className="wy-rc__name">{name}</span>
      {at !== undefined && <span className="wy-rc__time">{at}</span>}
    </>
  )
}

function plain(body: Inline[]): string {
  return body.map((r) => (r.type === "text" ? r.text : r.type === "break" ? "\n" : "")).join("")
}

/** Message text with its @mentions on the tint. */
function MessageBody({ body, mentions }: { body: Inline[]; mentions: Actor[] }) {
  const text = plain(body)
  const names = mentions.map((m) => `@${actorName(m)}`).filter((n) => n.length > 1)
  if (!names.length) return <div className="whitespace-pre-wrap">{text}</div>
  const re = new RegExp(`(${names.map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|")})`, "g")
  return (
    <div className="whitespace-pre-wrap">
      {text.split(re).map((part, i) =>
        names.includes(part) ? (
          <span key={i} className="wy-mention">
            {part}
          </span>
        ) : (
          part
        )
      )}
    </div>
  )
}

/* ------------------------------------------------------------ The @ box */

/** A text box that offers workspace people after @, as the card's mention picker. */
function MentionBox({
  placeholder,
  search,
  labels,
  onSubmit,
  onCancel,
  submitLabel,
  autoFocus,
}: {
  placeholder: string
  search?: (q: string) => Promise<MentionPerson[]>
  labels: EditorLabels
  onSubmit: (body: Inline[], mentions: string[]) => Promise<void>
  onCancel?: () => void
  submitLabel: string
  autoFocus?: boolean
}) {
  const [value, setValue] = React.useState("")
  const [query, setQuery] = React.useState<string | null>(null)
  const [people, setPeople] = React.useState<MentionPerson[]>([])
  // The query the shown list answers, so "No one matches" never shows while a search is on its way.
  const [answered, setAnswered] = React.useState<string | null>(null)
  const [active, setActive] = React.useState(0)
  const [picked, setPicked] = React.useState<MentionPerson[]>([])
  const [busy, setBusy] = React.useState(false)
  const [failed, setFailed] = React.useState(false)
  const box = React.useRef<HTMLTextAreaElement>(null)
  // The new comment's box takes the focus from the editor once the card is placed.
  React.useEffect(() => {
    if (!autoFocus) return
    const frame = requestAnimationFrame(() => box.current?.focus())
    return () => cancelAnimationFrame(frame)
  }, [autoFocus])

  React.useEffect(() => {
    // An empty query is never sent (the server refuses it): the hint shows until a name is typed.
    if (!query || !search) {
      setPeople([])
      setAnswered(null)
      return
    }
    let live = true
    const timer = setTimeout(() => {
      search(query).then(
        (list) => live && (setPeople(list.slice(0, 8)), setActive(0), setAnswered(query)),
        () => live && (setPeople([]), setAnswered(query))
      )
    }, 200)
    return () => {
      live = false
      clearTimeout(timer)
    }
  }, [query, search])

  const update = (next: string, caret: number) => {
    setValue(next)
    setFailed(false)
    const m = /(?:^|\s)@([^\s@]{0,30})$/.exec(next.slice(0, caret))
    setQuery(m ? m[1] : null)
  }
  const pick = (person: MentionPerson) => {
    const el = box.current
    const caret = el?.selectionStart ?? value.length
    const before = value.slice(0, caret).replace(/@([^\s@]{0,30})$/, `@${person.name} `)
    setValue(before + value.slice(caret))
    setPicked((p) => (p.some((x) => x.id === person.id) ? p : [...p, person]))
    setQuery(null)
    requestAnimationFrame(() => {
      el?.focus()
      el?.setSelectionRange(before.length, before.length)
    })
  }
  const submit = async () => {
    const text = value.trim()
    if (!text || busy) return
    setBusy(true)
    try {
      const mentions = picked.filter((p) => text.includes(`@${p.name}`)).map((p) => p.id)
      await onSubmit([{ type: "text", text }], mentions)
      setValue("")
      setPicked([])
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }
  const open = query !== null && !!search

  return (
    <div className="grid gap-2">
      <div className="relative">
        <textarea
          ref={box}
          autoFocus={autoFocus}
          value={value}
          placeholder={placeholder}
          aria-label={placeholder}
          rows={2}
          onChange={(e) => update(e.target.value, e.target.selectionStart)}
          onKeyDown={(e) => {
            if (open && people.length) {
              if (e.key === "ArrowDown" || e.key === "ArrowUp") {
                e.preventDefault()
                setActive((i) => (i + (e.key === "ArrowDown" ? 1 : people.length - 1)) % people.length)
                return
              }
              if (e.key === "Enter") {
                e.preventDefault()
                pick(people[active])
                return
              }
            }
            if (e.key === "Escape") {
              if (open) setQuery(null)
              else onCancel?.()
              return
            }
            if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) {
              e.preventDefault()
              void submit()
            }
          }}
          className="wy-rc__box resize-none bg-transparent outline-none placeholder:text-muted-foreground focus-visible:border-ring"
        />
        {open && (
          <div
            role="listbox"
            aria-label={labels.mentionPeople}
            className="wy-mentionpop absolute inset-x-0 top-full z-30 mt-1 rounded-lg border border-border bg-popover text-popover-foreground shadow-float"
          >
            <div className="px-2 pt-1.5 pb-1 text-xs font-medium text-muted-foreground">{labels.mentionPeople}</div>
            {!query && <div className="px-2 py-2 text-muted-foreground">{labels.mentionHint}</div>}
            {query && answered === query && people.length === 0 && <div className="px-2 py-2 text-muted-foreground">{labels.mentionNone}</div>}
            {people.map((person, i) => (
              <div
                key={person.id}
                role="option"
                aria-selected={i === active}
                onMouseDown={(e) => e.preventDefault()}
                onMouseEnter={() => setActive(i)}
                onClick={() => pick(person)}
                className={cn("flex cursor-pointer items-center gap-2 rounded-[6px] px-2 py-1.5", i === active && "bg-muted")}
              >
                <Avatar name={person.name} size={20} />
                <span className="truncate">{person.name}</span>
                {person.detail && <span className="ms-auto truncate text-xs text-muted-foreground">{person.detail}</span>}
              </div>
            ))}
          </div>
        )}
      </div>
      {failed && (
        <p role="alert" className="m-0 text-xs text-error-text">
          {labels.collabFailed}
        </p>
      )}
      <div className="wy-rc__foot">
        {onCancel && (
          <Button variant="ghost" size="sm" onClick={onCancel}>
            {labels.cancel}
          </Button>
        )}
        <Button variant="outline" size="sm" disabled={!value.trim() || busy} onClick={() => void submit()}>
          {submitLabel}
        </Button>
      </div>
    </div>
  )
}

/* ----------------------------------------------------------------- Cards */

interface CardProps {
  item: RailItem
  labels: EditorLabels
  actions: RailActions
  active: boolean
  meId?: string
  onActivate: () => void
  /** Rail cards are positioned; drawer cards sit in a list. */
  positioned?: { top: number }
  cardRef?: (el: HTMLDivElement | null) => void
}

function Card({ item, labels, actions, active, meId, onActivate, positioned, cardRef }: CardProps) {
  const common = {
    ref: cardRef,
    "data-rail-id": item.id,
    style: positioned ? { top: positioned.top } : undefined,
    onClick: (e: React.MouseEvent) => {
      if ((e.target as HTMLElement).closest("button,textarea,a,[role=option]")) return
      onActivate()
    },
  }
  const base = cn("wy-rc", !positioned && "wy-rc--static", active && "is-active")

  if (item.kind === "composer") {
    return (
      <div {...common} className={cn(base, "is-active")} role="group" aria-label={labels.comment}>
        <div className="wy-rc__who">
          <span className="wy-rc__name">{labels.you}</span>
          <span className="wy-rc__time">{labels.now}</span>
        </div>
        {item.quote && <div className="wy-rc__q">{item.quote}</div>}
        <MentionBox
          autoFocus
          placeholder={labels.commentPlaceholder}
          labels={labels}
          search={actions.searchPeople}
          submitLabel={labels.comment}
          onSubmit={(body, mentions) => actions.onComment?.(body, mentions) ?? Promise.resolve()}
          onCancel={actions.onCancelComment}
        />
      </div>
    )
  }

  if (item.kind === "suggestion") {
    const s = item.suggestion
    const isAgent = s.by.kind === "agent"
    const mine = !!meId && s.by.kind === "person" && s.by.id === meId
    return (
      <div {...common} className={cn(base, isAgent && "is-agent", item.pending && "is-pending")} role="group" aria-label={s.summary}>
        <div className="wy-rc__who">
          <Who by={s.by} at={item.pending ? labels.sugSending : railTime(s.at)} labels={labels} mine={mine} />
          {actions.canEdit && !item.pending && (
            <span className="wy-rc__acts">
              <IconButton icon="check" label={labels.accept} size="sm" onClick={() => actions.onDecide?.(s.id, "accepted")} />
              <IconButton icon="close" label={labels.reject} size="sm" onClick={() => actions.onDecide?.(s.id, "rejected")} />
            </span>
          )}
        </div>
        <div className="wy-rc__what">{summaryNode(s.patch, labels, s.summary)}</div>
        {item.unplaced && <div className="wy-rc__time">{labels.sugUnplaced}</div>}
      </div>
    )
  }

  const t = item.thread
  const [first, ...rest] = t.messages
  if (t.resolved) {
    return (
      <div {...common} className={cn(base, "is-resolved")} role="group">
        <div className="wy-rc__resolved">
          <Icon name="success" size={14} />
          {fill(labels.resolvedBy, {
            name: actorName(t.resolved.by),
            time: railTime(t.resolved.at),
          })}
        </div>
        <div className="wy-rc__what">{first ? plain(first.body) : t.quote}</div>
        {actions.canComment && actions.onReopen && (
          <div>
            <Button variant="ghost" size="sm" onClick={() => actions.onReopen?.(t.id)}>
              {labels.reopen}
            </Button>
          </div>
        )}
      </div>
    )
  }
  return (
    <div {...common} className={base} role="group" aria-label={t.quote}>
      {first && (
        <div className="wy-rc__who">
          <Who by={first.by} at={railTime(first.at)} labels={labels} mine={!!meId && first.by.kind === "person" && first.by.id === meId} />
          {actions.canComment && actions.onResolve && (
            <span className="wy-rc__acts">
              <IconButton icon="check" label={labels.resolve} size="sm" onClick={() => actions.onResolve?.(t.id)} />
            </span>
          )}
        </div>
      )}
      {!positioned && t.quote && <div className="wy-rc__q">{t.quote}</div>}
      {first && <MessageBody body={first.body} mentions={first.mentions} />}
      {rest.length > 0 &&
        (active || positioned ? (
          <div className="wy-rc__replies">
            {rest.map((m) => (
              <div key={m.id}>
                <div className="wy-rc__who">
                  <Who by={m.by} at={railTime(m.at)} labels={labels} mine={!!meId && m.by.kind === "person" && m.by.id === meId} />
                </div>
                <div className="mt-1">
                  <MessageBody body={m.body} mentions={m.mentions} />
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="wy-rc__time">{rest.length === 1 ? labels.oneReply : fill(labels.replies, { n: rest.length })}</div>
        ))}
      {actions.canComment &&
        actions.onReply &&
        (active ? (
          <MentionBox
            placeholder={labels.replyPlaceholder}
            labels={labels}
            search={actions.searchPeople}
            submitLabel={labels.reply}
            onSubmit={(body, mentions) => actions.onReply!(t.id, body, mentions)}
          />
        ) : (
          positioned && (
            <button type="button" className="wy-rc__reply text-start" onClick={onActivate}>
              {labels.replyPlaceholder}
            </button>
          )
        ))}
    </div>
  )
}

/* -------------------------------------------------------------- Header */

function counts(items: RailItem[], labels: EditorLabels) {
  const s = items.filter((i) => i.kind === "suggestion").length
  const c = items.filter((i) => i.kind === "thread" && !i.thread.resolved).length
  const parts: string[] = []
  if (s) parts.push(s === 1 ? labels.countSuggestion : fill(labels.countSuggestions, { n: s }))
  if (c) parts.push(c === 1 ? labels.countComment : fill(labels.countComments, { n: c }))
  return parts.join(" · ")
}

function AllMenu({ items, labels, actions }: { items: RailItem[]; labels: EditorLabels; actions: RailActions }) {
  const suggestions = items.flatMap((i) => (i.kind === "suggestion" && !i.pending ? [i.suggestion] : []))
  if (!actions.canEdit || !actions.onDecideAll || suggestions.length === 0) return null
  const agents = [...new Map(suggestions.flatMap((s) => (s.by.kind === "agent" ? [[s.by.id, s.by.name] as const] : []))).entries()]
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="sm" iconEnd="chevron-down">
          {labels.railAll}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => actions.onDecideAll?.("accepted")}>
          <Icon name="check" />
          {labels.acceptAll}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => actions.onDecideAll?.("rejected")}>
          <Icon name="close" />
          {labels.rejectAll}
        </DropdownMenuItem>
        {agents.map(([id, name]) => (
          <React.Fragment key={id}>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => actions.onDecideAll?.("accepted", { agent: id })}>
              <Icon name="robot" />
              {fill(labels.acceptAllFrom, { name })}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => actions.onDecideAll?.("rejected", { agent: id })}>
              <Icon name="robot" />
              {fill(labels.rejectAllFrom, { name })}
            </DropdownMenuItem>
          </React.Fragment>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/* ------------------------------------------------------------------ Rail */

const GAP = 10

/**
 * Card tops: each as near its text as it can be without overlapping; the
 * active card sits exactly beside its text and the others make room (E.6).
 */
export function stackCards(want: { id: string; y: number; h: number }[], active: string | null, minTop = 0): Record<string, number> {
  const sorted = [...want].sort((a, b) => a.y - b.y)
  const tops: Record<string, number> = {}
  const pin = sorted.findIndex((c) => c.id === active)
  if (pin < 0) {
    let next = minTop
    for (const c of sorted) {
      tops[c.id] = Math.max(c.y, next)
      next = tops[c.id] + c.h + GAP
    }
    return tops
  }
  tops[sorted[pin].id] = Math.max(sorted[pin].y, minTop)
  let next = tops[sorted[pin].id] + sorted[pin].h + GAP
  for (let i = pin + 1; i < sorted.length; i++) {
    tops[sorted[i].id] = Math.max(sorted[i].y, next)
    next = tops[sorted[i].id] + sorted[i].h + GAP
  }
  let limit = tops[sorted[pin].id] - GAP
  for (let i = pin - 1; i >= 0; i--) {
    tops[sorted[i].id] = Math.min(sorted[i].y, limit - sorted[i].h)
    limit = tops[sorted[i].id] - GAP
  }
  // Pushed above the top: lay them out downwards again from the top.
  const first = sorted[0] && tops[sorted[0].id]
  if (first !== undefined && first < minTop) {
    let n = minTop
    for (const c of sorted) {
      tops[c.id] = Math.max(tops[c.id], n)
      n = tops[c.id] + c.h + GAP
    }
  }
  return tops
}

export function CollabRail({
  items,
  resolved,
  labels,
  actions,
  active,
  onActive,
  anchorTop,
  meId,
  layoutKey,
  defaultShowResolved = false,
}: {
  items: RailItem[]
  resolved: RailItem[]
  labels: EditorLabels
  actions: RailActions
  active: string | null
  onActive: (id: string | null) => void
  /** Where an item's text is, in viewport pixels (from coordsAtPos), or null. */
  anchorTop: (item: RailItem) => number | null
  meId?: string
  /** Changes whenever the document's layout may have moved. */
  layoutKey: unknown
  /** Start with the resolved threads showing (stories). */
  defaultShowResolved?: boolean
}) {
  const railRef = React.useRef<HTMLDivElement>(null)
  const headRef = React.useRef<HTMLDivElement>(null)
  const cards = React.useRef(new Map<string, HTMLDivElement>())
  const [tops, setTops] = React.useState<Record<string, number>>({})
  const [showResolved, setShowResolved] = React.useState(defaultShowResolved)

  const layout = React.useCallback(() => {
    const rail = railRef.current
    if (!rail) return
    const origin = rail.getBoundingClientRect().top
    const head = headRef.current
    const minTop = head ? head.offsetTop + head.offsetHeight + 12 : 0
    const want = items.map((item) => {
      const y = anchorTop(item)
      return {
        id: item.id,
        y: y === null ? minTop : y - origin,
        h: cards.current.get(item.id)?.offsetHeight ?? 96,
      }
    })
    const next = stackCards(want, active, minTop)
    setTops((prev) => (JSON.stringify(prev) === JSON.stringify(next) ? prev : next))
  }, [items, anchorTop, active])

  React.useLayoutEffect(layout, [layout, layoutKey])
  React.useEffect(() => {
    const ro = new ResizeObserver(() => layout())
    if (railRef.current) ro.observe(railRef.current)
    cards.current.forEach((el) => ro.observe(el))
    window.addEventListener("resize", layout)
    return () => {
      ro.disconnect()
      window.removeEventListener("resize", layout)
    }
  }, [layout, items])

  const bottom = Math.max(0, ...items.map((i) => (tops[i.id] ?? 0) + (cards.current.get(i.id)?.offsetHeight ?? 96)))

  return (
    <aside ref={railRef} aria-label={labels.commentsAndSuggestions} className="wy-edx__rail" data-slot="collab-rail">
      <div ref={headRef} className="wy-edx__railhead">
        <span>{counts(items, labels) || labels.commentsAndSuggestions}</span>
        <span className="flex items-center gap-1">
          {resolved.length > 0 && (
            <Button variant="ghost" size="sm" aria-pressed={showResolved} onClick={() => setShowResolved((v) => !v)}>
              {fill(labels.countResolved, { n: resolved.length })}
            </Button>
          )}
          <AllMenu items={items} labels={labels} actions={actions} />
        </span>
      </div>
      {items.length === 0 && !showResolved && <p className="wy-edx__railnote">{labels.railEmpty}</p>}
      {items.map((item) => (
        <Card
          key={item.id}
          item={item}
          labels={labels}
          actions={actions}
          active={active === item.id}
          meId={meId}
          onActivate={() => onActive(item.id)}
          positioned={{ top: tops[item.id] ?? 0 }}
          cardRef={(el) => {
            if (el) cards.current.set(item.id, el)
            else cards.current.delete(item.id)
          }}
        />
      ))}
      {showResolved && (
        <div
          className="wy-drawerlist"
          style={{
            marginTop: items.length ? bottom + GAP - (headRef.current ? headRef.current.offsetTop + headRef.current.offsetHeight : 0) : 0,
          }}
        >
          {resolved.map((item) => (
            <Card key={item.id} item={item} labels={labels} actions={actions} active={false} meId={meId} onActivate={() => undefined} />
          ))}
        </div>
      )}
    </aside>
  )
}

/* ---------------------------------------------------------------- Drawer */

type Tab = "all" | "comments" | "suggestions" | "resolved"

export function CollabDrawer({
  open,
  onOpenChange,
  items,
  resolved,
  labels,
  actions,
  active,
  onActive,
  meId,
  defaultTab = "all",
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  items: RailItem[]
  resolved: RailItem[]
  labels: EditorLabels
  actions: RailActions
  active: string | null
  onActive: (id: string | null) => void
  meId?: string
  defaultTab?: Tab
}) {
  const [tab, setTab] = React.useState<Tab>(defaultTab)
  const shown =
    tab === "resolved"
      ? resolved
      : items.filter((i) => tab === "all" || (tab === "comments" ? i.kind !== "suggestion" : i.kind === "suggestion"))
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        closeLabel={labels.close}
        className="w-full max-w-[380px] gap-2.5 p-3 text-[0.8125rem]"
        aria-describedby={undefined}
      >
        <div className="flex min-h-8 items-center justify-between pe-8">
          <SheetTitle className="text-sm font-semibold">{labels.commentsAndSuggestions}</SheetTitle>
          <AllMenu items={items} labels={labels} actions={actions} />
        </div>
        <ToggleGroup
          size="sm"
          label={labels.show}
          required
          value={tab}
          onChange={(v) => setTab((v as Tab) ?? "all")}
          options={[
            { value: "all", label: labels.tabAll },
            { value: "comments", label: labels.tabComments },
            { value: "suggestions", label: labels.tabSuggestions },
            { value: "resolved", label: labels.tabResolved },
          ]}
        />
        <div className="wy-drawerlist">
          {shown.length === 0 && <p className="wy-edx__railnote">{tab === "resolved" ? labels.resolvedEmpty : labels.railEmpty}</p>}
          {shown.map((item) => (
            <Card
              key={item.id}
              item={item}
              labels={labels}
              actions={actions}
              active={active === item.id}
              meId={meId}
              onActivate={() => onActive(item.id)}
            />
          ))}
        </div>
      </SheetContent>
    </Sheet>
  )
}
