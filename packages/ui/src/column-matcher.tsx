import * as React from "react"

import { Badge } from "./badge"
import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * Matching a spreadsheet's columns to Whity's fields (docs/design/components/
 * TplImport; `.wk-map` in bundle.css), for imports of contacts, items, opening
 * stock and balances, and label sheets.
 *
 * Each row shows the file's column with an example value, an arrow, where it
 * goes in Whity (the screen's own picker), and what Whity did: matched, fixed
 * and said so, made into a tag, doubtful (amber), or skipped.
 */

export type ColumnMatchStatus = "matched" | "fixed" | "new" | "check" | "skipped"

export interface ColumnMatchRow {
  /** The column's header in the file. */
  source: string
  /** An example value, or what the column holds ("(empty in 170 rows)"). */
  example?: string
  /** Where it goes: a Select of Whity's fields (including the workspace's own). */
  target: React.ReactNode
  status: ColumnMatchStatus
  /** The status in words ("Matched, +962 added", "Check: no decimals"). */
  statusLabel: string
}

const STATUS_VARIANT: Record<ColumnMatchStatus, "success" | "info" | "warning" | "secondary"> = {
  matched: "success",
  fixed: "success",
  new: "info",
  check: "warning",
  skipped: "secondary",
}

function ColumnMatcher({
  rows,
  labels,
  className,
}: {
  rows: ColumnMatchRow[]
  labels?: { file?: string; whity?: string; status?: string; goesTo?: string }
  className?: string
}) {
  const L = { file: "In your file", whity: "In Whity", status: "Status", goesTo: "goes to", ...labels }
  const grid = "grid grid-cols-[minmax(0,1fr)_28px_minmax(0,1fr)_minmax(120px,170px)] items-center gap-2.5 px-3.5"
  return (
    <div data-slot="column-matcher" role="table" className={cn("text-sm", className)}>
      <div role="row" className={cn(grid, "bg-muted py-2 text-xs font-semibold text-muted-foreground")}>
        <span role="columnheader">{L.file}</span>
        <span role="presentation" />
        <span role="columnheader">{L.whity}</span>
        <span role="columnheader">{L.status}</span>
      </div>
      {rows.map((row) => (
        <div
          key={row.source}
          role="row"
          data-status={row.status}
          className={cn(grid, "min-h-(--row-h) border-b border-border py-2.5 last:border-b-0", row.status === "check" && "bg-warning-tint/40")}
        >
          <div role="cell" className="min-w-0">
            <code className="rounded-[5px] bg-muted px-1.5 py-0.5 font-mono text-xs">{row.source}</code>
            {row.example && <small className="mt-0.5 block truncate text-xs text-muted-foreground">{row.example}</small>}
          </div>
          <span role="presentation" className="justify-self-center text-muted-foreground">
            <Icon name="arrow-right" size={16} className="rtl:-scale-x-100" label={L.goesTo} />
          </span>
          <div role="cell" className="min-w-0">
            {row.target}
          </div>
          <div role="cell">
            <Badge variant={STATUS_VARIANT[row.status]}>{row.statusLabel}</Badge>
          </div>
        </div>
      ))}
    </div>
  )
}

export { ColumnMatcher }
