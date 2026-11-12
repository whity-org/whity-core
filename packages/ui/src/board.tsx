"use client"

import * as React from "react"

import { Avatar, type ActorKind } from "./avatar"
import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

/**
 * A board (T.2, docs/design/components/TplBoard; `.wk-board`, `.wk-col`,
 * `.wk-card` in bundle.css). Tasks live in Taskaat (T.1-T.4); these pieces
 * only draw them, for Tasks, Boards and Flows.
 *
 * Columns are task statuses. A `gate` column (Awaiting review) is amber: an
 * agent's task waits there with its evidence until a person accepts it. A card
 * being dragged is `lifted` onto the plane (tilted 2°) and the place it will
 * land is a dashed `BoardSlot`. Dragging itself belongs to the screen.
 */

function Board({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="board"
      className={cn("grid auto-cols-[minmax(240px,1fr)] grid-flow-col items-start gap-3.5 overflow-x-auto pb-2", className)}
      {...props}
    />
  )
}

export interface BoardColumnProps extends Omit<React.ComponentProps<"section">, "title"> {
  title: string
  count?: number
  icon?: IconName
  /** The review gate (amber): nothing passes into Done without a person. */
  gate?: boolean
  /** The column's menu (rename, limits), e.g. an IconButton. */
  menu?: React.ReactNode
  /** Shown at the foot as "Add task"; omit to hide. */
  addLabel?: string
  onAdd?: () => void
}

function BoardColumn({
  title,
  count,
  icon,
  gate = false,
  menu,
  addLabel,
  onAdd,
  className,
  children,
  ...props
}: BoardColumnProps) {
  const headingId = React.useId()
  return (
    <section
      data-slot="board-column"
      data-gate={gate ? "" : undefined}
      aria-labelledby={headingId}
      className={cn("grid gap-2 rounded-card p-2.5", gate ? "bg-warning-tint" : "bg-muted", className)}
      {...props}
    >
      <header className="flex items-center gap-2 px-1 pt-0.5 pb-1 text-sm font-semibold">
        {icon && <Icon name={icon} size={14} />}
        <h3 id={headingId} className="m-0 text-sm font-semibold">
          {title}
        </h3>
        {count != null && <span className="font-medium text-muted-foreground tabular-nums">{count}</span>}
        {menu && <span className="ms-auto text-muted-foreground">{menu}</span>}
      </header>
      <div role="list" className="grid gap-2">
        {children}
      </div>
      {addLabel && onAdd && (
        <button
          type="button"
          onClick={onAdd}
          className="flex min-h-8 items-center gap-1.5 rounded-control border-0 bg-transparent px-1 py-1.5 text-start text-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/30"
        >
          <Icon name="plus" size={14} />
          {addLabel}
        </button>
      )}
    </section>
  )
}

/** A label on a card: a small swatch in the label's own colour and its name. */
function LabelChip({ name, color, className }: { name: string; color?: string; className?: string }) {
  return (
    <span
      data-slot="label-chip"
      className={cn("inline-flex h-6 items-center gap-1.5 rounded-sm bg-muted px-2 text-xs whitespace-nowrap text-foreground", className)}
    >
      <i aria-hidden="true" className="block size-2 rounded-[2px] bg-primary" style={color ? { background: color } : undefined} />
      {name}
    </span>
  )
}

/** Checks met out of the total, as bars that turn green (`.wk-prog`). */
function CheckProgress({ met, total, label }: { met: number; total: number; label?: string }) {
  const text = (label ?? "{met} of {total} checks met").replace("{met}", String(met)).replace("{total}", String(total))
  return (
    <span data-slot="check-progress" role="img" aria-label={text} className="flex gap-[3px]">
      {Array.from({ length: total }, (_, index) => (
        <i key={index} className={cn("block h-1 w-3.5 rounded-[2px]", index < met ? "bg-success" : "bg-border")} />
      ))}
    </span>
  )
}

export interface BoardCardProps extends Omit<React.ComponentProps<"article">, "title"> {
  title: string
  /** A status badge at the top: Working, Evidence ready, Verified, Done, unverified. */
  badge?: React.ReactNode
  labels?: { name: string; color?: string }[]
  checks?: { met: number; total: number; label?: string }
  /** Already formatted ("12 Nov"). */
  due?: string
  assignee?: { name: string; kind?: ActorKind; src?: string; pattern?: string; hue?: number }
  /** Being dragged: on the plane, tilted. */
  lifted?: boolean
  /** Opens the task. */
  onOpen?: () => void
}

function BoardCard({
  title,
  badge,
  labels = [],
  checks,
  due,
  assignee,
  lifted = false,
  onOpen,
  className,
  ...props
}: BoardCardProps) {
  return (
    <article
      role="listitem"
      data-slot="board-card"
      data-lifted={lifted ? "" : undefined}
      className={cn(
        "grid gap-2 rounded-control border border-border bg-card px-3 py-2.5 text-sm",
        "transition-[box-shadow,transform] duration-150 ease-out-whity",
        lifted && "rotate-2 border-primary shadow-[var(--plane-primary-rest),var(--shadow-float)]",
        onOpen && "cursor-pointer hover:shadow-plane",
        className
      )}
      {...props}
    >
      {badge && <div className="justify-self-start">{badge}</div>}
      {onOpen ? (
        <button
          type="button"
          onClick={onOpen}
          className="m-0 border-0 bg-transparent p-0 text-start text-[0.9375rem] leading-snug font-medium text-foreground outline-none focus-visible:underline"
        >
          {title}
        </button>
      ) : (
        <b className="text-[0.9375rem] leading-snug font-medium">{title}</b>
      )}
      {(labels.length > 0 || checks) && (
        <div className="flex flex-wrap items-center gap-1.5">
          {labels.map((label) => (
            <LabelChip key={label.name} name={label.name} color={label.color} />
          ))}
          {checks && <CheckProgress met={checks.met} total={checks.total} label={checks.label} />}
        </div>
      )}
      {(due || assignee) && (
        <footer className="flex items-center gap-2 text-xs text-muted-foreground">
          {due && (
            <>
              <Icon name="calendar" size={12} />
              <span dir="ltr">{due}</span>
            </>
          )}
          {assignee && (
            <Avatar
              className="ms-auto"
              name={assignee.name}
              kind={assignee.kind}
              src={assignee.src}
              pattern={assignee.pattern}
              hue={assignee.hue}
              size={24}
            />
          )}
        </footer>
      )}
    </article>
  )
}

/** Where a dragged card will land: a dashed, empty slot. */
function BoardSlot({ className }: { className?: string }) {
  return (
    <div
      data-slot="board-slot"
      aria-hidden="true"
      className={cn("min-h-16 rounded-control border-[1.5px] border-dashed border-input", className)}
    />
  )
}

export { Board, BoardCard, BoardColumn, BoardSlot, CheckProgress, LabelChip }
