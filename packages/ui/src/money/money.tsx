import * as React from "react"

import { cn } from "../utils"
import { moneyText } from "./currency"

/**
 * An amount as people read it (docs/design/components/Money, CU.2): $312.00,
 * JOD 312.000, −$35.00. Western digits, isolated left to right so it never
 * flips in Arabic text, in the mono face amounts use.
 *
 * `minor` is the stored integer of minor units; `currency` is the amount's own
 * currency (the row's), or the workspace's when the screen has no row.
 */
export interface MoneyProps extends Omit<React.ComponentProps<"bdi">, "children"> {
  minor: number
  currency: string
}

function Money({ minor, currency, className, ...props }: MoneyProps) {
  return (
    <bdi
      {...props}
      dir="ltr"
      data-slot="money"
      data-negative={minor < 0 ? "" : undefined}
      className={cn("font-mono tabular-nums whitespace-nowrap", className)}
    >
      {moneyText(minor, currency)}
    </bdi>
  )
}

export { Money }
