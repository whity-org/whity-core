"use client"

import { useState } from "react"
import { Badge } from "@whity/ui/badge"
import { Button } from "@whity/ui/button"
import { Icon } from "@whity/ui/icon"
import { IconButton } from "@whity/ui/icon-button"
import { cn } from "@whity/ui/utils"

import { useTranslation } from "../i18n"
import { useCurrentLanguage } from "./language"
import { groupBySection } from "./builder-model"
import { ChoiceSelect } from "./choice-select"
import { useKindLabel } from "./kind-labels"
import { pickText } from "./text"
import type { FieldDraft, FieldKind, FormSectionDef } from "./types"

/** What an answer will look like, drawn quietly so the author sees the shape. */
function AnswerPreview({ field }: { field: FieldDraft }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const box = "flex min-h-10 items-center rounded-control border border-dashed border-input px-3 text-sm text-muted-foreground"
  switch (field.field_type) {
    case "textarea":
      return <div className={cn(box, "min-h-20 items-start py-2")}>{t("builder.preview.textarea", "Long answer")}</div>
    case "select":
    case "multiselect":
      return (
        <div className="space-y-1">
          {field.options.map((option, i) => (
            <div key={i} className="flex items-center gap-2 text-sm">
              <span className={cn("size-4 border border-input", field.field_type === "select" ? "rounded-full" : "rounded-sm")} aria-hidden />
              {pickText(option.label, currentLanguage) || t("builder.preview.choice", "Choice")}
            </div>
          ))}
        </div>
      )
    case "checkbox":
      return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <span className="size-4 rounded-sm border border-input" aria-hidden />
          {t("builder.preview.yes", "Yes")}
        </div>
      )
    case "file":
      return <div className={box}>{t("builder.preview.file", "Choose a file")}</div>
    case "repeating":
      return <div className={box}>{t("builder.preview.table", "A table the person adds rows to")}</div>
    case "signature":
      return <div className={box}>{t("builder.preview.signature", "Full name, typed as a signature")}</div>
    case "date":
      return <div className={box}>{t("builder.preview.date", "Pick a date")}</div>
    case "profile_ref":
      return <div className={box}>{t("builder.preview.person", "Choose a person")}</div>
    case "ou_ref":
      return <div className={box}>{t("builder.preview.unit", "Choose a unit")}</div>
    default:
      return <div className={box}>{t("builder.preview.text", "Short answer")}</div>
  }
}

/** Bring a question into view once it has been drawn. */
export function scrollToQuestion(index: number): void {
  window.requestAnimationFrame(() => document.getElementById(`question-card-${index}`)?.scrollIntoView({ block: "center" }))
}

const JUMP_NONE = "__none__"

/** "Jump to question": a long form stays navigable. Shown once there is much to scroll. */
function JumpTo({ fields, onJump }: { fields: readonly FieldDraft[]; onJump: (index: number) => void }) {
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  return (
    <div className="max-w-sm">
      <ChoiceSelect
        label={t("builder.jump", "Jump to a question")}
        value={JUMP_NONE}
        options={[
          { value: JUMP_NONE, label: t("builder.jump", "Jump to a question") },
          ...fields.map((f, i) => ({
            value: String(i),
            label: `${i + 1}. ${pickText(f.label, currentLanguage) || t("builder.question.untitled", "Untitled question")}`,
          })),
        ]}
        onChange={(next) => next !== JUMP_NONE && onJump(Number(next))}
      />
    </div>
  )
}

/** "Add question": the kinds the server can store, all in view, one click each. */
export function AddQuestion({ kinds, onAdd, disabled }: { kinds: readonly FieldKind[]; onAdd: (kind: FieldKind) => void; disabled?: boolean }) {
  const t = useTranslation("forms")
  const kindLabel = useKindLabel()
  const [open, setOpen] = useState(false)
  return (
    <div className="space-y-2">
      <Button variant="outline" disabled={disabled} aria-expanded={open} onClick={() => setOpen((v) => !v)}>
        <Icon name="plus" size={16} />
        {t("builder.addQuestion", "Add question")}
      </Button>
      {open && (
        <div
          ref={(node) => node?.scrollIntoView({ block: "nearest" })}
          role="group"
          aria-label={t("builder.addQuestion.kinds", "Kind of question")}
          className="grid grid-cols-2 gap-2 rounded-card border border-border bg-card p-2 sm:grid-cols-3">
          {kinds.map((kind) => (
            <Button
              key={kind}
              variant="ghost"
              className="justify-start"
              onClick={() => {
                setOpen(false)
                onAdd(kind)
              }}
            >
              {kindLabel(kind)}
            </Button>
          ))}
        </div>
      )}
    </div>
  )
}

