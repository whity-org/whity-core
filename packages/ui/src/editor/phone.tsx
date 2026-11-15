"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import { useEditorState } from "@tiptap/react"

import { Icon, type IconName } from "../icon"
import { IconButton } from "../icon-button"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "../sheet"
import { cn } from "../utils"
import { CATALOGUE, applyItem, currentItem } from "./catalogue"
import { turnInto } from "./behaviour"
import type { EditorMode } from "./contract"
import type { EditorLabels } from "./labels"

/** The web app on a phone (PW.6): under 640px, the same break as the shell's tab bar. */
const PHONE_QUERY = "(max-width: 639.98px)"

export function useEditorPhone(): boolean {
  const [phone, setPhone] = React.useState(false)
  React.useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return
    const mq = window.matchMedia(PHONE_QUERY)
    const on = () => setPhone(mq.matches)
    on()
    mq.addEventListener("change", on)
    return () => mq.removeEventListener("change", on)
  }, [])
  return phone
}

/**
 * How far the on-screen keyboard covers the bottom of the layout viewport, in
 * px (0 when it is closed or the browser has no visual viewport). The docked
 * toolbar rides this far up so it sits on top of the keyboard.
 */
export function useKeyboardInset(active: boolean): number {
  const [inset, setInset] = React.useState(0)
  React.useEffect(() => {
    const vv = typeof window === "undefined" ? undefined : window.visualViewport
    if (!active || !vv) {
      setInset(0)
      return
    }
    const update = () => {
      const covered = window.innerHeight - vv.height - vv.offsetTop
      // Under ~80px is browser chrome moving, not a keyboard.
      setInset(covered > 80 ? Math.round(covered) : 0)
    }
    update()
    vv.addEventListener("resize", update)
    vv.addEventListener("scroll", update)
    return () => {
      vv.removeEventListener("resize", update)
      vv.removeEventListener("scroll", update)
    }
  }, [active])
  return inset
}

export const MODE_ICON: Record<EditorMode, IconName> = { editing: "edit", suggesting: "suggest", viewing: "view" }

export interface ModeChoice {
  mode: EditorMode
  icon: IconName
  title: string
  hint: string
  disabled?: boolean
}

/** The mode switch as a bottom sheet (PW.6): three rows, the current one ticked. */
export function ModeSheet({
  open,
  onOpenChange,
  labels,
  mode,
  choices,
  onPick,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  labels: EditorLabels
  mode: EditorMode
  choices: ModeChoice[]
  onPick: (mode: EditorMode) => void
}) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" showCloseButton={false} className="gap-3 rounded-t-dialog pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
        <SheetHeader>
          <SheetTitle className="text-base">{labels.modeMenu}</SheetTitle>
          <SheetDescription className="sr-only">{labels.modeMenu}</SheetDescription>
        </SheetHeader>
        <div role="radiogroup" aria-label={labels.modeMenu} className="grid overflow-hidden rounded-control-lg border border-border">
          {choices.map((c) => (
            <button
              key={c.mode}
              type="button"
              role="radio"
              aria-checked={mode === c.mode}
              disabled={c.disabled}
              onClick={() => {
                onPick(c.mode)
                onOpenChange(false)
              }}
              className="flex min-h-12 items-center gap-3 border-0 border-b border-border bg-transparent px-3 py-2 text-start last:border-b-0 hover:bg-muted disabled:opacity-50"
            >
              <Icon name={c.icon} />
              <span className="grid min-w-0 flex-1 gap-0.5">
                <span className="text-sm font-medium text-foreground">{c.title}</span>
                <span className="text-[0.8125rem] text-muted-foreground">{c.hint}</span>
              </span>
              {mode === c.mode && <Icon name="check" className="text-primary" />}
            </button>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  )
}

/** The six tiles More tools leads with; the rest stay in the / menu and Insert on desktop. */
const PHONE_TILES = ["h2", "table", "image", "signature", "pageBreak", "callout"]

export interface MoreAction {
  id: string
  icon: IconName
  label: string
  /** A toggle that is on shows a tick in place of the chevron. */
  on?: boolean
  onSelect: () => void
}

/** More tools as a bottom sheet (PW.6): the actions, then the Insert tiles. */
export function MoreToolsSheet({
  open,
  onOpenChange,
  labels,
  editor,
  editable,
  actions,
  extra,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  labels: EditorLabels
  /** The host's own header controls (the theme picker), shown under the actions. */
  extra?: React.ReactNode
  editor: Editor | null
  editable: boolean
  actions: MoreAction[]
}) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="bottom" showCloseButton={false} className="max-h-[85%] gap-3 rounded-t-dialog pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
        <SheetHeader>
          <SheetTitle className="text-base">{labels.moreTools}</SheetTitle>
          <SheetDescription className="sr-only">{labels.moreTools}</SheetDescription>
        </SheetHeader>
        {actions.length > 0 && (
          <div className="grid overflow-hidden rounded-control-lg border border-border">
            {actions.map((a) => (
              <button
                key={a.id}
                type="button"
                onClick={() => {
                  onOpenChange(false)
                  a.onSelect()
                }}
                className="flex min-h-12 w-full items-center gap-3 border-0 border-b border-border bg-transparent px-3 text-start text-sm text-foreground last:border-b-0 hover:bg-muted"
              >
                <Icon name={a.icon} className="text-muted-foreground" />
                <span className="min-w-0 flex-1">{a.label}</span>
                {a.on ? <Icon name="check" size={16} className="text-primary" /> : <Icon name="chevron-right" size={16} className="text-muted-foreground" />}
              </button>
            ))}
          </div>
        )}
        {extra && <div className="flex flex-wrap items-center gap-2">{extra}</div>}
        {editor && editable && (
          <>
            <h3 className="m-0 text-[0.8125rem] font-medium text-muted-foreground">{labels.insert}</h3>
            <div className="grid grid-cols-3 gap-2">
              {PHONE_TILES.map((id) => CATALOGUE.find((i) => i.id === id)!).map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => {
                    onOpenChange(false)
                    applyItem(editor, item)
                  }}
                  className="grid min-h-20 content-center place-items-center gap-1.5 rounded-control-lg border-0 bg-muted px-1 py-2 text-center text-[0.8125rem] text-foreground hover:bg-accent"
                >
                  <Icon name={item.icon} size={22} className="text-primary" />
                  <span className="max-w-full min-w-0 truncate">{labels[item.label]}</span>
                </button>
              ))}
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  )
}

