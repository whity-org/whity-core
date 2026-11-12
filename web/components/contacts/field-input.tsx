'use client';

/**
 * One of the workspace's own fields as an input (WHIT-954): text, long text,
 * choose one, choose several, amount, date, or yes or no. The value is what the
 * API takes under `fields.<key>`.
 *
 * A person field (`profile_ref`) is not offered on these screens yet: it needs
 * a people picker, and showing people as numbers is not allowed. Screens skip
 * such fields with {@see isEditableHere}.
 */

import { Input } from '@/components/ui/input';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import type { CustomField } from '@/lib/contacts/api';

/** Whether this screen can show and change the field. */
export function isEditableHere(field: CustomField): boolean {
  return field.kind !== 'profile_ref';
}

export function fieldLabel(field: CustomField, language: string | null): string {
  const preferred = language === 'ar' ? field.label.ar : field.label.en;
  return preferred !== '' ? preferred : field.label.en !== '' ? field.label.en : field.label.ar;
}

export function FieldInput({
  field,
  value,
  onChange,
  disabled,
}: {
  field: CustomField;
  value: unknown;
  onChange: (value: unknown) => void;
  disabled?: boolean;
}) {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const id = `field-${field.key}`;
  const label = fieldLabel(field, language) + (field.required ? ' *' : '');

  switch (field.kind) {
    case 'textarea':
      return (
        <div className="space-y-1.5">
          <label htmlFor={id} className="text-sm font-medium">
            {label}
          </label>
          <Textarea id={id} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)} disabled={disabled} />
        </div>
      );
    case 'select':
      return (
        <div className="space-y-1.5">
          <label htmlFor={id} className="text-sm font-medium">
            {label}
          </label>
          <Select
            value={typeof value === 'string' && value !== '' ? value : '__none'}
            onValueChange={(v) => onChange(v === '__none' ? null : v)}
            disabled={disabled}
          >
            <SelectTrigger id={id}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none">{t('contacts.field.none', 'Not set')}</SelectItem>
              {field.options.map((option) => (
                <SelectItem key={option} value={option}>
                  {option}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      );
    case 'multiselect': {
      const picked = Array.isArray(value) ? value.map(String) : [];
      return (
        <fieldset className="space-y-1.5">
          <legend className="text-sm font-medium">{label}</legend>
          <div className="flex flex-wrap gap-3">
            {field.options.map((option) => (
              <label key={option} className="flex min-h-(--control-h) items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="size-4"
                  checked={picked.includes(option)}
                  disabled={disabled}
                  onChange={(e) =>
                    onChange(e.target.checked ? [...picked, option] : picked.filter((p) => p !== option))
                  }
                />
                {option}
              </label>
            ))}
          </div>
        </fieldset>
      );
    }
    case 'checkbox':
      return (
        <div className="flex min-h-(--control-h) items-center justify-between gap-3">
          <label htmlFor={id} className="text-sm font-medium">
            {label}
          </label>
          <Switch id={id} checked={value === true} onCheckedChange={(c) => onChange(c)} disabled={disabled} />
        </div>
      );
    case 'number':
      return (
        <Input
          id={id}
          label={label}
          inputMode="decimal"
          dir="ltr"
          className="font-mono"
          value={typeof value === 'string' || typeof value === 'number' ? String(value) : ''}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    case 'date':
      return (
        <Input
          id={id}
          type="date"
          label={label}
          dir="ltr"
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    default:
      return (
        <Input
          id={id}
          label={label}
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      );
  }
}
