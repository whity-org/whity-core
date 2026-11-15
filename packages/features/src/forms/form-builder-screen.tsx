"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { Icon } from "@whity/ui/icon"
import { PageHeader } from "@whity/ui/page-header"
import { AccessDeniedPanel, AreaError, LoadingRows, ReadOnlyNotice } from "@whity/ui/page-states"
import { ConfirmAction } from "@whity/ui/confirm-action"
import { SaveBar } from "@whity/ui/save-bar"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@whity/ui/sheet"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@whity/ui/tabs"
import { cn } from "@whity/ui/utils"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { useDateDisplay } from "../datetime"
import { createFormsApi, fieldBody } from "./api"
import type { BilingualValue } from "./bilingual-field"
import { BuilderCanvas, scrollToQuestion } from "./builder-canvas"
import { FormLanguageProvider, useFormLanguages } from "./form-languages"
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
import { FormDocumentFit, MakeTemplateFromForm } from "./form-document-fit"
import { FormSettingsTab, type FormSettings } from "./form-settings-tab"
import { useStatusLabel } from "./forms-list-screen"
import { QuestionPanel } from "./question-panel"
import { ResponsesTab } from "./responses-tab"
import { asMap, pickText, statusVariant } from "./text"
import type { DocumentFit, DocumentFitNeed, FieldDraft, FieldKind, FinalTemplateOption, FormRow, FormSectionDef, FormsScreenEnv } from "./types"

interface Loaded {
  form: FormRow
  fields: FieldDraft[]
  /** What the server last accepted, as it was sent. Whatever it does to that afterwards isn't a change. */
  savedBodies: string
  settings: FormSettings
  kinds: FieldKind[]
}

