'use client';

/**
 * A labelled choice from a list, in the design system's Select (never a
 * native select element). `value` and `onChange` are strings; a stored value that
 * is not in `options` stays selectable rather than snapping to the first one.
 * Radix forbids an empty item value, so an empty `value` shows the placeholder.
 */

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';

export interface FieldSelectOption {
  value: string;
  label: string;
}

export function FieldSelect({
  id,
  value,
  options,
  onChange,
  disabled,
  invalid,
  describedBy,
  placeholder,
  className,
}: {
  id: string;
  value: string;
  options: readonly FieldSelectOption[];
  onChange: (value: string) => void;
  disabled?: boolean;
  invalid?: boolean;
  describedBy?: string;
  placeholder?: string;
  className?: string;
}) {
  const known = value === '' || options.some((option) => option.value === value);
  return (
    <Select value={value === '' ? undefined : value} onValueChange={onChange} disabled={disabled}>
      <SelectTrigger
        id={id}
        aria-invalid={invalid || undefined}
        aria-describedby={describedBy}
        className={className ?? 'w-full'}
      >
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent position="popper">
        {!known && <SelectItem value={value}>{value}</SelectItem>}
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
