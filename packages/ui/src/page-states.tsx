import * as React from "react"

import { Alert, AlertDescription, AlertTitle } from "./alert"
import { Button } from "./button"
import { Icon, type IconName } from "./icon"
import { Lottie, type MomentName } from "./lottie"
import { Skeleton } from "./skeleton"
import { cn } from "./utils"

/**
 * The six page states every list and record page uses (docs/design/components/
 * PatternStates, V.2). A screen supplies its own wording; the shapes stay.
 * Strings come from the caller through `t()`: this package holds no translations.
 */

/** Loading: the real layout with skeleton rows, never a spinner or "Loading…". */
function LoadingRows({
  headers,
  rows = 5,
  label,
  className,
}: {
  /** The table's real column headers, which render at once. */
  headers: string[]
  rows?: number
  /** Announced to assistive technology. */
  label: string
  className?: string
}) {
  return (
    <div role="status" aria-busy="true" aria-label={label} className={cn("overflow-hidden rounded-card border border-border bg-card", className)}>
      <div className="flex h-10 items-center justify-between gap-3 bg-table-header px-3 text-xs font-medium text-muted-foreground">
        {headers.map((header) => (
          <span key={header} className="flex-1 last:flex-none">
            {header}
          </span>
        ))}
      </div>
      {Array.from({ length: rows }, (_, row) => (
        <div key={row} className="flex h-(--row-h) items-center gap-3 border-t border-divider px-3">
          {headers.map((header, column) => (
            <span key={header} className="flex-1">
              <Skeleton className="h-2.5 rounded-full" style={{ width: `${40 + ((row * 17 + column * 23) % 45)}%` }} />
            </span>
          ))}
        </div>
      ))}
    </div>
  )
}

/**
 * The dashed box shared by the two empty states: a tinted icon tile, or a Lottie
 * moment (LT.4) with the tile as its fallback.
 */
function EmptyBox({
  icon,
  title,
  description,
  action,
  moment,
  once,
  onceEver,
  className,
}: {
  icon: IconName
  title: string
  description?: string
  action?: React.ReactNode
  moment?: MomentName
  once?: string
  onceEver?: string
  className?: string
}) {
  const tile = (
    <span className="grid size-12 place-items-center rounded-card bg-muted text-muted-foreground">
      <Icon name={icon} size={24} />
    </span>
  )
  return (
    <div
      className={cn(
        "flex flex-col items-center gap-2 rounded-card border border-dashed border-border px-6 py-8 text-center",
        className
      )}
    >
      {moment ? <Lottie name={moment} once={once} onceEver={onceEver} className="h-[120px] w-[160px]" fallback={tile} /> : tile}
      <p className="mt-1 text-base font-semibold text-foreground">{title}</p>
      {description && <p className="max-w-prose text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-1">{action}</div>}
    </div>
  )
}

/** Empty for the first time: what this place is for and the one action that fills it. */
function EmptyFirstTime({
  icon = "inbox",
  title,
  description,
  action,
  moment,
  once,
  onceEver,
  className,
}: {
  icon?: IconName
  title: string
  description?: string
  /** The page's main button. */
  action?: React.ReactNode
  /** `empty-documents`, `empty-people`, `empty-invoices`, `empty-stock`, `all-caught-up`, or a first-run scene (LT.4). */
  moment?: MomentName
  /** Plays once a session under this key; defaults to the moment's name. */
  once?: string
  /** Plays once ever on this device under this key instead (first-run scenes). */
  onceEver?: string
  className?: string
}) {
  return (
    <EmptyBox
      icon={icon}
      title={title}
      description={description}
      action={action}
      moment={moment}
      once={moment && !onceEver ? (once ?? moment) : undefined}
      onceEver={onceEver}
      className={className}
    />
  )
}

