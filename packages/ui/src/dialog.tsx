"use client"

import * as React from "react"
import { Dialog as DialogPrimitive } from "radix-ui"

import { cn, useIsDarkMode } from "./utils"
import { Button } from "./button"
import { IconX } from "@tabler/icons-react"

function Dialog({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Portal>) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogClose({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Close>) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />
}

function DialogOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "fixed inset-0 isolate z-50 bg-overlay data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
        className
      )}
      {...props}
    />
  )
}

/**
 * Centred at 488px from 640px up. On a phone it is a sheet docked to the
 * bottom edge, full width, never taller than the screen (its body scrolls),
 * clear of the home bar, so a long form or a list of choices stays reachable
 * under the thumb (04-layout; the phone designs open choices as sheets).
 */
function DialogContent({
  className,
  children,
  showCloseButton = true,
  closeLabel = "Close",
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  showCloseButton?: boolean
  /** Accessible name for the corner close button (visually hidden). */
  closeLabel?: string
}) {
  const isDark = useIsDarkMode()

  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          isDark && "dark",
          "fixed top-1/2 start-1/2 z-50 grid w-full max-w-[calc(100%-2rem)] -translate-x-1/2 rtl:translate-x-1/2 -translate-y-1/2 gap-3 rounded-dialog bg-popover p-6 text-sm text-popover-foreground shadow-float outline-none sm:max-w-[488px] max-h-[calc(100dvh-2rem)] overflow-y-auto max-sm:inset-x-0 max-sm:top-auto max-sm:bottom-0 max-sm:max-h-[calc(100dvh-env(safe-area-inset-top)-0.5rem)] max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-b-none max-sm:pb-[calc(1.5rem+env(safe-area-inset-bottom))] max-sm:rtl:translate-x-0 max-sm:data-open:zoom-in-100 max-sm:data-open:slide-in-from-bottom-8 max-sm:data-closed:zoom-out-100 max-sm:data-closed:slide-out-to-bottom-8 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          className
        )}
        {...props}
      >
        {children}
        {showCloseButton && (
          <DialogPrimitive.Close data-slot="dialog-close" asChild>
            <Button
              variant="ghost"
              className="absolute top-[22px] end-5"
              size="icon-sm"
            >
              <IconX />
              <span className="sr-only">{closeLabel}</span>
            </Button>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-header"
      className={cn("flex flex-col gap-3", className)}
      {...props}
    />
  )
}

function DialogFooter({
  className,
  showCloseButton = false,
  closeLabel = "Close",
  ...props
}: React.ComponentProps<"div"> & {
  showCloseButton?: boolean
  /** Text of the footer's close button. */
  closeLabel?: string
}) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn(
        "flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end",
        // On a phone the buttons stay in view at the foot of the sheet while
        // a long form scrolls behind them (M.4). The negative offsets reach
        // over the sheet's own padding so the bar meets its edges.
        "max-sm:sticky max-sm:-bottom-[calc(1.5rem+env(safe-area-inset-bottom))] max-sm:z-[1] max-sm:-mx-6 max-sm:-mb-[calc(1.5rem+env(safe-area-inset-bottom))] max-sm:border-t max-sm:border-border max-sm:bg-popover max-sm:px-6 max-sm:pt-3 max-sm:pb-[calc(1.5rem+env(safe-area-inset-bottom))]",
        className
      )}
      {...props}
    >
      {showCloseButton && (
        <DialogPrimitive.Close asChild>
          <Button variant="outline">{closeLabel}</Button>
        </DialogPrimitive.Close>
      )}
      {props.children}
    </div>
  )
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn("pe-8 text-lg leading-snug font-semibold", className)}
      {...props}
    />
  )
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn("text-sm text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
