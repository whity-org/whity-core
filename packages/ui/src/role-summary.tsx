"use client"

import { Tooltip, TooltipContent, TooltipTrigger } from "./tooltip"
import { cn } from "./utils"

/**
 * A person's roles as a one-line subtitle under their name (WHIT-1175).
 *
 * Since a person can hold several roles in one workspace (WHIT-1079), joining
 * them all made the line under a name as long as the list, and with many roles
 * it pushed rows apart. This shows the first role and "+N more" on one line:
 * the first role truncates with an ellipsis, the count never does, and nothing
 * wraps. The whole list stays reachable:
 *
 * - on hover, through the `title`;
 * - for screen readers, as hidden text read in place of the short form;
 * - with `interactive`, through a focusable "+N more" with a tooltip, for a
 *   summary that is not already inside a button or link. Inside a control
 *   (a menu trigger, a picker row) a second focus stop is not allowed, so the
 *   control's own name carries the list and the screen it opens shows it.
 *
 * `before` and `after` are words that share the line, such as "Owner" or the
 * workspace name; they stay readable but give way to the roles' count first.
 * Every visible string is a label with an English default.
 */

export interface RoleSummaryLabels {
  /** "+{count} more" after the first role. */
  more?: (count: number) => string
  /** Between roles in the full list. */
  separator?: string
  /** Between `before`, the roles and `after`. */
  joiner?: string
  /** Accessible name of the interactive "+N more"; `{roles}` is the full list. */
  all?: string
}

export interface RoleSummaryProps {
  /** Role names, already in words, the primary one first. */
  roles: readonly string[]
  before?: string
  after?: string
  /** A focusable "+N more" with a tooltip; only outside another control. */
  interactive?: boolean
  labels?: RoleSummaryLabels
  className?: string
}

const defaultMore = (count: number) => `+${count} more`

/** The roles once each, in order, without empty names. */
export function uniqueRoles(roles: readonly string[]): string[] {
  return roles.filter((role, index) => role.trim() !== "" && roles.indexOf(role) === index)
}

/** The full sentence: before, every role, after. */
export function roleSummaryText(
  roles: readonly string[],
  { before, after, labels = {} }: Pick<RoleSummaryProps, "before" | "after" | "labels"> = {},
): string {
  const list = uniqueRoles(roles).join(labels.separator ?? ", ")
  return [before, list, after].filter((part) => part !== undefined && part !== "").join(labels.joiner ?? " · ")
}

function RoleSummary({ roles, before, after, interactive = false, labels = {}, className }: RoleSummaryProps) {
  const names = uniqueRoles(roles)
  const full = roleSummaryText(names, { before, after, labels })
  if (full === "") return null

  const joiner = labels.joiner ?? " · "
  const hidden = names.length - 1
  const more = hidden > 0 ? (labels.more ?? defaultMore)(hidden) : ""
  const lead = [before, names[0]].filter((part) => part !== undefined && part !== "").join(joiner)
  const visualOnly = interactive ? undefined : true
  const allLabel = (labels.all ?? "All roles: {roles}").replace("{roles}", names.join(labels.separator ?? ", "))

  return (
    <span
      data-slot="role-summary"
      title={hidden > 0 ? full : undefined}
      className={cn("flex min-w-0 max-w-full items-baseline gap-1 overflow-hidden whitespace-nowrap", className)}
    >
      {/* Without `interactive` the short form is for the eyes only and the full list for screen readers. */}
      {!interactive && <span className="sr-only">{full}</span>}
      {lead !== "" && (
        <span aria-hidden={visualOnly} className="min-w-0 truncate">
          {lead}
        </span>
      )}
      {hidden > 0 &&
        (interactive ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                aria-label={allLabel}
                data-slot="role-summary-more"
                className="shrink-0 rounded-sm underline decoration-dotted underline-offset-2 outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {more}
              </button>
            </TooltipTrigger>
            <TooltipContent>{names.join(labels.separator ?? ", ")}</TooltipContent>
          </Tooltip>
        ) : (
          <span aria-hidden={visualOnly} data-slot="role-summary-more" className="shrink-0">
            {more}
          </span>
        ))}
      {after !== undefined && after !== "" && (
        <span aria-hidden={visualOnly} className="min-w-0 truncate">
          {joiner.trimStart()}
          {after}
        </span>
      )}
    </span>
  )
}

export { RoleSummary }
