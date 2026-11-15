'use client';

import * as React from 'react';

import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { ColorPicker } from '@whity/ui/color-picker';
import type { DocumentThemeRef } from '@whity/ui/editor/contract';
import { Popover, PopoverContent, PopoverTrigger } from '@whity/ui/popover';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { cn } from '@whity/ui/utils';
import { useLanguageCode, useTranslation } from '../i18n';
import type { ThemeChoice } from './types';

type LogoChoice = 'wide' | 'square' | 'none';

/**
 * The theme picker in a document's settings (`TplDocThemes`, WHIT-785, E.44):
 * the themes the workspace can use, the workspace default first, then "Only
 * for this document" for the two things a document may set itself, the print
 * colour and the letterhead logo. Choosing the workspace default stores no
 * theme, so the document follows a later change of default.
 *
 * A theme the workspace no longer has (archived, or a plugin switched off)
 * gets the amber note: the draft shows in the workspace theme until it's back
 * and can't be published like this (the publish check names it).
 */
export function ThemePicker({
  value,
  themes,
  disabled,
  onChange,
}: {
  /** What the document stores; null or undefined follows the workspace default. */
  value: DocumentThemeRef | null | undefined;
  themes: ThemeChoice[];
  disabled?: boolean;
  onChange: (next: DocumentThemeRef | null) => void;
}) {
  const t = useTranslation('documents');
  const ar = useLanguageCode() === 'ar';
  const name = (choice: ThemeChoice) => (ar ? choice.nameAr || choice.name : choice.name || choice.nameAr);
  const fallback = themes.find((choice) => choice.isDefault) ?? themes[0];
  const chosenRef = value?.ref ?? fallback?.ref;
  const chosen = themes.find((choice) => choice.ref === chosenRef);
  const missing = value?.ref !== undefined && !chosen;
  const overrides = value?.overrides ?? {};
  const shown = chosen ?? fallback;

  const update = (ref: string | undefined, nextOverrides: DocumentThemeRef['overrides']) => {
    const clean = nextOverrides && Object.keys(nextOverrides).length > 0 ? nextOverrides : undefined;
    const isDefault = ref === undefined || ref === fallback?.ref;
    if (isDefault && !clean) {
      onChange(null);
      return;
    }
    onChange({ ...(isDefault ? {} : { ref }), ...(clean ? { overrides: clean } : {}) });
  };

  const withLogo = (logo: LogoChoice | undefined) => {
    const letterhead = { ...(overrides.letterhead ?? {}) };
    if (logo === undefined) delete letterhead.logo;
    else letterhead.logo = logo;
    const next = { ...overrides };
    if (Object.keys(letterhead).length === 0) delete next.letterhead;
    else next.letterhead = letterhead;
    return next;
  };

  const subtitle = (choice: ThemeChoice) =>
    choice.isDefault
      ? t('themes.picker.default', 'Workspace default')
      : choice.scope === 'system'
        ? 'Whity'
        : t('themes.picker.own', 'Your workspace’s');

  return (
    <div className="flex items-center gap-2">
      {missing && (
        <span className="sr-only" role="status">
          {t('themes.picker.missingShort', 'This document’s theme isn’t available')}
        </span>
      )}
      <Popover>
        <PopoverTrigger asChild>
          <Button variant="outline" size="sm" icon="branding" iconEnd="chevron-down" disabled={disabled}>
            {t('themes.picker.trigger', 'Theme: {name}', { name: shown ? name(shown) : t('themes.picker.standard', 'Whity standard') })}
          </Button>
        </PopoverTrigger>
        <PopoverContent align="start" className="grid w-80 gap-3">
          <p className="m-0 text-sm font-semibold text-foreground">{t('themes.picker.title', 'Theme for this document')}</p>

          {missing && (
            <Alert variant="warning">
              <AlertTitle>{t('themes.picker.missingTitle', 'This theme isn’t available any more')}</AlertTitle>
              <AlertDescription>
                {t(
                  'themes.picker.missingBody',
                  'This draft shows in your workspace theme until it’s back. It can’t be published like this.'
                )}
              </AlertDescription>
              {fallback && (
                <Button size="sm" variant="outline" className="mt-2 justify-self-start" onClick={() => update(undefined, value?.overrides)}>
                  {t('themes.picker.useInstead', 'Use {name} instead', { name: name(fallback) })}
                </Button>
              )}
            </Alert>
          )}

          <ul role="radiogroup" aria-label={t('themes.picker.title', 'Theme for this document')} className="m-0 grid list-none gap-2 p-0">
            {themes.map((choice) => {
              const on = !missing && choice.ref === chosenRef;
              return (
                <li key={choice.ref}>
                  <button
                    type="button"
                    role="radio"
                    aria-checked={on}
                    onClick={() => update(choice.ref, value?.overrides)}
                    className={cn(
                      'grid w-full grid-cols-[24px_minmax(0,1fr)] items-center gap-3 rounded-card border bg-card px-3 py-2 text-start',
                      'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                      on ? 'border-2 border-primary px-[11px] py-[7px]' : 'border-border hover:bg-accent'
                    )}
                  >
                    <span aria-hidden="true" className="size-6 rounded-md" style={{ background: choice.accent ?? 'var(--primary)' }} />
                    <span className="grid min-w-0">
                      <span className="truncate text-sm font-medium text-foreground">{name(choice)}</span>
                      <span className="text-xs text-muted-foreground">{subtitle(choice)}</span>
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>

          <div className="grid gap-3 border-t border-border pt-3">
            <p className="m-0 text-sm font-semibold text-foreground">{t('themes.picker.onlyHere', 'Only for this document')}</p>
            <ColorPicker
              label={t('themes.picker.colour', 'Print colour')}
              value={overrides.accent}
              placeholder={shown?.accent ?? t('themes.picker.fromTheme', 'From the theme')}
              clearable
              clearLabel={t('themes.picker.colourClear', 'Use the theme’s colour')}
              onChange={(color) => {
                const next = { ...overrides };
                if (color) next.accent = color;
                else delete next.accent;
                update(value?.ref, next);
              }}
            />
            <div className="grid gap-2">
              <span className="text-sm font-medium text-foreground">{t('themes.picker.logo', 'Letterhead logo')}</span>
              <ToggleGroup
                label={t('themes.picker.logo', 'Letterhead logo')}
                type="single"
                value={overrides.letterhead?.logo ?? 'theme'}
                options={[
                  { value: 'theme', label: t('themes.picker.logoTheme', 'As the theme') },
                  { value: 'wide', label: t('themes.picker.logoWide', 'Wide') },
                  { value: 'square', label: t('themes.picker.logoSquare', 'Square') },
                  { value: 'none', label: t('themes.picker.logoNone', 'None') },
                ]}
                onChange={(next) => {
                  if (typeof next !== 'string') return;
                  update(value?.ref, withLogo(next === 'theme' ? undefined : (next as LogoChoice)));
                }}
                className="max-w-full flex-wrap"
              />
            </div>
          </div>
        </PopoverContent>
      </Popover>
    </div>
  );
}
