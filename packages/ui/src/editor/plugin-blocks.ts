import type { Localized, PluginBlockDefinition, PluginBlocks, PluginBlockSource } from "./services"

/**
 * Plugin blocks in the editor (editor M7, E.17–E.20). A block's view is a
 * declaration; this fills it with the block's data the way Whity's printer
 * does (PluginBlockPrinter): `{{data.key}}` becomes the field's value, a
 * select's value its option's label, and `{en, ar}` text the reader's
 * language. Nothing a plugin declares is run.
 */

export function localized(text: Localized | string | null | undefined, lang: "en" | "ar"): string {
  if (!text) return ""
  if (typeof text === "string") return text
  return text[lang] || text.en || text.ar || ""
}

/** Each field's shown text; a select shows its option's label. */
export function fieldValues(definition: PluginBlockDefinition, data: Record<string, unknown>, lang: "en" | "ar"): Record<string, string> {
  const out: Record<string, string> = {}
  for (const field of definition.fields) {
    const raw = data[field.key]
    let text = raw === null || raw === undefined ? "" : String(raw)
    if (field.type === "select") {
      const option = field.options?.find((o) => o.value === text)
      if (option) text = localized(option.label, lang)
    }
    out[field.key] = text
  }
  return out
}

const isLocalized = (v: unknown): v is Localized =>
  !!v && typeof v === "object" && !Array.isArray(v) && Object.keys(v).length > 0 && Object.keys(v).every((k) => k === "en" || k === "ar")

/** The view with `{{data.key}}` filled and `{en, ar}` text in the reader's language. */
export function fillView(view: unknown[], values: Record<string, string>, lang: "en" | "ar"): unknown[] {
  const fill = (value: unknown): unknown => {
    if (typeof value === "string") return value.replace(/\{\{\s*data\.([a-z][a-z0-9_]{0,39})\s*\}\}/g, (_, key: string) => values[key] ?? "")
    if (isLocalized(value)) return fill(localized(value, lang))
    if (Array.isArray(value)) return value.map(fill)
    if (value && typeof value === "object") return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, fill(v)]))
    return value
  }
  return view.map(fill)
}

/** A block's declaration and its plugin, or null when the plugin is off, gone or no longer declares it. */
export function findPluginBlock(blocks: PluginBlocks | undefined, plugin: string, blockType: string): { source: PluginBlockSource; definition: PluginBlockDefinition } | null {
  const source = blocks?.plugins.find((p) => p.plugin === plugin)
  const definition = source?.blocks.find((b) => b.blockType === blockType)
  return source && definition ? { source, definition } : null
}

/** A new block's data: empty text, a select's first option. */
export function defaultData(definition: PluginBlockDefinition): Record<string, unknown> {
  return Object.fromEntries(definition.fields.map((f) => [f.key, f.type === "select" ? (f.options?.[0]?.value ?? "") : ""]))
}
