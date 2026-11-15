'use client';

import { Icon } from '@whity/ui/icon';
import { cn } from '@/lib/utils';

/** Words typed into the box, split on spaces, commas and new lines. */
export function splitChips(text: string): string[] {
  return text
    .split(/[\s,]+/)
    .map((item) => item.trim())
    .filter((item) => item !== '');
}

export interface ChipsInputProps {
  id: string;
  /** The values already turned into chips. */
  values: string[];
  /** What is typed in the box and not yet a chip. */
  input: string;
  onChange: (values: string[], input: string) => void;
  placeholder: string;
  /** Shown as a quiet chip when there is nothing yet, such as “Any domain”. */
  emptyChip?: string;
  /** The remove button's name; the value sits inside the phrase. */
  removeLabel: (value: string) => string;
  testId?: string;
}

/**
 * Free-text values as chips (domains, site addresses): the `x4-chipin` look in
 * the TplSettingsSignup and TplSettingsPublicForms cards (WHIT-1044). Enter, a
 * comma or a space turns what is typed into a chip; Backspace in an empty box
 * takes the last chip off. The caller decides whether text left in the box
 * counts, so nothing typed is lost on save.
 */
export function ChipsInput({
  id,
  values,
  input,
  onChange,
  placeholder,
  emptyChip,
  removeLabel,
  testId,
}: ChipsInputProps) {
  const commit = () => {
    const added = splitChips(input).filter((value) => !values.includes(value));
    if (added.length > 0 || input !== '') onChange([...values, ...added], '');
  };
  return (
    <div
      className={cn(
        'flex min-h-10 flex-wrap items-center gap-1.5 rounded-control border border-input bg-muted px-2 py-1.5',
        'focus-within:ring-2 focus-within:ring-ring'
      )}
      data-testid={testId}
    >
      {values.length === 0 && input === '' && emptyChip && (
        <span className="rounded-full border border-border bg-card px-2.5 py-0.5 text-sm text-muted-foreground">
          {emptyChip}
        </span>
      )}
      {values.map((value) => (
        <span
          key={value}
          dir="ltr"
          className="flex items-center gap-1 rounded-full border border-border bg-card py-0.5 ps-2.5 pe-1 text-sm text-foreground"
        >
          {value}
          <button
            type="button"
            className="grid size-6 place-items-center rounded-full text-muted-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
            aria-label={removeLabel(value)}
            onClick={() => onChange(values.filter((other) => other !== value), input)}
          >
            <Icon name="close" size={12} />
          </button>
        </span>
      ))}
      <input
        id={id}
        dir="ltr"
        value={input}
        placeholder={placeholder}
        className="min-w-32 flex-1 bg-transparent px-1 text-sm text-foreground outline-none placeholder:text-muted-foreground"
        onChange={(event) => {
          const value = event.target.value;
          // A comma or a space ends a value, so a pasted list becomes chips at once.
          if (/[\s,]$/.test(value) && value.trim() !== '') {
            const added = splitChips(value).filter((item) => !values.includes(item));
            onChange([...values, ...added], '');
          } else {
            onChange(values, value);
          }
        }}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            commit();
          } else if (event.key === 'Backspace' && input === '' && values.length > 0) {
            onChange(values.slice(0, -1), '');
          }
        }}
        onBlur={commit}
      />
    </div>
  );
}
