"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { DataTable } from "@whity/ui/data-table"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@whity/ui/dialog"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from "@whity/ui/dropdown-menu"
import type { BilingualValue } from "@whity/ui/bilingual-input"
import { BilingualField } from "./bilingual-field"
import { AccessDeniedPanel, AreaError, EmptyFiltered, EmptyFirstTime, LoadingRows } from "@whity/ui/page-states"
import { SearchField } from "@whity/ui/search-field"
import { PageHeader } from "@whity/ui/page-header"
import { Icon } from "@whity/ui/icon"
import { IconDots } from "@tabler/icons-react"

import { useDateDisplay } from "../datetime"
import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { createFormsApi, fieldBody } from "./api"
import { asMap, keyFrom, pickText, statusVariant } from "./text"
import type { FormRow, FormStatus, FormsScreenEnv } from "./types"

export function useStatusLabel() {
  const t = useTranslation("forms")
  return (status: FormStatus): string =>
    status === "published"
      ? t("builder.status.open", "Open")
      : status === "draft"
        ? t("builder.status.draft", "Draft")
        : t("builder.status.closed", "Closed")
}

/** Who can fill a form in, in words. */
export function useWhoCanFill() {
  const t = useTranslation("forms")
  return (form: Pick<FormRow, "status" | "public_enabled">): string => {
    if (form.status !== "published") return t("builder.who.nobody", "Nobody yet")
    return form.public_enabled
      ? t("builder.who.link", "Anyone with the link")
      : t("builder.who.members", "People who may fill in forms")
  }
}

