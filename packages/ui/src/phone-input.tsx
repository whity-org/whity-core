"use client"

import * as React from "react"

import { Icon } from "./icon"
import { cn } from "./utils"

/**
 * A country in the phone input's list (docs/design/components/PhoneInput):
 * its calling code, the national lengths it accepts, which numbers are
 * mobiles, and how a national number is grouped when read.
 */
export interface PhoneCountry {
  code: string
  dial: string
  name: string
  ar: string
  len: number[]
  mobile: RegExp
  group: number[] | ((digits: string) => number[])
  sample: string
}

/** The region, the UK and the US (decision 2.x "Inputs": +962 default, regional list). */
export const phoneCountries: PhoneCountry[] = [
  { code: "JO", dial: "962", name: "Jordan", ar: "الأردن", len: [8, 9], mobile: /^7[789]/, group: (d) => (/^7/.test(d) ? [2, 3, 4] : [1, 3, 4]), sample: "79 123 4567" },
  { code: "SA", dial: "966", name: "Saudi Arabia", ar: "السعودية", len: [9], mobile: /^5/, group: [2, 3, 4], sample: "50 123 4567" },
  { code: "AE", dial: "971", name: "United Arab Emirates", ar: "الإمارات", len: [9], mobile: /^5/, group: [2, 3, 4], sample: "50 123 4567" },
  { code: "PS", dial: "970", name: "Palestine", ar: "فلسطين", len: [9], mobile: /^5/, group: [2, 3, 4], sample: "59 123 4567" },
  { code: "IQ", dial: "964", name: "Iraq", ar: "العراق", len: [10], mobile: /^7/, group: [3, 3, 4], sample: "790 123 4567" },
  { code: "EG", dial: "20", name: "Egypt", ar: "مصر", len: [10], mobile: /^1/, group: [3, 3, 4], sample: "100 123 4567" },
  { code: "LB", dial: "961", name: "Lebanon", ar: "لبنان", len: [7, 8], mobile: /^(3|7|8)/, group: [2, 3, 3], sample: "71 123 456" },
  { code: "KW", dial: "965", name: "Kuwait", ar: "الكويت", len: [8], mobile: /^[569]/, group: [4, 4], sample: "5012 3456" },
  { code: "QA", dial: "974", name: "Qatar", ar: "قطر", len: [8], mobile: /^[3567]/, group: [4, 4], sample: "3312 3456" },
  { code: "BH", dial: "973", name: "Bahrain", ar: "البحرين", len: [8], mobile: /^3/, group: [4, 4], sample: "3612 3456" },
  { code: "OM", dial: "968", name: "Oman", ar: "عُمان", len: [8], mobile: /^9/, group: [4, 4], sample: "9212 3456" },
  { code: "GB", dial: "44", name: "United Kingdom", ar: "المملكة المتحدة", len: [10], mobile: /^7/, group: [4, 3, 3], sample: "7400 123 456" },
  { code: "US", dial: "1", name: "United States", ar: "الولايات المتحدة", len: [10], mobile: /^/, group: [3, 3, 4], sample: "201 555 0123" },
]

/** Any other country: the person types the whole number with its code. */
const OTHER = "OTHER"

const ARABIC_INDIC = "٠١٢٣٤٥٦٧٨٩"
const EXTENDED_INDIC = "۰۱۲۳۴۵۶۷۸۹"

function westernDigits(raw: string): string {
  return raw
    .replace(/[٠-٩]/g, (d) => String(ARABIC_INDIC.indexOf(d)))
    .replace(/[۰-۹]/g, (d) => String(EXTENDED_INDIC.indexOf(d)))
}

function groupDigits(digits: string, group: number[]): string {
  const out: string[] = []
  let i = 0
  group.forEach((n, k) => {
    if (i < digits.length) {
      out.push(digits.substr(i, k === group.length - 1 ? 99 : n))
      i += n
    }
  })
  return out.join(" ")
}

/** The listed country and national digits of an E.164 number, or OTHER with all its digits. */
export function splitE164(value: string | null | undefined, fallback = "JO"): { country: string; digits: string } {
  const raw = westernDigits(value ?? "").trim()
  if (!raw.startsWith("+")) {
    return { country: fallback, digits: raw.replace(/\D/g, "").replace(/^0+/, "") }
  }
  const all = raw.replace(/\D/g, "")
  const match = [...phoneCountries]
    .sort((a, b) => b.dial.length - a.dial.length)
    .find((c) => all.startsWith(c.dial) && c.len.includes(all.length - c.dial.length))
  return match ? { country: match.code, digits: all.slice(match.dial.length) } : { country: OTHER, digits: all }
}

export type PhoneInputLabels = Partial<Record<"country" | "other" | "otherSample" | "short" | "long" | "mobile" | "invalid", string>>

export interface PhoneInputProps {
  label: React.ReactNode
  /** ISO code of the country chosen first; Jordan by default. */
  defaultCountry?: string
  /** A number to start from: E.164, or a national number in `defaultCountry`. */
  defaultValue?: string | null
  /** Only mobiles (text messages and WhatsApp). */
  mobileOnly?: boolean
  /** Names the countries in Arabic. */
  lang?: "en" | "ar"
  labels?: PhoneInputLabels
  helperText?: React.ReactNode
  /** An error from the server; replaces the field's own. */
  errorText?: React.ReactNode
  disabled?: boolean
  readOnly?: boolean
  id?: string
  className?: string
  /**
   * When the field is left, or the country changes: the number as E.164 (null
   * when empty) and whether it is a whole number for its country.
   */
  onChange?: (e164: string | null, valid: boolean) => void
}

