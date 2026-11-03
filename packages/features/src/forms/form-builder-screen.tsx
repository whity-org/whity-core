"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { Icon } from "@whity/ui/icon"
import { PageHeader } from "@whity/ui/page-header"
import { AccessDeniedPanel, AreaError, LoadingRows, ReadOnlyNotice } from "@whity/ui/page-states"
import { ConfirmAction } from "@whity/ui/confirm-action"
import { SaveBar } from "@whity/ui/save-bar"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@whity/ui/sheet"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@whity/ui/tabs"
import type { BilingualValue } from "@whity/ui/bilingual-input"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { useDateDisplay } from "../datetime"
import { createFormsApi, fieldBody } from "./api"
import { BuilderCanvas, scrollToQuestion } from "./builder-canvas"
import { ProblemList, useProblemText } from "./problem-list"
import {
  duplicateField,
  KIND_ORDER,
  moveField,
  newField,
  relabel,
  problemsOf,
  questionIndexIn,
  removeField,
  reorder,
  snapshot,
} from "./builder-model"
import { FormSettingsTab, type FormSettings } from "./form-settings-tab"
import { useStatusLabel } from "./forms-list-screen"
import { QuestionPanel } from "./question-panel"
import { ResponsesTab } from "./responses-tab"
import { asMap, pickText, statusVariant } from "./text"
import type { FieldDraft, FieldKind, FormRow, FormSectionDef, FormsScreenEnv } from "./types"

interface Loaded {
  form: FormRow
  fields: FieldDraft[]
  settings: FormSettings
  kinds: FieldKind[]
}

function settingsOf(form: FormRow, rendered: Array<{ key: string | null; title?: unknown; description?: unknown }>): FormSettings {
  const sections: FormSectionDef[] = rendered
    .filter((s): s is { key: string; title?: unknown; description?: unknown } => typeof s.key === "string")
    .map((s) => ({
      key: s.key,
      title: asMap(s.title as FormSectionDef["title"] | []),
      description: asMap(s.description as FormSectionDef["description"] | []),
    }))
  return {
    name: asMap(form.name),
    description: asMap(form.description_i18n),
    submit_label: asMap(form.submit_label),
    reference_format: form.reference_format ?? "",
    sections,
  }
}

/** Fields as the editor holds them: the server's rows, with no empty-map arrays. */
function draftsOf(rows: FieldDraft[]): FieldDraft[] {
  return rows.map((row) => ({
    ...row,
    label: asMap(row.label),
    help_text_i18n: asMap(row.help_text_i18n),
    audience: row.audience ?? "applicant",
    visible_when: row.visible_when ?? null,
    options: row.options ?? [],
  }))
}

function hasText(map: Record<string, string | undefined>): boolean {
  return Object.values(map).some((v) => (v ?? "").trim() !== "")
}

