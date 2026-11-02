import * as React from "react"

import { Button } from "./button"
import { cn } from "./utils"

/**
 * The record page's save bar (decision P.3): it appears as soon as something
 * changes, names the changes, and holds Discard and Save changes, the page's one
 * main button. While it shows, leaving or reloading the page asks first.
 */
export interface SaveBarProps {
  /** Whether anything changed. The bar renders nothing otherwise. */
  dirty: boolean
  /** Names the changes: "Unit and role changed". */
  summary: React.ReactNode
  discardLabel: string
  saveLabel: string
  saving?: boolean
  onDiscard: () => void
  onSave: () => void
  className?: string
}

function SaveBar({ dirty, summary, discardLabel, saveLabel, saving = false, onDiscard, onSave, className }: SaveBarProps) {
  useLeaveGuard(dirty)
  if (!dirty) return null
  return (
    <div
      data-slot="save-bar"
      role="region"
      aria-live="polite"
      className={cn(
        "sticky bottom-4 z-[5] mt-5 flex flex-wrap items-center gap-3 rounded-card border border-border bg-popover py-2.5 ps-4 pe-3 text-sm text-popover-foreground shadow-float",
        className
      )}
    >
      <span className="min-w-0 flex-1">{summary}</span>
      <Button type="button" variant="outline" onClick={onDiscard} disabled={saving}>
        {discardLabel}
      </Button>
      <Button type="button" loading={saving} onClick={onSave}>
        {saveLabel}
      </Button>
    </div>
  )
}

/** Ask before reloading or closing the page while `active`. */
function useLeaveGuard(active: boolean) {
  React.useEffect(() => {
    if (!active) return
    const beforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
    }
    window.addEventListener("beforeunload", beforeUnload)
    return () => window.removeEventListener("beforeunload", beforeUnload)
  }, [active])
}

/**
 * A changed field's ring and its "Was …" note (P.3). Wrap the field; the ring
 * lands on whatever control sits inside.
 */
function ChangedField({
  changed,
  wasLabel,
  children,
  className,
}: {
  changed: boolean
  /** "Was Sweifieh" */
  wasLabel?: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <div
      data-changed={changed ? "" : undefined}
      className={cn(
        "grid gap-1 data-changed:[&_input]:border-primary data-changed:[&_input]:ring-3 data-changed:[&_input]:ring-primary-tint data-changed:[&_[data-slot=select-trigger]]:border-primary data-changed:[&_[data-slot=select-trigger]]:ring-3 data-changed:[&_[data-slot=select-trigger]]:ring-primary-tint",
        className
      )}
    >
      {children}
      {changed && wasLabel && <span className="text-xs text-muted-foreground">{wasLabel}</span>}
    </div>
  )
}

export { ChangedField, SaveBar, useLeaveGuard }
