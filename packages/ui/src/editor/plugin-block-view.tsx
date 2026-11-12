"use client"

import * as React from "react"
import { NodeViewWrapper, type ReactNodeViewProps } from "@tiptap/react"

import { Button } from "../button"
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "../dialog"
import { Icon } from "../icon"
import { Input } from "../input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "../select"
import { Textarea } from "../textarea"
import { cn } from "../utils"
import { fill, type EditorLabels } from "./labels"
import { fieldValues, fillView, findPluginBlock, localized } from "./plugin-blocks"
import { useEditorServices, type PluginBlockDefinition } from "./services"

/**
 * A plugin's block in a document (EditorCustomBlocks; E.17–E.20). Whity's
 * renderer draws its declared view with Whity's components; nothing the
 * plugin ships runs here. Selected or hovered, its source label shows the
 * plugin, Verified or Not verified (Whity's mark only), and Prints or Notes
 * only, with Edit opening a form built from its fields.
 *
 * When its plugin is off or gone, a placeholder says which plugin it needs,
 * with Enable for people who may switch workspace plugins on; the block's
 * data stays in the document (E.19).
 */
export function PluginBlockView(props: ReactNodeViewProps) {
  const { services, labels } = useEditorServices()
  const lang = services.language ?? "en"
  const plugin = String(props.node.attrs.plugin ?? "")
  const blockType = String(props.node.attrs.blockType ?? "")
  const data = (props.node.attrs.data ?? {}) as Record<string, unknown>
  const found = findPluginBlock(services.pluginBlocks, plugin, blockType)
  const editable = props.editor.isEditable
  const [editing, setEditing] = React.useState(false)

  if (!found) {
    return (
      <NodeViewWrapper data-type="plugin" data-block-id={props.node.attrs.id ?? undefined} className={cn("wy-rich", props.selected && "is-selected")}>
        <PluginOff plugin={plugin} />
      </NodeViewWrapper>
    )
  }

  const { source, definition } = found
  const name = localized(definition.label, lang)
  const view = fillView(definition.view, fieldValues(definition, data, lang), lang)
  return (
    <NodeViewWrapper
      data-type="plugin"
      data-block-id={props.node.attrs.id ?? undefined}
      className={cn("wy-rich wy-plugin", props.selected && "is-selected")}
      role="group"
      aria-label={fill(labels.pluginBlockLabel, { name, plugin: localized(source.label, lang) })}
    >
      <div className="wy-saved-src" contentEditable={false}>
        <span className="wy-plugin-tile" aria-hidden="true">
          {localized(source.label, lang).slice(0, 1).toUpperCase()}
        </span>
        <b dir="auto">{localized(source.label, lang)}</b>
        {source.verified ? (
          <span className="wy-plugin-mark">
            <Icon name="check" size={12} /> {labels.pluginVerified}
          </span>
        ) : (
          <span>{labels.pluginNotVerified}</span>
        )}
        <span className="wy-saved-sep" aria-hidden="true" />
        <span>{definition.print ? labels.pluginPrints : labels.pluginNotesOnly}</span>
        {editable && definition.fields.length > 0 && (
          <>
            <span className="wy-saved-sep" aria-hidden="true" />
            <button type="button" className="wy-saved-action" onMouseDown={(e) => e.preventDefault()} onClick={() => setEditing(true)}>
              {labels.pluginEdit}
            </button>
          </>
        )}
      </div>
      <div className="wy-plugin-body" contentEditable={false}>
        {services.renderPluginView ? services.renderPluginView(view) : <div className="wy-placeholder">{name}</div>}
      </div>
      {editing && (
        <PluginBlockForm
          definition={definition}
          data={data}
          lang={lang}
          labels={labels}
          onClose={() => setEditing(false)}
          onSave={(next) => {
            props.updateAttributes({ data: next })
            setEditing(false)
          }}
        />
      )}
    </NodeViewWrapper>
  )
}

