import * as React from "react"
import { cn } from "./utils"

/**
 * The everyday brand pattern, "sheets on paper" (docs/design/10-patterns.md):
 * white sheets with soft corners on the grey ground, and one of them resting
 * on its plane, the way the mark's sheet rests on the platform.
 *
 * - `PaperGround` is the grey ground the sheets lie on.
 * - `PaperSheet` is one sheet. `plane` sets it on the brand-coloured plane, a
 *   hard offset down and to the left that never mirrors in RTL (decision 4.3).
 *   Use it under the one thing that matters on the piece, and only once.
 *   `dashed` is the "not yet" sheet: something optional or still waiting.
 *
 * A clickable sheet on the plane lifts on hover like any clickable card.
 * Brand surfaces only (sign-in, covers, social): product screens use `Card`.
 * Never on a white-label tenant's pages, which carry their own brand.
 */

function PaperGround({ className, ...props }: React.ComponentProps<"div">) {
  return <div data-slot="paper-ground" className={cn("bg-background", className)} {...props} />
}

type SheetProps<T extends React.ElementType> = {
  as?: T
  /** Rest the sheet on its plane. One per piece. */
  plane?: boolean
  /** The "not yet" sheet: dashed and flat. Ignored with `plane`. */
  dashed?: boolean
  className?: string
} & Omit<React.ComponentPropsWithoutRef<T>, "as" | "className">

/*
 * The plane at sheet scale: 12px (0.75rem) down and to the start, in the brand
 * colour, growing to 16px on hover for something clickable. The shadow keeps
 * the sheet's corners, so the plane is the same rounded shape, like the mark.
 */
const PLANE = "shadow-[-0.75rem_0.75rem_0_0_var(--brand)]"
const PLANE_LIFT =
  "transition-[box-shadow,translate] duration-[var(--dur-fast)] ease-[var(--ease-out)] hover:translate-x-px hover:-translate-y-px hover:shadow-[-1rem_1rem_0_0_var(--brand)] focus-visible:translate-x-px focus-visible:-translate-y-px focus-visible:shadow-[-1rem_1rem_0_0_var(--brand)] motion-reduce:transition-none"

function PaperSheet<T extends React.ElementType = "div">({ as, plane = false, dashed = false, className, ...props }: SheetProps<T>) {
  const Comp: React.ElementType = as ?? "div"
  const interactive = Comp === "button" || Comp === "a"
  return (
    <Comp
      data-slot="paper-sheet"
      data-plane={plane || undefined}
      data-dashed={(!plane && dashed) || undefined}
      className={cn(
        "rounded-dialog bg-card text-card-foreground",
        plane ? PLANE : dashed ? "border border-dashed border-input" : "border border-border",
        plane && interactive && PLANE_LIFT,
        interactive &&
          "cursor-pointer text-start outline-none focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring",
        className
      )}
      {...props}
    />
  )
}

/** The mono eyebrow above a brand headline: Geist Mono, uppercase, tracked +6%. Arabic has no mono cut or case, so in RTL it is plain Noto Sans. */
function PaperEyebrow({ className, ...props }: React.ComponentProps<"p">) {
  return (
    <p
      data-slot="paper-eyebrow"
      className={cn("font-mono text-xs font-medium tracking-[0.06em] text-primary uppercase rtl:font-sans rtl:tracking-normal", className)}
      {...props}
    />
  )
}

/** A brand headline: Readex Pro 600, sentence case. Brand surfaces only, never product screens. */
function PaperHeadline({ className, ...props }: React.ComponentProps<"h2">) {
  return (
    <h2
      data-slot="paper-headline"
      className={cn("font-display text-2xl leading-tight font-semibold text-balance text-foreground sm:text-3xl", className)}
      {...props}
    />
  )
}

export { PaperGround, PaperSheet, PaperEyebrow, PaperHeadline }
