import * as React from "react"

import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * Where a person is in a flow of 3 to 6 steps (docs/design/components/Stepper).
 * Steps aren't links: people move with the form's own Back and Continue.
 */
export interface StepperProps {
  steps: { label: string; hint?: string }[]
  /** Index of the current step. */
  current: number
  orientation?: "horizontal" | "vertical"
  /** Names the stepper for assistive technology. */
  label: string
  /** "Step {n} of {total}", shown above the dots on a phone. */
  stepOfLabel?: (n: number, total: number) => string
  className?: string
}

function Stepper({ steps, current, orientation = "horizontal", label, stepOfLabel, className }: StepperProps) {
  const vertical = orientation === "vertical"
  return (
    <nav aria-label={label} data-slot="stepper" className={cn("grid gap-2", className)}>
      {!vertical && (
        <p className="text-sm text-muted-foreground sm:hidden">
          {stepOfLabel?.(current + 1, steps.length) ?? `Step ${current + 1} of ${steps.length}`}
          {steps[current] && <span className="font-medium text-foreground"> · {steps[current].label}</span>}
        </p>
      )}
      <ol className={cn("flex", vertical ? "flex-col gap-4" : "gap-0")}>
        {steps.map((step, index) => {
          const done = index < current
          const isCurrent = index === current
          const last = index === steps.length - 1
          return (
            <li
              key={step.label}
              aria-current={isCurrent ? "step" : undefined}
              className={cn("relative flex min-w-0 items-start gap-2.5", !vertical && !last && "flex-1")}
            >
              <span
                className={cn(
                  "grid size-7 flex-none place-items-center rounded-full border-[1.5px] border-input text-sm font-medium tabular-nums text-muted-foreground",
                  done && "border-primary bg-primary text-primary-foreground",
                  isCurrent && "border-primary bg-primary-tint text-primary-tint-foreground outline-[1.5px] outline-offset-2 outline-primary"
                )}
              >
                {done ? <Icon name="check" size={16} /> : index + 1}
              </span>
              <span className={cn("grid flex-none gap-0.5 pt-1", !vertical && "max-sm:hidden")}>
                <span
                  className={cn(
                    "text-sm font-medium whitespace-nowrap text-muted-foreground",
                    (done || isCurrent) && "text-foreground",
                    isCurrent && "font-semibold"
                  )}
                >
                  {step.label}
                </span>
                {step.hint && <span className="text-xs text-muted-foreground">{step.hint}</span>}
              </span>
              {!last && !vertical && (
                <span
                  aria-hidden
                  className={cn("mt-[13px] me-3 h-0.5 min-w-4 flex-1 rounded-[1px] bg-border", done && "bg-primary")}
                />
              )}
              {!last && vertical && (
                <span
                  aria-hidden
                  className={cn(
                    "absolute start-[13px] top-[34px] -bottom-2.5 w-0.5 rounded-[1px] bg-border",
                    done && "bg-primary"
                  )}
                />
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

export { Stepper }