/** A form's record page: the builder, its responses, and its settings. */
export function FormBuilderScreen({ env, formId, canManage }: { env: FormsScreenEnv; formId: number; canManage: boolean }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const statusLabel = useStatusLabel()
  const { dateTime } = useDateDisplay()
  const api = useMemo(() => createFormsApi(env.transport), [env.transport])

  const [loaded, setLoaded] = useState<Loaded | null>(null)
  const [failure, setFailure] = useState<{ denied: boolean; missing: boolean; message: string } | null>(null)
  const [fields, setFields] = useState<FieldDraft[]>([])
  const [settings, setSettings] = useState<FormSettings | null>(null)
  const [selected, setSelected] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)
  const [publicBusy, setPublicBusy] = useState(false)
  const [tab, setTab] = useState("questions")
  // Bumped when a question is added, so the cursor lands in its wording.
  const [focusWording, setFocusWording] = useState(0)

  useEffect(() => {
    if (focusWording === 0) return
    const id = window.setTimeout(() => {
      document.getElementById("q-label-en")?.focus()
      document.getElementById("q-label-en")?.scrollIntoView({ block: "nearest" })
    }, 0)
    return () => window.clearTimeout(id)
  }, [focusWording])

  const load = useCallback(async () => {
    setFailure(null)
    const fallback = t("builder.record.error", "This form could not be loaded.")
    const [formResult, fieldsResult] = await Promise.all([api.getForm(formId, fallback), api.getFields(formId, fallback)])
    if (!formResult.ok) {
      setFailure({ denied: formResult.status === 403, missing: formResult.status === 404, message: formResult.message })
      return
    }
    if (!fieldsResult.ok) {
      setFailure({ denied: fieldsResult.status === 403, missing: false, message: fieldsResult.message })
      return
    }
    const { fields: rows, vocabulary } = fieldsResult.data
    const kinds = KIND_ORDER.filter((k) => vocabulary.field_types.length === 0 || vocabulary.field_types.includes(k))
    const drafts = draftsOf(rows)
    const form = formResult.data
    const next = settingsOf(form, form.sections as unknown as Array<{ key: string | null; title?: unknown; description?: unknown }>)
    setLoaded({ form, fields: drafts, settings: next, kinds })
    setFields(drafts)
    setSettings(next)
    setSelected(null)
  }, [api, formId, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const fieldsChanged = loaded !== null && snapshot(fields.map(fieldBody)) !== snapshot(loaded.fields.map(fieldBody))
  const settingsChanged = loaded !== null && settings !== null && snapshot(settings) !== snapshot(loaded.settings)
  const dirty = fieldsChanged || settingsChanged

  const [showProblems, setShowProblems] = useState(false)
  const [publishOpen, setPublishOpen] = useState(false)
  const [wide, setWide] = useState(true)
  useEffect(() => {
    if (typeof window.matchMedia !== "function") return
    const query = window.matchMedia("(min-width: 1024px)")
    const update = () => setWide(query.matches)
    update()
    query.addEventListener("change", update)
    return () => query.removeEventListener("change", update)
  }, [])

  const problems = useMemo(() => problemsOf(fields, settings?.name ?? {}), [fields, settings])
  const blocking = useMemo(() => problems.filter((p) => p.blocking), [problems])
  const invalid = useMemo(() => new Set(blocking.flatMap((p) => (p.index === null ? [] : [p.index]))), [blocking])
  const problemText = useProblemText(fields)

  const jump = useCallback((index: number) => {
    setTab("questions")
    setSelected(index)
    scrollToQuestion(index)
  }, [])

  const savedOptionValues = useMemo(() => {
    const values = new Set<string>()
    for (const f of loaded?.fields ?? []) for (const o of f.options) values.add(o.value)
    return values
  }, [loaded])

  const save = useCallback(async (): Promise<boolean> => {
    if (loaded === null || settings === null) return false
    if (blocking.length > 0) {
      setShowProblems(true)
      const first = blocking.find((p) => p.index !== null)
      if (first?.index != null) jump(first.index)
      env.notify(problemText(blocking[0]), "error")
      return false
    }
    setShowProblems(false)
    setSaving(true)
    const failed = t("builder.save.failed", "Your changes could not be saved.")
    let form = loaded.form
    let savedSettings = loaded.settings
    let savedFields = loaded.fields
    let nextFields = fields

    if (settingsChanged) {
      const sectionKeys = new Set(settings.sections.map((s) => s.key))
      nextFields = fields.map((f) => (f.section_key !== null && !sectionKeys.has(f.section_key) ? { ...f, section_key: null } : f))
      const result = await api.updateForm(
        form.id,
        {
          name: settings.name,
          description: hasText(settings.description) ? settings.description : null,
          submit_label: hasText(settings.submit_label) ? settings.submit_label : null,
          reference_format: settings.reference_format.trim() === "" ? null : settings.reference_format.trim(),
          sections: settings.sections,
        },
        failed
      )
      if (!result.ok) {
        setSaving(false)
        env.notify(result.message, "error")
        return false
      }
      form = { ...form, ...result.data }
      savedSettings = settings
    }

    if (fieldsChanged || nextFields !== fields) {
      const result = await api.replaceFields(form.id, nextFields.map(fieldBody), failed)
      if (!result.ok) {
        setSaving(false)
        const at = questionIndexIn(result.message)
        if (at !== null) jump(at)
        env.notify(
          at === null ? result.message : t("builder.save.failedAt", "Question {number}: {message}", { number: at + 1, message: result.message }),
          "error"
        )
        // The first half is saved; remember it so a retry does not repeat it.
        setLoaded({ ...loaded, form, settings: savedSettings })
        return false
      }
      savedFields = draftsOf(result.data)
      nextFields = savedFields
    }

    setSaving(false)
    setLoaded({ ...loaded, form, fields: savedFields, settings: savedSettings })
    setFields(nextFields === fields ? fields : nextFields)
    env.notify(t("builder.save.done", "Your changes were saved."), "success")
    return true
  }, [api, blocking, env, fields, fieldsChanged, jump, loaded, problemText, settings, settingsChanged, t])

  function discard() {
    if (loaded === null) return
    setFields(loaded.fields)
    setSettings(loaded.settings)
    setSelected(null)
  }

  async function changeStatus(action: "publish" | "archive") {
    if (loaded === null) return
    if (dirty && !(await save())) return
    const failed = t("builder.action.failed", "That did not work. Try again.")
    const result = action === "publish" ? await api.publish(formId, failed) : await api.archive(formId, failed)
    if (!result.ok) {
      env.notify(result.message, "error")
      return
    }
    setLoaded((current) => (current === null ? current : { ...current, form: { ...current.form, ...result.data } }))
    const undo = action === "publish" ? "archive" : "publish"
    env.notify(
      action === "publish" ? t("builder.published", "The form is open.") : t("builder.closed.one", "The form is closed."),
      "success",
      { label: t("builder.undo", "Undo"), onClick: () => void changeStatus(undo) }
    )
  }

  async function setPublicLink(enabled: boolean) {
    if (loaded === null) return
    setPublicBusy(true)
    const failed = t("builder.action.failed", "That did not work. Try again.")
    const result = enabled ? await api.enablePublicLink(formId, failed) : await api.disablePublicLink(formId, failed)
    setPublicBusy(false)
    if (!result.ok) {
      env.notify(result.message, "error")
      return
    }
    if (enabled && typeof result.data === "object") {
      const updated = result.data as FormRow
      setLoaded((current) => (current === null ? current : { ...current, form: { ...current.form, ...updated } }))
    } else {
      await load()
    }
  }

  async function setMemberAccess(open: boolean) {
    if (loaded === null) return
    setPublicBusy(true)
    const result = await api.openToMembers(formId, open, t("builder.action.failed", "That did not work. Try again."))
    setPublicBusy(false)
    if (!result.ok) {
      env.notify(result.message, "error")
      return
    }
    setLoaded((current) => (current === null ? current : { ...current, form: { ...current.form, ...result.data } }))
  }

  function copyLink() {
    const url = loaded?.form.public_url
    if (!url) return
    void navigator.clipboard?.writeText(url)
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
  if (failure !== null) {
    return failure.missing ? (
      <AreaError
        message={t("builder.record.missing", "This form is no longer here.")}
        retryLabel={t("builder.back", "Back to forms")}
        onRetry={() => env.navigate("/admin/x/forms-builder")}
      />
    ) : (
      <AreaError message={failure.message} retryLabel={t("builder.retry", "Try again")} onRetry={() => void load()} />
    )
  }
  if (loaded === null || settings === null) {
    return <LoadingRows headers={[t("builder.col.form", "Form")]} label={t("builder.loading.one", "Loading the form")} rows={3} />
  }

  const { form, kinds } = loaded
  const readOnly = !canManage
  const title = pickText(settings.name, currentLanguage) || form.form_key
  const description = pickText(settings.description, currentLanguage)
  const selectedField = selected !== null ? fields[selected] : undefined

  const panel =
    selectedField === undefined || selected === null ? (
      <p className="text-sm text-muted-foreground">
        {t("builder.panel.empty", "Choose a question to change it, or add one. The form's own settings are in the Settings tab.")}
      </p>
    ) : (
      <QuestionPanel
        field={selectedField}
        index={selected}
        fields={fields}
        kinds={kinds}
        sections={settings.sections}
        savedOptionValues={savedOptionValues}
        readOnly={readOnly}
        onChange={(next) => patchField(selected, next)}
        onRelabel={(label: BilingualValue) => setFields((current) => relabel(current, selected, label))}
      />
    )

  function patchField(index: number, next: FieldDraft) {
    setFields((current) => current.map((f, i) => (i === index ? next : f)))
  }

  return (
    <div className="space-y-6">
      <PageHeader
        breadcrumb={
          <nav aria-label={t("builder.breadcrumb", "Where you are")} className="flex items-center gap-1 text-sm text-muted-foreground">
            <Button variant="link" className="h-auto px-0" onClick={() => env.navigate("/admin/x/forms-builder")}>
              {t("builder.title", "Form builder")}
            </Button>
            <Icon name="chevron-right" size={14} className="rtl:rotate-180" aria-hidden />
            <span aria-current="page" className="text-foreground">
              {title}
            </span>
          </nav>
        }
        title={title}
        icon={<Icon name="form" size={20} />}
        badge={<Badge variant={statusVariant(form.status)}>{statusLabel(form.status)}</Badge>}
        description={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
            <span>
              {form.public_enabled
                ? t("builder.facts.link", "Anyone with the link can fill it in.")
                : form.status === "published"
                  ? t("builder.facts.members", "People who may fill in forms can fill it in.")
                  : t("builder.facts.draft", "Nobody can fill it in yet.")}
            </span>
            <span>{t("builder.facts.changed", "Changed {when}", { when: dateTime(form.updated_at) ?? "" })}</span>
          </span>
        }
        action={
          canManage ? (
            <div className="flex flex-wrap gap-2">
              {form.status === "published" && (
                <Button variant="outline" onClick={() => window.open(`/forms/${form.id}`, "_blank", "noopener")}>
                  {t("builder.preview", "Preview")}
                </Button>
              )}
              {form.status === "published" && (
                <Button variant="outline" onClick={() => void changeStatus("archive")}>
                  {t("builder.closeForm", "Close the form")}
                </Button>
              )}
              {form.status === "draft" && (
                <Button
                  onClick={() => {
                    if (blocking.length > 0) {
                      setShowProblems(true)
                      const first = blocking.find((p) => p.index !== null)
                      if (first?.index != null) jump(first.index)
                      return
                    }
                    setPublishOpen(true)
                  }}
                >
                  {t("builder.publish", "Publish")}
                </Button>
              )}
              {form.status === "archived" && <Button onClick={() => void changeStatus("publish")}>{t("builder.reopen", "Reopen the form")}</Button>}
            </div>
          ) : undefined
        }
      />

      {readOnly && (
        <ReadOnlyNotice
          title={t("builder.readOnly.title", "You can look, not change")}
          description={t("builder.readOnly.body", "Ask an administrator for permission to manage forms.")}
        />
      )}

      {showProblems && <ProblemList fields={fields} problems={blocking} onJump={jump} />}

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="overflow-visible">
          <TabsTrigger value="questions">{t("builder.tab.questions", "Questions")}</TabsTrigger>
          <TabsTrigger value="responses">{t("builder.tab.responses", "Responses")}</TabsTrigger>
          <TabsTrigger value="settings">{t("builder.tab.settings", "Settings")}</TabsTrigger>
        </TabsList>

        <TabsContent value="questions" className="pt-4">
          <div className="grid overflow-hidden rounded-card border border-border bg-muted lg:grid-cols-[minmax(0,1fr)_26rem]">
            <BuilderCanvas
              title={title}
              description={description}
              fields={fields}
              sections={settings.sections}
              kinds={kinds}
              selected={selected}
              readOnly={readOnly}
              onSelect={setSelected}
              onAdd={(kind, sectionKey) => {
                setFields((current) => [...current, newField(kind, current, sectionKey)])
                setSelected(fields.length)
                setFocusWording((n) => n + 1)
              }}
              onMove={(index, direction) => {
                const next = moveField(fields, index, direction)
                setFields(next)
                setSelected(next.indexOf(fields[index]))
              }}
              onDuplicate={(index) => {
                setFields(duplicateField(fields, index))
                setSelected(index + 1)
              }}
              invalid={invalid}
              onReorder={(from, to) => {
                setFields(reorder(fields, from, to))
                setSelected(to)
              }}
              onDelete={(index) => {
                setFields(removeField(fields, index))
                setSelected(null)
              }}
            />
            {wide && (
              <aside className="border-t border-border bg-card p-5 lg:border-s lg:border-t-0">
                <div className="lg:sticky lg:top-4">{panel}</div>
              </aside>
            )}
          </div>
        </TabsContent>

        <TabsContent value="responses" className="pt-4">
          <ResponsesTab env={env} formId={form.id} />
        </TabsContent>

        <TabsContent value="settings" className="pt-4">
          <FormSettingsTab
            form={form}
            settings={settings}
            readOnly={readOnly}
            publicBusy={publicBusy}
            onChange={setSettings}
            onPublicLink={(enabled) => void setPublicLink(enabled)}
            onCopyLink={copyLink}
            onMemberAccess={(open) => void setMemberAccess(open)}
          />
        </TabsContent>
      </Tabs>

      {!wide && selectedField !== undefined && selected !== null && tab === "questions" && (
        <Sheet open onOpenChange={(open) => !open && setSelected(null)}>
          <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-md">
            <SheetHeader>
              <SheetTitle>{t("builder.panel.title", "Question settings")}</SheetTitle>
              <SheetDescription>{t("builder.panel.sheet", "Changes apply as you type. Save them with the bar at the bottom.")}</SheetDescription>
            </SheetHeader>
            <div className="p-4">{panel}</div>
          </SheetContent>
        </Sheet>
      )}

      <ConfirmAction
        open={publishOpen}
        onOpenChange={setPublishOpen}
        title={t("builder.publish.title", "Open this form?")}
        description={
          <span className="block space-y-2">
            <span className="block">
              {t("builder.publish.body", "People who may fill in forms will be able to fill it in. It has {count} questions, {required} of them required. You can close it again at any time.", {
                count: fields.length,
                required: fields.filter((f) => f.is_required).length,
              })}
            </span>
            {problems.length > 0 && (
              <span className="block">
                {t("builder.publish.warnings", "Worth a look first:")} {problems.slice(0, 5).map(problemText).join(" ")}
                {problems.length > 5 ? ` ${t("builder.publish.more", "And {count} more.", { count: problems.length - 5 })}` : ""}
              </span>
            )}
          </span>
        }
        confirmLabel={t("builder.publish.confirm", "Publish form")}
        cancelLabel={t("builder.cancel", "Cancel")}
        variant="default"
        onConfirm={() => void changeStatus("publish")}
      />

      <SaveBar
        dirty={dirty}
        saving={saving}
        summary={t("builder.save.summary", "You have changes that are not saved yet.")}
        discardLabel={t("builder.save.discard", "Discard changes")}
        saveLabel={t("builder.save.save", "Save changes")}
        onDiscard={discard}
        onSave={() => void save()}
      />
    </div>
  )
}
