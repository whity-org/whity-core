"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@whity/ui/card"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { pickText } from "./text"
import type { ResponsesSummary } from "./types"

/** A row of horizontal bars: a label, how many, and a bar as long as its share. */
function Bars({ rows, max }: { rows: Array<{ label: string; count: number }>; max: number }) {
  return (
    <ul className="space-y-2">
      {rows.map((row, i) => (
        <li key={i} className="space-y-1">
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span className="min-w-0 truncate">{row.label}</span>
            <span className="shrink-0 font-medium tabular-nums">{row.count}</span>
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-muted" aria-hidden>
            <div className="h-full rounded-full bg-primary" style={{ width: `${max === 0 ? 0 : Math.round((row.count / max) * 100)}%` }} />
          </div>
        </li>
      ))}
    </ul>
  )
}

const OPTIONS_SHOWN = 5

/**
 * What came back, at a glance: how many, how many a week, where they are in their
 * routing, and one card for each choice question. Free text and files are never
 * summarised: the server sends none.
 */
export function ResponsesSummaryCards({ summary }: { summary: ResponsesSummary }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const weekMax = Math.max(0, ...summary.per_week.map((w) => w.count))
  const choice = summary.questions.filter((q) => q.options.length > 0)

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("summary.total", "Responses")}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="text-3xl font-semibold tabular-nums">{summary.total}</div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("summary.routing", "Where they are")}</CardTitle>
        </CardHeader>
        <CardContent>
          <Bars
            max={Math.max(summary.routing.not_routed, summary.routing.in_progress, summary.routing.completed, summary.routing.withdrawn ?? 0)}
            rows={[
              { label: t("summary.routing.inProgress", "In progress"), count: summary.routing.in_progress },
              { label: t("summary.routing.completed", "Finished"), count: summary.routing.completed },
              { label: t("summary.routing.notRouted", "Not sent anywhere"), count: summary.routing.not_routed },
              ...(summary.routing.withdrawn !== undefined ? [{ label: t("summary.routing.withdrawn", "Withdrawn"), count: summary.routing.withdrawn }] : []),
            ]}
          />
        </CardContent>
      </Card>

      {summary.per_week.length > 0 && (
        <Card className="md:col-span-2">
          <CardHeader>
            <CardTitle className="text-base">{t("summary.perWeek", "Responses a week")}</CardTitle>
          </CardHeader>
          <CardContent>
            <Bars max={weekMax} rows={summary.per_week.map((w) => ({ label: w.week, count: w.count }))} />
          </CardContent>
        </Card>
      )}

      {choice.map((question) => {
        const shown = question.options.slice(0, OPTIONS_SHOWN)
        const rest = question.options.slice(OPTIONS_SHOWN).reduce((sum, o) => sum + o.count, 0)
        const rows = shown.map((o) => ({ label: pickText(o.label, currentLanguage) || o.value, count: o.count }))
        if (rest > 0) rows.push({ label: t("summary.other", "Other"), count: rest })
        return (
          <Card key={question.field_key}>
            <CardHeader>
              <CardTitle className="text-base">{pickText(question.label, currentLanguage) || question.field_key}</CardTitle>
            </CardHeader>
            <CardContent>
              <Bars max={Math.max(...rows.map((r) => r.count))} rows={rows} />
            </CardContent>
          </Card>
        )
      })}
    </div>
  )
}
