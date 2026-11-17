'use client';

/**
 * One widget an app adds to the dashboard (SDK 1.115, H.5): a stat, a short list,
 * one chart or a few actions, drawn from the app's blocks.
 *
 * It never breaks the page. Before drawing, it asks the widget's route once: a 403
 * or 404 means the person may not see it now, so it is left out; any other failure,
 * or a drawing error caught by the boundary, shows one calm line with Try again.
 */

import { Component, useEffect, useState, type ReactNode } from 'react';
import { Button } from '@whity/ui/button';
import { Skeleton } from '@whity/ui/skeleton';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import { apiFetch } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import type { DashboardWidget } from './use-dashboard';

type Check = 'ok' | 'denied';

export function AppWidgetBody({ widget, onDenied }: { widget: DashboardWidget; onDenied: () => void }) {
  const locale = useFormattingLocale();
  // Bumped by Try again, so the boundary starts over with a fresh check.
  const [attempt, setAttempt] = useState(0);

  const check = useFetch<Check>(async () => {
    if (!widget.source) return 'ok';
    const response = await apiFetch(widget.source, locale ? { headers: { 'Accept-Language': locale } } : undefined);
    if (response.status === 403 || response.status === 404) return 'denied';
    if (!response.ok) throw new Error('unavailable');
    return 'ok';
  }, [widget.source, locale, attempt]);

  const denied = check.data === 'denied';
  useEffect(() => {
    if (denied) onDenied();
  }, [denied, onDenied]);

  if (denied) return null;
  if (check.loading && check.data === null) return <Skeleton className="h-16 w-full" />;
  if (check.error !== null) return <CalmEmpty onRetry={() => setAttempt((n) => n + 1)} />;

  return (
    <WidgetBoundary key={attempt} fallback={<CalmEmpty onRetry={() => setAttempt((n) => n + 1)} />}>
      <BlockRenderer blocks={widget.blocks} />
    </WidgetBoundary>
  );
}

/** The one quiet line a widget shows when it has nothing to show. */
export function CalmEmpty({ onRetry }: { onRetry?: () => void }) {
  const t = useTranslation('common');

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground" data-slot="widget-empty">
      <span>{t('dashboard.widgets.empty', 'Nothing to show here right now')}</span>
      {onRetry && (
        <Button variant="ghost" size="sm" onClick={onRetry}>
          {t('dashboard.tryAgain', 'Try again')}
        </Button>
      )}
    </div>
  );
}

/** Catches a widget that throws while drawing, so the rest of the page carries on. */
class WidgetBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true };
  }

  render() {
    return this.state.failed ? this.props.fallback : this.props.children;
  }
}