/**
 * The formatting toolbar docked at the bottom on a phone (PW.6): text style,
 * bold, italic, list, insert, comment, then More tools and Hide keyboard. It
 * rides on top of the on-screen keyboard by `inset` px. Suggesting tints it
 * amber, as the mode button.
 */
export function PhoneToolbar({
  editor,
  labels,
  editable,
  suggesting,
  inset,
  onMore,
  onComment,
  onInsert,
}: {
  editor: Editor
  labels: EditorLabels
  editable: boolean
  suggesting: boolean
  inset: number
  onMore: () => void
  onComment?: () => void
  onInsert: () => void
}) {
  const s = useEditorState({
    editor,
    selector: ({ editor: e }) => ({
      bold: e.isActive("bold"),
      italic: e.isActive("italic"),
      item: currentItem(e)?.id ?? "text",
      empty: e.state.selection.empty,
    }),
  })
  const [typing, setTyping] = React.useState(false)
  React.useEffect(() => {
    const on = () => setTyping(document.activeElement instanceof HTMLElement && document.activeElement.isContentEditable)
    const later = () => requestAnimationFrame(on)
    document.addEventListener("focusin", later)
    document.addEventListener("focusout", later)
    on()
    return () => {
      document.removeEventListener("focusin", later)
      document.removeEventListener("focusout", later)
    }
  }, [])
  const heading = s.item === "h1" || s.item === "h2" || s.item === "h3"
  const press = (e: React.MouseEvent) => e.preventDefault()
  const btn = (icon: IconName, label: string, pressed: boolean, onClick: () => void, disabled = !editable) => (
    <IconButton icon={icon} label={label} size="lg" tooltip={false} pressed={pressed} disabled={disabled} onMouseDown={press} onClick={onClick} />
  )
  return (
    <div
      role="toolbar"
      aria-label={labels.formatting}
      data-slot="editor-toolbar"
      data-phone="true"
      style={{ insetBlockEnd: inset }}
      className={cn(
        "fixed inset-x-0 z-30 flex items-center gap-1 border-t border-border bg-card px-2",
        inset === 0 ? "h-[calc(3.5rem+env(safe-area-inset-bottom))] pb-[env(safe-area-inset-bottom)]" : "h-14",
        suggesting && "bg-warning-tint"
      )}
    >
      <button
        type="button"
        aria-label={labels.styleMenu}
        aria-pressed={heading}
        disabled={!editable}
        onMouseDown={press}
        onClick={() => {
          if (heading) turnInto(editor, "paragraph")
          else turnInto(editor, "heading", { level: 2 })
        }}
        className={cn(
          "inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-control-lg border-0 bg-transparent text-sm font-semibold text-foreground disabled:opacity-40",
          heading && "bg-primary-tint text-primary-tint-foreground"
        )}
      >
        Aa
      </button>
      {btn("bold", labels.bold, s.bold, () => editor.chain().focus().toggleMark("bold").run())}
      {btn("italic", labels.italic, s.italic, () => editor.chain().focus().toggleMark("italic").run())}
      {btn("list-bullet", labels.blockBullet, s.item === "bullet", () => {
        const item = CATALOGUE.find((i) => i.id === "bullet")!
        if (s.item === "bullet") turnInto(editor, "paragraph")
        else turnInto(editor, item.node.type, item.node.attrs)
      })}
      {btn("plus", labels.insert, false, onInsert)}
      {onComment && btn("comment-add", labels.comment, false, onComment, s.empty)}
      <span className="min-w-1 flex-1" />
      <IconButton icon="more" label={labels.moreTools} size="lg" tooltip={false} onMouseDown={press} onClick={onMore} />
      {typing && (
        <IconButton
          icon="keyboard"
          label={labels.hideKeyboard}
          size="lg"
          tooltip={false}
          onMouseDown={press}
          onClick={() => (document.activeElement as HTMLElement | null)?.blur()}
        />
      )}
    </div>
  )
}