/** Empty after a filter: names the search or filter and offers Clear filters. */
function EmptyFiltered({
  title,
  description,
  clearLabel,
  onClear,
  className,
}: {
  /** Names the filter: "No one matches “Khalda”". */
  title: string
  description?: string
  clearLabel: string
  onClear: () => void
  className?: string
}) {
  return (
    <EmptyBox
      icon="search"
      moment="no-results"
      title={title}
      description={description}
      className={className}
      action={
        <Button type="button" variant="outline" onClick={onClear}>
          {clearLabel}
        </Button>
      }
    />
  )
}

/** Error: what failed in plain words, inside the area that failed, with Try again. */
function AreaError({
  message,
  retryLabel,
  onRetry,
  className,
}: {
  message: string
  retryLabel: string
  onRetry: () => void
  className?: string
}) {
  return (
    <div role="alert" className={cn("flex flex-col items-center gap-2 px-3 py-6 text-center", className)}>
      <Icon name="alert" size={16} className="text-error-text" />
      <p className="text-sm text-error-text">{message}</p>
      <Button type="button" variant="outline" size="sm" onClick={onRetry}>
        {retryLabel}
      </Button>
    </div>
  )
}

/**
 * Waiting on something real (an upload, an import, an outside system): a looping
 * moment while `active`, then still on its last frame. The words say what is
 * happening and that the person can keep working; nothing waits for the picture.
 */
function WaitingState({
  moment = "updating",
  active = true,
  title,
  description,
  action,
  className,
}: {
  /** `uploading`, `waiting-reply`, `updating` (loops) or `offline` (plays once, then still). */
  moment?: MomentName
  /** False once the waiting is over. */
  active?: boolean
  title: string
  description?: React.ReactNode
  action?: React.ReactNode
  className?: string
}) {
  return (
    <div role="status" aria-busy={active} className={cn("flex flex-col items-center gap-2 px-6 py-8 text-center", className)}>
      <Lottie
        name={moment}
        play={moment === "offline" ? "view" : "loop"}
        active={active}
        className="size-[120px]"
        fallback={<Icon name={moment === "offline" ? "alert" : "clock"} size={24} className="text-muted-foreground" />}
      />
      <p className="mt-1 text-base font-semibold text-foreground">{title}</p>
      {description && <div className="max-w-prose text-sm text-muted-foreground">{description}</div>}
      {action && <div className="mt-1">{action}</div>}
    </div>
  )
}

/** Read-only: names the permission and who grants it. Fields disabled, actions hidden. */
function ReadOnlyNotice({
  title,
  description,
  className,
}: {
  title: string
  description?: string
  className?: string
}) {
  return (
    <Alert className={cn("border-transparent bg-muted", className)}>
      <Icon name="info" size={16} />
      <AlertTitle>{title}</AlertTitle>
      {description && <AlertDescription className="text-sm text-foreground opacity-100">{description}</AlertDescription>}
    </Alert>
  )
}

/**
 * Access denied, in place of the page content with the shell still there: what's
 * behind it, the permission in plain words, who can give it, and Ask for access.
 */
function AccessDeniedPanel({
  title,
  description,
  askLabel,
  onAsk,
  className,
}: {
  title: string
  description: string
  askLabel?: string
  onAsk?: () => void
  className?: string
}) {
  return (
    <div className={cn("flex flex-col items-center gap-2 px-6 py-10 text-center", className)}>
      <span className="grid size-11 place-items-center rounded-card bg-muted text-foreground">
        <Icon name="lock" size={20} />
      </span>
      <p className="mt-1 text-base font-semibold text-foreground">{title}</p>
      <p className="max-w-prose text-sm text-muted-foreground">{description}</p>
      {askLabel && onAsk && (
        <Button type="button" variant="outline" onClick={onAsk} className="mt-1">
          {askLabel}
        </Button>
      )}
    </div>
  )
}

export { AccessDeniedPanel, AreaError, EmptyFiltered, EmptyFirstTime, LoadingRows, ReadOnlyNotice, WaitingState }
