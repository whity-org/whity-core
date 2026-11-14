'use client';

import { useEffect, useRef, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Skeleton } from '@whity/ui/skeleton';
import { useTranslation } from '@whity/features/i18n';
import { fetchPreview, type ApiClient, type PreviewResult } from './api';
import { MarkPreview, printVars } from './section-headings';
import { formValue, previewBody, type FormSchema, type ThemeTokens } from './model';

/** How long the options must rest before a preview is rendered: each one is a render against the workspace's limits. */
export const PREVIEW_DEBOUNCE_MS = 900;

/**
 * The live preview beside the Form printouts options: the workspace's latest
 * form response printed with the unsaved options (`POST
 * /api/document-themes/form-printout-preview`), debounced. With no response
 * yet (404), or when the render is refused, the card's paper mock shows with
 * a note. "Preview a real form" opens the same PDF in a new tab.
 */
export function PreviewPanel({
  apiClient,
  tokens,
  form,
  locked,
  accent,
  workspaceName,
  lang,
}: {
  apiClient: ApiClient;
  tokens: ThemeTokens;
  form: FormSchema;
  locked: boolean;
  accent: string;
  workspaceName: string;
  lang: 'en' | 'ar';
}) {
  const t = useTranslation('documents');
  const [result, setResult] = useState<PreviewResult | null>(null);
  const [loading, setLoading] = useState(true);
  const lastUrl = useRef<string | null>(null);
  const body = JSON.stringify(previewBody(tokens, lang));

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      setLoading(true);
      fetchPreview(apiClient, JSON.parse(body) as Record<string, unknown>, controller.signal)
        .then((next) => {
          if (controller.signal.aborted) {
            if (next.kind === 'pdf') URL.revokeObjectURL(next.url);
            return;
          }
          if (lastUrl.current) URL.revokeObjectURL(lastUrl.current);
          lastUrl.current = next.kind === 'pdf' ? next.url : null;
          setResult(next);
        })
        .catch(() => {
          if (!controller.signal.aborted) setResult({ kind: 'none', message: null });
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, PREVIEW_DEBOUNCE_MS);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [apiClient, body]);

  useEffect(
    () => () => {
      if (lastUrl.current) URL.revokeObjectURL(lastUrl.current);
    },
    []
  );

  const pdf = result?.kind === 'pdf' ? result.url : null;
  const option = (key: string, standard: string) => {
    const found = form.options.find((o) => o.key === key);
    return found ? formValue(tokens, found, locked) : standard;
  };
  const sectionStyle = option('form.sectionStyle', 'mark');
  const questions = option('form.questions', 'stacked');

  return (
    <aside className="grid gap-2 lg:sticky lg:top-4" aria-label={t('themes.preview.label', 'Preview')}>
      <span className="text-xs text-muted-foreground">
        {pdf
          ? t('themes.preview.latest', 'Preview · page 1 of your latest form response')
          : t('themes.preview.example', 'Preview · page 1 of an example form')}
      </span>
      {loading && result === null ? (
        <Skeleton className="aspect-[210/297] w-full rounded-card" />
      ) : pdf ? (
        <iframe
          src={`${pdf}#toolbar=0&navpanes=0&view=FitH`}
          title={t('themes.preview.frame', 'Form printout preview')}
          className="aspect-[210/297] w-full rounded-card border border-border bg-card"
        />
      ) : (
        <>
          <PaperMock
            accent={accent}
            workspaceName={workspaceName}
            sectionStyle={sectionStyle}
            twoColumns={questions === 'twoColumns'}
          />
          <p className="text-xs text-muted-foreground" role="status">
            {result?.kind === 'none' && result.message
              ? result.message
              : t('themes.preview.none', 'There is no form response to preview yet, so this is an example. Once someone sends a form, its first page shows here.')}
          </p>
        </>
      )}
      <Button
        variant="outline"
        icon="view"
        disabled={!pdf}
        onClick={() => {
          if (pdf) window.open(pdf, '_blank', 'noopener');
        }}
      >
        {t('themes.preview.open', 'Preview a real form')}
      </Button>
    </aside>
  );
}

/**
 * The card's page 1 of a supplier application, in the print colour: shown
 * when there is no response to print yet. A printed-page miniature, so its
 * type keeps the sheet's scale rather than the screen's; it is one image to
 * a screen reader.
 */
