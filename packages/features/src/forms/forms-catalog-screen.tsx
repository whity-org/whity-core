"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@whity/ui/card"
import { Icon } from "@whity/ui/icon"
import { PageHeader } from "@whity/ui/page-header"
import { AccessDeniedPanel, AreaError, EmptyFirstTime, LoadingRows } from "@whity/ui/page-states"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { createFormsApi } from "./api"
import { pickText } from "./text"
import type { FormRow, FormsScreenEnv } from "./types"

/** The key a form's unsent answers are kept under in this browser. */
export function draftStorageKey(formId: number | string): string {
  return `whity:form-draft:${formId}`
}

function hasLocalDraft(formId: number): boolean {
  try {
    const raw = window.localStorage.getItem(draftStorageKey(formId))
    if (raw === null) return false
    const parsed: unknown = JSON.parse(raw)
    return parsed !== null && typeof parsed === "object" && Object.keys(parsed as object).length > 0
  } catch {
    return false
  }
}

/** The forms a person can fill in, as cards. Unsent drafts in this browser come first. */
export function FormsCatalogScreen({ env }: { env: FormsScreenEnv }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])
  const [forms, setForms] = useState<FormRow[] | null>(null)
  const [failure, setFailure] = useState<{ denied: boolean; message: string } | null>(null)
  const [drafts, setDrafts] = useState<ReadonlySet<number>>(new Set())

  const load = useCallback(async () => {
    setFailure(null)
    const result = await api.listForms(t("catalog.error", "The forms could not be loaded."))
    if (!result.ok) {
      setFailure({ denied: result.status === 403, message: result.message })
      return
    }
    const open = result.data.filter((form) => form.status === "published")
    setForms(open)
    setDrafts(new Set(open.filter((form) => hasLocalDraft(form.id)).map((form) => form.id)))
  }, [api, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  if (failure?.denied) {
    return (
      <AccessDeniedPanel
        title={t("catalog.denied.title", "You can't see the forms yet")}
        description={t("catalog.denied.body", "Ask an administrator to let you fill in forms.")}
      />
    )
  }

  const card = (form: FormRow, resume: boolean) => (
    <Card key={`${resume ? "d" : "a"}-${form.id}`}>
      <CardHeader>
        <CardTitle className="text-base">{pickText(form.name, currentLanguage) || form.form_key}</CardTitle>
        {pickText(form.description_i18n, currentLanguage) !== "" && (
          <CardDescription>{pickText(form.description_i18n, currentLanguage)}</CardDescription>
        )}
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-3">
        {resume ? <Badge variant="warning">{t("catalog.draft", "Not sent yet")}</Badge> : <span />}
        <Button variant={resume ? "default" : "outline"} onClick={() => env.navigate(`/forms/${form.id}`)}>
          {resume ? t("catalog.continue", "Continue") : t("catalog.fill", "Fill in")}
        </Button>
      </CardContent>
    </Card>
  )

  return (
    <div className="space-y-6">
      <PageHeader title={t("catalog.title", "Forms")} description={t("catalog.subtitle", "The forms you can fill in.")} icon={<Icon name="form" size={20} />} />
      {failure !== null ? (
        <AreaError message={failure.message} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
      ) : forms === null ? (
        <LoadingRows headers={[t("catalog.title", "Forms")]} label={t("catalog.loading", "Loading forms")} rows={3} />
      ) : forms.length === 0 ? (
        <EmptyFirstTime title={t("catalog.empty.title", "No forms for you yet")} description={t("catalog.empty.body", "When your workspace opens a form, it appears here.")} />
      ) : (
        <>
          {drafts.size > 0 && (
            <section className="space-y-3">
              <h2 className="text-base font-semibold">{t("catalog.resume", "Continue where you left off")}</h2>
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{forms.filter((f) => drafts.has(f.id)).map((f) => card(f, true))}</div>
            </section>
          )}
          <section className="space-y-3">
            <h2 className="text-base font-semibold">{t("catalog.all", "All forms")}</h2>
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{forms.map((f) => card(f, false))}</div>
          </section>
        </>
      )}
    </div>
  )
}
