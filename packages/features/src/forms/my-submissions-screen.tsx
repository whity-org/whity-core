"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { ConfirmAction } from "@whity/ui/confirm-action"
import { DataTable } from "@whity/ui/data-table"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@whity/ui/dropdown-menu"
import { Icon } from "@whity/ui/icon"
import { PageHeader } from "@whity/ui/page-header"
import { AreaError, EmptyFiltered, EmptyFirstTime, LoadingRows } from "@whity/ui/page-states"
import { SearchField } from "@whity/ui/search-field"

import { useDateDisplay } from "../datetime"
import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { createFormsApi } from "./api"
import { ResponseDialog, routingVariant, useRoutingLabel } from "./responses-tab"
import { pickText } from "./text"
import type { FormDraftRow, FormsScreenEnv, SubmissionRow } from "./types"

type Row = { kind: "draft"; id: string; draft: FormDraftRow } | { kind: "sent"; id: string; submission: SubmissionRow }

/** The server refuses the rest: a review recorded since this list loaded, say. */
function mayWithdraw(row: SubmissionRow): boolean {
  return row.state !== "withdrawn" && row.state !== "completed" && row.state !== "not approved"
}

/** What a person has sent, where each one is, and what they started and did not send. */
export function MySubmissionsScreen({ env }: { env: FormsScreenEnv }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const { dateTime } = useDateDisplay()
  const routingLabel = useRoutingLabel()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [sent, setSent] = useState<SubmissionRow[] | null>(null)
  const [drafts, setDrafts] = useState<FormDraftRow[]>([])
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState("")
  const [openId, setOpenId] = useState<number | null>(null)
  const [withdrawing, setWithdrawing] = useState<SubmissionRow | null>(null)
  const [discarding, setDiscarding] = useState<FormDraftRow | null>(null)

  const load = useCallback(async () => {
    setError(null)
    const [result, unsent] = await Promise.all([
      api.listMySubmissions(t("mine.error", "Your submissions could not be loaded.")),
      api.listMyDrafts(""),
    ])
    if (result.ok) setSent(result.data)
    else setError(result.message)
    // Without drafts the page still says what was sent.
    setDrafts(unsent.ok ? unsent.data : [])
  }, [api, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const nameOfSent = useCallback((row: SubmissionRow) => pickText(row.form_name, currentLanguage) || row.form_key || "", [currentLanguage])
  const nameOfDraft = useCallback((row: FormDraftRow) => pickText(row.form_name, currentLanguage) || t("mine.draft.untitled", "Form"), [currentLanguage, t])

  const rows = useMemo<Row[]>(() => {
    const term = search.trim().toLowerCase()
    const matches = (...texts: Array<string | null | undefined>) => term === "" || texts.some((text) => (text ?? "").toLowerCase().includes(term))
    return [
      ...drafts.filter((draft) => matches(nameOfDraft(draft))).map((draft): Row => ({ kind: "draft", id: `draft-${draft.form_id}`, draft })),
      ...(sent ?? []).filter((row) => matches(nameOfSent(row), row.reference)).map((submission): Row => ({ kind: "sent", id: `sent-${submission.id}`, submission })),
    ]
  }, [drafts, nameOfDraft, nameOfSent, search, sent])

  async function withdraw(row: SubmissionRow) {
    const result = await api.withdrawMySubmission(row.id, t("mine.withdraw.failed", "Your submission could not be withdrawn. Try again."))
    if (!result.ok) {
      if (result.reason === "already_decided") {
        env.notify(t("mine.withdraw.decided", "Somebody has already acted on this, so it can't be withdrawn."), "error")
      } else if (result.reason === "already_withdrawn") {
        env.notify(t("mine.withdraw.already", "This was already withdrawn."), "info")
      } else {
        env.notify(t("mine.withdraw.failed", "Your submission could not be withdrawn. Try again."), "error")
      }
      await load()
      return
    }
    env.notify(t("mine.withdraw.done", "{name} was withdrawn.", { name: nameOfSent(row) }), "success")
    await load()
  }

  async function discard(row: FormDraftRow) {
    const result = await api.deleteMyDraft(row.form_id, "")
    if (!result.ok) {
      env.notify(t("mine.discard.failed", "Your draft could not be deleted. Try again."), "error")
      return
    }
    env.notify(t("mine.discard.done", "Your draft of {name} was deleted.", { name: nameOfDraft(row) }), "success")
    await load()
  }

  const openRow = (row: Row) => {
    if (row.kind === "draft") env.navigate(`/forms/${row.draft.form_id}`)
    else setOpenId(row.submission.id)
  }

  const headers = [t("mine.col.form", "Form"), t("mine.col.sent", "Sent"), t("mine.col.where", "Where it is"), t("mine.col.reference", "Reference")]
  const nothingAtAll = (sent ?? []).length === 0 && drafts.length === 0

  return (
    <div className="space-y-6">
      <PageHeader title={t("mine.title", "My submissions")} description={t("mine.subtitle", "What you have sent, and where each one is.")} icon={<Icon name="form" size={20} />} />
      {error !== null ? (
        <AreaError message={error} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
      ) : sent === null ? (
        <LoadingRows headers={headers} label={t("mine.loading", "Loading your submissions")} />
      ) : nothingAtAll ? (
        <EmptyFirstTime title={t("mine.empty.title", "You have not sent a form yet")} description={t("mine.empty.body", "Forms you send appear here, with where they are.")} />
      ) : (
        <>
          <SearchField
            value={search}
            onValueChange={setSearch}
            label={t("mine.search", "Search your submissions")}
            placeholder={t("mine.search", "Search your submissions")}
            clearLabel={t("mine.search.clear", "Clear search")}
            className="max-w-sm"
          />
          {rows.length === 0 ? (
            <EmptyFiltered
              title={t("mine.filtered.title", "Nothing matches your search")}
              description={t("mine.filtered.body", "Try different words.")}
              clearLabel={t("mine.search.clear", "Clear search")}
              onClear={() => setSearch("")}
            />
          ) : (
            <DataTable<Row>
              aria-label={t("mine.title", "My submissions")}
              data={rows}
              getRowId={(row) => row.id}
              onRowClick={openRow}
              columns={[
                { id: "form", header: headers[0], cell: (row) => (row.kind === "draft" ? nameOfDraft(row.draft) : nameOfSent(row.submission)) },
                { id: "sent", header: headers[1], cell: (row) => (row.kind === "draft" ? "—" : dateTime(row.submission.submitted_at) ?? "") },
                {
                  id: "where",
                  header: headers[2],
                  cell: (row) =>
                    row.kind === "draft" ? (
                      <Badge variant="secondary">
                        {(row.draft.step ?? 0) > 0
                          ? t("mine.draft.step", "Draft · step {number}", { number: (row.draft.step ?? 0) + 1 })
                          : t("mine.draft", "Draft")}
                      </Badge>
                    ) : (
                      <Badge variant={routingVariant(row.submission.state)}>{routingLabel(row.submission)}</Badge>
                    ),
                },
                { id: "reference", header: headers[3], cell: (row) => (row.kind === "sent" ? <span className="font-mono">{row.submission.reference ?? "—"}</span> : "") },
              ]}
              rowActionsLabel={t("builder.col.actions", "Actions")}
              rowActions={(row) => {
                const name = row.kind === "draft" ? nameOfDraft(row.draft) : nameOfSent(row.submission)
                return (
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" aria-label={t("mine.more", "More actions for {name}", { name })}>
                        <Icon name="more" size={16} />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      {row.kind === "draft" ? (
                        <>
                          <DropdownMenuItem onSelect={() => openRow(row)}>{t("mine.continue", "Continue")}</DropdownMenuItem>
                          <DropdownMenuItem variant="destructive" onSelect={() => setDiscarding(row.draft)}>
                            {t("mine.discard", "Delete draft")}
                          </DropdownMenuItem>
                        </>
                      ) : (
                        <>
                          <DropdownMenuItem onSelect={() => openRow(row)}>{t("mine.open", "Open")}</DropdownMenuItem>
                          {row.submission.state === "sent back" && (
                            <DropdownMenuItem onSelect={() => env.navigate(`/forms/${row.submission.form_id}?edit=${row.submission.id}`)}>
                              {t("mine.edit", "Edit and send again")}
                            </DropdownMenuItem>
                          )}
                          {row.submission.state === "not approved" && (
                            <DropdownMenuItem onSelect={() => env.navigate(`/forms/${row.submission.form_id}?from=${row.submission.id}`)}>
                              {t("mine.startAgain", "Start a new one from this")}
                            </DropdownMenuItem>
                          )}
                          {mayWithdraw(row.submission) && (
                            <DropdownMenuItem variant="destructive" onSelect={() => setWithdrawing(row.submission)}>
                              {t("mine.withdraw", "Withdraw")}
                            </DropdownMenuItem>
                          )}
                        </>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>
                )
              }}
            />
          )}
        </>
      )}

      {openId !== null && <ResponseDialog env={env} submissionId={openId} mine onClose={() => setOpenId(null)} />}

      {withdrawing !== null && (
        <ConfirmAction
          open
          onOpenChange={(open) => !open && setWithdrawing(null)}
          title={t("mine.withdraw.title", "Withdraw {name}?", { name: nameOfSent(withdrawing) })}
          description={t(
            "mine.withdraw.body",
            "Nobody will be asked to act on it any more, and any document it produced stops being valid. Your answers stay on record. This can't be undone: to change your mind, send the form again."
          )}
          confirmLabel={t("mine.withdraw.confirm", "Withdraw submission")}
          cancelLabel={t("builder.cancel", "Cancel")}
          onConfirm={() => withdraw(withdrawing)}
        />
      )}

      {discarding !== null && (
        <ConfirmAction
          open
          onOpenChange={(open) => !open && setDiscarding(null)}
          title={t("mine.discard.title", "Delete your draft of {name}?", { name: nameOfDraft(discarding) })}
          description={t("mine.discard.body", "The answers you have not sent are removed. This can't be undone.")}
          confirmLabel={t("mine.discard", "Delete draft")}
          cancelLabel={t("builder.cancel", "Cancel")}
          onConfirm={() => discard(discarding)}
        />
      )}
    </div>
  )
}