/**
 * A phone number with its country code in the same control
 * (docs/design/components/PhoneInput). Jordan (+962) first; the national
 * number is grouped the way it is read, a leading 0 is dropped, Arabic-Indic
 * digits are accepted. Left to right in both languages, like every number.
 * Errors appear once the field is left, never while typing.
 */
function PhoneInput({
  label,
  defaultCountry = "JO",
  defaultValue,
  mobileOnly = false,
  lang = "en",
  labels,
  helperText,
  errorText,
  disabled,
  readOnly,
  id,
  className,
  onChange,
}: PhoneInputProps) {
  const reactId = React.useId()
  const baseId = id ?? reactId
  const L = {
    country: "Country code",
    other: "Other country",
    otherSample: "+33 6 12 34 56 78",
    short: "This number is too short for {country}.",
    long: "This number is too long for {country}.",
    mobile: "Use a mobile number for text messages.",
    invalid: "Enter the number with its country code, such as +33 6 12 34 56 78.",
    ...labels,
  }
  const start = React.useMemo(() => splitE164(defaultValue, defaultCountry), [defaultValue, defaultCountry])
  const [country, setCountry] = React.useState(start.country)
  const [digits, setDigits] = React.useState(start.digits)
  const [touched, setTouched] = React.useState(false)

  const listed = phoneCountries.find((c) => c.code === country) ?? null
  const name = listed ? (lang === "ar" ? listed.ar : listed.name) : L.other

  const problem = (code: string, d: string): string | null => {
    if (d === "") return null
    const c = phoneCountries.find((x) => x.code === code)
    const cname = c ? (lang === "ar" ? c.ar : c.name) : ""
    if (!c) return d.length < 7 || d.length > 15 || d.startsWith("0") ? L.invalid : null
    if (d.length < Math.min(...c.len)) return L.short.replace("{country}", cname)
    if (d.length > Math.max(...c.len)) return L.long.replace("{country}", cname)
    if (mobileOnly && !c.mobile.test(d)) return L.mobile
    return null
  }
  const e164 = (code: string, d: string): string | null => {
    if (d === "") return null
    const c = phoneCountries.find((x) => x.code === code)
    return "+" + (c ? c.dial : "") + d
  }
  const emit = (code: string, d: string) => onChange?.(e164(code, d), problem(code, d) === null)

  const ownError = touched ? problem(country, digits) : null
  const error = errorText ?? ownError
  const describedBy = error ? `${baseId}-error` : helperText ? `${baseId}-help` : undefined

  const read = (raw: string): string => {
    let d = westernDigits(raw).replace(/\D/g, "")
    if (listed && d.startsWith(listed.dial) && d.length > Math.max(...listed.len)) d = d.slice(listed.dial.length)
    return listed ? d.replace(/^0+/, "") : d
  }
  const shown = listed ? groupDigits(digits, typeof listed.group === "function" ? listed.group(digits) : listed.group) : digits ? "+" + digits : ""

  return (
    <div className={cn("space-y-1.5", className)}>
      <label htmlFor={baseId} className="block text-sm font-medium text-foreground">
        {label}
      </label>
      <div
        dir="ltr"
        data-slot="phone-input"
        className={cn(
          "flex h-10 overflow-hidden rounded-control border bg-card focus-within:ring-2 focus-within:ring-ring",
          error ? "border-destructive" : "border-input",
          disabled && "opacity-50"
        )}
      >
        <label className="relative inline-flex shrink-0 items-center gap-1 border-e border-input bg-muted pe-2 ps-2.5 font-mono text-sm text-foreground">
          <span className="sr-only">{L.country}</span>
          <select
            className="absolute inset-0 cursor-pointer opacity-0"
            value={country}
            disabled={disabled || readOnly}
            data-testid={`${baseId}-country`}
            onChange={(event) => {
              const next = event.target.value
              const from = listed
              // Keep what was typed: moving to Other carries the code along, and back strips it.
              let d = digits
              if (next === OTHER && from && d !== "") d = from.dial + d
              const to = phoneCountries.find((x) => x.code === next)
              if (from === null && to && d.startsWith(to.dial)) d = d.slice(to.dial.length)
              setCountry(next)
              setDigits(d)
              emit(next, d)
            }}
          >
            {phoneCountries.map((c) => (
              <option key={c.code} value={c.code}>
                {(lang === "ar" ? c.ar : c.name) + " +" + c.dial}
              </option>
            ))}
            <option value={OTHER}>{L.other}</option>
          </select>
          <span aria-hidden="true">{listed ? "+" + listed.dial : "+"}</span>
          <Icon name="chevron-down" size={14} className="text-muted-foreground" />
        </label>
        <input
          id={baseId}
          type="tel"
          inputMode="tel"
          autoComplete={listed ? "tel-national" : "tel"}
          placeholder={listed ? listed.sample : L.otherSample}
          className="min-w-0 flex-1 bg-transparent px-2.5 text-sm tabular-nums text-foreground outline-none"
          value={shown}
          disabled={disabled}
          readOnly={readOnly}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy}
          title={name}
          onChange={(event) => setDigits(read(event.target.value))}
          onBlur={() => {
            setTouched(true)
            emit(country, digits)
          }}
        />
      </div>
      {error ? (
        <p id={`${baseId}-error`} role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : helperText ? (
        <p id={`${baseId}-help`} className="text-sm text-muted-foreground">
          {helperText}
        </p>
      ) : null}
    </div>
  )
}

export { PhoneInput }