/** E.19: the block's plugin is off or gone. The data stays; Enable for those who may. */
function PluginOff({ plugin }: { plugin: string }) {
  const { services, labels } = useEditorServices()
  const lang = services.language ?? "en"
  const off = services.pluginBlocks?.off.find((p) => p.plugin === plugin)
  const name = off ? localized(off.label, lang) : plugin
  const canEnable = !!off && !!services.pluginBlocks?.canEnable && !!services.enablePlugin
  const [busy, setBusy] = React.useState(false)
  const [failed, setFailed] = React.useState(false)
  return (
    <div className="wy-plugin-off" contentEditable={false}>
      <span className="wy-tile" aria-hidden="true">
        <Icon name="plugin" />
      </span>
      <span className="grid min-w-0 flex-1 gap-0.5">
        <span className="font-medium" dir="auto">
          {fill(labels.pluginNeeds, { plugin: name })}
        </span>
        <span className="text-sm text-muted-foreground">{failed ? labels.pluginEnableFailed : labels.pluginNeedsHint}</span>
      </span>
      {canEnable && (
        <Button
          variant="outline"
          size="sm"
          loading={busy}
          onMouseDown={(e) => e.preventDefault()}
          onClick={async () => {
            setBusy(true)
            setFailed(false)
            try {
              await services.enablePlugin!(plugin)
            } catch {
              setFailed(true)
            } finally {
              setBusy(false)
            }
          }}
        >
          {labels.pluginEnable}
        </Button>
      )}
    </div>
  )
}

/** The block's Edit form, built from its declared fields. */
function PluginBlockForm({
  definition,
  data,
  lang,
  labels,
  onClose,
  onSave,
}: {
  definition: PluginBlockDefinition
  data: Record<string, unknown>
  lang: "en" | "ar"
  labels: EditorLabels
  onClose: () => void
  onSave: (data: Record<string, unknown>) => void
}) {
  const [values, setValues] = React.useState<Record<string, string>>(() =>
    Object.fromEntries(definition.fields.map((f) => [f.key, data[f.key] === null || data[f.key] === undefined ? "" : String(data[f.key])]))
  )
  const id = React.useId()
  const missing = definition.fields.some((f) => f.required && !values[f.key]?.trim())
  const set = (key: string, value: string) => setValues((v) => ({ ...v, [key]: value }))
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-[440px]" closeLabel={labels.pluginCancel}>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            if (missing) return
            onSave(Object.fromEntries(definition.fields.map((f) => [f.key, f.type === "number" && values[f.key] !== "" ? Number(values[f.key]) : values[f.key]])))
          }}
        >
          <DialogHeader>
            <DialogTitle>{fill(labels.pluginEditTitle, { name: localized(definition.label, lang) })}</DialogTitle>
          </DialogHeader>
          {definition.fields.map((field) => {
            const fieldId = `${id}-${field.key}`
            const label = localized(field.label, lang)
            return (
              <div key={field.key} className="grid gap-1.5">
                <label htmlFor={fieldId} className="text-sm font-medium">
                  {label}
                </label>
                {field.type === "longText" ? (
                  <Textarea id={fieldId} dir="auto" value={values[field.key]} onChange={(e) => set(field.key, e.target.value)} />
                ) : field.type === "select" ? (
                  <Select value={values[field.key] || undefined} onValueChange={(v) => set(field.key, v)}>
                    <SelectTrigger id={fieldId}>
                      <SelectValue placeholder={labels.pluginChoose} />
                    </SelectTrigger>
                    <SelectContent>
                      {(field.options ?? []).map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                          {localized(o.label, lang)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : (
                  <Input
                    id={fieldId}
                    dir={field.type === "text" ? "auto" : "ltr"}
                    type={field.type === "number" ? "number" : field.type === "date" ? "date" : "text"}
                    required={field.required}
                    value={values[field.key]}
                    onChange={(e) => set(field.key, e.target.value)}
                  />
                )}
              </div>
            )
          })}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {labels.pluginCancel}
            </Button>
            <Button type="submit" disabled={missing}>
              {labels.pluginSave}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
