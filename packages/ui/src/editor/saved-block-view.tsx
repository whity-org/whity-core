"use client"

import * as React from "react"
import { NodeViewWrapper, type ReactNodeViewProps } from "@tiptap/react"

import { Icon } from "../icon"
import { cn } from "../utils"
import type { Block } from "./contract"
import { freshEditorBlocks } from "./convert"
import { fill, type EditorLabels } from "./labels"
import { inlineText, useEditorServices } from "./services"

/**
 * A synced saved block in a document (EditorCustomBlocks; E.21, E.38): drawn
 * from the library, so editing the original reaches every document that uses
 * it. It shows a light outline in the main colour and, when selected or
 * hovered, its source label: the name, "Synced · used in 12 documents" and
 * Detach, which turns it into ordinary blocks changed here only.
 */
export function SavedBlockView(props: ReactNodeViewProps) {
  const { services, labels } = useEditorServices()
  const id = String(props.node.attrs.savedBlockId ?? "")
  const saved = services.savedBlocks?.find((b) => b.id === id) ?? null
  const editable = props.editor.isEditable

  const detach = () => {
    const pos = props.getPos()
    if (!saved || typeof pos !== "number") return
    props.editor
      .chain()
      .focus()
      .insertContentAt({ from: pos, to: pos + props.node.nodeSize }, freshEditorBlocks(saved.blocks))
      .run()
  }

  const usedIn = saved?.usedIn ?? 0
  return (
    <NodeViewWrapper
      data-type="savedBlock"
      data-block-id={props.node.attrs.id ?? undefined}
      className={cn("wy-rich wy-saved", props.selected && "is-selected")}
      role="group"
      aria-label={fill(labels.savedBlockLabel, { name: saved?.name ?? "" })}
    >
      {saved && (
        <div className="wy-saved-src" contentEditable={false}>
          <Icon name="sync" size={12} />
          <b dir="auto">{saved.name}</b>
          <span>
            {labels.savedBlockSynced} · {usedIn === 1 ? labels.savedBlockUsedInOne : fill(labels.savedBlockUsedIn, { n: usedIn })}
          </span>
          {editable && (
            <>
              <span className="wy-saved-sep" aria-hidden="true" />
              <button
                type="button"
                className="wy-saved-action"
                title={labels.savedBlockDetachHint}
                onMouseDown={(e) => e.preventDefault()}
                onClick={detach}
              >
                {labels.savedBlockDetach}
              </button>
            </>
          )}
        </div>
      )}
      <div className="wy-saved-body" contentEditable={false}>
        {saved ? <SavedBlocksPreview blocks={saved.blocks} labels={labels} /> : <div className="wy-placeholder">{labels.savedBlockMissing}</div>}
      </div>
    </NodeViewWrapper>
  )
}

/** A block type's name for what the preview can't draw as text. */
const TYPE_NAMES: Partial<Record<Block["type"], keyof EditorLabels>> = {
  table: "blockTable",
  image: "blockImage",
  file: "blockFile",
  record: "blockRecord",
  chart: "blockChart",
  signature: "blockSignature",
  barcode: "blockBarcode",
  qr: "blockQr",
  math: "blockMath",
  pageBreak: "blockPageBreak",
  columns: "blockColumns",
}

/**
 * The library's blocks, read-only, with the sheet's own type: text as text,
 * other blocks named. The saved block is edited in the original, not here.
 */
function SavedBlocksPreview({ blocks, labels }: { blocks: Block[]; labels: EditorLabels }) {
  return (
    <>
      {blocks.map((block) => {
        switch (block.type) {
          case "heading": {
            const H = `h${block.level + 1}` as "h2" | "h3" | "h4"
            return <H key={block.id}>{inlineText(block.content)}</H>
          }
          case "paragraph":
            return <p key={block.id}>{inlineText(block.content)}</p>
          case "quote":
            return <blockquote key={block.id}>{inlineText(block.content)}</blockquote>
          case "callout":
            return (
              <div key={block.id} className="wy-saved-callout" data-kind={block.kind}>
                <Icon name={block.kind === "important" ? "alert" : "info"} className="wy-callout-icon" />
                <p>{inlineText(block.content)}</p>
              </div>
            )
          case "bullet":
          case "number":
          case "checklist":
            return (
              <p key={block.id} style={{ paddingInlineStart: `${(block.level + 1) * 1.25}em` }}>
                {block.type === "checklist" ? (block.checked ? "☑ " : "☐ ") : block.type === "bullet" ? "• " : ""}
                {inlineText(block.content)}
              </p>
            )
          case "code":
            return <pre key={block.id}>{block.text}</pre>
          case "divider":
            return <hr key={block.id} />
          case "toggle":
            return (
              <React.Fragment key={block.id}>
                <p>{inlineText(block.summary)}</p>
                <SavedBlocksPreview blocks={block.children} labels={labels} />
              </React.Fragment>
            )
          default: {
            const name = TYPE_NAMES[block.type]
            return (
              <div key={block.id} className="wy-placeholder">
                {name ? labels[name] : block.type}
              </div>
            )
          }
        }
      })}
    </>
  )
}
