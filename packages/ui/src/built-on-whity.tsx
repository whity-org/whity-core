import * as React from "react"
import { cn } from "./utils"

/**
 * The credit a partner or tenant may show (docs/design/components/BuiltOnWhity):
 * the mono small-cut mark and "Built on Whity", linking to whity.dev.
 *
 * Use it in footers, About screens and the sidebar foot of a white-labelled app,
 * never in a header and never larger than the partner's own logo. The mark takes
 * the text colour, so it follows light and dark. `label` is translated text; the
 * name "Whity" stays in Latin letters.
 */
function BuiltOnWhity({
  href = "https://whity.dev",
  label = "Built on Whity",
  className,
}: {
  href?: string
  label?: string
  className?: string
}) {
  const maskId = React.useId()
  return (
    <a
      data-slot="built-on-whity"
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className={cn(
        "inline-flex items-center gap-2 rounded-sm font-mono text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring",
        className
      )}
    >
      <svg aria-hidden="true" viewBox="0 0 32 32" width="20" height="20" className="shrink-0">
        <defs>
          <mask id={maskId}>
            <rect x="-100" y="-100" width="400" height="400" fill="#fff" />
            <path
              d="M16.0 1.125H24.75A6.125 6.125 0 0 1 30.875 7.25V16.0A6.125 6.125 0 0 1 24.75 22.125H16.0A6.125 6.125 0 0 1 9.875 16.0V7.25A6.125 6.125 0 0 1 16.0 1.125Z"
              fill="#000"
            />
          </mask>
        </defs>
        <rect x="3" y="11" width="17.5" height="17.5" rx="4.5" fill="currentColor" stroke="currentColor" strokeWidth="3.25" mask={`url(#${maskId})`} />
        <rect x="11.5" y="2.75" width="17.75" height="17.75" rx="4.5" fill="none" stroke="currentColor" strokeWidth="3.25" />
      </svg>
      <span>{label}</span>
    </a>
  )
}

export { BuiltOnWhity }
