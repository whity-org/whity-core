'use client';

import { useTranslation } from '@whity/features/i18n';
import { printVars } from './section-headings';
import type { CompanyLines } from './api';
import type { ThemeTokens } from './model';

/** The letterhead lines a theme can print, in the order the schema lists them. */
const LINE_KEYS = ['address', 'taxId', 'phone', 'email'] as const;

/**
 * The Documents tab's preview (`TplDocThemes`): page 1 of a quote with the
 * theme's print colour, letterhead lines, headings in colour, title face and
 * large print, updated as the options change. A printed-page miniature, so
 * its type keeps the sheet's scale rather than the screen's; it is one image
 * to a screen reader.
 */
export function QuotePreview({
  tokens,
  accent,
  workspaceName,
  company,
  ar,
}: {
  /** The draft over what the theme resolves to: every value it prints with. */
  tokens: ThemeTokens;
  accent: string;
  workspaceName: string;
  company: CompanyLines;
  ar: boolean;
}) {
  const t = useTranslation('documents');
  const initials = workspaceName
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('');
  const headsInColour = tokens.accentUse === 'title-and-headings';
  const bodyFace = tokens.titleFace === 'noto';
  const chosen = tokens.letterhead?.lines ?? ['address', 'taxId'];
  const lines = LINE_KEYS.filter((key) => chosen.includes(key))
    .map((key) => (key === 'taxId' && company.taxId ? t('themes.quote.taxNo', 'Tax no. {n}', { n: company.taxId }) : company[key]))
    .filter((line) => line !== '');
  const logo = tokens.letterhead?.logo ?? 'wide';
  const heading = `font-semibold text-[1.15em] ${headsInColour ? 'text-(--print)' : ''}`;

  return (
    <figure className="m-0 grid gap-2">
      <figcaption className="text-xs text-muted-foreground">{t('themes.quote.caption', 'Preview · a quote')}</figcaption>
      <div
        role="img"
        aria-label={t('themes.quote.label', 'An example quote printed with this theme')}
        dir={ar ? 'rtl' : 'ltr'}
        className="light flex aspect-[210/297] w-full flex-col gap-2 rounded-card border border-border bg-card p-5 text-foreground leading-normal"
        // A picture of a printed page (role="img"), drawn at the sheet's scale
        // from one base size, as the card draws it; not text read on screen.
        style={{ ...printVars(accent), fontSize: tokens.largePrint ? 12.5 : 11 }}
      >
        <div className="flex items-start justify-between gap-3 border-b border-border pb-2.5">
          <div className="grid">
            <span className="font-semibold text-[1.1em]">{workspaceName}</span>
            {lines.length > 0 && <span className="text-muted-foreground text-[0.85em]">{lines.join(' · ')}</span>}
          </div>
          {logo !== 'none' && (
            <span className="grid size-[30px] flex-none place-items-center rounded-md bg-(--print) font-semibold text-card text-[1.1em]">
              {initials}
            </span>
          )}
        </div>
        <span className={`mt-1.5 font-semibold text-(--print) text-[1.8em] ${bodyFace ? 'font-sans' : 'font-display'}`}>
          {t('themes.quote.title', 'Quote')}
        </span>
        <span>
          <span dir="ltr">Q-0233</span> · {t('themes.quote.date', '13 November 2026')}
        </span>
        <span className={heading}>{t('themes.quote.propose', 'What we propose')}</span>
        <Lines />
        <span className={heading}>{t('themes.quote.terms', 'Terms')}</span>
        <Lines />
      </div>
    </figure>
  );
}

function Lines() {
  return (
    <span className="grid gap-[7px]" aria-hidden="true">
      {[0, 1, 2, 3, 4].map((i) => (
        <i key={i} className="block h-0.5 bg-border" />
      ))}
    </span>
  );
}
