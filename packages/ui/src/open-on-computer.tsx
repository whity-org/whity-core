"use client"

import * as React from "react"
import { IconCheck, IconCopy, IconDeviceDesktop, IconMail } from "@tabler/icons-react"

import { Button } from "./button"
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "./sheet"
import { cn } from "./utils"

/**
 * Phone web, read-only builders (PwBuilders): the two canvases that are not
 * worth squeezing (a route's flow, a role's permission grid) are read on a
 * phone, and changed on a computer. These are the two parts that say so: a
 * note on why, and a bottom bar whose one button opens a sheet with the link.
 *
 * Domain-free: every word comes in through `labels`, so the app translates.
 */

export interface PhoneReadOnlyNoteProps extends React.ComponentProps<"div"> {
  children: React.ReactNode
}

/** "You can read this route here. To change it, open it on a computer." */
function PhoneReadOnlyNote({ className, children, ...props }: PhoneReadOnlyNoteProps) {
  return (
    <div
      role="note"
      data-slot="phone-read-only-note"
      className={cn(
        "flex items-start gap-3 rounded-card bg-muted px-4 py-3 text-sm text-foreground",
        className
      )}
      {...props}
    >
      <IconDeviceDesktop aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
      <span>{children}</span>
    </div>
  )
}

export interface OpenOnComputerLabels {
  /** The button and the sheet's title. */
  open: string
  /** What the sheet says: why the link, and what to do with it. */
  description: string
  copy: string
  copied: string
  email: string
  notNow: string
  close: string
  /** The email's subject line. */
  emailSubject: string
}

export interface OpenOnComputerBarProps {
  labels: OpenOnComputerLabels
  /** The page to open on the computer. Defaults to this page's address. */
  href?: string
  className?: string
}

/**
 * The bottom bar (phones only) and its sheet. The bar sticks to the bottom of
 * the page's scroll area, which on a phone ends right above the tab bar.
 *
 * "Email me the link" opens the person's own mail app with the link filled in,
 * because the server cannot know which address to send to from here; copying
 * the link is the other way out.
 */
function OpenOnComputerBar({ labels, href, className }: OpenOnComputerBarProps) {
  const [open, setOpen] = React.useState(false)
  const [copied, setCopied] = React.useState(false)
  const [link, setLink] = React.useState(href ?? "")

  React.useEffect(() => {
    if (href === undefined) setLink(window.location.href)
  }, [href])

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(link)
      setCopied(true)
    } catch {
      // No clipboard (an insecure origin): the link is shown in full to copy by hand.
    }
  }

  const mailto = `mailto:?subject=${encodeURIComponent(labels.emailSubject)}&body=${encodeURIComponent(link)}`

  return (
    <>
      <div
        data-slot="open-on-computer-bar"
        className={cn(
          "sticky bottom-0 z-10 -mx-4 mt-4 border-t border-border bg-background px-4 py-3 sm:hidden",
          className
        )}
      >
        <Button variant="outline" size="lg" className="min-h-11" onClick={() => setOpen(true)}>
          <IconDeviceDesktop aria-hidden="true" />
          {labels.open}
        </Button>
      </div>
      <Sheet
        open={open}
        onOpenChange={(next) => {
          setOpen(next)
          if (!next) setCopied(false)
        }}
      >
        <SheetContent
          side="bottom"
          closeLabel={labels.close}
          className="gap-3 rounded-t-2xl pb-[calc(1.25rem+env(safe-area-inset-bottom))] text-sm"
          data-testid="open-on-computer-sheet"
        >
          <div aria-hidden className="mx-auto h-1 w-10 rounded-full bg-border" />
          <SheetHeader>
            <SheetTitle className="text-lg font-semibold">{labels.open}</SheetTitle>
            <SheetDescription>{labels.description}</SheetDescription>
          </SheetHeader>
          <div className="flex items-center gap-2 rounded-card bg-muted px-3 py-2">
            <span dir="ltr" className="min-w-0 flex-1 break-all font-mono text-xs text-foreground">
              {link}
            </span>
            <Button
              variant="ghost"
              size="icon-lg"
              aria-label={copied ? labels.copied : labels.copy}
              onClick={() => void copy()}
            >
              {copied ? <IconCheck /> : <IconCopy />}
            </Button>
          </div>
          <Button size="lg" className="min-h-11 w-full" asChild>
            <a href={mailto}>
              <IconMail aria-hidden="true" />
              {labels.email}
            </a>
          </Button>
          <Button variant="outline" size="lg" className="min-h-11 w-full" onClick={() => setOpen(false)}>
            {labels.notNow}
          </Button>
        </SheetContent>
      </Sheet>
    </>
  )
}

export { PhoneReadOnlyNote, OpenOnComputerBar }
