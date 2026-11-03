'use client';

/**
 * The question kinds that hold more than one value: several files, a table of rows,
 * and a signature. Drawn from {@link FormField}, which also draws each cell of a row.
 */

import { useState, type ReactNode } from 'react';
import { Button } from '@whity/ui/button';
import { Input } from '@whity/ui/input';
import { useTranslation } from '@whity/features/i18n';
import type { Answer, FileUploader, FormFieldSpec, LocalizedText } from '@/components/forms/form-fields';

export interface TableColumn {
  key: string;
  type: string;
  label: LocalizedText | string;
  required?: boolean;
  options?: FormFieldSpec['options'];
}

export type TableRow = Record<string, Answer>;

export function humanSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Several files on one question. Each is uploaded as it is picked and the answer is
 * the list of references. A failed upload says so and leaves the others attached,
 * because one bad file should not cost the ones that worked.
 */
export function SeveralFilesField({
  id,
  accept,
  max,
  value,
  upload,
  onChange,
}: {
  id: string;
  accept: string;
  max: number;
  value: string[];
  upload: FileUploader | undefined;
  onChange: (value: string[]) => void;
}) {
  const t = useTranslation('forms');
  const [names, setNames] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (upload === undefined) {
    return (
      <p className="text-sm text-muted-foreground" role="note">
        {t('field.noFiles', 'Files cannot be attached here.')}
      </p>
    );
  }

  async function add(picked: File[]) {
    if (upload === undefined) return;

    setBusy(true);
    setError(null);
    let current = value;
    for (const file of picked.slice(0, Math.max(max - current.length, 0))) {
      try {
        const stored = await upload(file);
        const name = t('field.attached', 'Attached: {name} ({size})', {
          name: stored.filename ?? file.name,
          size: humanSize(stored.byte_size),
        });
        setNames((known) => ({ ...known, [stored.reference]: name }));
        current = [...current, stored.reference];
        onChange(current);
      } catch (cause) {
        setError(cause instanceof Error ? cause.message : t('field.uploadFailed', 'That file could not be uploaded. Please try again.'));
      }
    }
    if (picked.length > max - value.length) {
      setError(t('field.tooManyFiles', 'You can attach up to {max} files here.', { max }));
    }
    setBusy(false);
  }

  return (
    <div className="space-y-1.5">
      <input
        id={id}
        type="file"
        multiple
        accept={accept}
        disabled={busy || value.length >= max}
        className="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-secondary/80"
        onChange={(event) => {
          const picked = Array.from(event.target.files ?? []);
          event.target.value = '';
          if (picked.length > 0) void add(picked);
        }}
      />
      <p className="text-xs text-muted-foreground">
        {t('field.filesCount', '{count} of {max} files attached', { count: value.length, max })}
      </p>
      {busy && <p className="text-xs text-muted-foreground">{t('field.uploading', 'Uploading…')}</p>}
      {value.map((reference) => (
        <div key={reference} className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
          <span className="min-w-0 truncate">{names[reference] ?? t('review.attached', 'Attached')}</span>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onChange(value.filter((other) => other !== reference))}
          >
            {t('field.remove', 'Remove')}
          </Button>
        </div>
      ))}
      {error !== null && (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * A table the person adds rows to. It always shows at least one row to type into,
 * and the server drops a row left blank, so an untouched row is not an answer.
 */
export function RepeatingField({
  value,
  maxRows,
  onChange,
  renderCell,
}: {
  value: TableRow[];
  maxRows: number;
  onChange: (value: TableRow[]) => void;
  renderCell: (row: TableRow, onCell: (next: TableRow) => void, rowIndex: number) => ReactNode;
}) {
  const t = useTranslation('forms');
  const rows = value.length > 0 ? value : [{}];

  return (
    <div className="space-y-3">
      {rows.map((row, index) => (
        <fieldset key={index} className="space-y-3 rounded-xl border border-border/60 p-3">
          <div className="flex items-center justify-between gap-3">
            <legend className="text-sm font-medium">{t('field.row', 'Row {number}', { number: index + 1 })}</legend>
            {rows.length > 1 && (
              <Button type="button" variant="ghost" size="sm" onClick={() => onChange(rows.filter((_, other) => other !== index))}>
                {t('field.removeRow', 'Remove row')}
              </Button>
            )}
          </div>
          {renderCell(row, (next) => onChange(rows.map((other, at) => (at === index ? next : other))), index)}
        </fieldset>
      ))}
      <Button type="button" variant="outline" size="sm" disabled={rows.length >= maxRows} onClick={() => onChange([...rows, {}])}>
        {t('field.addRow', 'Add a row')}
      </Button>
    </div>
  );
}

/**
 * A typed signature. The answer holds only the name: the time is the server's, so
 * this page has nothing to say about when it was signed.
 */
export function SignatureField({
  id,
  required,
  value,
  onChange,
}: {
  id: string;
  required: boolean;
  value: string;
  onChange: (name: string) => void;
}) {
  const t = useTranslation('forms');

  return (
    <div className="space-y-1.5">
      <Input id={id} type="text" aria-required={required} autoComplete="name" value={value} onChange={(event) => onChange(event.target.value)} />
      <p className="text-xs text-muted-foreground">
        {t('field.signatureNote', 'Typing your full name here counts as your signature. The time is recorded when you send this form.')}
      </p>
    </div>
  );
}
