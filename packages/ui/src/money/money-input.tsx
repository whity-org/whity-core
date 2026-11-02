import * as React from "react"

import { cn } from "../utils"
import { currencyExponent } from "./currency"

/**
 * A money field (docs/design/components/MoneyInput): the currency as a fixed
 * prefix, figures end-aligned and left to right, formatted to the currency's
 * decimals on blur (JOD 1,234.500). Arabic-Indic digits and the Arabic decimal
 * mark are accepted as typed and become Western digits (3.7b).
 *
 * The value is a decimal STRING in normal units ("1234.5"), never minor units.
 * Screens convert at the API boundary with `parseMoney` and `toDecimalString`.
 */
export interface MoneyInputProps extends Omit<React.ComponentProps<"input">, "value" | "onChange" | "type"> {
  currency: string
  value: string
  onValueChange: (value: string) => void
  /** "No more than {n} decimals for {currency}." Shown when there are too many. */
  tooManyDecimalsLabel?: (decimals: number, currency: string) => string
}

/** Western digits and a plain "." from whatever was typed, separators dropped. */
function normaliseMoneyText(raw: string): string {
  return raw
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
    .replace(/٫/g, ".")
    .replace(/[,٬\s]/g, "")
}

function decimalsOf(text: string): number {
  const dot = text.indexOf(".")
  return dot === -1 ? 0 : text.length - dot - 1
}

function formatForBlur(text: string, decimals: number): string {
  if (!/^-?\d*\.?\d*$/.test(text) || text === "" || text === "." || text === "-") return text
  const negative = text.startsWith("-")
  const [whole = "", fraction = ""] = (negative ? text.slice(1) : text).split(".")
  const grouped = (whole === "" ? "0" : whole.replace(/^0+(?=\d)/, "")).replace(/\B(?=(\d{3})+(?!\d))/g, ",")
  const padded = decimals > 0 ? `.${fraction.padEnd(decimals, "0")}` : ""
  return `${negative ? "-" : ""}${grouped}${padded}`
}

function MoneyInput({
  currency,
  value,
  onValueChange,
  tooManyDecimalsLabel,
  className,
  onBlur,
  onFocus,
  disabled,
  ...props
}: MoneyInputProps) {
  const [focused, setFocused] = React.useState(false)
  const exponent = currencyExponent(currency)
  const tooMany = decimalsOf(value) > exponent

  return (
    <div className="grid gap-1">
      <div
        data-slot="money-input"
        data-invalid={tooMany ? "" : undefined}
        className={cn(
          "flex h-10 items-stretch overflow-hidden rounded-control border border-input bg-card focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30 data-invalid:border-destructive has-disabled:cursor-not-allowed has-disabled:opacity-55",
          className
        )}
      >
        <span className="grid place-items-center border-e border-border bg-muted px-2.5 font-mono text-xs font-medium text-muted-foreground">
          {currency}
        </span>
        <input
          {...props}
          type="text"
          inputMode="decimal"
          dir="ltr"
          disabled={disabled}
          aria-invalid={tooMany || undefined}
          value={focused || tooMany ? value : formatForBlur(value, exponent)}
          onChange={(event) => onValueChange(normaliseMoneyText(event.target.value))}
          onFocus={(event) => {
            setFocused(true)
            onFocus?.(event)
          }}
          onBlur={(event) => {
            setFocused(false)
            onBlur?.(event)
          }}
          className="min-w-0 flex-1 border-0 bg-transparent px-2.5 text-right text-sm tabular-nums text-foreground outline-none"
        />
      </div>
      {tooMany && (
        <p className="text-xs text-destructive">
          {tooManyDecimalsLabel?.(exponent, currency) ?? `No more than ${exponent} decimals for ${currency}.`}
        </p>
      )}
    </div>
  )
}

export { MoneyInput, normaliseMoneyText }