function settingsOf(
  form: FormRow,
  rendered: Array<{ key: string | null; title?: unknown; description?: unknown }>,
  fields: FieldDraft[] = []
): FormSettings {
  const sections: FormSectionDef[] = rendered
    .filter((s): s is { key: string; title?: unknown; description?: unknown } => typeof s.key === "string")
    .map((s) => ({
      key: s.key,
      title: asMap(s.title as FormSectionDef["title"] | []),
      description: asMap(s.description as FormSectionDef["description"] | []),
    }))
  // A section can exist only as the key its questions carry, with no title stored
  // (a form imported through the API, such as an ethics application). It is still a
  // section: list it, untitled, so it can be named here and so saving the settings
  // doesn't take every question out of it.
  const listed = new Set(sections.map((s) => s.key))
  for (const field of fields) {
    if (field.section_key !== null && !listed.has(field.section_key)) {
      listed.add(field.section_key)
      sections.push({ key: field.section_key, title: {}, description: {} })
    }
  }
  return {
    name: asMap(form.name),
    title: asMap(form.title),
    banner: form.banner ?? null,
    description: asMap(form.description_i18n),
    submit_label: asMap(form.submit_label),
    reference_format: form.reference_format ?? "",
    reference_assign: form.reference_assign === "approval" ? "approval" : "submit",
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
export function FormBuilderScreen(props: { env: FormsScreenEnv; formId: number; canManage: boolean }) {
  return (
    <FormLanguageProvider formId={props.formId}>
      <FormBuilder {...props} />
    </FormLanguageProvider>
  )
}

function FormBuilder({ env, formId, canManage }: { env: FormsScreenEnv; formId: number; canManage: boolean }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const statusLabel = useStatusLabel()
  const { dateTime, time } = useDateDisplay()
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
    const next = settingsOf(form, form.sections as unknown as Array<{ key: string | null; title?: unknown; description?: unknown }>, drafts)
    setLoaded({ form, fields: drafts, savedBodies: snapshot(drafts.map(fieldBody)), settings: next, kinds })
    setFields(drafts)
    setSettings(next)
    setSelected(null)
  }, [api, formId, t])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the fetch on mount is the effect
    void load()
  }, [load])

  const bodies = snapshot(fields.map(fieldBody))
  const fieldsChanged = loaded !== null && bodies !== loaded.savedBodies
  const settingsChanged = loaded !== null && settings !== null && snapshot(settings) !== snapshot(loaded.settings)
  const dirty = fieldsChanged || settingsChanged
  // A save the server refused waits for the next edit. Sending it again every few
  // seconds would only repeat the same message.
  const attempt = `${bodies}|${snapshot(settings)}`
  const [refused, setRefused] = useState<string | null>(null)

  const [showProblems, setShowProblems] = useState(false)
  const [savedAt, setSavedAt] = useState<Date | null>(null)
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
  // The side panel keeps its own scroll (WHIT-1713); a newly chosen question
  // starts at the top of its settings, not wherever the last one was left.
  const panelScroll = useRef<HTMLDivElement>(null)
  useEffect(() => {
    if (panelScroll.current !== null) panelScroll.current.scrollTop = 0
  }, [selected])

  const { enabled: enabledLanguages } = useFormLanguages()
  const problems = useMemo(() => problemsOf(fields, settings?.name ?? {}, enabledLanguages), [fields, settings, enabledLanguages])
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

  const save = useCallback(async (quiet = false): Promise<boolean> => {
    if (loaded === null || settings === null) return false
    if (blocking.length > 0) {
      if (quiet) return false
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
    let savedBodies = loaded.savedBodies
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
          title: hasText(settings.title) ? settings.title : null,
          // An uploaded picture goes back as `{ kind: "image" }`, which keeps it; its
          // URL is the server's to give.
          banner:
            settings.banner === null
              ? null
              : settings.banner.kind === "image"
                ? { kind: "image" }
                : { kind: "pattern", style: settings.banner.style, seed: settings.banner.seed },
          reference_format: settings.reference_format.trim() === "" ? null : settings.reference_format.trim(),
          reference_assign: settings.reference_assign,
          sections: settings.sections,
        },
        failed
      )
      if (!result.ok) {
        setSaving(false)
        setRefused(attempt)
        env.notify(result.message, "error")
        return false
      }
      form = { ...form, ...result.data }
      savedSettings = settings
    }

    if (fieldsChanged || nextFields !== fields) {
      const sent = nextFields.map(fieldBody)
      const result = await api.replaceFields(form.id, sent, failed)
      if (!result.ok) {
        setSaving(false)
        setRefused(attempt)
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
      savedBodies = snapshot(sent)
      nextFields = savedFields
    }

    setSaving(false)
    setLoaded({ ...loaded, form, fields: savedFields, savedBodies, settings: savedSettings })
    // Not `setFields(fields)`: that is the list as it was when the save began, and
    // whatever was typed since would be thrown away. Saved questions only gain
    // their ids, so their keys are locked from here on.
    const idOf = new Map(savedFields.map((f) => [f.field_key, f.id]))
    const keptSections = new Set(settings.sections.map((section) => section.key))
    setFields((current) =>
      current.map((f) => ({
        ...f,
        id: f.id ?? idOf.get(f.field_key),
        section_key: f.section_key !== null && !keptSections.has(f.section_key) ? null : f.section_key,
      }))
    )
    if (quiet) setSavedAt(new Date())
    else env.notify(t("builder.save.done", "Your changes were saved."), "success")
    return true
  }, [api, attempt, blocking, env, fields, fieldsChanged, jump, loaded, problemText, settings, settingsChanged, t])

  // A draft is not live, so it saves itself a moment after you pause. An open form
  // does not: its questions are in front of people the moment they are saved.
  const autosaveReady = canManage && loaded !== null && loaded.form.status === "draft" && dirty && !saving && blocking.length === 0 && attempt !== refused
  useEffect(() => {
    if (!autosaveReady) return
    const id = window.setTimeout(() => void save(true), 2500)
    return () => window.clearTimeout(id)
  }, [autosaveReady, fields, settings, save])

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

  // WHIT-921: the templates a form's final document can be made with.
  const [finalTemplates, setFinalTemplates] = useState<FinalTemplateOption[]>([])
  useEffect(() => {
    let live = true
    void api.listFinalTemplates(t("builder.action.failed", "That did not work. Try again.")).then((result) => {
      if (live && result.ok) setFinalTemplates(result.data)
    })
    return () => {
      live = false
    }
  }, [api, t])

  /** WHIT-921: the form's final document template, and whether the applicant gets the final document. */
  async function setFinalDocument(changes: { final_template_id?: number | null; final_document_to_applicant?: boolean; document_left_out?: string[] }) {
    if (loaded === null) return
    setPublicBusy(true)
    const result = await api.updateForm(formId, changes, t("builder.action.failed", "That did not work. Try again."))
    setPublicBusy(false)
    if (!result.ok) {
      env.notify(result.message, "error")
      return
    }
    setLoaded((current) => (current === null ? current : { ...current, form: { ...current.form, ...result.data } }))
  }

  // How the chosen template and the form fit. Fetched again when either changes.
  const [documentFit, setDocumentFit] = useState<DocumentFit | null>(null)
  const finalTemplateId = loaded?.form.final_template_id ?? null
  const savedFields = loaded?.fields
  const leftOut = loaded?.form.document_left_out
  useEffect(() => {
    if (finalTemplateId === null || !canManage) {
      setDocumentFit(null)
      return
    }
    let live = true
    void api.documentFit(formId, t("builder.action.failed", "That did not work. Try again.")).then((result) => {
      if (live) setDocumentFit(result.ok ? result.data : null)
    })
    return () => {
      live = false
    }
  }, [api, canManage, finalTemplateId, formId, leftOut, savedFields, t])

  /** Add questions for what the template asks, unsaved, as if they had been added by hand. */
  function addQuestionsFor(needs: DocumentFitNeed[]) {
    const language = documentFit?.template.lang === "ar" ? "ar" : "en"
    setFields((current) => {
      const next = [...current]
      for (const need of needs) {
        if (need.field_key === null) continue
        next.push({ ...newField("text", next, null), field_key: need.field_key, label: { [language]: need.label } })
      }
      return next
    })
    env.notify(t("builder.fit.needs.added", "Added to the questions. Check them, then save."), "success", {
      label: t("builder.fit.needs.show", "Show questions"),
      onClick: () => setTab("questions"),
    })
  }

  async function addToTemplate(keys: string[]) {
    setPublicBusy(true)
    const result = await api.addToTemplate(formId, keys, t("builder.action.failed", "That did not work. Try again."))
    setPublicBusy(false)
    if (!result.ok) {
      env.notify(
        result.status === 409 ? t("builder.fit.unused.conflict", "Someone changed the template just now. Try again.") : result.message,
        "error"
      )
      return
    }
    setDocumentFit(result.data)
    env.notify(t("builder.fit.unused.added", "Added to the template's draft. Publish the template for them to print."), "success")
  }

  async function setLeftOut(keys: string[]) {
    await setFinalDocument({ document_left_out: keys })
  }

  async function makeTemplate(title: string, language: "en" | "ar"): Promise<boolean> {
    const result = await api.makeTemplate(formId, title, language, t("builder.action.failed", "That did not work. Try again."))
    if (!result.ok) {
      env.notify(
        result.status === 403 ? t("builder.fromForm.denied", "You need permission to publish document templates to do this.") : result.message,
        "error"
      )
      return false
    }
    const made = result.data
    setFinalTemplates((current) => [...current, made])
    setLoaded((current) => (current === null ? current : { ...current, form: { ...current.form, final_template_id: made.id, document_left_out: [] } }))
    env.notify(t("builder.fromForm.done", "The template is made and chosen for this form."), "success", {
      label: t("builder.fit.draft.open", "Open the template"),
      onClick: () => env.navigate(`/admin/document-sources/${made.id}`),
    })
    return true
  }

  /** WHIT-920: the public link asks visitors to sign in, so their submission can be sent back to them. */
  async function setSignInRequired(required: boolean) {
    if (loaded === null) return
    setPublicBusy(true)
    const result = await api.updateForm(formId, { public_requires_sign_in: required }, t("builder.action.failed", "That did not work. Try again."))
    setPublicBusy(false)
    if (!result.ok) {
      env.notify(result.message, "error")
      return
    }
    setLoaded((current) => (current === null ? current : { ...current, form: { ...current.form, ...result.data } }))
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

  /**
   * Upload a picture as the banner. The server keeps it at once, so it is set on
   * both the saved and the edited settings and doesn't show as an unsaved change.
   * Resolves true when it was kept.
   */
  async function uploadBanner(file: File): Promise<boolean> {
    const result = await api.uploadBanner(formId, file, t("builder.banner.uploadFailed", "The picture could not be uploaded. Try again."))
    if (!result.ok) {
      env.notify(
        result.status === 422
          ? t("builder.banner.refused", "Use a PNG, JPEG or WebP picture of 5 MB or less.")
          : result.message,
        "error"
      )
      return false
    }
    const banner = result.data
    setLoaded((current) =>
      current === null ? current : { ...current, form: { ...current.form, banner }, settings: { ...current.settings, banner } }
    )
    setSettings((current) => (current === null ? current : { ...current, banner }))
    return true
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
  // The canvas shows the form as the person filling it in sees it.
  const shownTitle = pickText(settings.title, currentLanguage) || title
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
            {savedAt !== null && !dirty && <span>{t("builder.autosaved", "Saved at {time}", { time: time(savedAt.toISOString()) ?? "" })}</span>}
          </span>
        }
        action={
          canManage ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" disabled={dirty} onClick={() => window.open(`/forms/${form.id}/preview`, "_blank", "noopener")}>
                {t("builder.preview", "Preview")}
              </Button>
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
          {/* `overflow-clip`, not `overflow-hidden`: hidden makes this box a scroll
              container, and then the panel's `sticky` sticks to it instead of the
              page and scrolls away with the questions (WHIT-1713). */}
          <div className="grid overflow-clip rounded-card border border-border bg-muted lg:grid-cols-[minmax(0,1fr)_26rem]">
            <BuilderCanvas
              title={shownTitle}
              description={description}
              banner={settings.banner}
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
              <aside aria-label={t("builder.panel.title", "Question settings")} className="border-t border-border bg-card lg:border-s lg:border-t-0">
                {/* Sticks below the top bar while the questions scroll, and scrolls
                    by itself when the settings are taller than the window. While
                    the save bar shows, it stops above it. */}
                <div
                  ref={panelScroll}
                  data-testid="question-panel-scroll"
                  className={cn(
                    "p-5 lg:sticky lg:top-20 lg:overflow-y-auto lg:overscroll-contain",
                    dirty ? "lg:max-h-[calc(100dvh-10rem)]" : "lg:max-h-[calc(100dvh-6rem)]",
                  )}
                >
                  {panel}
                </div>
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
            onSignInRequired={(required) => void setSignInRequired(required)}
            onUploadBanner={uploadBanner}
            finalTemplates={finalTemplates}
            onFinalDocument={(changes) => void setFinalDocument(changes)}
            documentFit={
              <>
                {documentFit !== null && (
                  <FormDocumentFit
                    fit={documentFit}
                    fields={fields}
                    formClosed={form.status === "archived"}
                    formOpen={form.status === "published"}
                    readOnly={readOnly}
                    busy={publicBusy}
                    dirty={dirty}
                    onAddToForm={addQuestionsFor}
                    onAddToTemplate={(keys) => void addToTemplate(keys)}
                    onLeaveOut={(keys) => void setLeftOut([...(form.document_left_out ?? []), ...keys])}
                    onAskAgain={() => void setLeftOut([])}
                    onOpenTemplate={(id) => env.navigate(`/admin/document-sources/${id}`)}
                  />
                )}
                {!readOnly && (
                  <MakeTemplateFromForm
                    disabled={publicBusy || dirty || fields.length === 0}
                    defaultTitle={(language) => pickText(settings.name, language) || title}
                    onMake={makeTemplate}
                  />
                )}
              </>
            }
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
            {documentFit !== null && documentFit.needs.some((need) => !fields.some((f) => f.field_key === need.field_key)) && (
              <span className="block">
                {t("builder.publish.documentGaps", "The final document has fields this form doesn't collect, and they would print empty. You can see them in Settings.")}
              </span>
            )}
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
        summary={
          loaded.form.status === "draft"
            ? t("builder.save.summary.draft", "Saving by itself when you pause.")
            : t("builder.save.summary", "You have changes that are not saved yet.")
        }
        discardLabel={t("builder.save.discard", "Discard changes")}
        saveLabel={t("builder.save.save", "Save changes")}
        onDiscard={discard}
        onSave={() => void save()}
      />
    </div>
  )
}
