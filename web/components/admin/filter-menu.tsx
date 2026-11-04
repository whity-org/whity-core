'use client';

import { Button } from '@whity/ui/button';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@whity/ui/dropdown-menu';
import { IconChevronDown } from '@tabler/icons-react';

export interface FilterOption {
  value: string;
  label: string;
}

interface FilterMenuProps {
  /** The filter's name: Role, Unit, Two-step. */
  label: string;
  options: FilterOption[];
  selected: string[];
  onChange: (selected: string[]) => void;
  /** Only one value at a time, for a filter whose values exclude each other. */
  single?: boolean;
  testId?: string;
}

/**
 * A filter button in a list page's bar (section 21): when set it turns tinted and
 * names its value.
 */
export function FilterMenu({ label, options, selected, onChange, single = false, testId }: FilterMenuProps) {
  const names = options.filter((option) => selected.includes(option.value)).map((option) => option.label);
  const isSet = names.length > 0;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="outline"
          className={`gap-2 ${isSet ? 'border-primary bg-primary/10 text-primary hover:bg-primary/15' : ''}`}
          data-testid={testId}
        >
          <span className="max-w-64 truncate">{isSet ? `${label}: ${names.join(', ')}` : label}</span>
          <IconChevronDown size={16} aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="max-h-80 overflow-y-auto">
        {options.map((option) => (
          <DropdownMenuCheckboxItem
            key={option.value}
            checked={selected.includes(option.value)}
            onSelect={(event) => {
              // A list stays open so several values can be ticked; a single choice closes.
              if (!single) event.preventDefault();
            }}
            onCheckedChange={(checked) => {
              if (single) onChange(checked ? [option.value] : []);
              else if (checked) onChange([...selected, option.value]);
              else onChange(selected.filter((value) => value !== option.value));
            }}
          >
            {option.label}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
