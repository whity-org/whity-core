"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { DataTable } from "@whity/ui/data-table"
import { Icon } from "@whity/ui/icon"
import { PageHeader } from "@whity/ui/page-header"
import { AreaError, EmptyFirstTime, LoadingRows } from "@whity/ui/page-states"

import { useDateDisplay } from "../datetime"
import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { createFormsApi } from "./api"
import { useRoutingLabel } from "./responses-tab"
import { pickText } from "./text"
import type { FormsScreenEnv, SubmissionRow } from "./types"

/** What a person has sent, and where each one is. */
export function MySubmissionsScreen({ env }: { env: FormsScreenEnv }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const { dateTime } = useDateDisplay()
  const routingLabel = useRoutingLabel()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [rows, setRows] = useState<SubmissionRow[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    const result = await api.listMySubmissions(t("mine.error", "Your submissions could not be loaded."))
    if (result.ok) setRows(result.data)
    else setError(result.message)
  }, [api, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const headers = [t("mine.col.form", "Form"), t("mine.col.reference", "Reference"), t("mine.col.sent", "Sent"), t("mine.col.where", "Where it is")]

  return (
    <div className="space-y-6">
      <PageHeader title={t("mine.title", "My submissions")} description={t("mine.subtitle", "What you have sent, and where each one is.")} icon={<Icon name="form" size={20} />} />
      {error !== null ? (
        <AreaError message={error} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
      ) : rows === null ? (
        <LoadingRows headers={headers} label={t("mine.loading", "Loading your submissions")} />
      ) : rows.length === 0 ? (
        <EmptyFirstTime title={t("mine.empty.title", "You have not sent a form yet")} description={t("mine.empty.body", "Forms you send appear here, with where they are.")} />
      ) : (
        <DataTable<SubmissionRow>
          aria-label={t("mine.title", "My submissions")}
          data={rows}
          getRowId={(row) => String(row.id)}
          columns={[
            { id: "form", header: headers[0], cell: (row) => pickText(row.form_name, currentLanguage) || row.form_key || "" },
            { id: "reference", header: headers[1], cell: (row) => <span className="font-mono">{row.reference ?? "—"}</span> },
            { id: "sent", header: headers[2], cell: (row) => dateTime(row.submitted_at) ?? "" },
            { id: "where", header: headers[3], cell: (row) => <Badge variant={row.state === "completed" ? "success" : "outline"}>{routingLabel(row)}</Badge> },
          ]}
        />
      )}
    </div>
  )
}
