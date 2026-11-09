import { Mark } from "./mark"
import { cn } from "./utils"

/**
 * The horizontal lockup (docs/design/01-logo.md): the mark, then "Whity" in
 * Readex Pro 600, always in Latin letters and always left to right, also in
 * Arabic. The word's cap height is about the plane's side and it starts a
 * short gap after the sheet. `size` is the mark's height in pixels; use 20 or
 * more. Whity's own surfaces only: a white-labelled tenant shows `TenantLogo`.
 */
function Lockup({
  size = 32,
  label = "Whity",
  className,
}: {
  size?: number
  /** The accessible name of the whole lockup. */
  label?: string
  className?: string
}) {
  return (
    <span
      data-slot="lockup"
      role="img"
      aria-label={label}
      dir="ltr"
      className={cn("inline-flex shrink-0 items-center text-foreground", className)}
      style={{ gap: Math.round(size * 0.16) }}
    >
      <Mark size={size} label="" />
      <span
        aria-hidden="true"
        className="font-display leading-none font-semibold"
        style={{ fontSize: Math.round(size * 0.94), letterSpacing: "-0.01em" }}
      >
        Whity
      </span>
    </span>
  )
}

export { Lockup }
