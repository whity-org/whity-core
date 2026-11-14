'use client';

import { useRef, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@whity/features/i18n';
import { MARK_TYPES, markProblem, type Mark } from './model';

/**
 * The client's marks printed in the footer (ISO, accreditation; FP.10): up
 * to `max` PNG, JPEG or WebP images of at most 300 KB each, stored in the
 * theme as data: URIs with alt text. Marks are part of the standard footer,
 * so they work on every plan.
 */
export function MarksUploader({
  marks,
  max,
  disabled,
  onChange,
}: {
  marks: Mark[];
  max: number;
  disabled: boolean;
  onChange: (marks: Mark[]) => void;
}) {
  const t = useTranslation('documents');
  const input = useRef<HTMLInputElement>(null);
  const [problem, setProblem] = useState<string | null>(null);

  const add = (file: File) => {
    const reason = markProblem(file);
    if (reason === 'type') {
      setProblem(t('themes.marks.type', 'A mark must be a PNG, JPEG or WebP image.'));
      return;
    }
    if (reason === 'size') {
      setProblem(t('themes.marks.size', 'A mark can be at most 300 KB. Make the image smaller and try again.'));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      if (typeof reader.result !== 'string') return;
      setProblem(null);
      onChange([...marks, { src: reader.result, alt: file.name.replace(/\.[a-z0-9]+$/i, '').slice(0, 80) }]);
    };
    reader.onerror = () => setProblem(t('themes.marks.read', 'The image could not be read. Try another file.'));
    reader.readAsDataURL(file);
  };

  return (
    <div className="grid gap-2 border-t border-border pt-4">
      <div className="grid gap-0.5">
        <span className="text-sm font-medium text-foreground">{t('themes.marks.title', 'Your marks')}</span>
        <span className="text-xs text-muted-foreground">
          {t('themes.marks.hint', 'Up to {max} images, such as ISO or accreditation marks. PNG, JPEG or WebP, 300 KB at most each. They print in the footer on every plan.', { max: String(max) })}
        </span>
      </div>
      {marks.length > 0 && (
        <ul className="grid gap-2">
          {marks.map((mark, i) => (
            <li key={i} className="flex flex-wrap items-center gap-3 rounded-control border border-border p-2">
              {/* eslint-disable-next-line @next/next/no-img-element -- a data: URI, nothing to optimise */}
              <img src={mark.src} alt={mark.alt} className="h-8 w-auto max-w-24 object-contain" />
              <label className="grid min-w-48 flex-1 gap-1 text-xs text-muted-foreground">
                {t('themes.marks.alt', 'Describe it for people who can’t see it')}
                <Input
                  value={mark.alt}
                  maxLength={80}
                  disabled={disabled}
                  onChange={(e) => onChange(marks.map((m, j) => (j === i ? { ...m, alt: e.target.value } : m)))}
                />
              </label>
              <Button
                variant="outline"
                size="sm"
                icon="delete"
                disabled={disabled}
                onClick={() => onChange(marks.filter((_, j) => j !== i))}
              >
                {t('themes.marks.remove', 'Remove')}
              </Button>
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap items-center gap-3">
        <Button
          variant="outline"
          icon="upload"
          disabled={disabled || marks.length >= max}
          onClick={() => input.current?.click()}
        >
          {t('themes.marks.add', 'Add a mark')}
        </Button>
        {marks.length >= max && (
          <span className="text-xs text-muted-foreground">
            {t('themes.marks.full', 'That’s {max} marks, the most a footer holds. Remove one to add another.', { max: String(max) })}
          </span>
        )}
      </div>
      <input
        ref={input}
        type="file"
        accept={MARK_TYPES.join(',')}
        className="hidden"
        aria-hidden="true"
        tabIndex={-1}
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) add(file);
          e.target.value = '';
        }}
      />
      {problem && (
        <p role="alert" className="text-xs text-error-text">
          {problem}
        </p>
      )}
    </div>
  );
}
