"use client"

import * as React from "react"

import { cn } from "./utils"

const ARABIC_INDIC = "٠١٢٣٤٥٦٧٨٩"
const EXTENDED_INDIC = "۰۱۲۳۴۵۶۷۸۹"

/** Western digits only: Arabic-Indic and extended Arabic-Indic digits are converted. */
function toDigits(raw: string): string {
  return raw
    .replace(/[٠-٩]/g, (d) => String(ARABIC_INDIC.indexOf(d)))
    .replace(/[۰-۹]/g, (d) => String(EXTENDED_INDIC.indexOf(d)))
    .replace(/\D/g, "")
}

export interface OtpInputProps {
  label: string
  /** Number of boxes; split in two halves for reading. */
  length?: number
  value?: string
  defaultValue?: string
  onChange?: (value: string) => void
  /** Called once every box is filled. */
  onComplete?: (value: string) => void
  helperText?: string
  /** What went wrong and what to do next. */
  errorText?: string
  disabled?: boolean
  autoFocus?: boolean
  id?: string
  className?: string
}

/**
 * Six boxes for a one-time code (docs/design/components/OtpInput). Typing moves
 * on, Backspace goes back, a pasted code fills every box. The first box asks
 * the browser for SMS autofill. Digits only, always left to right.
 */
function OtpInput({
  label,
  length = 6,
  value,
  defaultValue = "",
  onChange,
  onComplete,
  helperText,
  errorText,
  disabled,
  autoFocus,
  id,
  className,
}: OtpInputProps) {
  const reactId = React.useId()
  const baseId = id ?? reactId
  const [inner, setInner] = React.useState(() => toDigits(defaultValue).slice(0, length))
  const current = (value ?? inner).slice(0, length)
  const refs = React.useRef<Array<HTMLInputElement | null>>([])

  const commit = (next: string) => {
    const digits = next.slice(0, length)
    if (value === undefined) setInner(digits)
    onChange?.(digits)
    if (digits.length === length) onComplete?.(digits)
  }

  const focusAt = (index: number) => refs.current[Math.max(0, Math.min(length - 1, index))]?.focus()

  const onType = (index: number, raw: string) => {
    const typed = toDigits(raw)
    if (!typed) return
    const before = current.slice(0, index)
    const next = (before + typed + current.slice(index + typed.length)).slice(0, length)
    commit(next)
    focusAt(Math.min(index + typed.length, length - 1))
  }

  const onKeyDown = (index: number, event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Backspace") {
      event.preventDefault()
      if (current[index]) {
        commit(current.slice(0, index) + current.slice(index + 1))
      } else if (index > 0) {
        commit(current.slice(0, index - 1) + current.slice(index))
        focusAt(index - 1)
      }
    } else if (event.key === "ArrowLeft") {
      event.preventDefault()
      focusAt(index - 1)
    } else if (event.key === "ArrowRight") {
      event.preventDefault()
      focusAt(index + 1)
    }
  }

  const onPaste = (event: React.ClipboardEvent<HTMLInputElement>) => {
    const pasted = toDigits(event.clipboardData.getData("text"))
    if (!pasted) return
    event.preventDefault()
    commit(pasted.slice(0, length))
    focusAt(Math.min(pasted.length, length - 1))
  }

  const half = Math.ceil(length / 2)
  const describedBy = errorText ? `${baseId}-error` : helperText ? `${baseId}-help` : undefined

  return (
    <div className={cn("space-y-2", className)}>
      <span id={`${baseId}-label`} className="block text-sm font-medium text-foreground">
        {label}
      </span>
      <div dir="ltr" role="group" aria-labelledby={`${baseId}-label`} aria-describedby={describedBy} className="flex items-center justify-center gap-2">
        {Array.from({ length }, (_, index) => (
          <React.Fragment key={index}>
            {index === half && (
              <span aria-hidden="true" className="text-muted-foreground">
                -
              </span>
            )}
            <input
              ref={(node) => {
                refs.current[index] = node
              }}
              type="text"
              inputMode="numeric"
              pattern="[0-9]*"
              autoComplete={index === 0 ? "one-time-code" : "off"}
              maxLength={length}
              disabled={disabled}
              autoFocus={autoFocus && index === 0}
              aria-label={`${label} ${index + 1} / ${length}`}
              aria-invalid={errorText ? true : undefined}
              value={current[index] ?? ""}
              onChange={(event) => onType(index, event.target.value)}
              onKeyDown={(event) => onKeyDown(index, event)}
              onPaste={onPaste}
              onFocus={(event) => event.target.select()}
              className={cn(
                "h-12 w-11 rounded-control border bg-card text-center font-mono text-xl text-foreground outline-none",
                "focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50",
                errorText ? "border-destructive" : "border-input"
              )}
            />
          </React.Fragment>
        ))}
      </div>
      {errorText ? (
        <p id={`${baseId}-error`} role="alert" className="text-sm text-destructive">
          {errorText}
        </p>
      ) : helperText ? (
        <p id={`${baseId}-help`} className="text-sm text-muted-foreground">
          {helperText}
        </p>
      ) : null}
    </div>
  )
}

export { OtpInput }
