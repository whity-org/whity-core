import * as React from "react"

import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "./alert-dialog"
import { Button } from "./button"
import { Input } from "./input"

/**
 * Confirming by how undoable an action is (docs/design/components/PatternConfirm, P.4).
 *
 * Tier 1, undoable, needs no dialog: act at once and show an Undo toast.
 * Tier 2, hard to undo: this dialog, with the question as its title and the
 * consequence as its text. Tier 3, security-sensitive or irreversible: the same
 * with `typeToConfirm`, and the button stays disabled until the text matches.
 *
 * The confirm button repeats the verb ("Delete role"), never "OK" or "Yes".
 */
export interface ConfirmActionProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The question: "Delete the Branch manager role?" */
  title: string
  /** The consequence for people and data. */
  description: React.ReactNode
  /** The verb: "Delete role". */
  confirmLabel: string
  cancelLabel: string
  onConfirm: () => void | Promise<void>
  /** Tier 3: the name or email to type, and the label that asks for it. */
  typeToConfirm?: { value: string; label: string }
  /** Destructive unless the action only changes who can do what. */
  variant?: "destructive" | "default"
}

function ConfirmAction({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  cancelLabel,
  onConfirm,
  typeToConfirm,
  variant = "destructive",
}: ConfirmActionProps) {
  const [typed, setTyped] = React.useState("")
  const [busy, setBusy] = React.useState(false)

  React.useEffect(() => {
    if (open) setTyped("")
  }, [open])

  const matches = typeToConfirm === undefined || typed.trim() === typeToConfirm.value

  const confirm = async () => {
    setBusy(true)
    try {
      await onConfirm()
      onOpenChange(false)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        {typeToConfirm && (
          <Input
            label={typeToConfirm.label}
            value={typed}
            autoComplete="off"
            onChange={(event) => setTyped(event.target.value)}
          />
        )}
        <AlertDialogFooter>
          <AlertDialogCancel>{cancelLabel}</AlertDialogCancel>
          <Button type="button" variant={variant} disabled={!matches} loading={busy} onClick={confirm}>
            {confirmLabel}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

export { ConfirmAction }
