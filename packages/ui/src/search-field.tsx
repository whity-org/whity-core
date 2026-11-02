import * as React from "react"

import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * The search box for lists and the top bar (docs/design/components/SearchField).
 * Shows the shortcut until you type, then a clear button. Escape clears; Enter
 * calls `onSearch`.
 */
export interface SearchFieldProps extends Omit<React.ComponentProps<"input">, "value" | "onChange" | "type"> {
  value: string
  onValueChange: (value: string) => void
  onSearch?: (value: string) => void
  /** Names the box for assistive technology. */
  label: string
  clearLabel: string
  /** The shortcut hint, such as "Ctrl K". Leave out in lists. */
  shortcut?: string
}

function SearchField({ value, onValueChange, onSearch, label, clearLabel, shortcut, className, onKeyDown, ...props }: SearchFieldProps) {
  return (
    <div data-slot="search-field" className={cn("relative flex min-w-0 items-center", className)}>
      <Icon name="search" size={16} className="pointer-events-none absolute start-2.5 text-muted-foreground" />
      <input
        {...props}
        type="search"
        aria-label={label}
        value={value}
        onChange={(event) => onValueChange(event.target.value)}
        onKeyDown={(event) => {
          onKeyDown?.(event)
          if (event.defaultPrevented) return
          if (event.key === "Escape" && value !== "") {
            event.preventDefault()
            onValueChange("")
          } else if (event.key === "Enter") {
            onSearch?.(value)
          }
        }}
        className="h-10 w-full min-w-0 rounded-control border border-input bg-card ps-8.5 pe-12 text-sm outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 [&::-webkit-search-cancel-button]:hidden"
      />
      {value !== "" ? (
        <button
          type="button"
          aria-label={clearLabel}
          title={clearLabel}
          onClick={() => onValueChange("")}
          className="absolute end-2 grid rounded-sm p-1 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/30"
        >
          <Icon name="close" size={16} />
        </button>
      ) : (
        shortcut && (
          <kbd className="absolute end-2 rounded-sm border border-border bg-muted px-1 font-mono text-xs text-muted-foreground">
            {shortcut}
          </kbd>
        )
      )}
    </div>
  )
}

export { SearchField }