/** The form as people will see it: title, section bars and question cards. */
export function BuilderCanvas({
  title,
  description,
  fields,
  sections,
  kinds,
  selected,
  readOnly,
  onSelect,
  onAdd,
  onMove,
  onDuplicate,
  onDelete,
  onReorder,
  invalid,
}: {
  title: string
  description: string
  fields: readonly FieldDraft[]
  sections: readonly FormSectionDef[]
  kinds: readonly FieldKind[]
  selected: number | null
  readOnly: boolean
  onSelect: (index: number | null) => void
  onAdd: (kind: FieldKind, sectionKey: string | null) => void
  onMove: (index: number, direction: -1 | 1) => void
  onDuplicate: (index: number) => void
  onDelete: (index: number) => void
  onReorder: (from: number, to: number) => void
  /** Questions with something missing. */
  invalid: ReadonlySet<number>
}) {
  const [dragFrom, setDragFrom] = useState<number | null>(null)
  const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set())
  const t = useTranslation("forms")
  const { currentLanguage } = useCurrentLanguage()
  const kindLabel = useKindLabel()
  const groups = groupBySection(fields, sections.map((s) => s.key))
  const labelOf = (key: string) => pickText(fields.find((f) => f.field_key === key)?.label, currentLanguage) || key
  // A form with no questions still needs somewhere to add the first one.
  const shown = groups.length === 0 ? [{ key: null as string | null, items: [] }] : groups

  return (
    <div className="space-y-4 p-4 md:p-6">
      <div className="rounded-card border border-border bg-card p-5">
        <h2 className="text-lg font-semibold">{title || t("builder.untitled", "Untitled form")}</h2>
        {description !== "" && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
      </div>

      {sections.length > 0 && (
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setCollapsed(new Set(sections.map((s) => s.key)))}>
            {t("builder.sections.collapseAll", "Collapse sections")}
          </Button>
          <Button variant="outline" onClick={() => setCollapsed(new Set())}>
            {t("builder.sections.expandAll", "Expand sections")}
          </Button>
        </div>
      )}

      {fields.length > 8 && (
        <JumpTo
          fields={fields}
          onJump={(index) => {
            const key = fields[index].section_key
            if (key !== null) setCollapsed((current) => new Set([...current].filter((k) => k !== key)))
            onSelect(index)
            scrollToQuestion(index)
          }}
        />
      )}

      {shown.map((group) => {
        const section = sections.find((s) => s.key === group.key)
        const sectionNumber = section === undefined ? 0 : sections.indexOf(section) + 1
        return (
          <div key={group.key ?? "none"} className="space-y-3">
            {section !== undefined && (
              <button
                type="button"
                aria-expanded={!collapsed.has(section.key)}
                onClick={() =>
                  setCollapsed((current) => {
                    const next = new Set(current)
                    if (next.has(section.key)) next.delete(section.key)
                    else next.add(section.key)
                    return next
                  })
                }
                className="flex w-full items-center justify-between gap-3 rounded-card border border-border border-t-4 border-t-primary bg-card px-4 py-3 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <span>
                  <span className="block text-sm font-semibold">
                    {t("builder.section.bar", "Section {number}", { number: sectionNumber })}
                    {pickText(section.title, currentLanguage) !== "" && ` · ${pickText(section.title, currentLanguage)}`}
                  </span>
                  <span className="block text-xs text-muted-foreground">
                    {t("builder.section.count", "{count} questions", { count: group.items.length })}
                  </span>
                </span>
                <Icon name={collapsed.has(section.key) ? "chevron-right" : "chevron-down"} size={16} className="rtl:rotate-180" aria-hidden />
              </button>
            )}
            {(section !== undefined && collapsed.has(section.key) && !group.items.some((i) => i.index === selected) ? [] : group.items).map(({ field, index }) => {
              const position = group.items.findIndex((i) => i.index === index)
              const isSelected = selected === index
              const label = pickText(field.label, currentLanguage)
              const help = pickText(field.help_text_i18n, currentLanguage)
              return (
                <div
                  key={field.field_key}
                  role="button"
                  tabIndex={0}
                  aria-pressed={isSelected}
                  onClick={() => onSelect(index)}
                  id={`question-card-${index}`}
                  draggable={!readOnly}
                  onDragStart={() => setDragFrom(index)}
                  onDragEnd={() => setDragFrom(null)}
                  onDragOver={(event) => dragFrom !== null && event.preventDefault()}
                  onDrop={(event) => {
                    event.preventDefault()
                    if (dragFrom !== null) onReorder(dragFrom, index)
                    setDragFrom(null)
                  }}
                  onKeyDown={(event) => {
                    if (event.target !== event.currentTarget) return
                    if (event.key === "Enter" || event.key === " ") {
                      event.preventDefault()
                      onSelect(index)
                    } else if (!readOnly && event.altKey && (event.key === "ArrowUp" || event.key === "ArrowDown")) {
                      event.preventDefault()
                      onMove(index, event.key === "ArrowUp" ? -1 : 1)
                    }
                  }}
                  className={cn(
                    "space-y-2 rounded-card border bg-card p-4 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    isSelected ? "border-primary ring-2 ring-primary" : invalid.has(index) ? "border-destructive" : "border-border",
                    dragFrom === index && "opacity-50"
                  )}
                >
                  <div className="flex flex-wrap items-center gap-2">
                    {!readOnly && <Icon name="drag" size={16} className="cursor-grab text-muted-foreground" aria-hidden />}
                    <span className="text-sm text-muted-foreground">{index + 1}.</span>
                    <span className="text-sm font-medium">
                      {label || t("builder.question.untitled", "Untitled question")}
                      {field.is_required && (
                        <span className="ms-1 text-destructive" aria-label={t("builder.required", "required")}>
                          *
                        </span>
                      )}
                    </span>
                    <Badge variant="outline">{kindLabel(field.field_type)}</Badge>
                    {field.visible_when !== null && (
                      <Badge variant="info">
                        {t("builder.pill.onlyIfQuestion", "Only if “{question}” is {answer}", {
                          question: labelOf(field.visible_when.field),
                          answer: String(field.visible_when.equals ?? (field.visible_when.in ?? []).join(", ")),
                        })}
                      </Badge>
                    )}
                    {invalid.has(index) && <Badge variant="destructive">{t("builder.pill.needsAttention", "Needs attention")}</Badge>}
                    {field.audience === "staff" && <Badge variant="warning">{t("builder.pill.staff", "Staff answer")}</Badge>}
                  </div>
                  {help !== "" && <p className="text-xs text-muted-foreground">{help}</p>}
                  <AnswerPreview field={field} />
                  {isSelected && !readOnly && (
                    <div className="flex flex-wrap gap-1 border-t border-border pt-2" onClick={(e) => e.stopPropagation()}>
                      <IconButton icon="arrow-up" label={t("builder.move.up", "Move up")} size="sm" disabled={position === 0} onClick={() => onMove(index, -1)} />
                      <IconButton
                        icon="arrow-down"
                        label={t("builder.move.down", "Move down")}
                        size="sm"
                        disabled={position === group.items.length - 1}
                        onClick={() => onMove(index, 1)}
                      />
                      <IconButton icon="copy" label={t("builder.duplicate", "Duplicate")} size="sm" onClick={() => onDuplicate(index)} />
                      <IconButton icon="delete" label={t("builder.delete", "Delete question")} size="sm" onClick={() => onDelete(index)} />
                    </div>
                  )}
                </div>
              )
            })}
            {!readOnly && <AddQuestion kinds={kinds} onAdd={(kind) => onAdd(kind, group.key)} />}
          </div>
        )
      })}
    </div>
  )
}
