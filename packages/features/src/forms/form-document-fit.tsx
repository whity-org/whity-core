"use client"

import { useMemo, useState } from "react"
import { Alert, AlertDescription, AlertTitle } from "@whity/ui/alert"
import { Button } from "@whity/ui/button"
import { Checkbox } from "@whity/ui/checkbox"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@whity/ui/dialog"
import { Input } from "@whity/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@whity/ui/select"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { pickText } from "./text"
import type { DocumentFit, DocumentFitNeed, FieldDraft } from "./types"

/** The check boxes of one list. Everything starts ticked, so only what was unticked is kept. */
function useUnticked() {
  const [unticked, setUnticked] = useState<ReadonlySet<string>>(new Set())
  return {
    isTicked: (key: string) => !unticked.has(key),
    set: (key: string, ticked: boolean) =>
      setUnticked((current) => {
        const next = new Set(current)
        if (ticked) next.delete(key)
        else next.add(key)
        return next
      }),
  }
}

function TickRow({ id, label, checked, disabled, onChange }: { id: string; label: string; checked: boolean; disabled: boolean; onChange: (checked: boolean) => void }) {
  return (
    <li className="flex items-center gap-2">
      <Checkbox id={id} checked={checked} disabled={disabled} onCheckedChange={(value) => onChange(value === true)} />
      <label htmlFor={id} dir="auto" className="text-sm">
        {label}
      </label>
    </li>
  )
}

/** What has to be said about a template field the form can't fill by adding a question. */
function useReasonText() {
  const t = useTranslation("forms")
  return (need: DocumentFitNeed) =>
    need.reason === "staff_only"
      ? t("builder.fit.reason.staff", "Only your team answers this question, so it can't print.")
      : t("builder.fit.reason.record", "This comes from another kind of record, so this form can't fill it.")
}

/**
 * Under the form's final document template: whether the template and the form
 * fit, in both directions, and the ways of closing a gap.
 *
 * Questions added here go into the builder like any other, so nothing reaches
 * the form until it is saved. Questions added to a template go into its draft,
 * which prints nothing until somebody publishes it.
 */
