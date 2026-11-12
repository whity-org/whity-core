import * as React from "react"

import { cn } from "./utils"

/**
 * A row of headline numbers (docs/design/components/TplReports, TplImport;
 * `.wk-kpis`, `.wk-kpi` in bundle.css), for Reports, Import and Bank. Values
 * are mono and stay left to right; money comes already formatted in normal
 * units with the currency's decimals ("JOD 31,880.000").
 */

export interface KpiItem {
  label: string
  value: string
  /** The comparison or what the number means ("+12% on September", "At cost"). */
  note?: string
  /** Colours the note: good green, bad red. Never the only signal: the note says it. */
  tone?: "good" | "bad"
}

function KpiStrip({ items, className }: { items: KpiItem[]; className?: string }) {
  return (
    <dl
      data-slot="kpi-strip"
      className={cn("m-0 grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3", className)}
    >
      {items.map((item) => (
        <div key={item.label} className="grid gap-1 rounded-card border border-border bg-card px-4 py-3.5">
          <dt className="text-xs text-muted-foreground">{item.label}</dt>
          <dd className="m-0 font-mono text-[1.375rem] font-medium tabular-nums" dir="ltr">
            {item.value}
          </dd>
          {item.note && (
            <dd
              className={cn(
                "m-0 text-xs",
                item.tone === "good" ? "text-success-text" : item.tone === "bad" ? "text-destructive" : "text-muted-foreground"
              )}
            >
              {item.note}
            </dd>
          )}
        </div>
      ))}
    </dl>
  )
}

export { KpiStrip }
