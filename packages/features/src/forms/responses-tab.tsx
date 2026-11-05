"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { DataTable } from "@whity/ui/data-table"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@whity/ui/dialog"
import { AccessDeniedPanel, AreaError, EmptyFirstTime, LoadingRows } from "@whity/ui/page-states"

import { useDateDisplay } from "../datetime"
import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { createFormsApi } from "./api"
import { pickText } from "./text"
import { ResponsesSummaryCards } from "./responses-summary"
import type { FieldDraft, FormsScreenEnv, ResponsesSummary, SubmissionDetail, SubmissionRow } from "./types"

/** Where a response is in its routing, in plain words. */
export function useRoutingLabel() {
  const t = useTranslation("forms")
  return (row: Pick<SubmissionRow, "state" | "current_step">): string => {
    if (row.state === "completed") return t("responses.routing.done", "Finished")
    if (row.state === "in progress") {
      return row.current_step ? t("responses.routing.with", "With {step}", { step: row.current_step }) : t("responses.routing.inProgress", "In progress")
    }
    return t("responses.routing.none", "Not sent anywhere")
  }
}

/** An answer as one line of words. */
export function answerText(field: Pick<FieldDraft, "field_type" | "options" | "validation">, value: unknown, language: string | null, yes: string, no: string): string {
  if (value === null || value === undefined || value === "") return ""
  if (typeof value === "boolean") return value ? yes : no
  const optionLabel = (raw: unknown): string => {
    const match = field.options.find((o) => o.value === String(raw))
    return match ? pickText(match.label, language) || String(raw) : String(raw)
  }
  if (Array.isArray(value)) {
    return value
      .map((item) => {
        if (typeof item === "object" && item !== null) {
          const named = (item as { name?: unknown; original_name?: unknown; filename?: unknown }).name ?? (item as { original_name?: unknown }).original_name ?? (item as { filename?: unknown }).filename
          if (typeof named === "string") return named
          return Object.values(item as Record<string, unknown>)
            .filter((v) => typeof v === "string" || typeof v === "number")
            .join(", ")
        }
        return optionLabel(item)
      })
      .join("; ")
  }
  if (typeof value === "object") {
    const named = (value as { name?: unknown; original_name?: unknown }).name ?? (value as { original_name?: unknown }).original_name
    return typeof named === "string" ? named : ""
  }
  return optionLabel(value)
}

/** The Responses tab: every response to this form, and each one's answers. */
export function ResponsesTab({ env, formId }: { env: FormsScreenEnv; formId: number }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const { dateTime } = useDateDisplay()
  const routingLabel = useRoutingLabel()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [rows, setRows] = useState<SubmissionRow[] | null>(null)
  const [failure, setFailure] = useState<{ denied: boolean; message: string } | null>(null)
  const [openId, setOpenId] = useState<number | null>(null)
  const [summary, setSummary] = useState<ResponsesSummary | null>(null)

  const load = useCallback(async () => {
    setFailure(null)
    // The summary is a bonus: a server without it leaves the table as it was.
    void api.getSummary(formId, "").then((result) => setSummary(result.ok ? result.data : null))
    const result = await api.listSubmissions(formId, t("responses.error", "The responses could not be loaded."))
    if (result.ok) setRows(result.data)
    else setFailure({ denied: result.status === 403, message: result.message })
  }, [api, formId, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const headers = [t("responses.col.reference", "Reference"), t("responses.col.submitted", "Submitted"), t("responses.col.routing", "Where it is")]

  if (failure?.denied) {
    return (
      <AccessDeniedPanel
        title={t("responses.denied.title", "You can't read the responses")}
        description={t("responses.denied.body", "Ask an administrator to let you read responses to this form.")}
      />
    )
  }
  if (failure !== null) return <AreaError message={failure.message} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
  if (rows === null) return <LoadingRows headers={headers} label={t("responses.loading", "Loading responses")} />
  if (rows.length === 0) {
    return (
      <EmptyFirstTime
        title={t("responses.empty.title", "No responses yet")}
        description={t("responses.empty.body", "Responses appear here as soon as somebody sends the form.")}
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-end">
        <Button asChild variant="outline">
          <a href={`/api/v1/forms/${formId}/responses/export?language=${currentLanguage === "ar" ? "ar" : "en"}`} download>
            {t("responses.export", "Export responses")}
          </a>
        </Button>
      </div>
      {summary !== null && summary.total > 0 && <ResponsesSummaryCards summary={summary} />}
      <DataTable<SubmissionRow>
        aria-label={t("responses.title", "Responses")}
        data={rows}
        getRowId={(row) => String(row.id)}
        onRowClick={(row) => setOpenId(row.id)}
        columns={[
          { id: "person", header: t("responses.col.person", "From"), cell: (row) => row.submitted_by_name ?? t("responses.visitor", "A visitor") },
          { id: "reference", header: headers[0], cell: (row) => <span className="font-mono">{row.reference ?? `#${row.id}`}</span> },
          { id: "submitted", header: headers[1], cell: (row) => dateTime(row.submitted_at) ?? "" },
          { id: "routing", header: headers[2], cell: (row) => <Badge variant={row.state === "completed" ? "success" : "outline"}>{routingLabel(row)}</Badge> },
        ]}
      />
      {openId !== null && <ResponseDialog env={env} submissionId={openId} onClose={() => setOpenId(null)} />}
    </div>
  )
}

/** One response as a record: the questions, and what was answered. */
export function ResponseDialog({ env, submissionId, onClose }: { env: FormsScreenEnv; submissionId: number; onClose: () => void }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const { dateTime } = useDateDisplay()
  const routingLabel = useRoutingLabel()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [detail, setDetail] = useState<SubmissionDetail | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    const result = await api.getSubmission(submissionId, t("responses.error.one", "This response could not be loaded."))
    if (result.ok) setDetail(result.data)
    else setError(result.message)
  }, [api, submissionId, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const answers = detail === null || Array.isArray(detail.data) ? {} : detail.data

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{detail?.reference ?? t("responses.one.title", "Response")}</DialogTitle>
          <DialogDescription>
            {detail === null
              ? ""
              : `${detail.submitted_by_name ?? t("responses.visitor", "A visitor")} · ${dateTime(detail.submitted_at) ?? ""} · ${routingLabel(detail)}`}
          </DialogDescription>
        </DialogHeader>
        {error !== null ? (
          <AreaError message={error} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
        ) : detail === null ? (
          <p className="text-sm text-muted-foreground">{t("responses.loading", "Loading responses")}</p>
        ) : (
          <dl className="space-y-4">
            {detail.fields.map((field) => (
              <div key={field.field_key} className="space-y-0.5">
                <dt className="text-sm font-medium">{pickText(field.label, currentLanguage) || field.field_key}</dt>
                <dd className="text-sm text-muted-foreground">
                  {answerText(field, answers[field.field_key], currentLanguage, t("responses.yes", "Yes"), t("responses.no", "No")) ||
                    t("responses.noAnswer", "No answer")}
                </dd>
              </div>
            ))}
          </dl>
        )}
        <div className="flex justify-end">
          <Button variant="outline" onClick={onClose}>
            {t("builder.close", "Close")}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