/** The form builder list: every form, its status, who can fill it, and when it last changed. */
export function FormsListScreen({ env, canManage }: { env: FormsScreenEnv; canManage: boolean }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const { dateTime } = useDateDisplay()
  const statusLabel = useStatusLabel()
  const whoCanFill = useWhoCanFill()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])

  const [forms, setForms] = useState<FormRow[] | null>(null)
  const [failure, setFailure] = useState<{ denied: boolean; message: string } | null>(null)
  const [search, setSearch] = useState("")
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    setFailure(null)
    const result = await api.listForms(t("builder.list.error", "The forms could not be loaded."))
    if (result.ok) setForms(result.data)
    else setFailure({ denied: result.status === 403, message: result.message })
  }, [api, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const nameOf = useCallback((form: FormRow) => pickText(form.name, currentLanguage) || form.form_key, [currentLanguage])

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase()
    return (forms ?? []).filter((form) => term === "" || nameOf(form).toLowerCase().includes(term))
  }, [forms, search, nameOf])

  async function setOpen(form: FormRow, open: boolean) {
    const result = open
      ? await api.publish(form.id, t("builder.action.failed", "That did not work. Try again."))
      : await api.archive(form.id, t("builder.action.failed", "That did not work. Try again."))
    if (!result.ok) {
      env.notify(result.message, "error")
      return
    }
    const updated = result.data
    setForms((current) => (current ?? []).map((row) => (row.id === form.id ? { ...row, ...updated } : row)))
    env.notify(
      open
        ? t("builder.reopened", "{name} is open again.", { name: nameOf(form) })
        : t("builder.closed", "{name} is closed.", { name: nameOf(form) }),
      "success",
      {
        label: t("builder.undo", "Undo"),
        onClick: () => void setOpen(updated, !open),
      }
    )
  }

  async function duplicate(form: FormRow) {
    const detail = await api.getForm(form.id, t("builder.action.failed", "That did not work. Try again."))
    if (!detail.ok) {
      env.notify(detail.message, "error")
      return
    }
    const taken = new Set((forms ?? []).map((row) => row.form_key))
    const copyName = { ...asMap(form.name) }
    for (const key of Object.keys(copyName)) copyName[key] = t("builder.copyOf", "Copy of {name}", { name: copyName[key] ?? "" })
    const created = await api.createForm(
      { form_key: keyFrom(`${form.form_key}_copy`, taken, "form"), name: copyName },
      t("builder.action.failed", "That did not work. Try again.")
    )
    if (!created.ok) {
      env.notify(created.message, "error")
      return
    }
    const fieldsResult = await api.getFields(form.id, "")
    const fields = fieldsResult.ok ? fieldsResult.data.fields : []
    if (fields.length > 0) await api.replaceFields(created.data.id, fields.map(fieldBody), "")
    await api.updateForm(
      created.data.id,
      {
        description: asMap(form.description_i18n),
        submit_label: form.submit_label,
        sections: form.sections.map((s) => ({ key: s.key, title: asMap(s.title), description: asMap(s.description) })),
        reference_format: form.reference_format,
      },
      ""
    )
    env.notify(t("builder.duplicated", "{name} was copied.", { name: nameOf(form) }), "success")
    env.navigate(`/admin/x/forms-builder/${created.data.id}`)
  }

  function copyLink(form: FormRow) {
    if (form.public_url === null) return
    void navigator.clipboard?.writeText(form.public_url)
    env.notify(t("builder.linkCopied", "The link was copied."), "success")
  }

  if (failure?.denied) {
    return (
      <AccessDeniedPanel
        title={t("builder.denied.title", "You can't build forms")}
        description={t("builder.denied.body", "Ask an administrator to give you permission to manage forms.")}
      />
    )
  }

  const headers = [
    t("builder.col.form", "Form"),
    t("builder.col.status", "Status"),
    t("builder.col.who", "Who can fill it"),
    t("builder.col.changed", "Changed"),
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("builder.title", "Form builder")}
        description={t("builder.subtitle", "Build the forms your people and visitors fill in.")}
        icon={<Icon name="form" size={20} />}
        action={
          canManage ? (
            <Button onClick={() => setCreating(true)}>{t("builder.new", "New form")}</Button>
          ) : undefined
        }
      />

      {failure !== null ? (
        <AreaError message={failure.message} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
      ) : forms === null ? (
        <LoadingRows headers={headers} label={t("builder.loading", "Loading forms")} />
      ) : forms.length === 0 ? (
        <EmptyFirstTime
          title={t("builder.empty.title", "No forms yet")}
          description={t("builder.empty.body", "Start with a blank form. You can change it any time before you open it.")}
          action={canManage ? <Button onClick={() => setCreating(true)}>{t("builder.new", "New form")}</Button> : undefined}
        />
      ) : (
        <>
          <SearchField
            value={search}
            onValueChange={setSearch}
            label={t("builder.search", "Search forms")}
            clearLabel={t("builder.search.clear", "Clear search")}
            placeholder={t("builder.search", "Search forms")}
            className="max-w-sm"
          />
          {visible.length === 0 ? (
            <EmptyFiltered
              title={t("builder.filtered.title", "No form matches")}
              clearLabel={t("builder.filtered.clear", "Clear search")}
              onClear={() => setSearch("")}
            />
          ) : (
            <DataTable<FormRow>
              aria-label={t("builder.title", "Form builder")}
              data={visible}
              getRowId={(row) => String(row.id)}
              onRowClick={(row) => env.navigate(`/admin/x/forms-builder/${row.id}`)}
              columns={[
                {
                  id: "name",
                  header: headers[0],
                  cell: (row) => (
                    <div className="min-w-0">
                      <div className="truncate font-medium">{nameOf(row)}</div>
                      {pickText(row.description_i18n, currentLanguage) !== "" && (
                        <div className="truncate text-xs text-muted-foreground">{pickText(row.description_i18n, currentLanguage)}</div>
                      )}
                    </div>
                  ),
                },
                { id: "status", header: headers[1], cell: (row) => <Badge variant={statusVariant(row.status)}>{statusLabel(row.status)}</Badge> },
                { id: "who", header: headers[2], cell: (row) => whoCanFill(row) },
                { id: "changed", header: headers[3], cell: (row) => dateTime(row.updated_at) ?? "" },
              ]}
              rowActionsLabel={t("builder.col.actions", "Actions")}
              rowActions={(row) => (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label={t("builder.more", "More actions for {name}", { name: nameOf(row) })}>
                      <IconDots aria-hidden />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => env.navigate(`/admin/x/forms-builder/${row.id}`)}>{t("builder.row.open", "Open")}</DropdownMenuItem>
                    {row.public_url !== null && (
                      <DropdownMenuItem onSelect={() => copyLink(row)}>{t("builder.row.copyLink", "Copy link")}</DropdownMenuItem>
                    )}
                    {canManage && (
                      <>
                        <DropdownMenuItem onSelect={() => void duplicate(row)}>{t("builder.row.duplicate", "Duplicate")}</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {row.status === "archived" ? (
                          <DropdownMenuItem onSelect={() => void setOpen(row, true)}>{t("builder.row.reopen", "Reopen")}</DropdownMenuItem>
                        ) : row.status === "published" ? (
                          <DropdownMenuItem onSelect={() => void setOpen(row, false)}>{t("builder.row.close", "Close")}</DropdownMenuItem>
                        ) : null}
                      </>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              )}
            />
          )}
        </>
      )}

      {creating && (
        <NewFormDialog
          env={env}
          existingKeys={new Set((forms ?? []).map((row) => row.form_key))}
          onClose={() => setCreating(false)}
        />
      )}
    </div>
  )
}

function NewFormDialog({ env, existingKeys, onClose }: { env: FormsScreenEnv; existingKeys: ReadonlySet<string>; onClose: () => void }) {
  const t = useTranslation("forms")
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [name, setName] = useState<BilingualValue>({})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const typed = Object.values(name).some((value) => (value ?? "").trim() !== "")

  async function create() {
    setSaving(true)
    setError(null)
    const base = name.en?.trim() || name.ar?.trim() || ""
    const result = await api.createForm(
      { form_key: keyFrom(base, existingKeys, "form"), name },
      t("builder.new.failed", "The form could not be created.")
    )
    setSaving(false)
    if (!result.ok) {
      setError(result.message)
      return
    }
    env.navigate(`/admin/x/forms-builder/${result.data.id}`)
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("builder.new", "New form")}</DialogTitle>
          <DialogDescription>{t("builder.new.body", "Name the form. You add the questions next.")}</DialogDescription>
        </DialogHeader>
        <BilingualField
          id="new-form-name"
          label={t("builder.new.name", "Form name")}
          value={name}
          onChange={setName}
        />
        {error !== null && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t("builder.cancel", "Cancel")}</Button>
          <Button disabled={!typed || saving} onClick={() => void create()}>
            {t("builder.new.create", "Create form")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
