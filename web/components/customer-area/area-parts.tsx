'use client';

/**
 * Small parts every customer area page shares (WHIT-1416): the plain-words
 * outcome card for the six states (section 21), a loader for one area API
 * call, and the page heading.
 */

import { useEffect, useState, type ReactNode } from 'react';
import { OutcomeIcon, type OutcomeTone } from '@/components/auth/public-outcome';
import { Button } from '@whity/ui/button';
import { Card, CardContent } from '@whity/ui/card';
import type { IconName } from '@whity/ui/icon';
import { useCurrentLanguage, useTranslation } from '@whity/features/i18n';

export type Load<T> = { kind: 'loading' } | { kind: 'gone' } | { kind: 'busy' } | { kind: 'failed' } | { kind: 'ready'; data: T };

/**
 * Run one area GET and keep its state. `fetcher` answers the api client's
 * `{data, response}`; the page reloads by bumping `reloadKey`.
 */
export function useAreaLoad<T>(fetcher: () => Promise<{ data?: unknown; response: Response }>, deps: unknown[]): [Load<T>, () => void] {
  const [state, setState] = useState<Load<T>>({ kind: 'loading' });
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let live = true;
    fetcher()
      .then(({ data, response }) => {
        if (!live) return;
        if (response.status === 404 || response.status === 401) setState({ kind: 'gone' });
        else if (response.status === 429) setState({ kind: 'busy' });
        else if (!response.ok || !data) setState({ kind: 'failed' });
        else setState({ kind: 'ready', data: (data as { data: T }).data });
      })
      .catch(() => live && setState({ kind: 'failed' }));
    return () => {
      live = false;
    };
    // The caller lists what the request depends on.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);

  return [state, () => setTick((n) => n + 1)];
}

/** Loading, gone, busy and failed, in plain words; null when ready. */
export function LoadState({ state, onRetry, goneTitle }: { state: Load<unknown>; onRetry: () => void; goneTitle?: string }) {
  const t = useTranslation('customer');
  if (state.kind === 'loading') {
    return <div className="h-48 animate-pulse rounded-lg bg-muted" aria-busy="true" aria-label={t('state.loading', 'Opening…')} />;
  }
  if (state.kind === 'gone') {
    return <Outcome icon="link" tone="muted" title={goneTitle ?? t('state.gone', 'This isn’t here any more')} body={t('state.goneBody', 'It may have been moved or removed. Go back and choose again.')} />;
  }
  if (state.kind === 'busy') {
    return <Outcome icon="clock" tone="warn" title={t('state.busy', 'Too many tries from here')} body={t('state.busyBody', 'Wait a minute, then try again.')} />;
  }
  if (state.kind === 'failed') {
    return (
      <Outcome
        icon="sync"
        tone="warn"
        title={t('state.failed', 'This didn’t open')}
        body={t('state.failedBody', 'Check your connection and try again.')}
        action={<Button onClick={onRetry}>{t('state.retry', 'Try again')}</Button>}
      />
    );
  }
  return null;
}

export function Outcome({ icon, tone, title, body, action }: { icon: IconName; tone: OutcomeTone; title: string; body?: string; action?: ReactNode }) {
  return (
    <Card>
      <CardContent className="space-y-4 py-8 text-center" data-testid="customer-outcome">
        <OutcomeIcon name={icon} tone={tone} className="mx-auto" />
        <h2 className="text-xl font-semibold">{title}</h2>
        {body && <p className="text-muted-foreground">{body}</p>}
        {action}
      </CardContent>
    </Card>
  );
}

export function PageTitle({ children, action }: { children: ReactNode; action?: ReactNode }) {
  return (
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
      <h1 className="text-2xl font-semibold">{children}</h1>
      {action}
    </div>
  );
}

/** The server's plain sentence from an error answer, if it sent one. */
export function serverText(error: unknown): string | null {
  const text = (error as { error?: unknown } | null | undefined)?.error;
  return typeof text === 'string' && text !== '' ? text : null;
}

/** A `{en, ar}` name in the reader's language, falling back to the other. */
export function useLocalized(): (text: Record<string, string> | null | undefined) => string {
  const { currentLanguage } = useCurrentLanguage();
  const lang = String(currentLanguage ?? 'en').slice(0, 2);
  return (text) => {
    if (!text) return '';
    return text[lang] || text.en || Object.values(text).find((v) => v !== '') || '';
  };
}
