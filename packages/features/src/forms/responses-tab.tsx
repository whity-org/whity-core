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
import type { FieldDraft, FormsScreenEnv, ResponsesSummary, SubmissionDetail, SubmissionFile, SubmissionRow } from "./types"

/** Where a response is in its routing, in plain words. */
export function useRoutingLabel() {
  const t = useTranslation("forms")
  return (row: Pick<SubmissionRow, "state" | "current_step">): string => {
    if (row.state === "completed") return t("responses.routing.done", "Finished")
    if (row.state === "withdrawn") return t("responses.routing.withdrawn", "Withdrawn")
    if (row.state === "in progress") {
      return row.current_step ? t("responses.routing.with", "With {step}", { step: row.current_step }) : t("responses.routing.inProgress", "In progress")
    }
    return t("responses.routing.none", "Not sent anywhere")
  }
}

/** The badge for where a response is: withdrawn reads as over, not as waiting. */
export function routingVariant(state: string | undefined): "success" | "secondary" | "outline" {
  if (state === "completed") return "success"
  return state === "withdrawn" ? "secondary" : "outline"
}

/** An answer as one line of words. */
export function answerText(field: Pick<FieldDraft, "field_type" | "options" | "validation">, value: unknown, language: string | null, yes: string, no: string, date: (value: string) => string): string {
  if (value === null || value === undefined || value === "") return ""
  if (typeof value === "boolean") return value ? yes : no
  if (field.field_type === "date" && typeof value === "string") return date(value)
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
          { id: "routing", header: headers[2], cell: (row) => <Badge variant={routingVariant(row.state)}>{routingLabel(row)}</Badge> },
        ]}
      />
      {openId !== null && <ResponseDialog env={env} submissionId={openId} onClose={() => setOpenId(null)} />}
    </div>
  )
}

/** One response as a record: the questions, and what was answered. */
export function ResponseDialog({ env, submissionId, mine = false, onClose }: { env: FormsScreenEnv; submissionId: number; mine?: boolean; onClose: () => void }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const { date, dateTime } = useDateDisplay()
  const routingLabel = useRoutingLabel()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [detail, setDetail] = useState<SubmissionDetail | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    const failed = t("responses.error.one", "This response could not be loaded.")
    // Somebody reading their own holds no `forms:read`, so it has its own address.
    const result = mine ? await api.getMySubmission(submissionId, failed) : await api.getSubmission(submissionId, failed)
    if (result.ok) setDetail(result.data)
    else setError(result.message)
  }, [api, mine, submissionId, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const answers = detail === null || Array.isArray(detail.data) ? {} : detail.data
  const filesBase = `/api/v1${mine ? "/me" : ""}/form-submissions/${submissionId}/files`
  // A question with a rule and no answer wasn't asked: the server keeps no answer to a
  // hidden question, so "No answer" would blame the person for something they never saw.
  const asked = (detail?.fields ?? []).filter((field) => field.visible_when === null || field.visible_when === undefined || !isBlank(answers[field.field_key]))

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
            {asked.map((field) => (
              <div key={field.field_key} className="space-y-0.5">
                <dt className="text-sm font-medium">{pickText(field.label, currentLanguage) || field.field_key}</dt>
                <dd className="text-sm text-muted-foreground">
                  {field.field_type === "file" && answers[field.field_key] ? (
                    <AttachedFiles base={filesBase} files={(detail.files ?? []).filter((file) => file.field_key === field.field_key)} />
                  ) : (
                    answerText(field, answers[field.field_key], currentLanguage, t("responses.yes", "Yes"), t("responses.no", "No"), (value) => date(value) ?? "—") ||
                    t("responses.noAnswer", "No answer")
                  )}
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

function isBlank(answer: unknown): boolean {
  return answer === null || answer === undefined || answer === "" || (Array.isArray(answer) && answer.length === 0)
}

/** The files answering one question. A submission older than the list still says one came. */
function AttachedFiles({ base, files }: { base: string; files: SubmissionFile[] }) {
  const t = useTranslation("forms")
  if (files.length === 0) return <>{t("responses.attached", "Attached")}</>
  return (
    <span className="flex flex-col items-start gap-2 pt-1">
      {files.map((file) => (
        <Button key={file.id} asChild variant="outline" className="h-auto min-h-10 max-w-full whitespace-normal break-all text-start">
          <a href={`${base}/${file.id}`} download>
            {t("responses.downloadFile", "Download {name}", { name: file.filename ?? t("responses.attachedFile", "attached file") })}
          </a>
        </Button>
      ))}
    </span>
  )
}