export function PaperMock({
  accent,
  workspaceName,
  sectionStyle,
  twoColumns,
}: {
  accent: string;
  workspaceName: string;
  sectionStyle: string;
  twoColumns: boolean;
}) {
  const t = useTranslation('documents');
  const initials = workspaceName
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('');
  const style = printVars(accent);
  const answers: [string, string][] = [
    [t('themes.mock.q1', '1.1 Company name'), 'Al-Nour Mills'],
    [t('themes.mock.q2', '1.2 Registration'), '200114582'],
    [t('themes.mock.q3', '1.3 Supplies'), `☒ ${t('themes.mock.flour', 'Flour')}  ☒ ${t('themes.mock.sugar', 'Sugar')}  ☐ ${t('themes.mock.dairy', 'Dairy')}`],
  ];

  return (
    <div
      role="img"
      aria-label={t('themes.mock.label', 'An example first page of a form printout in your print colour')}
      className="light flex aspect-[210/297] w-full flex-col gap-1.5 rounded-card border border-border bg-card p-4 text-foreground leading-snug"
      // A picture of a printed page (role="img"), drawn at the sheet's scale
      // from one base size, as the card draws it; not text read on screen.
      style={{ ...style, fontSize: 10 }}
    >
      <div className="flex items-start justify-between border-b border-border pb-2">
        <div className="grid">
          <span className="font-semibold">{workspaceName}</span>
          <span className="text-muted-foreground text-[0.8em]">{t('themes.mock.unit', 'Purchasing')}</span>
        </div>
        <span className="grid size-6 place-items-center rounded-md bg-(--print) font-semibold text-[0.9em] text-card">{initials}</span>
      </div>
      <span className="mt-2 font-semibold text-(--print) text-[1.5em]">{t('themes.mock.title', 'Supplier application')}</span>
      <div className="flex flex-wrap gap-2.5 border-y border-y-border py-1 text-[0.9em]">
        <span className="font-semibold text-success-text">✓ {t('themes.mock.approved', 'Approved')}</span>
        <span dir="ltr">SUP-2026-031</span>
        <span className="text-muted-foreground">{t('themes.mock.received', 'Received 4 Nov')}</span>
      </div>
      <SectionHeading style={sectionStyle} title={t('themes.mock.section', 'Company')} />
      <div className={twoColumns ? 'grid grid-cols-[40%_1fr] gap-1.5 text-[0.9em]' : 'grid gap-0.5 text-[0.9em]'}>
        {answers.map(([q, a]) => (
          <div key={q} className="contents">
            <span className="font-semibold">{q}</span>
            <span className={twoColumns ? '' : 'ps-4'}>{a}</span>
          </div>
        ))}
      </div>
      <div className="mt-2 grid gap-1.5" aria-hidden="true">
        {[0, 1, 2, 3, 4].map((i) => (
          <i key={i} className="block h-px bg-border" />
        ))}
      </div>
      <div className="mt-auto flex gap-2 border-t border-border pt-1 text-muted-foreground text-[0.8em]">
        <span dir="ltr">SUP-2026-031</span>
        <span>{t('themes.mock.page', 'Page 1 of 2')}</span>
        <span className="ms-auto rounded-sm border border-border px-1">{t('themes.mock.mark', 'Food safety certified')}</span>
      </div>
    </div>
  );
}

/** Section 1 under the chosen heading style (FP.4). */
function SectionHeading({ style, title }: { style: string; title: string }) {
  const heading = <span className="font-semibold text-[1.3em]">{title}</span>;
  switch (style) {
    case 'number':
      return (
        <div className="mt-1.5 flex items-baseline gap-2 border-b border-border pb-1">
          <span className="font-semibold text-(--print) text-[1.5em]">1</span>
          {heading}
        </div>
      );
    case 'eyebrow':
      return (
        <div className="mt-1.5 grid border-b border-border pb-1">
          <span className="font-mono text-(--print) text-[0.85em]" dir="ltr">SECTION 01 OF 03</span>
          {heading}
        </div>
      );
    case 'rail':
      return (
        <div className="mt-1.5 grid gap-1 pb-1">
          <span className="flex gap-0.5" aria-hidden="true">
            {[0, 1, 2].map((i) => (
              <i key={i} className={i < 1 ? 'h-[3px] w-4 rounded-sm bg-(--print)' : 'h-[3px] w-4 rounded-sm bg-border'} />
            ))}
          </span>
          {heading}
        </div>
      );
    case 'tab':
      return (
        <div className="mt-1.5 grid justify-items-start gap-1 border-b-2 border-(--print) pb-1">
          <span className="rounded-t-[5px] bg-(--print-tint) px-1.5 font-mono text-(--print) text-[0.85em]" dir="ltr">SECTION 1</span>
          {heading}
        </div>
      );
    case 'plane':
      return (
        <div className="mt-1.5 flex items-center gap-2 rounded-md bg-(--print-tint) px-2 py-1">
          <span className="font-semibold text-(--print)">1</span>
          {heading}
        </div>
      );
    default:
      return (
        <div className="mt-1.5 flex items-center gap-2">
          <MarkPreview n="1" size={20} />
          {heading}
        </div>
      );
  }
}
