"use client"

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@whity/ui/select"

/** One Whity select for a short list of choices. `value` is always one of `options`. */
export function ChoiceSelect({
  id,
  value,
  options,
  onChange,
  label,
  disabled,
}: {
  id?: string
  value: string
  options: Array<{ value: string; label: string }>
  onChange: (next: string) => void
  /** Names the control when no visible label sits beside it. */
  label?: string
  disabled?: boolean
}) {
  return (
    <Select value={value} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger id={id} aria-label={label} className="w-full">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
