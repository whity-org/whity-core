"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import { NodeSelection } from "@tiptap/pm/state"
import { useEditorState } from "@tiptap/react"

import { Button } from "../button"
import { IconButton } from "../icon-button"
import { FieldPicker, flatFields } from "./field-picker"
import { filterFields, type FieldDef, type FieldGroup } from "./fields"
import type { EditorLabels } from "./labels"

/**
 * A selected merge field's own bar (E.26): what fills it (its group, its
 * sample) and the two things an author does to one: change it for another
 * field, or remove it. Before this, clicking a chip only selected it, so the
 * only way to swap "Bill to" for "Customer name" was to delete it and find the
 * new one in Field ▾.
 */
function FieldBar({
  editor,
  labels,
  groups,
  container,
}: {
  editor: Editor
  labels: EditorLabels
  groups: FieldGroup[] | undefined
  container: React.RefObject<HTMLDivElement | null>
}) {
  const target = useEditorState({
    editor,
    selector: ({ editor: e }) => {
      const sel = e.state.selection
      return e.isEditable && sel instanceof NodeSelection && sel.node.type.name === "field" ? { node: sel.node, pos: sel.from } : null
    },
  })
  const [changing, setChanging] = React.useState(false)
  const [query, setQuery] = React.useState("")
  const [active, setActive] = React.useState(0)
  const [place, setPlace] = React.useState<{ top: number; start: number } | null>(null)

  const pos = target?.pos
  React.useEffect(() => {
    setChanging(false)
    setQuery("")
  }, [pos])
  React.useLayoutEffect(() => {
    if (pos === undefined || !container.current) {
      setPlace(null)
      return
    }
    const dom = editor.view.nodeDOM(pos) as HTMLElement | null
    if (!dom) return
    const box = container.current.getBoundingClientRect()
    const rect = dom.getBoundingClientRect()
    const rtl = getComputedStyle(container.current).direction === "rtl"
    setPlace({ top: rect.bottom - box.top + 6, start: rtl ? box.right - rect.right : rect.left - box.left })
  }, [editor, pos, container, target?.node])

  const all = React.useMemo(() => flatFields(groups ?? []), [groups])
  const shown = React.useMemo(() => filterFields(groups ?? [], query), [groups, query])
  const flat = flatFields(shown)
  React.useEffect(() => setActive(0), [query, changing])

  if (!target || !place) return null
  const path = String(target.node.attrs.path ?? "")
  const def: FieldDef | undefined = all.find((f) => f.path === path)
  const group = (groups ?? []).find((g) => g.items.some((f) => f.path === path))?.group
  const label = String(target.node.attrs.label || def?.label || path)

  const replace = (field: FieldDef) => {
    editor
      .chain()
      .focus()
      .command(({ tr }) => {
        tr.setNodeMarkup(target.pos, undefined, { ...target.node.attrs, path: field.path, label: field.label, required: field.required ? true : null })
        tr.setSelection(NodeSelection.create(tr.doc, target.pos))
        return true
      })
      .run()
    setChanging(false)
  }
  const remove = () => editor.chain().focus().deleteRange({ from: target.pos, to: target.pos + target.node.nodeSize }).run()

  return (
    <div
      role="toolbar"
      aria-label={labels.fieldBar}
      data-slot="field-bar"
      className="absolute z-20 grid w-max max-w-[360px] gap-1 rounded-control border border-border bg-popover p-1.5 text-sm text-popover-foreground shadow-float"
      style={{ top: place.top, insetInlineStart: Math.max(0, place.start) }}
      onMouseDown={(e) => {
        if ((e.target as HTMLElement).tagName !== "INPUT") e.preventDefault()
      }}
    >
      <div className="flex items-center gap-2 px-1">
        <span className="min-w-0">
          <span className="block truncate font-medium" dir="auto">
            {label}
          </span>
          <span className="block truncate text-xs text-muted-foreground" dir="auto">
            {[group, def?.sample ? `${labels.fieldExample} ${def.sample}` : null].filter(Boolean).join(" · ")}
          </span>
        </span>
        <span className="ms-auto flex shrink-0 items-center gap-0.5">
          {all.length > 0 && (
            <Button variant="outline" size="sm" onClick={() => setChanging((v) => !v)} aria-expanded={changing}>
              {labels.fieldChange}
            </Button>
          )}
          <IconButton icon="delete" label={labels.fieldRemove} size="sm" onClick={remove} />
        </span>
      </div>
      {changing && (
        <FieldPicker
          id="wy-fieldbar"
          groups={shown}
          active={Math.min(active, Math.max(0, flat.length - 1))}
          onActive={setActive}
          onPick={replace}
          labels={labels}
          search={
            <input
              autoFocus
              aria-label={labels.fieldSearch}
              placeholder={labels.fieldSearch}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                const n = flat.length || 1
                if (e.key === "ArrowDown" || e.key === "ArrowUp") {
                  e.preventDefault()
                  setActive((i) => (i + (e.key === "ArrowDown" ? 1 : n - 1)) % n)
                } else if (e.key === "Enter" && flat[active]) {
                  e.preventDefault()
                  replace(flat[active])
                } else if (e.key === "Escape") {
                  e.preventDefault()
                  setChanging(false)
                  editor.commands.focus()
                }
              }}
              className="mx-1 mb-1 h-8 rounded-control-sm border border-input bg-card px-2 text-sm outline-none focus-visible:border-ring"
            />
          }
        />
      )}
    </div>
  )
}

export { FieldBar }
