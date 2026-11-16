"use client"

import * as React from "react"

import { Button } from "../button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../dialog"
import { Input } from "../input"
import { RadioGroup, RadioGroupItem } from "../radio-group"
import { fill, type EditorLabels } from "./labels"

export interface SaveBlockChoice {
  name: string
  mode: "synced" | "copy"
  sharing: "me" | "workspace"
}

/**
 * Save as block (EditorCustomBlocks card; E.21, E.22): a name, Synced or
 * Copy, and Only me or Everyone in the workspace. Everyone is offered only to
 * people who manage the workspace library. Synced is the default: editing it
 * once updates every document that uses it, and a published template picks
 * the change up at its next publish (E.38).
 */
function SaveBlockDialog({
  open,
  onOpenChange,
  blockCount,
  canShare,
  onSave,
  labels,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** How many blocks are selected, for "Saves 3 blocks as one". */
  blockCount: number
  /** Whether "Everyone in the workspace" is offered (E.22). */
  canShare: boolean
  onSave: (choice: SaveBlockChoice) => Promise<void>
  labels: EditorLabels
}) {
  const [name, setName] = React.useState("")
  const [mode, setMode] = React.useState<SaveBlockChoice["mode"]>("synced")
  const [sharing, setSharing] = React.useState<SaveBlockChoice["sharing"]>("me")
  const [busy, setBusy] = React.useState(false)
  const [failed, setFailed] = React.useState(false)
  const nameId = React.useId()

  React.useEffect(() => {
    if (!open) return
    setName("")
    setMode("synced")
    setSharing("me")
    setFailed(false)
  }, [open])

  const save = async () => {
    const trimmed = name.trim()
    if (!trimmed || busy) return
    setBusy(true)
    setFailed(false)
    try {
      await onSave({ name: trimmed, mode, sharing: canShare ? sharing : "me" })
      onOpenChange(false)
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  const option = (value: string, title: string, hint: string, group: string) => (
    <label className="flex cursor-pointer items-start gap-2.5 rounded-control-lg border border-border px-3 py-2.5 text-sm has-[[data-state=checked]]:border-primary">
      <RadioGroupItem value={value} id={`${group}-${value}`} className="mt-0.5" />
      <span className="grid gap-0.5">
        <span className="font-medium">{title}</span>
        <span className="text-xs text-muted-foreground">{hint}</span>
      </span>
    </label>
  )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[440px]" closeLabel={labels.saveBlockCancel}>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            void save()
          }}
        >
          <DialogHeader>
            <DialogTitle>{labels.saveBlockTitle}</DialogTitle>
            <DialogDescription>{blockCount === 1 ? labels.saveBlockExplainOne : fill(labels.saveBlockExplain, { n: blockCount })}</DialogDescription>
          </DialogHeader>

          <div className="grid gap-1.5">
            <label htmlFor={nameId} className="text-sm font-medium">
              {labels.saveBlockName}
            </label>
            <Input id={nameId} value={name} maxLength={120} autoFocus onChange={(e) => setName(e.target.value)} placeholder={labels.saveBlockNamePlaceholder} />
          </div>

          <fieldset className="grid gap-2">
            <legend className="mb-1.5 text-sm font-medium">{labels.saveBlockHow}</legend>
            <RadioGroup value={mode} onValueChange={(v) => setMode(v as SaveBlockChoice["mode"])} className="grid gap-2">
              {option("synced", labels.saveBlockSynced, labels.saveBlockSyncedHint, "mode")}
              {option("copy", labels.saveBlockCopy, labels.saveBlockCopyHint, "mode")}
            </RadioGroup>
          </fieldset>

          {canShare && (
            <fieldset className="grid gap-2">
              <legend className="mb-1.5 text-sm font-medium">{labels.saveBlockWho}</legend>
              <RadioGroup value={sharing} onValueChange={(v) => setSharing(v as SaveBlockChoice["sharing"])} className="grid gap-2">
                {option("me", labels.saveBlockOnlyMe, labels.saveBlockOnlyMeHint, "sharing")}
                {option("workspace", labels.saveBlockEveryone, labels.saveBlockEveryoneHint, "sharing")}
              </RadioGroup>
            </fieldset>
          )}

          {failed && (
            <p role="alert" className="text-sm text-error-text">
              {labels.collabFailed}
            </p>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              {labels.saveBlockCancel}
            </Button>
            <Button type="submit" disabled={!name.trim() || busy}>
              {labels.saveBlockSave}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export { SaveBlockDialog }
