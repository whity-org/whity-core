"use client"

import * as React from "react"
import { Tooltip as TooltipPrimitive } from "radix-ui"

import { cn } from "./utils"

/**
 * Compound Tooltip (Provider/Root/Trigger/Content), matching the same Radix-
 * wrapping convention as Dialog/DropdownMenu/Select in this package —
 * PLATFORM NOTE: this compound shape (Provider+Root+Trigger+Content) is a
 * WEB/Radix idiom. A future Flutter/Dart port has no equivalent multi-part
 * API — it would flatten this to a single `Tooltip(message, child)` widget.
 * What DOES carry over is the semantic content: `side` (top/right/bottom/
 * left) and the delay duration are plain, portable concepts a Flutter
 * tooltip can express with its own props of the same meaning.
 */

function TooltipProvider({
  // Tooltips appear after 500 ms on hover and at once on keyboard focus (stage 8).
  delayDuration = 500,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Provider>) {
  return (
    <TooltipPrimitive.Provider
      data-slot="tooltip-provider"
      delayDuration={delayDuration}
      {...props}
    />
  )
}

function Tooltip({
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Root>) {
  return (
    <TooltipProvider>
      <TooltipPrimitive.Root data-slot="tooltip" {...props} />
    </TooltipProvider>
  )
}

function TooltipTrigger({
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Trigger>) {
  return <TooltipPrimitive.Trigger data-slot="tooltip-trigger" {...props} />
}

function TooltipContent({
  className,
  sideOffset = 6,
  children,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        data-slot="tooltip-content"
        sideOffset={sideOffset}
        className={cn(
          // The reference is docs/design/components/Tooltip (`.wy-tip__bubble`): ink on the page colour.
          "z-50 inline-flex max-w-64 items-center gap-1.5 rounded-md bg-foreground px-2 py-[5px] text-xs leading-tight font-medium text-balance text-background data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0 motion-reduce:animate-none [&_kbd]:font-mono [&_kbd]:text-2xs [&_kbd]:opacity-75",
          className
        )}
        {...props}
      >
        {children}
      </TooltipPrimitive.Content>
    </TooltipPrimitive.Portal>
  )
}

/**
 * The icon rail's tooltip (AppShellCollapsed): the item's name beside the
 * rail, towards the page. The rail sits on the right in Arabic, so the side
 * follows the document's direction (Radix sides are physical). With `show`
 * false the child renders on its own, so the expanded sidebar has no tooltips
 * over labels it already shows.
 */
function RailTooltip({
  label,
  show = true,
  children,
}: {
  label: React.ReactNode
  show?: boolean
  children: React.ReactElement
}) {
  if (!show) return children
  const rtl = typeof document !== "undefined" && document.documentElement.dir === "rtl"
  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent side={rtl ? "left" : "right"} data-slot="rail-tooltip">
        {label}
      </TooltipContent>
    </Tooltip>
  )
}

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider, RailTooltip }