export function FormDocumentFit({
  fit,
  fields,
  formClosed,
  formOpen,
  readOnly,
  busy,
  dirty,
  onAddToForm,
  onAddToTemplate,
  onLeaveOut,
  onAskAgain,
  onOpenTemplate,
}: {
  fit: DocumentFit
  fields: readonly FieldDraft[]
  /** Questions can't be changed on an archived form. */
  formClosed: boolean
  /** People can already fill it in. */
  formOpen: boolean
  readOnly: boolean
  busy: boolean
  dirty: boolean
  onAddToForm: (needs: DocumentFitNeed[]) => void
  onAddToTemplate: (keys: string[]) => void
  onLeaveOut: (keys: string[]) => void
  onAskAgain: () => void
  onOpenTemplate: (id: number) => void
}) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const reasonText = useReasonText()
  const neededTicks = useUnticked()
  const unusedTicks = useUnticked()

  // A question just added here isn't saved yet, but it does answer the warning.
  const needs = useMemo(() => {
    const asked = new Set(fields.map((f) => f.field_key))
    return fit.needs.filter((need) => need.field_key === null || !asked.has(need.field_key))
  }, [fit.needs, fields])
  const addable = needs.filter((need) => need.field_key !== null)
  const blocked = needs.filter((need) => need.field_key === null)
  const questionText = (label: DocumentFit["unused"][number]["label"], key: string) => pickText(label, currentLanguage) || key

  const waiting = fit.unused.filter((q) => q.in_draft)
  const leftOut = fit.unused.filter((q) => q.left_out && !q.in_draft)
  const unused = fit.unused.filter((q) => !q.in_draft && !q.left_out)

  const chosenNeeds = addable.filter((need) => neededTicks.isTicked(need.path))
  const chosenUnused = unused.filter((q) => unusedTicks.isTicked(q.field_key)).map((q) => q.field_key)
  const canAddQuestions = !readOnly && !formClosed
  const allFits = needs.length === 0 && fit.unused.length === 0

  return (
    <div className="space-y-3" data-testid="document-fit">
      {!fit.template.published && (
        <Alert variant="warning">
          <AlertDescription>
            {t("builder.fit.unpublished", "This template isn't published yet, so no final document can be made until it is.")}
          </AlertDescription>
        </Alert>
      )}

      {allFits && fit.template.published && (
        <p className="text-sm text-muted-foreground">
          {t("builder.fit.ok", "Every field on this template has an answer, and every question has a place on it.")}
        </p>
      )}

      {needs.length > 0 && (
        <Alert variant="warning" data-testid="document-fit-needs">
          <AlertTitle>{t("builder.fit.needs.title", "The template asks for things this form doesn't collect")}</AlertTitle>
          <AlertDescription className="space-y-3">
            <p>{t("builder.fit.needs.body", "These would print empty on the final document. Do you want to add them as questions?")}</p>
            {addable.length > 0 && (
              <ul className="space-y-2">
                {addable.map((need) => (
                  <TickRow
                    key={need.path}
                    id={`fit-need-${need.path}`}
                    label={need.label}
                    checked={neededTicks.isTicked(need.path)}
                    disabled={!canAddQuestions}
                    onChange={(ticked) => neededTicks.set(need.path, ticked)}
                  />
                ))}
              </ul>
            )}
            {blocked.length > 0 && (
              <ul className="space-y-1">
                {blocked.map((need) => (
                  <li key={need.path} className="text-sm">
                    <span dir="auto" className="font-medium">
                      {need.label}
                    </span>
                    {": "}
                    <span className="text-muted-foreground">{reasonText(need)}</span>
                  </li>
                ))}
              </ul>
            )}
            {formClosed && <p>{t("builder.fit.needs.closed", "This form is closed. Reopen it to add questions.")}</p>}
            {canAddQuestions && formOpen && addable.length > 0 && (
              <p>{t("builder.fit.needs.open", "This form is open. New questions start as optional and reach people once you save them.")}</p>
            )}
            {canAddQuestions && addable.length > 0 && (
              <Button variant="outline" disabled={chosenNeeds.length === 0} onClick={() => onAddToForm(chosenNeeds)}>
                {t("builder.fit.needs.add", "Add to the form")}
              </Button>
            )}
          </AlertDescription>
        </Alert>
      )}

      {unused.length > 0 && (
        <Alert variant="warning" data-testid="document-fit-unused">
          <AlertTitle>{t("builder.fit.unused.title", "Some questions have no place in this template")}</AlertTitle>
          <AlertDescription className="space-y-3">
            <p>{t("builder.fit.unused.body", "These answers won't appear on the final document. Add them to the template, or leave them out.")}</p>
            <ul className="space-y-2">
              {unused.map((q) => (
                <TickRow
                  key={q.field_key}
                  id={`fit-unused-${q.field_key}`}
                  label={questionText(q.label, q.field_key)}
                  checked={unusedTicks.isTicked(q.field_key)}
                  disabled={readOnly}
                  onChange={(ticked) => unusedTicks.set(q.field_key, ticked)}
                />
              ))}
            </ul>
            {!readOnly && !fit.can_edit_template && (
              <p>{t("builder.fit.unused.noPermission", "You can't change templates. Ask someone who can, or leave these out.")}</p>
            )}
            {!readOnly && (
              <div className="flex flex-wrap gap-2">
                {fit.can_edit_template && (
                  <Button variant="outline" disabled={busy || chosenUnused.length === 0} onClick={() => onAddToTemplate(chosenUnused)}>
                    {t("builder.fit.unused.add", "Add to the template")}
                  </Button>
                )}
                <Button variant="outline" disabled={busy || chosenUnused.length === 0} onClick={() => onLeaveOut(chosenUnused)}>
                  {t("builder.fit.unused.leaveOut", "Leave out of the document")}
                </Button>
              </div>
            )}
          </AlertDescription>
        </Alert>
      )}

      {waiting.length > 0 && (
        <Alert variant="info" data-testid="document-fit-waiting">
          <AlertTitle>{t("builder.fit.draft.title", "Waiting to be published")}</AlertTitle>
          <AlertDescription className="space-y-3">
            <p dir="auto">{waiting.map((q) => questionText(q.label, q.field_key)).join("، ")}</p>
            <p>
              {t(
                "builder.fit.draft.body",
                "These are in the template's draft. Open the template to check where they sit, then publish it. Until then the document prints as it did."
              )}
            </p>
            <Button variant="outline" onClick={() => onOpenTemplate(fit.template.id)}>
              {t("builder.fit.draft.open", "Open the template")}
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {leftOut.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground" data-testid="document-fit-left-out">
          <span>
            {t("builder.fit.leftOut", "Left out of the document on purpose:")}{" "}
            <span dir="auto">{leftOut.map((q) => questionText(q.label, q.field_key)).join("، ")}</span>
          </span>
          {!readOnly && (
            <Button variant="link" className="h-auto px-0" disabled={busy} onClick={onAskAgain}>
              {t("builder.fit.leftOut.again", "Ask about these again")}
            </Button>
          )}
        </div>
      )}

      {fit.route_uses_other_template && (
        <p className="text-sm text-muted-foreground">
          {t("builder.fit.routeOther", "This form's route chooses its own template for the final document, so this check doesn't cover that one.")}
        </p>
      )}
      {dirty && (
        <p className="text-sm text-muted-foreground">{t("builder.fit.saveFirst", "You have unsaved changes. Save them to check this again.")}</p>
      )}
    </div>
  )
}

