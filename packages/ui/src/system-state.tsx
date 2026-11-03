import * as React from "react"

import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

export interface SystemStateProps {
  icon: IconName
  /** A small mono label: 404, 500, OFFLINE. */
  code?: string
  title: string
  description: string
  /** The one thing to do next, then at most one quieter way out. */
  actions?: React.ReactNode
  className?: string
}

/**
 * A full-page error or pause (docs/design/components/TplSystemStates): what
 * happened, that nothing was lost when that is true, and what to do next. The
 * art is the mark's two planes with the sheet dashed, "not set down".
 */
function SystemState({ icon, code, title, description, actions, className }: SystemStateProps) {
  return (
    <main className={cn("grid min-h-screen place-items-center bg-background p-4", className)}>
      <div className="flex w-full max-w-md flex-col items-center gap-3 rounded-card border border-border bg-card px-6 py-10 text-center shadow-sm">
        <span aria-hidden="true" className="relative mb-2 block size-14">
          <span className="absolute start-0 bottom-0 size-9 rounded-control bg-muted" />
          <span className="absolute end-0 top-0 grid size-10 place-items-center rounded-control border border-dashed border-border bg-card text-muted-foreground">
            <Icon name={icon} size={20} />
          </span>
        </span>
        {code ? (
          <span dir="ltr" className="font-mono text-xs uppercase tracking-wider text-muted-foreground">
            {code}
          </span>
        ) : null}
        <h1 className="text-lg font-semibold text-foreground">{title}</h1>
        <p className="text-sm text-muted-foreground">{description}</p>
        {actions ? <div className="mt-2 flex flex-wrap items-center justify-center gap-2">{actions}</div> : null}
      </div>
    </main>
  )
}

export { SystemState }
