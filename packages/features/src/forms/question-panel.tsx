"use client"

import type { ReactNode } from "react"
import { Badge } from "@whity/ui/badge"
import type { BilingualValue } from "@whity/ui/bilingual-input"
import { BilingualField } from "./bilingual-field"
import { Button } from "@whity/ui/button"
import { Checkbox } from "@whity/ui/checkbox"
import { IconButton } from "@whity/ui/icon-button"
import { Input } from "@whity/ui/input"
import { Switch } from "@whity/ui/switch"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { ChoiceSelect } from "./choice-select"
import { blankOption, canControl, CHOICE_KINDS, controllersFor, optionValueFrom } from "./builder-model"
import { useKindLabel } from "./kind-labels"
import { asMap, pickText } from "./text"
import type { FieldColumn, FieldDraft, FieldKind, FieldValidation, FormSectionDef } from "./types"

const NO_SECTION = "__none__"
const ALWAYS = "__always__"
const COLUMN_KINDS: FieldKind[] = ["text", "textarea", "number", "date", "select", "checkbox"]

function Row({ id, label, hint, children }: { id?: string; label: string; hint?: string; children: ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      {children}
      {hint !== undefined && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

function rules(field: FieldDraft): FieldValidation {
  return Array.isArray(field.validation) ? {} : field.validation
}

/** The side panel: one question's type, wording, choices and rules. */
export function QuestionPanel({
  field,
  index,
  fields,
  kinds,
  sections,
  savedOptionValues,
  readOnly,
  onChange,
  onRelabel,
}: {
  field: FieldDraft
  index: number
  fields: readonly FieldDraft[]
  /** The kinds this server can store. */
  kinds: readonly FieldKind[]
  sections: readonly FormSectionDef[]
  /** Choice values already saved: their stored text must not change. */
  savedOptionValues: ReadonlySet<string>
  readOnly: boolean
  onChange: (next: FieldDraft) => void
  onRelabel: (label: BilingualValue) => void
}) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const kindLabel = useKindLabel()
  const validation = rules(field)
  const controllers = controllersFor(fields, index)
  const controller = controllers.find((c) => c.field_key === field.visible_when?.field) ?? null
  const answerValue =
    field.visible_when === null
      ? ""
      : field.visible_when.equals !== undefined
        ? String(field.visible_when.equals)
        : (field.visible_when.in ?? [])[0] ?? ""

  function setRules(patch: Partial<FieldValidation>) {
    const merged: FieldValidation = { ...validation, ...patch }
    for (const key of Object.keys(merged) as Array<keyof FieldValidation>) {
      if (merged[key] === undefined || merged[key] === null) delete merged[key]
    }
    onChange({ ...field, validation: merged })
  }

  function setOptionLabel(optionIndex: number, label: BilingualValue) {
    const options = field.options.map((o, i) => {
      if (i !== optionIndex) return o
      const locked = savedOptionValues.has(o.value)
      return { ...o, label, value: locked ? o.value : optionValueFrom(label, field.options, o.value) }
    })
    onChange({ ...field, options })
  }

  function setColumn(columnIndex: number, patch: Partial<FieldColumn>) {
    const columns = (validation.columns ?? []).map((c, i) => (i === columnIndex ? { ...c, ...patch } : c))
    setRules({ columns })
  }

  function setCondition(controllerKey: string | null, answer: string) {
    if (controllerKey === null) {
      onChange({ ...field, visible_when: null })
      return
    }
    const source = controllers.find((c) => c.field_key === controllerKey)
    const value: string | boolean = source?.field_type === "checkbox" ? answer !== "false" : answer
    onChange({ ...field, visible_when: { field: controllerKey, equals: value } })
  }

  const answerChoices =
    controller === null
      ? []
      : controller.field_type === "checkbox"
        ? [
            { value: "true", label: t("builder.panel.yes", "Yes") },
            { value: "false", label: t("builder.panel.no", "No") },
          ]
        : controller.options.map((o) => ({ value: o.value, label: pickText(o.label, currentLanguage) || o.value }))

  return (
    <div className="space-y-5" aria-label={t("builder.panel.title", "Question settings")} role="group">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold">{t("builder.panel.heading", "Question")}</h3>
        <Badge variant="outline">{t("builder.panel.number", "Question {number}", { number: index + 1 })}</Badge>
      </div>
      <Row id="q-kind" label={t("builder.panel.type", "Type")}>
        <ChoiceSelect
          id="q-kind"
          value={field.field_type}
          disabled={readOnly}
          options={kinds.map((k) => ({ value: k, label: kindLabel(k) }))}
          onChange={(next) => {
            const kind = next as FieldKind
            onChange({
              ...field,
              field_type: kind,
              options: CHOICE_KINDS.includes(kind) && field.options.length === 0 ? [blankOption([])] : field.options,
              validation:
                kind === "repeating" && !(validation.columns?.length ?? 0)
                  ? { columns: [{ key: "column_1", type: "text", label: {}, required: false }] }
                  : field.validation,
            })
          }}
        />
      </Row>

      <BilingualField
        id="q-label"
        className="sm:[&>.grid]:grid-cols-1"
        label={t("builder.panel.wording", "Question")}
        value={asMap(field.label)}
        onChange={onRelabel}
        disabled={readOnly}
      />

      <BilingualField
        id="q-help"
        className="sm:[&>.grid]:grid-cols-1"
        label={t("builder.panel.help", "Help under the question")}
        value={asMap(field.help_text_i18n)}
        onChange={(help) => onChange({ ...field, help_text_i18n: help })}
        disabled={readOnly}
      />

      {CHOICE_KINDS.includes(field.field_type) && (
        <div className="space-y-3">
          <div className="text-sm font-medium">{t("builder.panel.options", "Choices")}</div>
          {field.options.map((option, i) => (
            <div key={i} className="flex items-start gap-2">
              <div className="min-w-0 flex-1">
                <BilingualField
                  id={`q-option-${i}`}
                  className="sm:[&>.grid]:grid-cols-1"
                  value={asMap(option.label)}
                  onChange={(label) => setOptionLabel(i, label)}
                  disabled={readOnly}
                />
              </div>
              {!readOnly && field.options.length > 1 && (
                <IconButton
                  icon="delete"
                  label={t("builder.panel.removeOption", "Remove this choice")}
                  onClick={() => onChange({ ...field, options: field.options.filter((_, j) => j !== i) })}
                />
              )}
            </div>
          ))}
          {!readOnly && (
            <Button variant="outline" onClick={() => onChange({ ...field, options: [...field.options, blankOption(field.options)] })}>
              {t("builder.panel.addOption", "Add a choice")}
            </Button>
          )}
        </div>
      )}

      {field.field_type === "file" && (
        <Row id="q-files" label={t("builder.panel.maxFiles", "Most files")} hint={t("builder.panel.maxFiles.hint", "From 1 to 20.")}>
          <Input
            id="q-files"
            type="number"
            min={1}
            max={20}
            disabled={readOnly}
            value={validation.maxFiles ?? 1}
            onChange={(e) => setRules({ maxFiles: Math.min(20, Math.max(1, Number(e.target.value) || 1)) })}
          />
        </Row>
      )}

      {field.field_type === "number" && (
        <div className="grid grid-cols-2 gap-3">
          <Row id="q-min" label={t("builder.panel.min", "Smallest")}>
            <Input
              id="q-min"
              type="number"
              disabled={readOnly}
              value={validation.min ?? ""}
              onChange={(e) => setRules({ min: e.target.value === "" ? undefined : Number(e.target.value) })}
            />
          </Row>
          <Row id="q-max" label={t("builder.panel.max", "Largest")}>
            <Input
              id="q-max"
              type="number"
              disabled={readOnly}
              value={validation.max ?? ""}
              onChange={(e) => setRules({ max: e.target.value === "" ? undefined : Number(e.target.value) })}
            />
          </Row>
        </div>
      )}

      {(field.field_type === "text" || field.field_type === "textarea") && (
        <Row id="q-length" label={t("builder.panel.maxLength", "Longest answer")} hint={t("builder.panel.maxLength.hint", "In characters. Leave empty for no limit.")}>
          <Input
            id="q-length"
            type="number"
            min={1}
            disabled={readOnly}
            value={validation.maxLength ?? ""}
            onChange={(e) => setRules({ maxLength: e.target.value === "" ? undefined : Number(e.target.value) })}
          />
        </Row>
      )}

      {field.field_type === "repeating" && (
        <div className="space-y-3">
          <div className="text-sm font-medium">{t("builder.panel.columns", "Columns")}</div>
          {(validation.columns ?? []).map((column, i) => (
            <div key={i} className="space-y-2 rounded-card border border-border p-3">
              <BilingualField
                id={`q-column-${i}`}
                value={asMap(column.label)}
                onChange={(label) => setColumn(i, { label })}
                disabled={readOnly}
              />
              <div className="flex items-center gap-2">
                <div className="min-w-0 flex-1">
                  <ChoiceSelect
                    label={t("builder.panel.columnType", "Column type")}
                    value={column.type}
                    disabled={readOnly}
                    options={COLUMN_KINDS.map((k) => ({ value: k, label: kindLabel(k) }))}
                    onChange={(type) => setColumn(i, { type, options: type === "select" && !column.options?.length ? [blankOption([])] : column.options })}
                  />
                </div>
                {!readOnly && (validation.columns?.length ?? 0) > 1 && (
                  <IconButton
                    icon="delete"
                    label={t("builder.panel.removeColumn", "Remove this column")}
                    onClick={() => setRules({ columns: (validation.columns ?? []).filter((_, j) => j !== i) })}
                  />
                )}
              </div>
              {column.type === "select" && (
                <div className="space-y-2">
                  {(column.options ?? []).map((option, j) => (
                    <BilingualField
                      key={j}
                      id={`q-column-${i}-option-${j}`}
                      value={asMap(option.label)}
                      onChange={(label) =>
                        setColumn(i, {
                          options: (column.options ?? []).map((o, k) =>
                            k === j ? { ...o, label, value: optionValueFrom(label, column.options ?? [], o.value) } : o
                          ),
                        })
                      }
                      disabled={readOnly}
                    />
                  ))}
                  {!readOnly && (
                    <Button variant="outline" onClick={() => setColumn(i, { options: [...(column.options ?? []), blankOption(column.options ?? [])] })}>
                      {t("builder.panel.addOption", "Add a choice")}
                    </Button>
                  )}
                </div>
              )}
              <div className="flex items-center gap-2">
                <Checkbox
                  id={`q-column-${i}-required`}
                  checked={column.required === true}
                  disabled={readOnly}
                  onCheckedChange={(next) => setColumn(i, { required: next === true })}
                />
                <label htmlFor={`q-column-${i}-required`} className="text-sm">
                  {t("builder.panel.required", "Required")}
                </label>
              </div>
            </div>
          ))}
          {!readOnly && (validation.columns?.length ?? 0) < 20 && (
            <Button
              variant="outline"
              onClick={() => {
                const columns = validation.columns ?? []
                setRules({ columns: [...columns, { key: `column_${columns.length + 1}`, type: "text", label: {}, required: false }] })
              }}
            >
              {t("builder.panel.addColumn", "Add a column")}
            </Button>
          )}
          <div className="grid grid-cols-2 gap-3">
            <Row id="q-minrows" label={t("builder.panel.minRows", "Fewest rows")}>
              <Input
                id="q-minrows"
                type="number"
                min={1}
                disabled={readOnly}
                value={validation.minRows ?? ""}
                onChange={(e) => setRules({ minRows: e.target.value === "" ? undefined : Number(e.target.value) })}
              />
            </Row>
            <Row id="q-maxrows" label={t("builder.panel.maxRows", "Most rows")}>
              <Input
                id="q-maxrows"
                type="number"
                min={1}
                disabled={readOnly}
                value={validation.maxRows ?? ""}
                onChange={(e) => setRules({ maxRows: e.target.value === "" ? undefined : Number(e.target.value) })}
              />
            </Row>
          </div>
        </div>
      )}

      <div className="flex items-center gap-3">
        <Switch
          id="q-required"
          checked={field.is_required}
          disabled={readOnly}
          onCheckedChange={(next) => onChange({ ...field, is_required: next })}
        />
        <label htmlFor="q-required" className="text-sm font-medium">
          {t("builder.panel.required", "Required")}
        </label>
      </div>

      <Row id="q-audience" label={t("builder.panel.audience", "Who answers")}>
        <ChoiceSelect
          id="q-audience"
          value={field.audience}
          disabled={readOnly}
          options={[
            { value: "applicant", label: t("builder.panel.audience.applicant", "The person filling in the form") },
            { value: "staff", label: t("builder.panel.audience.staff", "Staff, when they review it") },
          ]}
          onChange={(next) => onChange({ ...field, audience: next === "staff" ? "staff" : "applicant" })}
        />
      </Row>

      <Row
        id="q-show"
        label={t("builder.panel.show", "Show this question")}
        hint={
          controllers.length === 0
            ? t("builder.panel.show.none", "A rule can use an earlier choose-one, choose-several or checkbox question.")
            : undefined
        }
      >
        <ChoiceSelect
          id="q-show"
          value={field.visible_when === null ? ALWAYS : field.visible_when.field}
          disabled={readOnly || controllers.length === 0}
          options={[
            { value: ALWAYS, label: t("builder.panel.show.always", "Always") },
            ...controllers.map((c) => ({
              value: c.field_key,
              label: t("builder.panel.show.onlyIf", "Only if: {question}", {
                question: pickText(c.label, currentLanguage) || c.field_key,
              }),
            })),
          ]}
          onChange={(next) => {
            if (next === ALWAYS) return setCondition(null, "")
            const source = controllers.find((c) => c.field_key === next)
            const first = source?.field_type === "checkbox" ? "true" : (source?.options[0]?.value ?? "")
            setCondition(next, first)
          }}
        />
      </Row>

      {controller !== null && canControl(controller) && (
        <Row id="q-answer" label={t("builder.panel.show.answer", "Is answered")}>
          <ChoiceSelect
            id="q-answer"
            value={answerValue}
            disabled={readOnly}
            options={answerChoices}
            onChange={(next) => setCondition(controller.field_key, next)}
          />
        </Row>
      )}

      {field.visible_when !== null && controller !== null && (
        <p className="rounded-control bg-muted p-3 text-xs text-muted-foreground">
          {t("builder.panel.show.sentence", "This question is shown only when “{question}” is answered {answer}.", {
            question: pickText(controller.label, currentLanguage) || controller.field_key,
            answer: answerChoices.find((c) => c.value === answerValue)?.label ?? answerValue,
          })}
        </p>
      )}

      {sections.length > 0 && (
        <Row id="q-section" label={t("builder.panel.section", "Section")}>
          <ChoiceSelect
            id="q-section"
            value={field.section_key ?? NO_SECTION}
            disabled={readOnly}
            options={[
              { value: NO_SECTION, label: t("builder.panel.section.none", "No section") },
              ...sections.map((s, i) => ({
                value: s.key,
                label: pickText(s.title, currentLanguage) || t("builder.section.untitled", "Section {number}", { number: i + 1 }),
              })),
            ]}
            onChange={(next) => onChange({ ...field, section_key: next === NO_SECTION ? null : next })}
          />
        </Row>
      )}
    </div>
  )
}