/** "No template fits": make one from the form, with its questions already in place. */
export function MakeTemplateFromForm({
  defaultTitle,
  disabled,
  onMake,
}: {
  defaultTitle: (language: "en" | "ar") => string
  disabled: boolean
  /** Resolves true when the template was made and chosen. */
  onMake: (title: string, language: "en" | "ar") => Promise<boolean>
}) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const startLanguage: "en" | "ar" = currentLanguage === "ar" ? "ar" : "en"
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [language, setLanguage] = useState<"en" | "ar">(startLanguage)
  const [title, setTitle] = useState("")

  function show() {
    setLanguage(startLanguage)
    setTitle(defaultTitle(startLanguage))
    setOpen(true)
  }

  async function make() {
    setBusy(true)
    const made = await onMake(title.trim(), language)
    setBusy(false)
    if (made) setOpen(false)
  }

  return (
    <div className="space-y-1">
      <Button variant="outline" disabled={disabled} onClick={show}>
        {t("builder.fromForm.action", "Make a template from this form")}
      </Button>
      <p className="text-xs text-muted-foreground">
        {t("builder.fromForm.hint", "No template that fits? Whity can make one with every question already in place.")}
      </p>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("builder.fromForm.title", "Make a template from this form")}</DialogTitle>
            <DialogDescription>
              {t(
                "builder.fromForm.body",
                "It will list every question people answer, in order. Whity publishes it and chooses it for this form's final document. You can change how it looks afterwards."
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-1">
              <label htmlFor="from-form-title" className="text-sm font-medium">
                {t("builder.fromForm.name", "Template name")}
              </label>
              <Input id="from-form-title" dir="auto" value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} />
            </div>
            <div className="space-y-1">
              <label htmlFor="from-form-language" className="text-sm font-medium">
                {t("builder.fromForm.language", "Language of the document")}
              </label>
              <Select
                value={language}
                onValueChange={(value) => {
                  const next = value === "ar" ? "ar" : "en"
                  // Follow the language, unless the person has written their own name.
                  if (title.trim() === defaultTitle(language)) setTitle(defaultTitle(next))
                  setLanguage(next)
                }}
              >
                <SelectTrigger id="from-form-language">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="en">{t("builder.lang.en", "English")}</SelectItem>
                  <SelectItem value="ar">{t("builder.lang.ar", "Arabic")}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              {t("builder.cancel", "Cancel")}
            </Button>
            <Button disabled={busy || title.trim() === ""} onClick={() => void make()}>
              {t("builder.fromForm.confirm", "Make the template")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
