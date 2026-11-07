'use client';

/**
 * A name in Arabic and English, and which of the two is the original (#821).
 *
 * The original is the legal name: it is what every other language falls back
 * to, so it must be filled in. The other may stay empty.
 */

import { useId } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { BilingualInput, type BilingualValue } from '@whity/ui/bilingual-input';
import { RadioGroup, RadioGroupItem } from '@whity/ui/radio-group';
import type { NameLanguage } from '@/lib/localized-name';

export interface LocalizedNameFieldProps {
  /** Base id for the inputs; the Arabic box is `${id}-ar`, the English `${id}-en`. */
  id: string;
  label: string;
  names: BilingualValue;
  originalLanguage: NameLanguage;
  onNamesChange: (names: BilingualValue) => void;
  onOriginalLanguageChange: (language: NameLanguage) => void;
  disabled?: boolean;
  /** Shown under the boxes when the original is empty. */
  error?: string | null;
}

export function LocalizedNameField({
  id,
  label,
  names,
  originalLanguage,
  onNamesChange,
  onOriginalLanguageChange,
  disabled = false,
  error = null,
}: LocalizedNameFieldProps) {
  const t = useTranslation('common');
  const originalId = useId();
  const languageWord = (code: NameLanguage) =>
    code === 'ar' ? t('localizedName.arabic', 'Arabic') : t('localizedName.english', 'English');

  return (
    <div className="space-y-3" data-testid={`${id}-field`}>
      <BilingualInput
        id={id}
        label={label}
        value={names}
        onChange={onNamesChange}
        disabled={disabled}
        primaryLang={{ code: 'ar', label: languageWord('ar') }}
        secondaryLang={{ code: 'en', label: languageWord('en') }}
        presentLabel={t('localizedName.set', 'Set')}
        emptyLabel={t('localizedName.empty', 'Empty')}
      />

      <div className="space-y-1.5">
        <p id={originalId} className="text-sm font-medium text-foreground">
          {t('localizedName.original', 'The original name is in')}
        </p>
        <RadioGroup
          aria-labelledby={originalId}
          value={originalLanguage}
          onValueChange={(value) => onOriginalLanguageChange(value === 'ar' ? 'ar' : 'en')}
          disabled={disabled}
          className="flex flex-wrap gap-4"
          data-testid={`${id}-original`}
        >
          {(['ar', 'en'] as const).map((code) => (
            <label key={code} className="flex min-h-10 items-center gap-2 text-sm text-foreground">
              <RadioGroupItem value={code} id={`${id}-original-${code}`} />
              {languageWord(code)}
            </label>
          ))}
        </RadioGroup>
        <p className="text-xs text-muted-foreground">
          {t(
            'localizedName.hint',
            'The original is the official name. People who read the other language see it when that language is empty.'
          )}
        </p>
        {error ? (
          <p className="text-xs text-destructive" data-testid={`${id}-error`}>
            {error}
          </p>
        ) : null}
      </div>
    </div>
  );
}
